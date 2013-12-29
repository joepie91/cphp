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
	
	public function ValidateEmail($key, $critical = false)
	{
		$this->next = new CPHPFormValidatorPromise($this, $this->handler, $key, array(), "email", "The value is not a valid e-mail address.", $critical, function($key, $value, $args, $handler){
			return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
		});
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
