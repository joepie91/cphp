<?php
/*
 * CPHP is more free software. It is licensed under the WTFPL, which
 * allows you to do pretty much anything with it, without having to
 * ask permission. Commercial use is allowed, and no attribution is
 * required. We do politely request that you share your modifications
 * to benefit other developers, but you are under no enforced
 * obligation to do so :)
 * 
 * Please read the accompanying LICENSE document for the full WTFPL
 * licensing text.
 */

/* TODO:
 *  - Freak out if there are invalid CSRF tokens.
 *  - Let the user choose not to have it freak out if there are invalid CSRF tokens. 
 */

if($_CPHP !== true) { die(); }

class FormValidationException extends Exception {
	private function DoGetOffendingKeys($exceptions)
	{
		$results = array();
		
		foreach($exceptions as $exception)
		{
			if(isset($exception['key']))
			{
				$results[] = array(
					"key" => $exception["key"],
					"index" => isset($exception["index"]) ? $exception["index"] : 0
				);
			}
			
			if(isset($exception["children"]))
			{
				$results = array_merge($results, $this->DoGetOffendingKeys($exception["children"]));
			}
		}
		
		return $results;
	}
	
	public function __construct($message, $exceptions)
	{
		$this->message = $message;
		$this->exceptions = $exceptions;
	}
	
	public function GetErrors()
	{
		/* We just need to return a flattened version of the exception list here. */
		$results = array();
		
		foreach($this->exceptions as $exception_list)
		{
			$results = array_merge($results, $exception_list);
		}
		
		return $results;
	}
	
	public function GetErrorMessages($custom_map = array())
	{
		$flattened = $this->GetErrors();
		
		$results = array();
		
		foreach($flattened as $exception)
		{
			if(!empty($custom_map) && array_key_exists($exception["error_type"], $custom_map) && array_key_exists($exception["key"], $custom_map[$exception["error_type"]]))
			{
				/* A custom error message was defined for this particular key/type error combination. */
				$results[] = $custom_map[$exception["error_type"]][$exception["key"]];
			}
			else
			{
				/* Use default error message. */
				$results[] = $exception["error_msg"];
			}
		}
		
		return $results;
	}
	
	public function GetOffendingKeys()
	{
		$results = array();
		
		foreach($this->exceptions as $exception_list)
		{
			$results = array_merge($results, $this->DoGetOffendingKeys($exception_list));
		}
		
		return $results;
	}
}

class ImmediateAbort extends FormValidationException { }

class CPHPFormValidatorPromiseBaseClass
{
	public $previous = null;
	public $next = null;
	
	public function __construct($creator)
	{
		$this->previous = $creator;
	}
	
	public function StartResolve()
	{
		/* Back and forth! */
		if($this->previous == $this->handler)
		{
			$this->ContinueResolve(array());
		}
		else
		{
			$this->previous->StartResolve();
		}
	}
	
	public function ContinueResolve($results)
	{
		$own_result = $this->Resolve($results);
		
		if(is_null($own_result) === false)
		{
			$results[] = $own_result;
		}
		
		if(is_null($this->next) === false)
		{
			$this->next->ContinueResolve($results);
		}
		else
		{
			$this->ValidationFinished($results);
		}
	}
	
	public function ValidationFinished($results)
	{
		if(count($results) > 0)
		{
			throw new FormValidationException("One or more validation steps failed.", $results);
		}
	}
	
	/* Operators */
	public function Either($error_message)
	{
		$this->next = new CPHPFormValidatorOperatorEither($this, $error_message, array_slice(func_get_args(), 1));
		$this->next->handler = $this->handler;
		return $this->next;
	}
	
	public function All($error_message)
	{
		$this->next = new CPHPFormValidatorOperatorAll($this, $error_message, array_slice(func_get_args(), 1));
		$this->next->handler = $this->handler;
		return $this->next;
	}
	
	/* Special instructions */
	
	public function AbortIfErrors()
	{
		$this->next = new CPHPFormValidatorAbortIfErrors($this, $this->handler);
		$this->next->handler = $this->handler;
		return $this->next;
	}
	
	public function Done()
	{
		/* Trigger validation routine */
		try
		{
			$this->StartResolve();
		}
		catch (ImmediateAbort $e)
		{
			throw new FormValidationException("A critical validation step failed.", $e->exceptions);
		}
	}
	
	/* Validators */
	public function RequireKey($key, $critical = false)
	{
		$this->next = new CPHPFormValidatorPromise($this, $this->handler, $key, array(), "required", "A value is required for this field.", $critical, function($key, $value, $args, $handler){
			return isset($handler->formdata[$key]);
		});
		$this->next->handler = $this->handler;
		return $this->next;
	}
	
