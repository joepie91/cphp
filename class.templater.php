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

define("MODE_NONE", 0);
define("MODE_TAG", 1);

class NewTemplater
{
	public $root = null;
	
	public $constructs = array(
		'standalone' => array(
			'?'		=> "TemplateVariable",
			'!'		=> "TemplateLocaleString",
			'input'		=> "TemplateInput"
		),
		'block' => array(
			'foreach'	=> array(
				'processor'	=> "TemplateForEach",
				'subconstructs'	=> array()
			),
			'if'		=> array(
				'processor'	=> "TemplateIf",
				'subconstructs'	=> array(
					"else"		=> "TemplateElse",
					"elseif"	=> "TemplateElseIf"
				)
			)
		)
	);
	
	public static function Render($template_name, $localized_strings, $data)
	{
		global $template_global_vars;
		$data = array_merge($data, $template_global_vars);
		
		$templater = new NewTemplater();
		$templater->Load($template_name);
		$templater->Localize($localized_strings);
		$templater->Parse();
		
		$result = $templater->Evaluate($localized_strings, $data);
		$result = CSRF::InsertTokens($result);
		
		return $result;
	}
	
	public function Load($template_name)
	{
		global $template_cache;
		
		if(isset($template_cache[$template_name]))
		{
			$this->template = $template_cache[$template_name];
		}
		else
		{
			$this->template = file_get_contents("templates/{$template_name}.tpl");
			$template_cache[$template_name] = $template;
		}
		
		if($template === false)
		{
			throw new TemplateException("Failed to load template {$template_name}.");
		}
	}
	
