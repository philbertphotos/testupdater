<?php
/*************************************************************************
 * Workflow Action Registry
 *************************************************************************
 *
 * Only actions listed here can be executed by the workflow engine.
 *
 *
 * Class naming:
 *     Action_ReportLogs
 *     Action_EmailContext
 *     Action_CleanupOldSessions
 *     Action_TestEcho
 */

require_once(DOCROOT . '/admin/workflows/classes/actioninterface.php');
require_once(DOCROOT . '/admin/workflows/classes/engine.php');

return array(
	'email.send' => 'Action_SendEmail',
	'users.find.new' => 'Action_FindNewUsers',
    'report_logs' => 'Action_ReportLogs',
    'email_context' => 'Action_EmailContext',
	'cleanup_old_sessions' => 'Action_CleanupOldSessions',
    'test.echo' => 'Action_TestEcho'

);
?>