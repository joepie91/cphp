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

$template_cache = array();
$template_global_vars = array();

class Templater
{
	public $basedir = "templates/";
	public $extension = ".tpl";
	private $tpl = NULL;
	private $tpl_rendered = NULL;
	public $templatename = "";
	
	public function Load($template)
	{
		global $template_cache;
		
		if(isset($template_cache[$template]))
		{
			$tpl_contents = $template_cache[$template];
		}
		else
		{
			$tpl_contents = file_get_contents($this->basedir . $template . $this->extension);
			$template_cache[$template] = $tpl_contents;
		}
		
		if($tpl_contents !== false)
		{
			$this->tpl = $tpl_contents;
			$this->tpl_rendered = $tpl_contents;
		}
		else
		{
			Throw new Exception("Failed to load template {$template}.");
		}
	}
	
	public function Reset()
	{
		if(!is_null($this->tpl))
		{
			$this->tpl_rendered = $this->tpl;
		}
		else
		{
			Throw new Exception("No template loaded.");
		}
	}
	
	public function Localize($strings)
	{
		if(!is_null($this->tpl))
		{
			preg_match_all("/<%!([a-zA-Z0-9_-]+)>/", $this->tpl_rendered, $strlist);
			foreach($strlist[1] as $str)
			{
				if(isset($strings[$str]))
				{
					$this->tpl_rendered = str_replace("<%!{$str}>", $strings[$str], $this->tpl_rendered);
				}
			}
		}
		else
		{
			Throw new Exception("No template loaded.");
		}
	}
	
	public function Compile($strings)
	{
		global $template_global_vars;
		
		if(!is_null($this->tpl))
		{
			$strings = array_merge($strings, $template_global_vars);
			
			$this->tpl_rendered = $this->ParseForEach($this->tpl_rendered, $strings);
			$this->tpl_rendered = $this->ParseIf($this->tpl_rendered, $strings);
			
			preg_match_all("/<%\?([a-zA-Z0-9_-]+)>/", $this->tpl_rendered, $strlist);
			foreach($strlist[1] as $str)
			{
				if(isset($strings[$str]))
				{
					$this->tpl_rendered = str_replace("<%?{$str}>", $strings[$str], $this->tpl_rendered);
				}
			}
		}
		else
		{
			Throw new Exception("No template loaded.");
		}
	}
	
	public function ParseForEach($source, $data)
	{
		$templater = $this;
		
		return preg_replace_callback("/<%foreach ([a-z0-9_-]+) in ([a-z0-9_-]+)>(.*?)<%\/foreach>/si", function($matches) use($data, $templater) {
			$variable_name = $matches[1];
			$array_name = $matches[2];
			$template = $matches[3];
			$returnvalue = "";
			
			if(isset($data[$array_name]))
			{
				foreach($data[$array_name] as $item)
				{
					$rendered = $template;
					
					$rendered = $templater->ParseIf($rendered, $data, $item, $variable_name);
					
					foreach($item as $key => $value)
					{
						$rendered = str_replace("<%?{$variable_name}[{$key}]>", $value, $rendered);
					}
					
					$returnvalue .= $rendered;
				}
				
				return $returnvalue;
			}
			
			return false;
		}, $source);
	}
	
