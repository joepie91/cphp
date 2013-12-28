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
	public function __construct($message, $exceptions)
	{
		$this->message = $message;
		$this->exceptions = $exceptions;
	}
}

class CPHPFormValidator
{
	public function GenerateResultObject($failed, $critical)
	{
		if($failed === true && $critical === true)
		{
			$object = new CPHPFormValidatorAbort();
		}
		else
		{
			$object = new CPHPFormValidatorResult();
		}
		
		$object->handler = $this->handler;
		return $object;
	}
	
	public function RequireKey($key, $critical = false)
	{
		return $this->ProcessSingleValidationResult($key, (!isset($this->handler->formdata[$key])), "required", "A value is required for this field.", $critical);
	}
	
	public function ValidateEmail($key, $critical = false)
	{
		return $this->ProcessValidation($key, function($key, $value){
			return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
		}, "email", "The value is not a valid e-mail address.", $critical);
	}
	
	public function ProcessValidation($key, $validator, $error_type, $error_message, $critical)
	{
		if(is_array($this->handler->formdata[$key]))
		{
			foreach($this->handler->formdata[$key] as $i => $value)
			{
				$result = $validator($key, $this->handler->formdata[$key], $this->handler);
				$return_object = $this->ProcessArrayValueValidationResult($key, $i, $result, $error_type, $error_message, $critical);
				
				if($result !== true && $critical === true)
				{
					return $return_object;
				}
			}
			
			return $return_object;
		}
		else
		{
			$result = $validator($key, $this->handler->formdata[$key], $this->handler);
			return $this->ProcessSingleValidationResult($key, $result, $error_type, $error_message, $critical);
		}
	}
	
	public function ProcessSingleValidationResult($key, $result, $error_type, $error_message, $critical)
	{
		if($result === false)
		{
			$this->handler->StoreValidationException(array(
				"type" => "single",
				"key" => $key,
				"error_type" => $error_type,
				"error_msg" => $error_message
			));
			$failed = true;
		}
		else
		{
			$failed = false;
		}
		
		return $this->GenerateResultObject($failed, $critical);
	}
	
	public function ProcessArrayValueValidationResult($key, $i, $result, $error_type, $error_message, $critical)
	{
		if($result === false)
		{
			$this->handler->StoreValidationException(array(
				"type" => "array_value",
				"key" => $key,
				"index" => $i,
				"error_type" => $error_type,
				"error_msg" => $error_message
			), $this);
			$failed = true;
		}
		else
		{
			$failed = false;
		}
		
		return $this->GenerateResultObject($failed, $critical);
	}
	
	public function Done()
	{
		$this->handler->RaiseValidationExceptions(false);
	}
	
	public function Either()
	{
		$statements = 
	}
	
	public function All()
	{
		
	}
	
	public function ValidateIf($condition, $validation)
	{
		
	}
}

class CPHPFormHandler extends CPHPFormValidator
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
	
	public function GetGroupedValues($keys)
	{
		/* Returns an array of associative arrays. This is used for forms that have
		 * multiple array inputs, and where each input has a corresponding element
		 * for another input name. */
		
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
		
		if(!isset($_POST[$key]))
		{
			return array();
		}
		elseif(is_array($_POST[$key]))
		{
			return $_POST[$key];
		}
		else
		{
			return array($_POST[$key]);
		}
	}
	
	public function GetValue($key)
	{
		/* Returns a single value for the given key. If the key contains an array, it
		 * will return the first element. If the key does not exist, it will return null. */
		 
		if(!isset($_POST[$key]))
		{
			return null;
		}
		elseif(is_array($_POST[$key]))
		{
			return $_POST[$key][0];
		}
		else
		{
			return $_POST[$key];
		}
	}
}

class CPHPFormValidatorResult extends CPHPFormValidator
{
	
}

class CPHPFormValidatorAbort
{
	public function Abort()
	{
		$this->handler->RaiseValidationExceptions(true);
	}
	
	public function __get($anything)
	{
		return $this->Abort;
	}
}
