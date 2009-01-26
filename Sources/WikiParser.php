<?php

// Makes Table of Content
function do_toctable($tlevel, $toc, $main = true)
{
	$stack = array(
		'',
		array(),
	);
	$num = 0;
	$mainToc = array();

	foreach ($toc as $t)
	{
		list ($level, $title) = $t;

		if ($level == $tlevel)
		{
			if (!empty($stack[0]))
			{
				$mainToc[] = array(
					$num,
					$stack[0],
					!empty($stack[1]) ? do_toctable($tlevel + 1, $stack[1], false) : array(),
				);
			}

			$stack = array(
				$title,
				array()
			);

			$num++;
		}
		elseif ($level >= $tlevel)
			$stack[1][] = array($level, $title);
	}

	if (!empty($stack[0]))
	{
		$mainToc[] = array(
			$num,
			$stack[0],
			!empty($stack[1]) ? do_toctable($tlevel+1, $stack[1], false) : array(),
		);
	}

	return $mainToc;
}

// Callback for wikivariables
function wikivariable_callback($groups)
{
	global $context, $pageVariables;

	if (empty($groups[2]))
	{
		if (isset($pageVariables[$groups[1]]))
			return $pageVariables[$groups[1]];
		elseif (isset($context['wiki_variables'][$groups[1]]))
			return $context['wiki_variables'][$groups[1]];
	}
	else
	{
		if (isset($pageVariables))
			$pageVariables[$groups[1]] = $groups[2];
		return '';
	}

	return $groups[0];
}

// Parses variables from content
function wikiparse_variables($message)
{
	global $rep_temp, $pageVariables;

	$pageVariables = array();

	$message = preg_replace_callback('%{{([a-zA-Z]+):(.+?)}}%', 'wikivariable_callback', $message);

	$temp = $pageVariables;
	unset($pageVariables);

	return $temp;
}

class WikiParser
{
	var $page_info;
	var $namespace;
	var $params;
	var $parse_bbc = true;

	var $tableOfContents = array();
	var $pageSections = array();
	var $currentSection = array();

	function __construct($page_info, $namespace, $parse_bbc = true)
	{
		$this->page_info = $page_info;
		$this->namespace = $namespace;
		$this->parse_bbc = $parse_bbc;
	}

