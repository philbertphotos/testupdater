<?php
/*
 * Custom Security Helpers
 * ------------------------------------------------------------
 * Keeps custom code behind UM login and ACL checks.
 */

function customIsLoggedIn()
{
	global $m;
	
	if ($m->isLoggedIn()) {
		return true;
	}

	if(session_status() !== PHP_SESSION_ACTIVE)
	{
		return false;
	}

	if(empty($_SESSION['session_registration']) && empty($_SESSION['username']))
	{
		return false;
	}

	return true;
}

function customIsPublicAction($action)
{
	$public = array();

	return in_array($action, $public);
}

function customCanAccess($resource, $permission = 'read')
{
	global $acl;
	return $acl->isAllowed($acl->getRole(), $permission, $resource);
}

function customCleanAction($action)
{
	return preg_replace('/[^a-zA-Z0-9_-]/', '', $action);
}
?>
