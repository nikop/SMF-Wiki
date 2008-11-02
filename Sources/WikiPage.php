<?php

function ViewPage()
{
	global $smcFunc, $context, $modSettings, $txt, $user_info, $sourcedir;

	$context['can_edit_page'] = siteAllowed('wiki_edit');
	$context['page_content'] = wikiparser($context['current_page']['title'], $context['current_page']['body'], true, $context['current_page']['namespace']);

	// Don't index older versions please
	if (!$context['current_page']['is_current'])
		$context['robot_no_index'] = true;

	// Template
	$context['page_title'] = $context['forum_name'] . ' - ' . $context['current_page']['title'];
	$context['wiki_title'] = $context['current_page']['title'];
	$context['sub_template'] = 'view_page';
}

function DiffPage()
{
	global $smcFunc, $context, $modSettings, $txt, $user_info, $sourcedir;

	require_once($sourcedir . '/Subs-Post.php');

	// This is pretty much duplicate content
	$context['robot_no_index'] = true;

	if (!empty($_REQUEST['revision']) && $_REQUEST['revision'] < $_REQUEST['old_revision'])
	{
		$context['diff_page'] = $context['current_page'];
		$context['current_page'] = loadWikiPage($_REQUEST['page'], $_REQUEST['namespace'], (int) $_REQUEST['old_revision']);
	}
	else
	{
		$context['diff_page'] = loadWikiPage($_REQUEST['page'], $_REQUEST['namespace'], (int) $_REQUEST['old_revision']);
	}

	$diff = diff(explode("\n", un_preparsecode($context['diff_page']['body'])), explode("\n", un_preparsecode($context['current_page']['body'])));

	$context['diff'] = array();

	$old = 0;
	$new = 0;

	foreach ($diff as $l)
	{
		if (!is_array($l))
		{
			$old++;
			$new++;

			$context['diff'][] = array(
				'',
				$l,
				$old,
				$new,
			);
		}
		else
		{
			if (!empty($l['d']))
			{
				foreach ($l['d'] as $ld)
				{
					$old++;

					$context['diff'][] = array(
						'd',
						$ld,
						$old,
						'...',
					);
				}
			}
			if (!empty($l['i']))
			{
				foreach ($l['i'] as $li)
				{
					$new++;

					$context['diff'][] = array(
						'a',
						$li,
						'...',
						$new,
					);
				}
			}
		}
	}

	$context['page_content'] = wikiparser($context['current_page']['title'], $context['current_page']['body'], true, $context['current_page']['namespace']);

	// Template
	$context['page_title'] = $context['forum_name'] . ' - ' . $context['current_page']['title'];
	$context['wiki_title'] = $context['current_page']['title'];
	$context['sub_template'] = 'view_page';
}

?>