	function parse($message, $params = array())
	{
		$this->params = $params;

		$message = $this->__parse__curls($message);

		if ($this->parse_bbc)
			$message = parse_bbc($message);

		$message = preg_replace_callback('/\[\[(.*?)(\|(.*?))?\]\](.*?)([.,\'"\s]|$|\r\n|\n|\r|<br( \/)?>|<)/', array($this, '__link_callback'), $message);

		$parts = preg_split(
			'%(={2,5})\s{0,}(.+?)\s{0,}\1\s{0,}|(<br />|<br /><br />|<br />|<!!!>|</!!!>|<div|<ul|<table|<code|</div>|</ul>|</table>|</code>)%',
			$message,
			null,
			PREG_SPLIT_DELIM_CAPTURE
		);

		$this->currentSection = array(
			'title' => $this->page_info['title'],
			'level' => 1,
			'content' => '',
			'edit_url' => wiki_get_url(array(
				'page' => wiki_urlname($this->page_info['title'], $this->namespace),
				'sa' => 'edit',
			)),
		);

		// Set current status for parser
		$para_open = false;
		$can_para = true;
		$in_bracket = false;
		$currentBracket = '';

		$toc = array();

		$i = 0;
		while ($i < count($parts))
		{
			// New Section
			if (substr($parts[$i], 0, 1) == '=' && strlen($parts[$i]) >= 2 && strlen($parts[$i]) <= 5)
			{
				if (str_replace('=', '', $parts[$i]) == '')
				{
					if ($para_open)
						$this->currentSection['content'] .= '</p>';
					$this->pageSections[] = $this->currentSection;

					$toc[] = array(strlen($parts[$i]), $parts[$i + 1]);

					$this->currentSection = array(
						'title' => $parts[$i + 1],
						'level' => strlen($parts[$i]),
						'content' => '',
						'edit_url' => wiki_get_url(array(
							'page' => wiki_urlname($this->page_info['title'], $this->namespace),
							'sa' => 'edit',
							'section' => count($this->pageSections),
						)),
					);

					$para_open = false;

					$i += 1;
				}
			}
			// New Paragraph?
			elseif ($parts[$i] == '<br /><br />')
			{
				if ($para_open)
					$this->currentSection['content'] .= '</p>';
				$para_open = false;
			}
			// Block tags can't be in paragraph
			elseif (in_array($parts[$i], array('<div', '<ul', '<table', '<code')))
			{
				if ($para_open)
					$this->currentSection['content'] .= '</p>';
				$para_open = false;

				// Don't start new paragraph
				$can_para = false;

				$this->currentSection['content'] .= $parts[$i];
			}
			elseif (in_array($parts[$i], array('</div>', '</ul>', '</table>', '</code')))
			{
				// Now new paragraph can be started again
				$can_para = true;

				$this->currentSection['content'] .= $parts[$i];
			}
			// No paragraphs area
			elseif ($parts[$i] == '<!!!>')
			{
				if ($para_open)
					$this->currentSection['content'] .= '</p>';
				$para_open = false;

				// Don't start new paragraph
				$can_para = false;
			}
			// No paragraphs area
			elseif ($parts[$i] == '</!!!>')
			{
				// Now new paragraph can be started again
				$can_para = true;
			}
			// Avoid starting paragraph with newline
			elseif ($parts[$i] == '<br />')
			{
				if ($para_open || !$can_para)
					$this->currentSection['content'] .= $parts[$i];
			}
			elseif (!empty($parts[$i]))
			{
				// Open new paragraph if one isn't open
				if (!$para_open && $can_para)
				{
					$this->currentSection['content'] .= '<p>';
					$para_open = true;
				}

				$this->currentSection['content'] .= $parts[$i];
			}

			$i++;
		}

		$this->pageSections[] = $this->currentSection;
		$this->currentSection = null;

		$this->tableOfContents = do_toctable(2, $toc);
	}