	public function Parse()
	{
		$current_level = 0;
		$current_element = null;
		$elements = array(0 => array());
		$subconstructs = array();
		$blocks = array();
		$block_tokens = array();
		$mode = MODE_NONE;
		$buffer = '';
		
		/* Main parsing loop */
		$length = strlen($this->template);
		
		for($pos = 0; $pos < $length; $pos++)
		{
			$char = $this->template[$pos];
			
			if($mode == MODE_TAG)
			{
				if($char == '}')
				{
					/* The tag is closed. Parse the buffer contents and place
					 * the appropriate syntax element on the stack, then revert
					 * to raw data mode. */
					
					$tokens = preg_split('/\s+/', $buffer);
					$construct_name = array_shift($tokens);
					$found = false;
					
					if($construct_name[0] == '/')
					{
						/* This is a closing tag for a block. */
						$construct_name = substr($construct_name, 1);
						$wanted_construct = $blocks[$current_level - 1];
						
						if($construct_name != $wanted_construct)
						{
							/* The closing tag we found isn't for the right construct. Error out. */
							$position = $pos - strlen($buffer) - 3;
							throw new TemplateSyntaxException("Opening tag ({$wanted_construct}) does not match closing tag ({$construct_name}) at position {$position}.", $this->template_name, $position);
						}
						else
						{
							/* This is a valid closing tag. Go up a level and add the appropriate element to the stack. */
							$current_level -= 1;
							
							if(!empty($subconstructs[$current_level + 1]))
							{
								/* We have one or more subconstructs. First of all, let's add the code we currently have into the
								 * list of subconstructs as the final subconstruct. */
								
								$subconstructs[$current_level + 1][] = array(
									'name'		=> $last_subconstruct[$current_level + 1],
									'tokens'	=> $block_tokens[$current_level + 1],
									'elements'	=> $elements[$current_level + 1]
								);
								
								$sub_elements = array();
								
								foreach($subconstructs[$current_level + 1] as $subconstruct)
								{
									/* For each subconstruct, create the appropriate element and throw it on the stack. */
									if($subconstruct['name'] == $construct_name)
									{
										/* This is the first subconstruct - basically the one that relates to the original block
										 * construct. We will create a special type of subconstruct - a ParentSubconstruct - to
										 * accomodate this. */
										$sub_elements[] = new TemplateParentSubconstruct(array(), $subconstruct['elements']);
									}
									else
									{
										/* This is an actual subconstruct. We will create the appropriate element. */
										$sub_processor = $this->constructs['block'][$construct_name]['subconstructs'][$subconstruct['name']];
										$sub_elements[] = new $sub_processor($subconstruct['tokens'], $subconstruct['elements']);
									}
								}
								
								/* Create the actual element and add it to the stack with its subconstructs. */
								$processor = $this->constructs['block'][$construct_name]['processor'];
								$elements[$current_level][] = new $processor($block_tokens[$current_level], $sub_elements, true);
							}
							else
							{
								/* There were no subconstructs, so we can add a normal element to the stack. */
								$processor = $this->constructs['block'][$construct_name]['processor'];
								$elements[$current_level][] = new $processor($block_tokens[$current_level], $elements[$current_level + 1]);
							}
							
							$elements[$current_level + 1] = array();
							$blocks[$current_level] = null;
							$subconstructs[$current_level + 1] = array();
							$last_subconstruct[$current_level] = null;
						}
					}
					else
					{
						/* This is either a stand-alone or block opening tag. */
						foreach($this->constructs['standalone'] as $construct => $processor)
						{
							/* Guideline: identifiers of 1 or 2 characters are immediately adjacent to the first token.
							 * Longer identifiers have a space between the identifier and the first token. This way we
							 * can accomodate short constructs like {%!key} without making longer constructs confusing
							 * to read. */
							if(strlen($construct) <= 2)
							{
								/* There is no space inbetween the construct identifier and the first token, so we'll have
								 * to play around with substrings a bit. */
								$matches = (substr($construct_name, 0, strlen($construct)) == $construct);
								
								if($matches)
								{
									/* Prepend an extra token containing the actual first token. */
									$tokens = array_merge(array(substr($construct_name, strlen($construct))), $tokens);
								}
							}
							else
							{
								/* There's a space inbetween the construct identifier and the first token, so we can safely
								 * just compare the construct identifier. */
								$matches = ($construct_name == $construct);
							}
							
							if($matches)
							{
								/* Add a new element to the stack for this construct. */
								$found = true;
								
								$elements[$current_level][] = new $processor($tokens);
								
								break;
							}
						}
						
						if($found == false)
						{
							/* Search for block constructs. */
							foreach($this->constructs['block'] as $construct => $data)
							{
								if($construct_name == $construct)
								{
									$processor = $data['processor'];
									
									$blocks[$current_level] = $construct;
									$last_subconstruct[$current_level + 1] = $construct;
									$block_tokens[$current_level] = $tokens;
									
									$current_level += 1;
									
									$found = true;
									break;
								}
							}
						}
						
						if($found == false && !empty($blocks[$current_level - 1]))
						{
							/* Search the subconstructs for the current block to see if we found any of those. */
							foreach($this->constructs['block'][$blocks[$current_level - 1]]['subconstructs'] as $construct => $processor)
							{
								if($construct_name == $construct)
								{
									/* We found the relevant subconstruct - let's add the last subconstruct to the list, and
									 * initialize a new one. */
									if(empty($subconstructs[$current_level]))
									{
										$relevant_tokens = array();
									}
									else
									{
										$relevant_tokens = $block_tokens[$current_level];
									}
									
									$subconstructs[$current_level][] = array(
										'name' 		=> $last_subconstruct[$current_level],
										'tokens'	=> $relevant_tokens,
										'elements'	=> $elements[$current_level]
									);
									
									$elements[$current_level] = array();
									$last_subconstruct[$current_level] = $construct;
									$block_tokens[$current_level] = $tokens;
									
									$found = true;
									break;
								}
							}
						}
						
						if($found == false)
						{
							/* Apparently a false alarm - there were no matching constructs in the grammar.
							 * Add the data as a raw data element and continue reading. */
							$elements[$current_level][] = new TemplateRawData("{%{$buffer}}");
						}
					}
					
					$mode = MODE_NONE;
					$buffer = '';
				}
				else
				{
					$buffer .= $char;
				}
			}
			elseif($char == '{' && $this->template[$pos+1] == '%')
			{
				/* A tag is opened here. Put the previous buffer contents on the
				 * stack as raw data and switch to tag mode, as well as advancing
				 * the pointer by one to accomodate the lookahead. */
				 
				$elements[$current_level][] = new TemplateRawData($buffer);
				$buffer = '';
				
				$pos += 1;
				$mode = MODE_TAG;
			}
			else
			{
				$buffer .= $char;
			}
		}
		
		/* Add a raw data element to the stack with all remaining data in the buffer. */
		$elements[0][] = new TemplateRawData($buffer);
		
		$this->root = new TemplateRoot('', $elements[0]);
	}
	
