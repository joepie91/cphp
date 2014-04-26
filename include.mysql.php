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

if($_CPHP !== true) { die(); }

$cphp_mysql_connected = false;

class CachedPDO extends PDO
{
	public function CachedQuery($query, $parameters = array(), $expiry = 60)
	{
		/* TODO: Do type guessing before checking cache, so as to avoid
		 *       different parameter hashes depending on input type for
		 *       numbers. */
		$query_hash = md5($query);
		$parameter_hash = md5(serialize($parameters));
		$cache_hash = $query_hash . $parameter_hash;

		$return_object = new stdClass;
		
		if($expiry != 0 && $result = mc_get($cache_hash))
		{
			$return_object->source = "memcache";
			$return_object->data = $result;
		}
		else
		{
			$statement = $this->prepare($query);
			
			if(count($parameters) > 0)
			{
				foreach($parameters as $key => $value)
				{
					$type = $this->GuessType($value);
					
					if(preg_match("/^[0-9]+$/", $value) && is_string($value))
					{
						/* PDO library apparently thinks it's part of a strongly typed language and doesn't do any typecasting.
						 * We'll do it ourselves then. */
						$int_value = (int) $value;
						 
						if($int_value < PHP_INT_MAX)
						{
							/* We only want to cast to integer if the result doesn't exceed INT_MAX, to avoid overflows. The
							 * only way to do this appears to be aborting when it *equals* or exceeds INT_MAX, as an overflow
							 * would occur during this check also. */
							$value = $int_value;
							$type = PDO::PARAM_INT;
						}
					}
					
					if($type == PDO::PARAM_STR)
					{
						$value = strval($value);
					}
					
					$statement->bindValue($key, $value, $type);
				}
			}
			
			if($statement->execute() === true)
			{
				if($result = $statement->fetchAll(PDO::FETCH_ASSOC))
				{
					if(count($result) > 0)
					{	
						if($expiry != 0)
						{
							mc_set($cache_hash, $result, $expiry);
						}
						
						$return_object->source = "database";
						$return_object->data = $result;
					}
					else
					{
						return false;
					}
				}
				else
				{
					$last_id = $this->lastInsertId();
					
					if($last_id == "0" || !starts_with(strtoupper($query), "INSERT"))
					{
						/* There were zero results. Return null instead of an object without results, to allow for statements
						 * of the form if($result = $database->CachedQuery()) . */
						return null;
					}
					else
					{
						/* This was an INSERT query. Return the primary ID of the created row. */
						return $last_id;
					}
				}
			}
			else
			{
				/* The query failed. */
				$err = $statement->errorInfo();
				
				if($err[0] == "23000")
				{
					if(starts_with($err[2], "Duplicate entry")) /* There does not seem to be a better way of doing this. */
					{
						throw new DatabaseDuplicateException("The query failed because one of the keys was not unique: {$err[2]}", 0, null, array('query' => $query, 'parameters' => $parameters));
					}
					else
					{
						throw new DatabaseConstraintException("The query violates a database constraint: {$err[2]}", 0, null, array('query' => $query, 'parameters' => $parameters));
					}
				}
				else
				{
					throw new DatabaseException("The query failed: {$err[2]}", 0, null, array('query' => $query, 'parameters' => $parameters));
				}
			}
		}
		
		return $return_object;
	}
	
	public function GuessType($value)
	{
		if(is_object($value))
		{
			throw new DatabaseException("Query parameters must be numeric, boolean, null, a string value, or something that can be auto-cast to a string. You provided an object.");
		}
		if(is_int($value))
		{
			return PDO::PARAM_INT;
		}
		elseif(is_bool($value))
		{
			return PDO::PARAM_BOOL;
		}
		elseif(is_null($value))
		{
			return PDO::PARAM_NULL;
		}
		else
		{
			return PDO::PARAM_STR;
		}
	}
}

if(!empty($cphp_config->database->driver))
{
	if(empty($cphp_config->database->database))
	{
		die("No database was configured. Refer to the CPHP manual for instructions.");
	}
	
	try
	{
		$database = new CachedPDO("mysql:host={$cphp_config->database->hostname};dbname={$cphp_config->database->database}", $cphp_config->database->username, $cphp_config->database->password);
		$database->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_TO_STRING);
		$cphp_mysql_connected = true;
	}
	catch (Exception $e)
	{
		die("Could not connect to the specified database. Refer to the CPHP manual for instructions.");
	}
}
