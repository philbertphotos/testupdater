<?php
/**
 * Action_CleanupOldSessions
 *
 * Reusable action that deletes old session rows from a configurable session table.
 *
 * IMPORTANT:
 * Start with dry_run=true in workflow_steps.parameters_json.
 * Confirm the matched count before allowing deletes.
 */
class Action_CleanupOldSessions implements WorkflowActionInterface
{
    public function run($context, $params, $engine)
    {
        $db = $this->getDb($engine);

        /**
         * Modify these in workflow_steps.parameters_json:
         *
         * table               = sessions table name
         * last_activity_field = field used to determine age
         * date_type           = datetime or unix
         * max_idle_minutes    = session age threshold
         * dry_run             = true to report only, false to delete
         */
        $table = $engine->safeIdentifier($params['table'] ?? 'sessions');
        $lastField = $engine->safeIdentifier($params['last_activity_field'] ?? 'last_activity');
        $dateType = strtolower($params['date_type'] ?? 'unix');
        $maxIdleMinutes = max(1, (int)($params['max_idle_minutes'] ?? 1440));
        $dryRun = isset($params['dry_run']) ? (bool)$params['dry_run'] : true;

        if ($dateType === 'datetime') {
            $cutoff = date('Y-m-d H:i:s', time() - ($maxIdleMinutes * 60));
            $where = "$lastField < '" . $engine->escape($cutoff) . "'";
        } else {
            $cutoff = time() - ($maxIdleMinutes * 60);
            $where = "$lastField < '" . (int)$cutoff . "'";
        }

        $countRow = $db->row("SELECT COUNT(*) AS total FROM $table WHERE $where");
        $matched = $countRow ? (int)$countRow->total : 0;
        $deleted = 0;

        if (!$dryRun && $matched > 0) {
            $db->query("DELETE FROM $table WHERE $where");
            $deleted = $matched;
        }

        $context['cleanup_sessions'] = array(
            'table' => $table,
            'last_activity_field' => $lastField,
            'date_type' => $dateType,
            'max_idle_minutes' => $maxIdleMinutes,
            'matched' => $matched,
            'deleted' => $deleted,
            'dry_run' => $dryRun
        );

        return $context;
    }

    protected function getDb($engine)
    {
        $ref = new ReflectionClass($engine);
        $prop = $ref->getProperty('db');
        $prop->setAccessible(true);
        return $prop->getValue($engine);
    }
}
?>
