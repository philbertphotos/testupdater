<?php
/*************************************************************************
 * Action_FindNewUsers Test Script
 *************************************************************************
 *
 * Purpose:
 * Test LDAP connectivity and verify Action_FindNewUsers output.
 *
 *************************************************************************/

require_once('../env.php');
require_once('workflows/actions/Action_FindNewUsers.php');

echo '<h2>Action_FindNewUsers Test</h2>';

/*************************************************************************
 * Test Parameters
 *************************************************************************/

$params = array(
	'days'   => 365,
	'domain' => 'vi.gov'
);

/*************************************************************************
 * Mock Workflow Engine
 *************************************************************************/

class WorkflowEngineTest
{
	public function log($level, $message, $data = array())
	{
		echo '<pre>';
		echo '[' . strtoupper($level) . '] ' . $message . PHP_EOL;

		if (!empty($data)) {
			print_r($data);
		}

		echo '</pre>';
	}
}

$engine = new WorkflowEngineTest();

/*************************************************************************
 * Execute Action
 *************************************************************************/

$context = array();

try {

	$action = new Action_FindNewUsers();

	$result = $action->run(
		$context,
		$params,
		$engine
	);

	echo '<h3>Results</h3>';

	echo '<pre>';
	print_r($result);
	echo '</pre>';

} catch (Exception $e) {

	echo '<div class="alert alert-danger">';

	echo '<strong>Exception:</strong><br>';

	echo htmlspecialchars(
		$e->getMessage()
	);

	echo '</div>';
}