	function __parse__curls($message, $params = array())
	{
		global $txt;

		$parts = preg_split(
			'%(&lt;nowiki&gt;|&lt;/nowiki&gt;|{{{|}}}|{{|}}|\||&quot;|")%',
			$message,
			null,
			PREG_SPLIT_DELIM_CAPTURE
		);

		$inBracket = false;
		$inQuote = false;
		$wikiParseSection = true;
		$currentBracket = array(
			'name' => '',
			'params' => array(),
			'data' => array(),
			'non_parsed' => '',
		);

		$openBrackets = array();

		$message = '';

		$i = 0;

		while ($i < count($parts))
		{
			if (!$inBracket && $parts[$i] == '&lt;nowiki&gt;' && $wikiParseSection)
				$wikiParseSection = false;
			elseif (!$inBracket && $parts[$i] == '&lt;/nowiki&gt;' && !$wikiParseSection)
				$wikiParseSection = true;
			elseif (!$wikiParseSection && !$inBracket)
				$message .= $parts[$i];
			elseif (!$wikiParseSection)
			{
				$currentBracket['non_parsed'] .= $parts[$i];
				$currentBracket['data'][] = $parts[$i];
			}
			// Quotes
			elseif ($inQuote && ($parts[$i] == '&quot;' || $parts[$i] == '&quot;'))
			{
				$inQuote = false;
				$currentBracket['non_parsed'] .= $parts[$i];
			}
			elseif ($inQuote)
			{
				$currentBracket['non_parsed'] .= $parts[$i];
				$currentBracket['data'][] = $parts[$i];
			}
			elseif (!$inQuote && $inBracket && ($parts[$i] == '&quot;' || $parts[$i] == '&quot;'))
			{
				$inQuote = true;
				$currentBracket['non_parsed'] .= $parts[$i];
			}
			// Brackets
			elseif ($parts[$i] == '{{' && isset($parts[$i + 1]))
			{
				if ($inBracket)
				{
					$openBrackets[] = $currentBracket;
					$currentBracket = array(
						'name' => '',
						'params' => array(),
						'data' => array(),
						'non_parsed' => '',
					);
				}

				$currentBracket['non_parsed'] = $parts[$i];
				$i++;

				$inBracket = true;
				$currentBracket['type'] = 2;
				$currentBracket['name'] = $parts[$i];
				$currentBracket['non_parsed'] .= $parts[$i];
			}
			elseif ($parts[$i] == '{{{' && isset($parts[$i + 1]))
			{
				if ($inBracket)
				{
					$openBrackets[] = $currentBracket;
					$currentBracket = array(
						'name' => '',
						'params' => array(),
						'data' => array(),
						'non_parsed' => '',
					);
				}

				$currentBracket['non_parsed'] = $parts[$i];
				$i++;

				$inBracket = true;
				$currentBracket['type'] = 3;
				$currentBracket['name'] = $parts[$i];
				$currentBracket['non_parsed'] .= $parts[$i];
			}
			elseif ($inBracket && $parts[$i] == '|')
			{
				if (!empty($currentBracket['data']))
					$currentBracket['params'][] = $currentBracket['data'];
				$currentBracket['data'] = array('|');
				$currentBracket['non_parsed'] .= $parts[$i];
			}
			elseif (($currentBracket['type'] === 2 && $parts[$i] == '}}' || $currentBracket['type'] === 3 && $parts[$i] == '}}}'))
			{
				$currentBracket['non_parsed'] .= $parts[$i];

				// is there param?
				if (!empty($currentBracket['data']))
				{
					$currentBracket['params'][] = $currentBracket['data'];
					$currentBracket['data'] = array();
				}

				$currentBracket['parsed'] = '';

				if ($currentBracket['type'] == 3)
				{
					if (isset($params[$currentBracket['name']]))
					{
						$currentBracket['parsed'] .= $params[$currentBracket['name']];
						$currentBracket['boolean_value'] = true;
					}
					else
					{
						$currentBracket['parsed'] .= $currentBracket['non_parsed'];
						$currentBracket['boolean_value'] = false;
					}
				}
				elseif ($currentBracket['type'] == 2)
				{
					if (substr($currentBracket['name'], 0, 1) == '#')
					{
						$prams = array();

						list ($function, $param1) = explode(':', substr($currentBracket['name'], 1), 2);

						$funcParams = array();

						if (trim($param1) != '')
							$funcParams[] = trim($param1);

						foreach ($currentBracket['params'] as $temp)
						{
							$param = array();
							$dynamicParams = array();

							foreach ($temp as $ib => $part)
							{
								// Separator
								if ($ib == 0 && is_string($part) && $part == '|' && empty($funcParams))
									$funcParams[] = $param;
								elseif ($ib == 0 && is_string($part) && $part == '|')
									continue;
								elseif (is_string($part))
									$param[] = $part;
								elseif (is_array($part))
								{
									$dynamicParams[] = $part;
									$param[] = $part['parsed'];
								}
							}

							if (count($dynamicParams) == 1 && trim($dynamicParams[0]['parsed']) == trim(implode('', $param)))
								$funcParams[] = $dynamicParams[0];
							else
								$funcParams[] = trim(implode('', $param));
						}

						$function = trim($function);

						if ($function == 'if')
						{
							if (isset($funcParams[0]) && is_array($funcParams[0]))
							{
								if (isset($funcParams[0]['boolean_value']) && $funcParams[0]['boolean_value'] === true)
									$currentBracket['parsed'] .= isset($funcParams[1]) ? $funcParams[1] : '';
								else
									$currentBracket['parsed'] .= isset($funcParams[2]) ? $funcParams[2] : '';
							}
							elseif (isset($funcParams[0]))
							{
								if (trim($funcParams[0]) == true)
									$currentBracket['parsed'] .= isset($funcParams[1]) ? $funcParams[1] : '';
								else
									$currentBracket['parsed'] .= isset($funcParams[2]) ? $funcParams[2] : '';
							}
						}
					}
					elseif (isset($this->page_info['variables'][$currentBracket['name']]))
						$currentBracket['parsed'] .= $this->page_info['variables'][$currentBracket['name']];
					elseif (isset($context['wiki_variables'][$currentBracket['name']]))
						$currentBracket['parsed'] .= $context['wiki_variables'][$currentBracket['name']];
					else
					{
						$currentBracket['name'] = trim(str_replace(array('<br />', '&nbsp;'), array("\n", ' '), $currentBracket['name']));

						list ($namespace, $page) = __url_page_parse($currentBracket['name']);

						$nextNumeric = 1;

						$templateParams = array();

						foreach ($currentBracket['params'] as $temp)
						{
							$param = array();
							$dynamicParams = array();

							foreach ($temp as $ib => $part)
							{
								// Separator
								if ($ib == 0 && is_string($part) && $part == '|' && empty($funcParams))
									$funcParams[] = $param;
								elseif ($ib == 0 && is_string($part) && $part == '|')
									continue;
								elseif (is_string($part))
									$param[] = $part;
								elseif (is_array($part))
									$param[] = $part['parsed'];
							}

							$param = trim(implode('', $param));
							if (strpos($param, '='))
							{
								list ($key, $value) = explode('=', $param, 2);

								$key = trim(str_replace('<br />', '', $key));

								if (is_numeric($key))
									$nextNumeric = $key + 1;
							}
							else
								$key = $nextNumeric++;

							$value = str_replace("\n", '<br />', trim(str_replace('<br />', "\n", $value)));

							$templateParams[$key] = $value;
						}

						if (empty($namespace))
							$namespace = 'Template';

						if (!isset($context['wiki_template']))
							$context['wiki_template'] = array();

						if (!isset($context['wiki_template'][$namespace . ':' . $page]))
							$context['wiki_template'][$namespace . ':' . $page] = cache_quick_get('wiki-template-' . $namespace . ':' . $page, 'Subs-Wiki.php', 'wiki_template_get', array($namespace, $page));

						if ($context['wiki_template'][$namespace . ':' . $page] === false)
							$currentBracket['parsed'] .= '<span style="color: red">' . sprintf($txt['template_not_found'], (!empty($namespace) ? $namespace . ':' . $page : $page)). '</span>';

						$currentBracket['parsed'] .= $this->__parse__curls($context['wiki_template'][$namespace . ':' . $page], $templateParams);
					}
				}
				else
					$currentBracket['parsed'] .= $currentBracket['non_parsed'];

				if (empty($openBrackets))
				{
					$inBracket = false;

					$message .= $currentBracket['parsed'];

					$currentBracket = array(
						'name' => '',
						'params' => array(),
						'non_parsed' => '',
					);
				}
				else
				{
					$parsedBracket = $currentBracket;

					$currentBracket = array_pop($openBrackets);
					$currentBracket['non_parsed'] .= $parsedBracket['non_parsed'];
					$currentBracket['data'][] = $parsedBracket;
				}
			}
			elseif ($inBracket && !empty($parts[$i]))
			{
				$currentBracket['non_parsed'] .= $parts[$i];
				$currentBracket['data'][] = $parts[$i];
			}
			elseif (!$inBracket && !empty($parts[$i]))
				$message .= $parts[$i];

			$i++;
		}

		// Try to fix mistakes
		if (!empty($currentBracket['non_parsed']))
		{
			foreach ($openBrackets as $brc)
				$message .= $brc['non_parsed'];
			$message .= $currentBracket['non_parsed'];
		}

		return $message;
	}

