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

function random_string($length)
{
	$output = "";
	for ($i = 0; $i < $length; $i++) 
	{ 
		$output .= substr("ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789", mt_rand(0, 61), 1); 
	}
	return $output;
}

function extract_globals()
{
    $vars = array();
    
    foreach($GLOBALS as $key => $value){
        $vars[] = "$".$key;
    }
    
    return "global " . join(",", $vars) . ";";
}

function utf8entities($utf8) 
{
	// Credits to silverbeat@gmx.at (http://www.php.net/manual/en/function.htmlentities.php#96648)
	$encodeTags = true;
	$result = '';
	for ($i = 0; $i < strlen($utf8); $i++) 
	{
		$char = $utf8[$i];
		$ascii = ord($char);
		if ($ascii < 128) 
		{
			$result .= ($encodeTags) ? htmlentities($char) : $char;
		} 
		else if ($ascii < 192) 
		{
			// Do nothing.
		} 
		else if ($ascii < 224) 
		{
			$result .= htmlentities(substr($utf8, $i, 2), ENT_QUOTES, 'UTF-8');
			$i++;
		} 
		else if ($ascii < 240) 
		{
			$ascii1 = ord($utf8[$i+1]);
			$ascii2 = ord($utf8[$i+2]);
			$unicode = (15 & $ascii) * 4096 +
			(63 & $ascii1) * 64 +
			(63 & $ascii2);
			$result .= "&#$unicode;";
			$i += 2;
		} 
		else if ($ascii < 248) 
		{
			$ascii1 = ord($utf8[$i+1]);
			$ascii2 = ord($utf8[$i+2]);
			$ascii3 = ord($utf8[$i+3]);
			$unicode = (15 & $ascii) * 262144 +
			(63 & $ascii1) * 4096 +
			(63 & $ascii2) * 64 +
			(63 & $ascii3);
			$result .= "&#$unicode;";
			$i += 3;
		}
	}
	return $result;
}

function clean_array($arr)
{
	$result = array();
	foreach($arr as $key => $value)
	{
		if(!empty($value))
		{
			$result[$key] = $value;
		}
	}
	return $result;
}

function pretty_dump($input)
{
	ob_start();
	
	var_dump($input);
	
	$output = ob_get_contents();
	ob_end_clean();
	
	while(preg_match("/^[ ]*[ ]/m", $output) == 1)
	{
		$output = preg_replace("/^([ ]*)[ ]/m", "$1&nbsp;&nbsp;&nbsp;", $output);
	}
	
	$output = nl2br($output);
	
	echo($output);
}

function rgb_from_hex($hex)
{
	if(strlen($hex) == 6)
	{
		$r = substr($hex, 0, 2);
		$g = substr($hex, 2, 2);
		$b = substr($hex, 4, 2);
		
		$rgb['r'] = base_convert($r, 16, 10);
		$rgb['g'] = base_convert($g, 16, 10);
		$rgb['b'] = base_convert($b, 16, 10);
		
		return $rgb;
	}
	else
	{
		return false;
	}
}

function hex_from_rgb($rgb)
{
	if(!empty($rgb['r']) && !empty($rgb['g']) && !empty($rgb['b']))
	{
		return base_convert($rgb['r'], 10, 16) . base_convert($rgb['g'], 10, 16) . base_convert($rgb['b'], 10, 16);
	}
	else
	{
		return false;
	}
}

function strip_tags_attr($string, $allowtags = NULL, $allowattributes = NULL)
{ 
	/* Thanks to nauthiz693@gmail.com (http://www.php.net/manual/en/function.strip-tags.php#91498) */
	$string = strip_tags($string,$allowtags);
	
	if (!is_null($allowattributes)) 
	{ 
		if(!is_array($allowattributes)) 
		{
			$allowattributes = explode(",",$allowattributes); 
		}

		if(is_array($allowattributes)) 
		{
			$allowattributes = implode(")(?<!",$allowattributes); 
		}

		if (strlen($allowattributes) > 0) 
		{
			$allowattributes = "(?<!".$allowattributes.")"; 
		}

		$string = preg_replace_callback("/<[^>]*>/i",create_function('$matches', 'return preg_replace("/ [^ =]*'.$allowattributes.'=(\"[^\"]*\"|\'[^\']*\')/i", "", $matches[0]);'),$string); 
	} 
	
	return $string; 
} 

function filter_html($input)
{
	return strip_tags_attr($input, "<a><b><i><u><span><div><p><img><br><hr><font><ul><li><ol><dt><dd><h1><h2><h3><h4><h5><h6><h7><del><map><area><strong><em><big><small><sub><sup><ins><pre><blockquote><cite><q><center><marquee><table><tr><td><th>", "href,src,alt,class,style,align,valign,color,face,size,width,height,shape,coords,target,border,cellpadding,cellspacing,colspan,rowspan");
}

function filter_html_strict($input)
{
	return strip_tags_attr($input, "<strong><em><br><hr><img><a><span><p><div>", "src,href,style");
}

function parse_rss($url)
{
	$rss = new DOMDocument();
	$rss->load($url);
	
	$items = array();
	
	foreach($rss->getElementsByTagName('item') as $item)
	{
		$items[] = array(
			'title'		=> $item->getElementsByTagName('title')->item(0)->nodeValue,
			'description'	=> $item->getElementsByTagName('description')->item(0)->nodeValue,
			'url'		=> $item->getElementsByTagName('link')->item(0)->nodeValue,
			'date'		=> strtotime($item->getElementsByTagName('pubDate')->item(0)->nodeValue)
		);
	}
	
	return $items;
}