	public function ParseIf($source, $data, $context = null, $identifier = "")
	{
		return preg_replace_callback("/<%if ([][a-z0-9_-]+) (=|==|>|<|>=|<=|!=) ([^>]+)>(.*?)<%\/if>/si", function($matches) use($data, $context, $identifier) {
			$variable_name = $matches[1];
			$operator = $matches[2];
			$value = $matches[3];
			$template = $matches[4];
			
			if(!empty($identifier))
			{
				if(preg_match("/{$identifier}\[([a-z0-9_-]+)\]/i", $variable_name, $submatches))
				{
					// Local variable.
					$name = $submatches[1];
					
					if(isset($context[$name]))
					{
						$variable = $context[$name];
					}
					else
					{
						return false;
					}
				}
				elseif(preg_match("/[a-z0-9_-]+\[[a-z0-9_-]+\]/i", $variable_name))
				{
					// Not the right scope.
					return false;
				}
				else
				{
					// Global variable.
					if(isset($data[$variable_name]))
					{
						$variable = $data[$variable_name];
					}
					else
					{
						return false;
					}
				}
			}
			else
			{
				if(isset($data[$variable_name]))
				{
					$variable = $data[$variable_name];
				}
				else
				{
					return false;
				}
			}
			
				
			if($variable === "true") { $variable = true; }
			if($variable === "false") { $variable = false; }
			if(is_numeric($variable)) { $variable = (int)$variable; }
			if($value === "true") { $value = true; }
			if($value === "false") { $value = false; }
			if(is_numeric($value)) { $value = (int)$value; }
			
			switch($operator)
			{
				case "=":
				case "==":
					$display = ($variable == $value);
					break;
				case ">":
					$display = ($variable > $value);
					break;
				case "<":
					$display = ($variable < $value);
					break;
				case ">=":
					$display = ($variable >= $value);
					break;
				case "<=":
					$display = ($variable <= $value);
					break;
				case "!=":
					$display = ($variable != $value);
					break;
				default:
					return false;
					break;
			}
			
			if($display === true)
			{
				return $template;
			}
			else
			{
				return "";
			}
			
			return false;
		}, $source);
	}
	
	public function Render()
	{
		if(!is_null($this->tpl))
		{
			return $this->tpl_rendered;
		}
		else
		{
			Throw new Exception("No template loaded.");
		}
	}
	
	public function Output()
	{
		if(!is_null($this->tpl))
		{
			echo($this->tpl_rendered);
		}
		else
		{
			Throw new Exception("No template loaded.");
		}
	}
	
	public static function InlineRender($templatename, $localize = array(), $compile = array())
	{
		$template = new Templater();
		$template->Load($templatename);
		$template->Localize($localize);
		$template->Compile($compile);
		return $template->Render();
	}
	
	public static function AdvancedParse($templatename, $localize = array(), $compile = array())
	{		
		$template = new Templater();
		$template->templatename = $template->basedir . $templatename . $template->extension;;
		$template->Load($templatename);
		$template->Localize($localize);
		$template->Parse($compile);
		return $template->Render();
	}
	
	public function Parse($strings)
	{
		$tree = $this->BuildSyntaxTree();
	}
	
