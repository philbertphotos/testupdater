<?php
/**
 * Workflow Action Registry
 *
 * Only actions listed here can be executed by the workflow engine.
 *
 * To add a new reusable action:
 * 1. Create a new file in /actions.
 * 2. Make the class implement WorkflowActionInterface.
 * 3. Require the file below.
 * 4. Add the action key to the returned array.
 */
require_once(DOCROOT . '/admin/workflows/classes/WorkflowActionInterface.php');
require_once(DOCROOT . '/admin/workflows/classes/WorkflowEngine.php');

require_once(DOCROOT . '/admin/workflows/actions/Action_ReportLogs.php');
require_once(DOCROOT . '/admin/workflows/actions/Action_EmailContext.php');
require_once(DOCROOT . '/admin/workflows/actions/Action_CleanupOldSessions.php');

	return array(
		// Collect rows from a log table into workflow context.
		'report_logs' => 'Action_ReportLogs',

		// Send workflow context by email.
		'email_context' => 'Action_EmailContext',

		// Delete or count old sessions.
		'cleanup_old_sessions' => 'Action_CleanupOldSessions',
		
		//test
		'test.echo' =>
			'Action_TestEcho'

	);
?>