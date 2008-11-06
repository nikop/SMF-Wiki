<?php
/**********************************************************************************
* Wiki.php                                                                        *
***********************************************************************************
* SMF Wiki                                                                        *
* =============================================================================== *
* Software Version:           SMF Wiki 0.1                                        *
* Software by:                Niko Pahajoki (http://www.madjoki.com)              *
* Copyright 2008 by:          Niko Pahajoki (http://www.madjoki.com)              *
* Support, News, Updates at:  http://www.smfarcade.info                           *
***********************************************************************************
* This program is free software; you may redistribute it and/or modify it under   *
* the terms of the provided license as published by Simple Machines LLC.          *
*                                                                                 *
* This program is distributed in the hope that it is and will be useful, but      *
* WITHOUT ANY WARRANTIES; without even any implied warranty of MERCHANTABILITY    *
* or FITNESS FOR A PARTICULAR PURPOSE.                                            *
*                                                                                 *
* See the "license.txt" file for details of the Simple Machines license.          *
* The latest version can always be found at http://www.simplemachines.org.        *
**********************************************************************************/

if (!defined('SMF'))
	die('Hacking attempt...');

function loadWiki($mode = '')
{
	global $context, $modSettings, $settings, $txt, $user_info, $smcFunc, $sourcedir, $wiki_version;

	require_once($sourcedir . '/Subs-Wiki.php');

	// Wiki Version
	$wiki_version = '0.1';

	loadTemplate('Wiki', array('wiki'));
	loadLanguage('Wiki');

	// Normal mode
	if ($mode == '')
	{
		// Linktree
		$context['linktree'][] = array(
			'url' => wiki_get_url('Main_Page'),
			'name' => $txt['wiki'],
		);

		// Template
		$context['template_layers'][] = 'wiki';
	}
	// Admin Mode
	elseif ($mode == 'admin')
	{

	}
}

function Wiki($standalone = false)
{
	global $context, $modSettings, $settings, $txt, $user_info, $smcFunc, $sourcedir;

	loadWiki();

	if (!isset($_REQUEST['page']))
		$_REQUEST['page'] = '';

	// Santise Namespace
	if (strpos($_REQUEST['page'], ':'))
		list ($_REQUEST['namespace'], $_REQUEST['page']) = explode(':', $_REQUEST['page'], 2);
	else
		$_REQUEST['namespace'] = '';

	$namespace = ucfirst($smcFunc['strtolower'](str_replace(array(' ', '[', ']', '{', '}', '|'), '_', $_REQUEST['namespace'])));
	$page = str_replace(array(' ', '[', ']', '{', '}', '|'), '_', $_REQUEST['page']);

	if ($namespace != $_REQUEST['namespace'] || $page != $_REQUEST['page'])
		redirectexit(wiki_get_url(wiki_urlname($page, $namespace)));

	// Wiki Menu
	$menu = cache_quick_get('wiki-navigation', 'Subs-Wiki.php', 'wiki_template_get', array('Template', 'Navigation'));
	$context['wiki_navigation'] = array();
	
	if ($menu)
	{
		$menu = preg_split('~<br( /)?' . '>~', $menu);

		$current_menu = false;

		foreach ($menu as $item)
		{
			$item = trim($item);

			if (strpos($item, '|') !== false)
				list ($url, $title) = explode('|', $item, 2);
			else
			{
				$url = '';
				$title = $item;
			}

			if (substr($title, 0, 2) == '__' || substr($title, -2, 2) == '__')
				$title = isset($txt['wiki_' . substr($title, 2, -2)]) ? $txt['wiki_' . substr($title, 2, -2)] : $title;

			if (substr($item, 0, 1) != ':')
			{
				$context['wiki_navigation'][] = array(
					'url' => $url,
					'title' => $title,
					'items' => array(),
				);

				$current_menu = &$context['wiki_navigation'][count($context['wiki_navigation']) - 1];
			}
			else
			{
				$current_menu['items'][] = array(
					'url' => substr($url, 1),
					'title' => $title,
				);
			}
		}
	}

	// Load Namespace unless it's Special
	if ($namespace != 'Special')
	{
		$request = $smcFunc['db_query']('', '
			SELECT namespace, ns_prefix, page_header, page_footer, default_page
			FROM {db_prefix}wiki_namespace
			WHERE namespace = {string:namespace}',
			array(
				'namespace' => $_REQUEST['namespace'],
			)
		);

		$row = $smcFunc['db_fetch_assoc']($request);
		$smcFunc['db_free_result']($request);

		if (!$row)
			fatal_lang_error('wiki_namespace_not_found', false, array(read_urlname($_REQUEST['namespace'])));

		$context['namespace'] = array(
			'id' => $row['namespace'],
			'prefix' => $row['ns_prefix'],
			'url' => wiki_get_url(wiki_urlname($row['default_page'], $row['namespace'])),
		);

		if (empty($_REQUEST['page']))
			redirectexit($context['namespace']['url']);

		if (!empty($context['namespace']['prefix']))
		{
			$context['linktree'][] = array(
				'url' =>  $context['namespace']['url'],
				'name' => $context['namespace']['prefix'],
			);
		}
	}

	// Normal Namespace
	if ($namespace != 'Special')
	{
		require_once($sourcedir . '/WikiMain.php');
		WikiMain();
	}
	// Special Namespace
	elseif ($namespace == 'Special')
	{
		if (strpos($_REQUEST['page'], '/'))
			list ($_REQUEST['special'], $_REQUEST['page']) = explode('/', $_REQUEST['page'], 2);
		else
		{
			$_REQUEST['special'] = $_REQUEST['page'];
			$_REQUEST['page'] = '';
		}

		$specialArray = array(
			'RecentChanges' => array('WikiHistory.php', 'WikiRecentChanges'),
		);

		if (!isset($_REQUEST['special']) || !isset($specialArray[$_REQUEST['special']]))
			fatal_lang_error('wiki_action_not_found', false, array($_REQUEST['special']));

		$context['current_page_name'] = 'Special:' . $_REQUEST['special'];

		require_once($sourcedir . '/' . $specialArray[$_REQUEST['special']][0]);
		$specialArray[$_REQUEST['special']][1]();
	}
}

?>