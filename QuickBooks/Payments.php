<?php

/**
 * QuickBooks Payments class
 * 
 * Copyright (c) {2010-04-16} {Keith Palmer / ConsoliBYTE, LLC.
 * All rights reserved. This program and the accompanying materials
 * are made available under the terms of the Eclipse Public License v1.0
 * which accompanies this distribution, and is available at
 * http://www.opensource.org/licenses/eclipse-1.0.php
 * 
 * QuickBooks Merchant Service enables online stores to charge credit cards and 
 * debit cards via simple HTTPS POSTs to the QuickBooks Merchant Service 
 * payment gateway. This class simplifies the process and wraps it in a nice 
 * OOP interface. 
 * 
 * @author Keith Palmer <keith@consolibyte.com>
 * @license LICENSE.txt 
 *  
 * @package QuickBooks
 * @subpackage MerchantAccount
 */

/**
 * Utilities class (for masking and some other misc things)
 */
QuickBooks_Loader::load('/QuickBooks/Utilities.php');

/**
 * HTTP connection class
 */
QuickBooks_Loader::load('/QuickBooks/HTTP.php');

/**
 * QuickBooks driver factory for database logging
 */
QuickBooks_Loader::load('/QuickBooks/Driver/Factory.php');

/**
 * QuickBooks credit card class
 */
QuickBooks_Loader::load('/QuickBooks/Payments/CreditCard.php');

/**
 * QuickBooks merchant service transaction class
 */
QuickBooks_Loader::load('/QuickBooks/Payments/Transaction.php');

/**
 * Token class
 */
QuickBooks_Loader::load('/QuickBooks/Payments/Token.php');

/**
 * QuickBooks Merchant Service implementation
 */
class Quickbooks_Payments
{
	/**
	 * No error occurred
	 * @var integer
	 */
	const OK = QUICKBOOKS_ERROR_OK;
	
	/**
	 * No error occurred
	 * @var integer
	 */
	const ERROR_OK = QUICKBOOKS_ERROR_OK;

	const ERROR_AUTH = -2000;
	const ERROR_HTTP = -2001;
	
	const URL_CHARGE = '/quickbooks/v4/payments/charges';
	const URL_TOKEN = '/quickbooks/v4/payments/tokens';
	//const URL_ACCOUNT = '/quickbooks/v4/customers/<id>/bank-accounts';
	//const URL_CARD = '/quickbooks/v4/customers/<id>/cards';
	const URL_ECHECK = '/quickbooks/v4/payments/echecks';

	const BASE_SANDBOX = 'https://sandbox.api.intuit.com';
	const BASE_PRODUCTION = 'https://api.intuit.com';

	protected $_sandbox = false;
	protected $_debug = false;
	protected $_driver = null;
	protected $_masking = false;

	protected $_oauth_consumer_key;
	protected $_oauth_consumer_secret;

	public function __construct($oauth_consumer_key, $oauth_consumer_secret, $sandbox = false)
	{
		$this->_oauth_consumer_key = $oauth_consumer_key;
		$this->_oauth_consumer_secret = $oauth_consumer_secret;

		$this->_sandbox = (bool) $sandbox;
	}

	protected function _getBaseURL()
	{
		if ($this->_sandbox)
		{
			return Quickbooks_Payments::BASE_SANDBOX;
		}
		else
		{
			return Quickbooks_Payments::BASE_PRODUCTION;
		}
	}

	public function charge($Context, $Object_or_token, $amount, $currency = 'USD', $description = '')
	{
		$capture = true;
		return $this->_chargeOrAuth($Context, $Object_or_token, $amount, $currency, $capture, $description);
	}

	public function authorize($Context, $Object_or_token, $amount, $currency = 'USD', $description = '')
	{
		$capture = false;
		return $this->_chargeOrAuth($Context, $Object_or_token, $amount, $currency, $capture, $description);
	}

