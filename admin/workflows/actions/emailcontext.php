<?php
/**
 * Action_EmailContext
 *
 * Sends a simple plain-text email using data collected in the workflow context.
 *
 * This is intentionally simple for the first build.
 * Later you can replace mail() with your preferred SMTP or Microsoft 365 mail method.
 */
class Action_EmailContext implements WorkflowActionInterface
{
    public function run($context, $params, $engine)
    {
        $to = trim((string)($params['to'] ?? ''));
        $subject = trim((string)($params['subject'] ?? 'User Manager Workflow Report'));
        $includeContext = isset($params['include_context']) ? (bool)$params['include_context'] : true;

        if ($to === '') {
            throw new Exception('Email action requires a to address.');
        }

        $body = array();
        $body[] = 'User Manager Workflow Report';
        $body[] = 'Generated: ' . date('Y-m-d H:i:s');
        $body[] = '';

        if (isset($context['_workflow'])) {
            $body[] = 'Workflow: ' . $context['_workflow']['name'] . ' (' . $context['_workflow']['key'] . ')';
            $body[] = 'Run ID: ' . $context['_workflow']['run_id'];
            $body[] = '';
        }

        if (isset($context['report_logs'])) {
            $body[] = 'Log Report';
            $body[] = 'Table: ' . $context['report_logs']['table'];
            $body[] = 'Rows Found: ' . $context['report_logs']['count'];
            $body[] = '';

            foreach ($context['report_logs']['rows'] as $row) {
                $body[] = print_r($row, true);
            }
        }

        if (isset($context['cleanup_sessions'])) {
            $body[] = 'Session Cleanup';
            $body[] = 'Table: ' . $context['cleanup_sessions']['table'];
            $body[] = 'Matched: ' . $context['cleanup_sessions']['matched'];
            $body[] = 'Deleted: ' . $context['cleanup_sessions']['deleted'];
            $body[] = 'Dry Run: ' . ($context['cleanup_sessions']['dry_run'] ? 'Yes' : 'No');
            $body[] = '';
        }

        if ($includeContext) {
            $body[] = 'Full Context';
            $body[] = json_encode($context, JSON_PRETTY_PRINT);
        }

        $message = implode("\n", $body);

        $sent = mail($to, $subject, $message);

        $context['email_context'] = array(
            'to' => $to,
            'subject' => $subject,
            'sent' => $sent ? 1 : 0
        );

        return $context;
    }
}
?>