	private function Localize($strings)
	{
		/* We have to do localization separately for now, because otherwise we can't use variables in
		 * localized strings. TODO: Fix that. */
		
		foreach($strings as $key => $string)
		{
			$this->template = str_replace("{%!{$key}}", $string, $this->template);
		}
	}
	
	public function Evaluate($localized_strings, $data)
	{
		return $this->root->Evaluate($localized_strings, $data);
	}
}

class TemplateElement
{
	public function PrintDebug($level, $last)
	{
		return $this->PrintDebugSelf($level, $last);
	}
	
	public function PrintDebugSelf($level, $last = false)
	{
		if(!empty($this->tokens))
		{
			$description = implode(" ", $this->tokens);
		}
		elseif(!empty($this->data))
		{
			$description = cut_text(preg_replace("/\s+/", " ", $this->data), 300);
		}
		else
		{
			$description = "";
		}
		
		$whitespace = '';
		
		for($i = 0; $i < $level; $i++)
		{
			if($this->parser->last[$i])
			{
				$whitespace .= '    ';
			}
			else
			{
				$whitespace .= '|   ';
			}
		}
		
		$character = ($last === true) ? '`' : '|';
		
		$level_text = str_repeat(" ", 3 - strlen((string) $level)) . $level;
		
		return "\n[{$level_text}]{$whitespace}{$character}-[{$this->type}] {$description}";
	}
	
	public function FetchVariable($variable_name, $data)
	{
		$operation = null;
		
		if(strpos($variable_name, "|") !== false)
		{
			list($operation, $variable_name) = explode("|", $variable_name, 2);
			
			if($operation != "isset" && $operation != "isempty")
			{
				throw new TemplateEvaluationException("Unrecognized operation '{$operation}' used for variable '{$variable_name}'.");
			}
		}
		
		if(preg_match("/([^\[]+)\[([^\]]+)\]/", $variable_name, $matches))
		{
			/* Collection item */
			$collection_name = $matches[1];
			$key_name = $matches[2];
			
			$target = $this->parent;
			
			/* Traverse up the tree to find the provider of this particular collection */
			while(true)
			{
				if($target->context == $collection_name)
				{
					if(is_array($target->context_item))
					{
						if(is_null($operation))
						{
							return $target->context_item[$key_name];
						}
						elseif($operation == "isset")
						{
							return true;
						}
						elseif($operation == "isempty")
						{
							return empty($target->context_item[$key_name]);
						}
					}
					else
					{
						throw new TemplateEvaluationException("The specified collection '{$collection_name}' is not an array.");
					}
				}
				
				if(!empty($target->parent))
				{
					$target = $target->parent;
				}
				else
				{
					/* Reached the top of the tree. */
					if($operation == "isset")
					{
						return false;
					}
					else
					{
						throw new TemplateEvaluationException("Could not find the referenced collection '{$collection_name}'.");
					}
				}
			}
		}
		else
		{
			/* Stand-alone variable. */
			$target = $this->parent;
			
			if(isset($data[$variable_name]))
			{
				if(is_null($operation))
				{
					return $data[$variable_name];
				}
				elseif($operation == "isset")
				{
					return true;
				}
				elseif($operation == "isempty")
				{
					return empty($data[$variable_name]);
				}
			}
			else
			{
				/* Traverse up the tree to find a provider for the specified variable. */
				while(true)
				{
					if($target->context == $variable_name)
					{
						if(!is_array($target->context_item))
						{
							if(is_null($operation))
							{
								return $target->context_item;
							}
							elseif($operation == "isset")
							{
								return true;
							}
							elseif($operation == "isempty")
							{
								return empty($target->context_item);
							}
						}
						else
						{
							throw new TemplateEvaluationException("The referenced variable '{$variable_name}' is an array.");
						}
					}
					
					if(!empty($target->parent))
					{
						$target = $target->parent;
					}
					else
					{
						/* Reached the top of the tree. */
						if($operation == "isset")
						{
							return false;
						}
						else
						{
							throw new TemplateEvaluationException("Could not find the referenced variable '{$variable_name}'.");
						}
					}
				}
			}
		}
	}
}

