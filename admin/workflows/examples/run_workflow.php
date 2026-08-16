<?php
/**
 * Manual Workflow Test Runner
 *
 * Example URLs:
 * /examples/run_workflow.php?key=report.admin.logs
 * /examples/run_workflow.php?key=cleanup.old.sessions
 *
 * SECURITY:
 * This file should be protected before production use.
 * Add your normal manager/admin access check here, for example:
 * $m->requirePageAccess('Managers');
 */
require_once($_SERVER['DOCUMENT_ROOT'] . '/env.php');

// Optional if you want page header while testing in browser.
// include(DOCROOT . '/header.php');

// IMPORTANT: protect this runner in production.
// $m->requirePageAccess('Managers');

$actions = require(DOCROOT . '/admin/workflows/registry.php');

$workflowKey = isset($_GET['key']) ? $_GET['key'] : '';

if ($workflowKey === '') {
    header('Content-Type: text/plain');
    echo "Missing workflow key.\n";
    echo "Example: ?key=report.admin.logs\n";
    exit;
}

try {
    $engine = new WorkflowEngine($d, $actions);
    $context = $engine->run($workflowKey);

    header('Content-Type: application/json');
    echo json_encode(array(
        'result' => 0,
        'info' => 'Workflow completed.',
        'context' => $context
    ), JSON_PRETTY_PRINT);
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(array(
        'result' => 1,
        'info' => $e->getMessage()
    ), JSON_PRETTY_PRINT);
}
?>
