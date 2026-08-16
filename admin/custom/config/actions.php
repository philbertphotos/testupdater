<?php
/*
 * Custom AJAX Action Registry
 * ------------------------------------------------------------
 * Add new custom AJAX actions here.
 */
return array(

	'createuser' => array(
		'file'       => '/admin/custom/actions/createuser.php',
		'resource'   => 'createuser',
		'permission' => 'create'
	),

	'importusers' => array(
		'file'       => '/admin/custom/actions/importusers.php',
		'resource'   => 'importusers',
		'permission' => 'create'
	),
	'masterlist' => array(
		'file'       => '/admin/custom/actions/masterlist.php',
		'resource'   => 'masterlist',
		'permission' => 'create'
	),

	'sort' => array(
		'file'       => '/admin/custom/actions/sort.php',
		'resource'   => 'sort',
		'permission' => 'create'
	)
);
?>