	public function _chargeOrAuth($Context, $Object_or_token, $amount, $currency, $capture, $description)
	{
		$payload = array(
			'amount' => sprintf('%01.2f', $amount), 
			'currency' => $currency, 
			);

		if ($Object_or_token instanceof QuickBooks_Payments_CreditCard)
		{
			$payload['card'] = $Object_or_token->toArray();
		}
		else if ($Object_or_token instanceof QuickBooks_Payments_BankAccount)
		{
			$this->_setError();
			return false;
		}
		else if ($Object_or_token instanceof QuickBooks_Payments_Token)
		{
			// It's a token 
			$payload['token'] = $Object_or_token->toString();
		}
		else
		{
			// It's a string token 
			$payload['token'] = $Object_or_token;
		}

		// Sign the request 
		$resp = $this->_http($Context, QuickBooks_Payments::URL_CHARGE, json_encode($payload));

		$data = json_decode($resp, true);

		return new QuickBooks_Payments_Transaction($data);
	}

	public function tokenize($Context, $Object)
	{
		if ($Object instanceof QuickBooks_Payments_CreditCard)
		{
			$payload = array(
				'card' => $Object->toArray()
				);
		}
		else if ($Object instanceof QuickBooks_Payments_BankAccount)
		{

		}
		else
		{
			$this->_setError();
			return false;
		}

		$resp = $this->_http($Context, QuickBooks_Payments::URL_TOKEN, json_encode($payload));

		$data = json_decode($resp, true);

		if (isset($data['value']))
		{
			return new QuickBooks_Payments_Token($data['value']);
		}

		$this->_setError();
		return false;
	}

	/**
	 * Get the last raw XML response that was received
	 * 
	 * @return string
	 */
	public function lastResponse()
	{
		return $this->_last_response;
	}
	
	/**
	 * Get the last raw XML request that was sent
	 *
	 * @return string
	 */
	public function lastRequest()
	{
		return $this->_last_request;
	}
	
	public function lastError()
	{
		return $this->_errnum . ': ' . $this->_errmsg;
	}

	/**
	 * Set an error message
	 * 
	 * @param integer $errnum	The error number/code
	 * @param string $errmsg	The text error message
	 * @return void
	 */
	protected function _setError($errnum, $errmsg = '')
	{
		$this->_errnum = $errnum;
		$this->_errmsg = $errmsg;
	}	

	/**
	 * 
	 * 
	 * 
	 * @param string $message
	 * @param integer $level
	 * @return boolean
	 */
	public function log($message)
	{
		if ($this->_masking)
		{
			$message = QuickBooks_Utilities::mask($message);
		}
		
		if ($this->_debug)
		{
			print($message . QUICKBOOKS_CRLF);
		}
		
		if ($this->_driver)
		{
			// Send it to the driver to be logged 
			$this->_driver->log($message, null, $level);
		}
		
		return true;
	}

	protected function _http($Context, $url_path, $raw_body)
	{
		$method = 'POST';
		$url = $this->_getBaseURL() . $url_path;

		$authcreds = $Context->authcreds();

		$params = array();

		$OAuth = new QuickBooks_IPP_OAuth($this->_oauth_consumer_key, $this->_oauth_consumer_secret);
		$signed = $OAuth->sign($method, $url, $authcreds['oauth_access_token'], $authcreds['oauth_access_token_secret'], $params);

		//print_r($signed);

		$HTTP = new QuickBooks_HTTP($signed[2]);
		
		$headers = array(
			'Content-Type' => 'application/json',
			'Request-Id' => QuickBooks_Utilities::GUID(),
			);
		$HTTP->setHeaders($headers);
		
		// Turn on debugging for the HTTP object if it's been enabled in the payment processor
		$HTTP->useDebugMode($this->_debug);
		
		// 
		$HTTP->setRawBody($raw_body);
		
		$HTTP->verifyHost(false);
		$HTTP->verifyPeer(false);
		
		$return = $HTTP->POST();
		
		$this->_last_request = $HTTP->lastRequest();
		$this->_last_response = $HTTP->lastResponse();
		
		// 
		$this->log($HTTP->getLog(), QUICKBOOKS_LOG_DEBUG);
		
		$info = $HTTP->lastInfo();

		$errnum = $HTTP->errorNumber();
		$errmsg = $HTTP->errorMessage();
		
		if ($errnum)
		{
			// An error occurred!
			$this->_setError(QuickBooks_Payments::ERROR_HTTP, $errnum . ': ' . $errmsg);
			return false;
		}

		if ($info['http_code'] == 401)
		{
			$this->_setError(QuickBooks_Payments::ERROR_AUTH, 'Payments return a 401 Unauthorized status.');
			return false;
		}
		
		// Everything is good, return the data!
		$this->_setError(QuickBooks_Payments::ERROR_OK, '');
		return $return;
	}
}