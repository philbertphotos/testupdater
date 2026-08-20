<?php
/**
 * Event Scheduler Bridge Example
 *
 * Use this idea later when connecting the Events page to workflows.
 *
 * Example event argument:
 * workflow=cleanup.old.sessions
 *
 * Your existing api_cron.php/event runner can parse that argument and call this logic.
 */
require_once($_SERVER['DOCUMENT_ROOT'] . '/env.php');

$actions = require(DOCROOT . '/admin/workflows/registery.php');
$engine = new WorkflowEngine($d, $actions);

// Replace this with your event argument parser.
$workflowKey = isset($workflowKey) ? $workflowKey : 'cleanup.old.sessions';

$engine->run($workflowKey);
?>
