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

if(!empty($cphp_config->database->driver))
{
	if(empty($cphp_config->database->database))
	{
		die("No database was configured. Refer to the CPHP manual for instructions.");
	}
	
	if(mysql_connect($cphp_config->database->hostname, $cphp_config->database->username, $cphp_config->database->password))
	{
		if(mysql_select_db($cphp_config->database->database))
		{
			$cphp_mysql_connected = true;
		}
		else
		{
			die("Could not connect to the specified database. Refer to the CPHP manual for instructions.");
		}
	}
	else
	{
		die("Could not connect to the specified database server. Refer to the CPHP manual for instructions.");
	}
}