	public function RequireNonEmpty($key, $critical = false)
	{
		$this->next = new CPHPFormValidatorPromise($this, $this->handler, $key, array(), "required", "The value for this field must not be empty.", $critical, function($key, $value, $args, $handler){
			return trim($value) !== "";
		});
		$this->next->handler = $this->handler;
		return $this->next;
	}
	
	public function ValidateEmail($key, $critical = false)
	{
		$this->next = new CPHPFormValidatorPromise($this, $this->handler, $key, array(), "email", "The value is not a valid e-mail address.", $critical, function($key, $value, $args, $handler){
			return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
		});
		$this->next->handler = $this->handler;
		return $this->next;
	}
	
	public function ValidateUrl($key, $critical = false)
	{
		$this->next = new CPHPFormValidatorPromise($this, $this->handler, $key, array(), "url", "The value is not a valid URL.", $critical, function($key, $value, $args, $handler){
			return filter_var($value, FILTER_VALIDATE_URL) !== false;
		});
		$this->next->handler = $this->handler;
		return $this->next;
	}
	
	public function ValidateIp($key, $critical = false)
	{
		$this->next = new CPHPFormValidatorPromise($this, $this->handler, $key, array(), "ip", "The value is not a valid IP address.", $critical, function($key, $value, $args, $handler){
			return filter_var($value, FILTER_VALIDATE_IP) !== false;
		});
		$this->next->handler = $this->handler;
		return $this->next;
	}
	
	public function ValidateIpv4($key, $critical = false)
	{
		$this->next = new CPHPFormValidatorPromise($this, $this->handler, $key, array(), "ip4", "The value is not a valid IPv4 address.", $critical, function($key, $value, $args, $handler){
			return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
		});
		$this->next->handler = $this->handler;
		return $this->next;
	}
	
	public function ValidateIpv6($key, $critical = false)
	{
		$this->next = new CPHPFormValidatorPromise($this, $this->handler, $key, array(), "ip6", "The value is not a valid IPv6 address.", $critical, function($key, $value, $args, $handler){
			return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
		});
		$this->next->handler = $this->handler;
		return $this->next;
	}
	
	public function ValidatePublicIp($key, $critical = false)
	{
		$this->next = new CPHPFormValidatorPromise($this, $this->handler, $key, array(), "ip_public", "The value is not an IP in a publicly usable range.", $critical, function($key, $value, $args, $handler){
			return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
		});
		$this->next->handler = $this->handler;
		return $this->next;
	}
	
	public function ValidatePrivateIp($key, $critical = false)
	{
		$this->next = new CPHPFormValidatorPromise($this, $this->handler, $key, array(), "ip_private", "The value is not an IP in a private range.", $critical, function($key, $value, $args, $handler){
			return (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_PRIV_RANGE) === false && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) !== false);
		});
		$this->next->handler = $this->handler;
		return $this->next;
	}
	
	public function ValidateRegex($key, $error_message, $pattern, $critical = false)
	{
		$this->next = new CPHPFormValidatorPromise($this, $this->handler, $key, array("pattern" => $pattern), "regex", $error_message, $critical, function($key, $value, $args, $handler){
			return preg_match($args["pattern"], $value) === 1;
		});
		$this->next->handler = $this->handler;
		return $this->next;
	}
	
	public function ValidateCustom($key, $error_message, $validator, $critical = false)
	{
		$this->next = new CPHPFormValidatorPromise($this, $this->handler, $key, array(), "custom", $error_message, $critical, $validator);
		$this->next->handler = $this->handler;
		return $this->next;
	}
}

class CPHPFormValidatorPromise extends CPHPFormValidatorPromiseBaseClass
{
	public function __construct($creator, $handler, $key, $args, $error_type, $error_message, $critical, $function)
	{
		parent::__construct($creator);
		$this->key = $key;
		$this->func = $function;
		$this->args = $args;
		$this->error_type = $error_type;
		$this->error_message = $error_message;
		$this->critical = $critical;
		$this->handler = $handler;
	}
	
	public function Resolve($results)
	{
		$func = $this->func;  /* WTF PHP? Why can't I call $this->func directly? */
		
		$exceptions = array();
		
		$values = isset($this->handler->formdata[$this->key]) ? $this->handler->formdata[$this->key] : null;
		
		if(is_array($values) === true)
		{
			/* Array */
			foreach($values as $i => $value)
			{
				if($func($this->key, $value, $this->args, $this->handler) !== true)
				{
					$exceptions[] = array(
						"type" => "array_value",
						"key" => $this->key,
						"index" => $i,
						"error_type" => $this->error_type,
						"error_msg" => $this->error_message
					);
				}
			}
		}
		else
		{
			/* Single value */
			if($func($this->key, $values, $this->args, $this->handler) !== true)
			{
				$exceptions[] = array(
					"type" => "single",
					"key" => $this->key,
					"index" => 0,
					"error_type" => $this->error_type,
					"error_msg" => $this->error_message
				);
			}
		}
		
		if(count($exceptions) > 0 && $this->critical === true)
		{
			$results[] = $exceptions;
			throw new ImmediateAbort("Critical validation did not pass.", $results);
		}
		
		if(count($exceptions) == 0)
		{
			return null;
		}
		else
		{
			return $exceptions;
		}
	}
}