class TemplateDataElement extends TemplateElement
{
	public $data = '';
	
	public function __construct($data)
	{
		$this->data = $data;
	}
	
	public function Evaluate($parent, $localized_strings, $data)
	{
		$this->parent = $parent;
		return $this->data;
	}
}

class TemplateBlockElement extends TemplateElement
{
	public $elements = array();
	public $tokens = array();
	public $has_subconstructs = false;
	
	public function __construct($tokens, $elements, $has_subconstructs = false)
	{
		$this->elements = $elements;
		$this->tokens = $tokens;
		$this->has_subconstructs = $has_subconstructs;
	}
	
	public function PrintDebug($level, $last = false)
	{
		return $this->PrintDebugSelf($level, $last) . $this->PrintDebugChildren($level + 1);
	}
	
	public function PrintDebugChildren($level)
	{
		$returnvalue = "";
		$total_children = count($this->elements);
		
		for($i = 0; $i < $total_children; $i++)
		{
			$last = ($i == $total_children - 1);
			$returnvalue .= $this->elements[$i]->PrintDebug($level, $last);
		}
		
		return $returnvalue;
	}
	
	public function Evaluate($parent, $localized_strings, $data)
	{
		$this->parent = $parent;
		return $this->EvaluateChildren($localized_strings, $data);
	}
	
	public function EvaluateChildren($localized_strings, $data)
	{
		$return_data = "";
		
		foreach($this->elements as $element)
		{
			$return_data .= $element->Evaluate($this, $localized_strings, $data);
		}
		
		return $return_data;
	}
	
