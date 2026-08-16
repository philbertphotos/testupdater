<?php
/*
 * Custom AJAX Response Helpers
 */

function customJson($data)
{
	echo json_encode($data);
	exit;
}

function customSuccess($info = '', $extra = array())
{
	$data = array(
		'result' => 0,
		'info'   => $info
	);

	if(!empty($extra) && is_array($extra))
	{
		$data = array_merge($data, $extra);
	}

	customJson($data);
}

function customError($error = 'Request failed.', $extra = array())
{
	$data = array(
		'result' => 1,
		'error'  => $error,
		'info'   => $error
	);

	if(!empty($extra) && is_array($extra))
	{
		$data = array_merge($data, $extra);
	}

	customJson($data);
}
?>
