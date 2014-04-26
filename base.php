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
require("include.forms.php");

require("include.lib.php");

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

/* lighttpd (and perhaps some other HTTPds) won't pass on GET parameters
 * when using the server.error-handler-404 directive that is required to
 * use the CPHP router. This patch will try to detect such problems, and
 * manually extract the GET data from the request URI. I admit, it's a
 * bit of a hack, but there doesn't really seem to be a different way of
 * solving this issue. */

/* Detect whether the request URI and the $_GET array disagree on the
 * existence of GET parameters. */
if(strpos($_SERVER['REQUEST_URI'], "?") !== false && empty($_GET))
{
	/* Separate the protocol/host/path component from the query string. */
	list($uri, $query) = explode("?", $_SERVER['REQUEST_URI'], 2);
	
	/* Store the entire query string in the relevant $_SERVER variable -
	 * lighttpds strange behaviour breaks this variable as well. */
	$_SERVER['QUERY_STRING'] = $query;
	
	/* Finally, run the query string through PHPs own internal GET data
	 * parser, and have it store the result in the $_GET variable. This
	 * should yield an identical result to a well-functioning HTTPd. */
	parse_str($query, $_GET);
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

if(!empty($cphp_config->autoloader))
{
	function cphp_autoload_class($class_name) 
	{
		global $_APP;
		
		$class_name = str_replace("\\", "/", strtolower($class_name));
		
		if(file_exists("classes/{$class_name}.php"))
		{
			require_once("classes/{$class_name}.php");
		}
	}

	spl_autoload_register('cphp_autoload_class');
}

/* https://stackoverflow.com/a/1159235/1332715 */
set_error_handler(function($errno, $errstr, $errfile, $errline, $errcontext) {
	if(!(error_reporting() & $errno))
		return;
	switch($errno) {
	case E_WARNING      :
	case E_USER_WARNING :
	case E_STRICT       :
	case E_NOTICE       :
	case E_USER_NOTICE  :
		$type = 'warning';
		$fatal = false;
		break;
	default             :
		$type = 'fatal error';
		$fatal = true;
		break;
	}
	$trace = array_reverse(debug_backtrace());
	array_pop($trace);
	if(php_sapi_name() == 'cli') {
		echo 'Backtrace from ' . $type . ' \'' . $errstr . '\' at ' . $errfile . ' ' . $errline . ':' . "\n";
		foreach($trace as $item)
			echo '  ' . (isset($item['file']) ? $item['file'] : '<unknown file>') . ' ' . (isset($item['line']) ? $item['line'] : '<unknown line>') . ' calling ' . $item['function'] . '()' . "\n";
	} else {
		echo '<p class="error_backtrace">' . "\n";
		echo '  Backtrace from ' . $type . ' \'' . $errstr . '\' at ' . $errfile . ' ' . $errline . ':' . "\n";
		echo '  <ol>' . "\n";
		foreach($trace as $item)
			echo '    <li>' . (isset($item['file']) ? $item['file'] : '<unknown file>') . ' ' . (isset($item['line']) ? $item['line'] : '<unknown line>') . ' calling ' . $item['function'] . '()</li>' . "\n";
		echo '  </ol>' . "\n";
		echo '</p>' . "\n";
	}
	if(ini_get('log_errors')) {
		$items = array();
		foreach($trace as $item)
			$items[] = (isset($item['file']) ? $item['file'] : '<unknown file>') . ' ' . (isset($item['line']) ? $item['line'] : '<unknown line>') . ' calling ' . $item['function'] . '()';
		$message = 'Backtrace from ' . $type . ' \'' . $errstr . '\' at ' . $errfile . ' ' . $errline . ': ' . join(' | ', $items);
		error_log($message);
	}
	if($fatal)
		exit(1);
});


set_exception_handler(function($e){
	/* Intentionally not using the templater here; any inner exceptions
	 * cause serious debugging issues. Avoiding potential issues by just
	 * hardcoding the response here, with no code that could raise an
	* exception. */
	$exception_class = get_class($e);
	$exception_message = $e->getMessage();
	$exception_file = $e->getFile();
	$exception_line = $e->getLine();
	$exception_trace = $e->getTraceAsString();
	
	error_log("Uncaught {$exception_class} in {$exception_file}:{$exception_line} ({$exception_message}). Traceback: {$exception_trace}");
	
	switch(strtolower(ini_get('display_errors')))
	{
		case "1":
		case "on":
		case "true":
			$inner_exceptions = array();
			$inner_e = $e;
			
			while(true)
			{
				$inner_e = $inner_e->getPrevious();
				
				if($inner_e === null)
				{
					break;
				}
				else
				{
					$inner_exceptions[] = array($inner_e->getMessage(), $inner_e->getTraceAsString());
				}
			}
			
			if(empty($inner_exceptions))
			{
				$inner_traces = "";
			}
			else
			{
				$inner_traces = "<h2>One or more previous exceptions were also recorded.</h2>";
			}
			
			foreach($inner_exceptions as $inner_e)
			{
				$inner_traces .= "
					<p>
						<span class='message'>{$inner_e[0]}</span>
					</p>
					<pre>{$inner_e[1]}</pre>
				";
			}
			
			$error_body = "
				<p>
					An uncaught <span class='detail'>{$exception_class}</span> was thrown, in <span class='detail'>{$exception_file}</span> on line <span class='detail'>{$exception_line}</span>.
				</p>
				<p>
					<span class='message'>{$exception_message}</span>
				</p>
				<pre>{$exception_trace}</pre>
				{$inner_traces}
				<p><strong>Important:</strong> These errors should never be displayed on a production server! Make sure that <em>display_errors</em> is turned off in your PHP configuration, if you want to hide these tracebacks.</p>
			";
			break;
		default:
			$error_body = "
				<p>
					Something went wrong while creating this page, but we're not yet quite sure what it was.
				</p>
				<p>
					If the issue persists, please contact the administrator for this application or website.
				</p>
			";
			break;
	}
	
	http_status_code(500);
	
	echo("
		<!doctype html>
		<html>
			<head>
				<title>An unexpected error occurred.</title>
				<style>
					body
					{
						margin: 24px auto;
						padding: 24px 16px;
						font-family: sans-serif;
						font-size: 18px;
						width: 960px;
						color: #676767;
					}
					
					h1
					{
						border-bottom: 2px solid black;
						color: #444444;
						font-size: 26px;
						padding-bottom: 6px;
					}
					
					h2
					{
						color: #575757;
						border-bottom: 2px solid #444444;
						padding-top: 22px;
						padding-bottom: 6px;
						font-size: 21px;
					}
					
					pre
					{
						overflow: auto;
						font-size: 13px;
						color: black;
						padding: 10px;
						border: 1px solid gray;
						border-radius: 6px;
						background-color: #F8F8F8;
					}
					
					.message
					{
						font-weight: bold;
						color: #5B0000;
					}
					
					.detail
					{
						color: black;
					}
				</style>
			</head>
			<body>
				<h1>An unexpected error occurred.</h1>
				{$error_body}
			</body>
		</html>
	");
	
	die();
});