	public function EvaluateExpression($expression, $data)
	{
		$position = 0;
		$operator = null;
		$left = array();
		$right = array();
		
		foreach($expression as $token)
		{
			if(in_array($token, array('<', '>', '=', '<=', '>=', '!=', '==')))
			{
				$operator = $token;
				$position += 1;
			}
			elseif($position == 0)
			{
				$left[] = $token;
			}
			elseif($position == 1)
			{
				$right[] = $token;
			}
			else
			{
				$statement = implode(" ", $expression);
				throw new TemplateEvaluationException("Multiple operators found in expression '{$statement}'.");
			}
		}
		
		if(empty($left) || empty($right) || empty($operator))
		{
			$statement = implode(" ", $expression);
			throw new TemplateEvaluationException("Invalid expression found: {$statement}");
		}
		
		/* To preserve backwards compatibility, we need to treat the right side of the expression as always being a string. */
		$right = implode(" ", $right);
		
		/* If the right side is surrounded by quotes, we'll want to get rid of those. */
		if($right[0] == '"' && $right[strlen($right)-1] == '"')
		{
			$right = substr($right, 1, strlen($right) - 2);
		}
		
		$left = implode(" ", $left);
		
		/* The left side of the expression can be either a string (as indicated by quotes) or a variable. */
		if($left[0] == '"' && $left[strlen($left)-1] == '"')
		{
			/* The left side is a string. */
			$left = substr($left, 1, strlen($left) - 2);
		}
		else
		{
			/* The left side is a variable. */
			$left = $this->FetchVariable($left, $data);
		}
		
		if($left == "true")
		{
			$left = true;
		}
		elseif($left == "false")
		{
			$left = false;
		}
		elseif($left == "null")
		{
			$left = null;
		}
		
		if($right == "true")
		{
			$right = true;
		}
		elseif($right == "false")
		{
			$right = false;
		}
		elseif($right == "null")
		{
			$right = null;
		}
		
		switch($operator)
		{
			case '=':
			case '==':
				$matches = ($left == $right);
				break;
			case '<':
				$matches = ($left < $right);
				break;
			case '<=':
				$matches = ($left <= $right);
				break;
			case '>':
				$matches = ($left > $right);
				break;
			case '>=':
				$matches = ($left >= $right);
				break;
			case '!=':
				$matches = ($left != $right);
				break;
			default:
				throw new TemplateEvaluationException("An unknown operator was used.");
		}
		
		return $matches;
	}
}

class TemplateSubconstructElement extends TemplateBlockElement { }

class TemplateStandaloneElement extends TemplateElement
{
	public $tokens = array();
	
	public function __construct($tokens)
	{
		$this->tokens = $tokens;
	}
}

class TemplateRawData extends TemplateDataElement
{
	public $type = "raw data";
}

class TemplateIf extends TemplateBlockElement
{
	public $type = "if construct";
	public $resolved = false;
	
	public function Evaluate($parent, $localized_strings, $data)
	{
		$this->parent = $parent;
		
		if($this->has_subconstructs)
		{
			$return_data = "";
			foreach($this->elements as $element)
			{
				$return_data .= $element->Evaluate($this, $localized_strings, $data);
			}
			
			return $return_data;
		}
		else
		{
			if($this->EvaluateSelf($localized_strings, $data))
			{
				return $this->EvaluateChildren($localized_strings, $data);
			}
		}
	}
	
	public function EvaluateSelf($localized_strings, $data)
	{
		$result = $this->EvaluateExpression($this->tokens, $data);
		$this->resolved = $result;
		return $result;
	}
}

class TemplateElseIf extends TemplateSubconstructElement
{
	public $type = "elseif subconstruct";
	
	public function Evaluate($parent, $localized_strings, $data)
	{
		$this->parent = $parent;
		
		if($parent->resolved == false && $this->EvaluateExpression($this->tokens, $data))
		{
			$this->parent->resolved = true;
			return $this->EvaluateChildren($localized_strings, $data);
		}
	}
}

class TemplateElse extends TemplateSubconstructElement
{
	public $type = "else subconstruct";
	
	public function Evaluate($parent, $localized_strings, $data)
	{
		$this->parent = $parent;
		
		if($parent->resolved == false)
		{
			return $this->EvaluateChildren($localized_strings, $data);
		}
	}
}


class TemplateForEach extends TemplateBlockElement
{
	public $type = "foreach construct";
	public $context = null;
	
	public function Evaluate($parent, $localized_strings, $data)
	{
		$this->parent = $parent;
		
		$position = 0;
		
		$item = array();
		$collection = array();
		
		foreach($this->tokens as $token)
		{
			if($token == 'in')
			{
				$position += 1;
			}
			elseif($position == 0)
			{
				$item[] = $token;
			}
			elseif($position == 1)
			{
				$collection[] = $token;
			}
			else
			{
				$statement = implode(" ", $this->tokens);
				throw new TemplateEvaluationException("More than one 'in' keyword was found in a foreach statement (full statement was {$statement}).");
			}
		}
		
		$item = implode(" ", $item);
		$collection = implode(" ", $collection);
		
		if(empty($item) || empty($collection))
		{
			$statement = implode(" ", $this->tokens);
			throw new TemplateEvaluationException("An invalid foreach statement was found (full statement was {$statement}).");
		}
		
		$items = $this->FetchVariable($collection, $data);
		$this->context = $item;
		
		$return_data = '';
		
		foreach($items as $subitem)
		{
			$this->context_item = $subitem;
			
			foreach($this->elements as $element)
			{
				$return_data .= $element->Evaluate($this, $localized_strings, $data);
			}
		}
		
		return $return_data;
	}
}