	public function BuildSyntaxTree()
	{
		$content = $this->tpl_rendered;
		$length = strlen($content);
		$offset = 0;
		$depth = 0;
		$current_tag = "";
		$current_element = null;
		$root = array();
		$tag_start = 0;
		$tag_end = 0;
		
		define("CPHP_TEMPLATER_SWITCH_NONE",		1);
		define("CPHP_TEMPLATER_SWITCH_TAG_OPEN",	2);
		define("CPHP_TEMPLATER_SWITCH_TAG_SYNTAX",	3);
		define("CPHP_TEMPLATER_SWITCH_TAG_IDENTIFIER",	4);
		define("CPHP_TEMPLATER_SWITCH_TAG_STATEMENT",	5);
		define("CPHP_TEMPLATER_TYPE_TAG_NONE",		10);
		define("CPHP_TEMPLATER_TYPE_TAG_OPEN",		11);
		define("CPHP_TEMPLATER_TYPE_TAG_CLOSE",		12);
		
		$switch = CPHP_TEMPLATER_SWITCH_NONE;
		$type = CPHP_TEMPLATER_TYPE_TAG_NONE;
		
		while($offset < $length)
		{
			$char = $content[$offset];
			//echo("<br><br>");
			//pretty_dump("**");
			if($char == "{" && $switch == CPHP_TEMPLATER_SWITCH_NONE)
			{
				$switch = CPHP_TEMPLATER_SWITCH_TAG_OPEN;
				$tag_start = $offset;
			}
			elseif($char == "%" && $switch == CPHP_TEMPLATER_SWITCH_TAG_OPEN)
			{
				$switch = CPHP_TEMPLATER_SWITCH_TAG_IDENTIFIER;
				$identifier = "";
			}
			elseif($char != "%" && $switch == CPHP_TEMPLATER_SWITCH_TAG_OPEN)
			{
				// Not a templater tag, abort.
				$switch = CPHP_TEMPLATER_SWITCH_NONE;
				$type = CPHP_TEMPLATER_TYPE_TAG_NONE;
			}
			elseif($switch == CPHP_TEMPLATER_SWITCH_TAG_IDENTIFIER && $type == CPHP_TEMPLATER_TYPE_TAG_NONE)
			{
				if($char == "/")
				{
					//pretty_dump("close $char {$identifier}");
					$type = CPHP_TEMPLATER_TYPE_TAG_CLOSE;
				}
				else
				{
					//pretty_dump("open {$identifier}");
					$type = CPHP_TEMPLATER_TYPE_TAG_OPEN;
					continue;
				}
			}
			else
			{
				//pretty_dump(">> $char");
				//pretty_dump(($char != "}" && $switch == CPHP_TEMPLATER_SWITCH_TAG_IDENTIFIER && $type == CPHP_TEMPLATER_TYPE_TAG_CLOSE));
				//pretty_dump(($char == "}" && $switch == CPHP_TEMPLATER_SWITCH_TAG_IDENTIFIER && $type == CPHP_TEMPLATER_TYPE_TAG_CLOSE));
				if(($char != " " && $switch == CPHP_TEMPLATER_SWITCH_TAG_IDENTIFIER && $type == CPHP_TEMPLATER_TYPE_TAG_OPEN) ||
				($char != "}" && $switch == CPHP_TEMPLATER_SWITCH_TAG_IDENTIFIER && $type == CPHP_TEMPLATER_TYPE_TAG_CLOSE))
				{
					//pretty_dump("identifier");
					$identifier .= $char;
				}
				elseif($char == " " && $switch == CPHP_TEMPLATER_SWITCH_TAG_IDENTIFIER && $type == CPHP_TEMPLATER_TYPE_TAG_OPEN)
				{
					//pretty_dump("switch statement");
					$switch = CPHP_TEMPLATER_SWITCH_TAG_STATEMENT;
					$statement = "";
				} 
				elseif($char != "}" && $switch == CPHP_TEMPLATER_SWITCH_TAG_STATEMENT)
				{
					//pretty_dump("statement");
					$statement .= $char;
				}
				elseif(($char == "}" && $switch == CPHP_TEMPLATER_SWITCH_TAG_STATEMENT && $type == CPHP_TEMPLATER_TYPE_TAG_OPEN) ||
				($char == "}" && $switch == CPHP_TEMPLATER_SWITCH_TAG_IDENTIFIER && $type == CPHP_TEMPLATER_TYPE_TAG_CLOSE))
				{
					//pretty_dump("Identifier: {$identifier}");
					//pretty_dump("Statement: {$statement}");
					
					$tag_end = $offset;
					
					if($type == CPHP_TEMPLATER_TYPE_TAG_OPEN)
					{
						// This was an opening tag.
						echo("Opening tag found, start position [{$tag_start}], end position [{$tag_end}], identifier [{$identifier}], statement [ {$statement} ]<br>");
					}
					elseif($type == CPHP_TEMPLATER_TYPE_TAG_CLOSE)
					{
						// This was a closing tag.
						echo("Closing tag found, start position [{$tag_start}], end position [{$tag_end}], identifier [{$identifier}]<br>");
					}
					else
					{
						throw new TemplateParsingException("The type of tag could not be determined.", $this->templatename, $tag_start, $tag_end);
					}
					
					$switch = CPHP_TEMPLATER_SWITCH_NONE;
					$type = CPHP_TEMPLATER_TYPE_TAG_NONE;
					$identifier = "";
					$statement = "";
				}
			}
			
			$offset += 1;
		}
	}
}

class TemplateSyntaxElement
{
	public $parent = null;
	public $children = array();
}

class TemplateIfElement extends TemplateSyntaxElement
{
	public $left = "";
	public $right = "";
	public $operator = "";
	public $if_block = "";
	public $else_block = "";
}