	function __link_callback($groups)
	{
		global $context;

		list ($namespace, $page) = __url_page_parse($groups[1]);

		if ($namespace == $context['namespace_images']['id'])
		{
			if (!empty($groups[3]))
			{
				$options = explode('|', $groups[3]);
				$align = '';
				$size = '';
				$caption = '';
				$alt = '';

				// Size
				if (!empty($options[0]))
				{
					if ($options[0] == 'thumb')
						$size = ' width="180"';
					elseif (is_numeric($options[0]))
						$size = ' width="' . $options[0] . '"';
					elseif (strpos($options[0], 'x') !== false)
					{
						list ($width, $height) = explode('x', $options[0], 2);

						if (is_numeric($width) && is_numeric($height))
						{
							$size = ' width="' . $width . '" height="' . $height. '"';
						}
					}
				}

				// Align
				if (!empty($options[1]) && ($options[1] == 'left' || $options[1] == 'right'))
					$align = $options[1];

				// Alt
				if (!empty($options[2]))
					$alt = $options[2];

				// Caption
				if (!empty($options[3]))
					$caption = $options[3];

				if (!empty($align) || !empty($caption))
					$code = '<div' . (!empty($align) ? $code .= ' style="float: ' . $align . '; clear: ' . $align . '"' : '') . '>';

				$code .= '<a href="' . wiki_get_url(wiki_urlname($groups[1])) . '"><img src="' . wiki_get_url(array('page' => wiki_urlname($groups[1]), 'image')) . '" alt="' . $alt . '"' . $size . ' /></a>';

				if (!empty($align) || !empty($caption))
					$code .= '</div>';

				return $code;
			}

			return '<a href="' . wiki_get_url(wiki_urlname($groups[1])) . '"><img src="' . wiki_get_url(array('page' => wiki_urlname($groups[1]), 'image')) . '" alt="" /></a>';
		}
		else
		{
			if (empty($groups[3]))
				$link = '<a href="' . wiki_get_url(wiki_urlname($groups[1])) . '">' . read_urlname($groups[1]) . $groups[4] . '</a>';
			else
				$link = '<a href="' . wiki_get_url(wiki_urlname($groups[1])) . '">' . $groups[3] . $groups[4] . '</a>';

			return $link . $groups[5];
		}
	}
}