class CPHPFormValidatorAbortIfErrors extends CPHPFormValidatorPromiseBaseClass
{
	public function __construct($creator, $handler)
	{
		parent::__construct($creator);
		$this->handler = $handler;
	}
	
	public function Resolve($results)
	{
		if(count($results) > 0)
		{
			throw new FormValidationException("One or more validation errors before an AbortIfErrors statement.", $results);
		}
		
		return $results;
	}
}

class CPHPFormValidatorOperator extends CPHPFormValidatorPromiseBaseClass
{
	public function __construct($creator, $error_message, $children)
	{
		parent::__construct($creator);
		$this->error_message = $error_message;
		$this->children = $children;
	}
}

class CPHPFormValidatorOperatorEither extends CPHPFormValidatorOperator
{
	public function Resolve($results)
	{
		$exceptions = array();
		foreach($this->children as $child)
		{
			$result = $child->Resolve($exceptions);
			if(is_null($result) === false)
			{
				$exceptions[] = $result;
			}
		}
		
		if(count($exceptions) == count($this->children))
		{
			return array(array(
				"type" => "operator",
				"error_type" => "either",
				"error_msg" => $this->error_message,
				"children" => $exceptions
			));
		}
		else
		{
			return null;
		}
	}
}

class CPHPFormValidatorOperatorAll extends CPHPFormValidatorOperator
{
	public function Resolve($results)
	{
		$exceptions = array();
		foreach($this->children as $child)
		{
			$result = $child->Resolve($exceptions);
			if(is_null($result) === false)
			{
				$exceptions[] = $result;
			}
		}
		
		if(count($exceptions) > 0)
		{
			return array(array(
				"type" => "operator",
				"error_type" => "both",
				"error_msg" => $this->error_message,
				"children" => $exceptions
			));
		}
		else
		{
			return null;
		}
	}
}

class CPHPFormHandler extends CPHPFormValidatorPromiseBaseClass
{
	public function __construct($formdata = null, $no_csrf = false)
	{
		if(is_null($formdata))
		{
			$this->formdata = $_POST;
		}
		else
		{
			$this->formdata = $formdata;
		}
		
		$this->no_csrf = $no_csrf;
		$this->handler = $this;
		$this->validation_exceptions = array();
		$this->exception_buffer = array();
		$this->first_validation = true;
	}
	
	public function StoreValidationException($exception, $validator_object)
	{
		if($validator_object == $this)
		{
			if($this->first_validation === true)
			{
				$this->first_validation = false;
				$this->validation_exceptions[] = $exception;
			}
			else
			{
				$this->exception_buffer[] = $exception;
			}
		}
		else
		{
			$this->validation_exceptions[] = $exception;
		}
	}
	
	public function RaiseValidationExceptions($aborted)
	{
		if(count($this->validation_exceptions) > 0)
		{
			throw new FormValidationException("One or more validation errors occurred.", $this->validation_exceptions);
		}
		
		$this->validation_exceptions = array();
	}
	
	public function GetGroupedValues()
	{
		/* Returns an array of associative arrays. This is used for forms that have
		 * multiple array inputs, and where each input has a corresponding element
		 * for another input name. */
		
		$keys = func_get_args();
		
		$sCounts = array();
		foreach($keys as $key)
		{
			$sCounts[] = count($this->formdata[$key]);
		}
		$sTotalItems = max($sCounts);
		
		$sAllValues = array();
		
		for($i = 0; $i < $sTotalItems; $i++)
		{			
			$sValues = array();
			foreach($keys as $key)
			{
				$sValues[$key] = $this->formdata[$key][$i];
			}
			$sAllValues[] = $sValues;
		}
		
		return $sAllValues;
	}
	
	public function GetValues($key)
	{
		/* Returns an array with zero or more values for the given key. If the key
		 * does not exist, an empty array is returned. */
		
		if(!isset($this->formdata[$key]))
		{
			return array();
		}
		elseif(is_array($this->formdata[$key]))
		{
			return $this->formdata[$key];
		}
		else
		{
			return array($this->formdata[$key]);
		}
	}
	
	public function GetValue($key)
	{
		/* Returns a single value for the given key. If the key contains an array, it
		 * will return the first element. If the key does not exist, it will return null. */
		 
		if(!isset($this->formdata[$key]))
		{
			return null;
		}
		elseif(is_array($this->formdata[$key]))
		{
			return $this->formdata[$key][0];
		}
		else
		{
			return $this->formdata[$key];
		}
	}
}