class TemplateParentSubconstruct extends TemplateSubconstructElement
{
	public $type = "parent subconstruct";
	
	public function Evaluate($parent, $localized_strings, $data)
	{
		$this->parent = $parent;
		
		if($parent->EvaluateSelf($localized_strings, $data))
		{
			return $this->EvaluateChildren($localized_strings, $data);
		}
	}
}

class TemplateVariable extends TemplateStandaloneElement
{
	public $type = "variable";
	
	public function Evaluate($parent, $localized_strings, $data)
	{
		$this->parent = $parent;
		
		return $this->FetchVariable($this->tokens[0], $data);
	}
}

class TemplateLocaleString extends TemplateStandaloneElement
{
	public $type = "locale string";
	
	public function Evaluate($parent, $localized_strings, $data)
	{
		$this->parent = $parent;
		
		$key = $this->tokens[0];
		
		if(!isset($localized_strings[$key]))
		{
			/*throw new TemplateEvaluationException("The specified localized string '$key' was not found.");*/
			/* We'll simply ignore invalid localized strings for now, since they may be part of an unrelated blob of text. */
			return "{%!{$key}}";
		}
		
		return $localized_strings[$key];
	}
}

class TemplateInput extends TemplateStandaloneElement
{
	public $type = "input element";
	
	public function Evaluate($parent, $localized_strings, $data)
	{
		$this->parent = $parent;
		
		$type = "text";
		$value = "";
		$group = "general";
		$name = null;
		$additional_list = array();
		
		$argument_list = implode(" ", $this->tokens);
		
		if(preg_match_all('/([a-zA-Z0-9-]+)="([^"]+)"/', $argument_list, $matches, PREG_SET_ORDER))
		{
			foreach($matches as $argument)
			{
				switch($argument[1])
				{
					case "name":
						$name = $argument[2];
						break;
					case "value":
						$value = $argument[2];
						break;
					case "group":
						$group = $argument[2];
						break;
					case "name":
						$name = $argument[2];
						break;
					case "type":
						$type = $argument[2];
						break;
					case "value":
						$value = $argument[2];
						break;
					default:
						$additional_list[$argument[1]] = $argument[2];
				}
			}
		}
		
		if(empty($name))
		{
			throw new TemplateEvaluationException("No name was specified for an input element.");
		}
		
		if(isset($_POST[$name]))
		{
			$value = str_replace('"', '\"', htmlspecialchars($_POST[$name]));
		}
				
		$final_list = array();
		
		foreach($additional_list as $key => $value)
		{
			$final_list[] = "{$key}=\"{$value}\"";
		}
		
		$additional = implode(" ", $final_list);
		
		return "<input type=\"{$type}\" id=\"form_{$group}_{$name}\" name=\"{$name}\" value=\"{$value}\" {$additional}>";
	}
}

class TemplateRoot extends TemplateBlockElement
{
	public $type = "root";
	
	public function Evaluate($localized_strings, $data)
	{
		$this->parent = null;
		
		$return_data = "";
		
		foreach($this->elements as $element)
		{
			$return_data .= $element->Evaluate($this, $localized_strings, $data);
		}
		
		return $return_data;
	}
}

class Templater
{
	public static function AdvancedParse($templatename, $localize = array(), $compile = array())
	{
		return NewTemplater::Render($templatename, $localize, $compile);
	}
}

