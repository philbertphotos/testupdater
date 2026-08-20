<?php
/*************************************************************************
 * Manual Workflow Test Runner
 *************************************************************************
 *
 * Location:
 *
 * /admin/workflows/api.php
 *
 * Example URLs:
 *
 * /admin/workflows/api.php?key=users.find.new
 * /admin/workflows/api.php?key=users.audit.standards
 * /admin/workflows/api.php?key=users.email.report
 *
 *************************************************************************
 * PURPOSE
 *************************************************************************
 *
 * Simple workflow execution endpoint used during workflow
 * development, testing and troubleshooting.
 *
 * Features:
 *
 * - Execute workflow by key
 * - Load workflow registry
 * - Return workflow context
 * - Return execution timing
 * - Return memory statistics
 * - Return debug information
 *
 *************************************************************************
 * SECURITY
 *************************************************************************
 *
 * DEVELOPMENT USE ONLY
 *
 * This endpoint is intentionally unrestricted while workflows
 * are being developed.
 *
 * BEFORE PRODUCTION:
 *
 * [ ] Require authenticated session
 * [ ] Require manager/admin role
 * [ ] Require workflow.run permission
 * [ ] Add workflow execution audit logging
 * [ ] Disable debug output
 * [ ] Restrict allowed workflow keys
 * [ ] Consider IP restrictions if required
 *
 * Example:
 *
 * $m->requirePageAccess('Managers');
 *
 *************************************************************************
 * EXAMPLE WORKFLOWS
 *************************************************************************
 *
 * users.find.new
 * users.audit.standards
 * users.email.report
 * sessions.cleanup.old
 *
 *************************************************************************/

require_once($_SERVER['DOCUMENT_ROOT'] . '/env.php');

/*************************************************************************
 * Optional Page Rendering
 *************************************************************************/

// include(DOCROOT . '/header.php');

/*************************************************************************
 * Future Authentication / Authorization
 *************************************************************************/

// TODO:
// Require login session.
//
// Example:
// if (!$m->isLoggedIn()) {
//     die('Not authorized.');
// }

// TODO:
// Require manager role.
//
// Example:
// $m->requirePageAccess('Managers');

// TODO:
// Add workflow execution permission.
//
// Example:
// if (!$acl->allowed('workflow.run')) {
//     die('Access denied.');
// }

/*************************************************************************
 * Workflow Registry
 *************************************************************************/

$actions = require(DOCROOT . '/admin/workflows/registry.php');

/*************************************************************************
 * Input
 *************************************************************************/

$workflowKey = isset($_GET['key'])
	? trim((string)$_GET['key'])
	: '';

if ($workflowKey === '') {

	header('Content-Type: text/plain');

	echo "Missing workflow key.\n";
	echo "\n";
	echo "Examples:\n";
	echo "  ?key=users.find.new\n";
	echo "  ?key=users.audit.standards\n";

	exit;
}

/*************************************************************************
 * Execution Metrics
 *************************************************************************/

$started = microtime(true);
$startedAt = date('Y-m-d H:i:s');

/*************************************************************************
 * Execute Workflow
 *************************************************************************/

try {

	$engine = new WorkflowEngine($d, $actions);

	$context = $engine->run($workflowKey);

	$executionTime = round(
		microtime(true) - $started, 4);

	$memoryPeak = memory_get_peak_usage(true);
	$memoryPeakMb = round(
		$memoryPeak / 1048576, 2);

	/*************************************************************************
	 * Future Audit Logging
	 *************************************************************************/

	// TODO:
	// Record workflow execution.
	//
	// Example:
	//
	// workflow_audit
	// (
	//     userid,
	//     workflow_key,
	//     execution_time,
	//     memory_peak,
	//     ipaddress,
	//     created
	// );

	header('Content-Type: application/json');

	echo json_encode(
		array(
			'result' => 0,
			'info' => 'Workflow completed.',
			'workflow' => $workflowKey,
			'started' => $startedAt,
			'execution_time_seconds' => $executionTime,
			'memory_peak_bytes' => $memoryPeak,
			'memory_peak_mb' => $memoryPeakMb,
			'context' => $context
		),
		JSON_PRETTY_PRINT
	);

} catch (Exception $e) {

	$executionTime = round(
		microtime(true) - $started, 4);

	$memoryPeak = memory_get_peak_usage(true);
	$memoryPeakMb = round(
		$memoryPeak / 1048576, 2);

	header('Content-Type: application/json');

	echo json_encode(
		array(
			'result' => 1,
			'workflow' => $workflowKey,
			'started' => $startedAt,
			'execution_time_seconds' => $executionTime,
			'memory_peak_bytes' => $memoryPeak,
			'memory_peak_mb' => $memoryPeakMb,
			'info' => $e->getMessage()
		),
		JSON_PRETTY_PRINT
	);
}
?>