// Callback for replace variables and templates
function wikiparses_variable_replace($groups)
{
	global $context, $variablesTemp, $templateParams;

	if (isset($variablesTemp[$groups[1]]))
		return $variablesTemp[$groups[1]];
	// Is it global variable (ie wikiversion)
	elseif (isset($context['wiki_variables'][$groups[1]]))
		return $context['wiki_variables'][$groups[1]];
	else
		return $groups[0];
}

// Parses wiki page
function wikiparser($page_info, $message, $parse_bbc = true, $namespace = null)
{
	global $variablesTemp;

	// temp
	$parser = new WikiParser($page_info, $namespace, $parse_bbc);
	$parser->parse($message);

	return array('toc' => $parser->tableOfContents, 'sections' => $parser->pageSections);
}

// Callback for making wikilinks
function wikilink_callback($groups)
{
	if (empty($groups[3]))
		$link = '<a href="' . wiki_get_url(wiki_urlname($groups[1])) . '">' . read_urlname($groups[1]) . $groups[4] . '</a>';
	else
		$link = '<a href="' . wiki_get_url(wiki_urlname($groups[1])) . '">' . $groups[3] . $groups[4] . '</a>';

	return $link . $groups[5];
}

// Callback for templates
function wikitemplate_callback($groups)
{
	global $context, $wikiReplaces;
	static $templateFunctions = array();


}

// Callback for condtional IF
function wikitemplate_if_callback($groups)
{
	global $context, $wikiReplaces;

	if (!empty($wikiReplaces['@@' . $groups[2] . '@@']))
		return $groups[4];
	else
		return '';
}

?>