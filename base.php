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

require("include.constants.php");

require("include.config.php");
require("include.debug.php");

require("include.dependencies.php");
require("include.exceptions.php");
require("include.datetime.php");
require("include.misc.php");

require("include.memcache.php");
require("include.mysql.php");
require("include.session.php");
require("include.csrf.php");

require("class.templater.php");
require("class.localizer.php");

require("include.locale.php");

if(empty($not_html))
{
	header("Content-Type:text/html; charset=UTF-8");
}

require("class.base.php");
require("class.databaserecord.php");

foreach($cphp_config->components as $component)
{
	require("components/component.{$component}.php");
}

if(get_magic_quotes_gpc())
{
	/* By default, get rid of all quoted variables. Magic quotes are evil. */
	foreach($_POST as &$var)
	{
		$var = stripslashes($var);
	}
	
	foreach($_GET as &$var)
	{
		$var = stripslashes($var);
	}
}
