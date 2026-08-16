<?php
/*
 * Custom Dashboard Modules
 * ------------------------------------------------------------
 * These cards appear in Dashboard V3 under Custom Modules.
 */
return array(

	array(
		'name'        => 'createuser',
		'title'       => 'Create User',
		'description' => 'Create custom user accounts.',
		'icon'        => 'fa-user-plus',
		'url'         => '/admin/createuser',
		'resource'    => 'menu',
		'permission'  => 'read',
		'active'      => 1,
		'sortorder'   => 10
	),

	array(
		'name'        => 'importusers',
		'title'       => 'Import Users',
		'description' => 'Import users from approved CSV files.',
		'icon'        => 'fa-upload',
		'url'         => '/admin/import',
		'resource'    => 'menu',
		'permission'  => 'read',
		'active'      => 1,
		'sortorder'   => 20
	),

	array(
		'name'        => 'importdopusers',
		'title'       => 'DOP Import',
		'description' => 'Import or update DOP user records.',
		'icon'        => 'fa-building',
		'url'         => '/admin/import-dop',
		'resource'    => 'importdopusers',
		'permission'  => 'read',
		'active'      => 1,
		'sortorder'   => 30
	),
	array(
		'name'        => 'sort',
		'title'       => 'Sort Students',
		'description' => 'Sort students bt localid.',
		'icon'        => 'fa-building',
		'url'         => '/admin/sort',
		'resource'    => 'menu',
		'permission'  => 'read',
		'active'      => 1,
		'sortorder'   => 30
	),
	array(
		'name'        => 'masterlist',
		'title'       => 'Update Students',
		'description' => 'Import list of studentID to remove stale records.',
		'icon'        => 'fa-building',
		'url'         => '/admin/masterlist',
		'resource'    => 'menu',
		'permission'  => 'read',
		'active'      => 1,
		'sortorder'   => 30
	)
);
?>
