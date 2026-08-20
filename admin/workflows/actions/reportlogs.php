<?php
/**
 * Action_ReportLogs
 *
 * Reusable action that selects logs from a configurable log table.
 *
 * This action DOES NOT send email.
 * It collects the log rows and stores them in $context['report_logs'].
 * Another action, such as Action_EmailContext, can send the result.
 *
 * Modify through workflow step JSON, not this file, when possible.
 */
class Action_ReportLogs implements WorkflowActionInterface
{
    public function run($context, $params, $engine)
    {
        $db = $this->getDb($engine);

        /**
         * Modify these in workflow_steps.parameters_json:
         *
         * table        = logs table name
         * date_field   = datetime or unix timestamp field
         * date_type    = datetime or unix
         * since_hours  = how far back to report
         * level_field  = optional field such as level, severity, type
         * levels       = optional list of values to include
         * limit        = max rows
         */
        $table = $engine->safeIdentifier($params['table'] ?? 'logs');
        $dateField = $engine->safeIdentifier($params['date_field'] ?? 'created_at');
        $dateType = strtolower($params['date_type'] ?? 'datetime');
        $sinceHours = max(1, (int)($params['since_hours'] ?? 24));
        $limit = min(1000, max(1, (int)($params['limit'] ?? 100)));
        $levelField = isset($params['level_field']) ? $engine->safeIdentifier($params['level_field']) : '';
        $levels = isset($params['levels']) && is_array($params['levels']) ? $params['levels'] : array();

        $where = array();

        if ($dateType === 'unix') {
            $cutoff = time() - ($sinceHours * 3600);
            $where[] = "$dateField >= '" . (int)$cutoff . "'";
        } else {
            $cutoff = date('Y-m-d H:i:s', time() - ($sinceHours * 3600));
            $where[] = "$dateField >= '" . $engine->escape($cutoff) . "'";
        }

        if ($levelField && count($levels) > 0) {
            $safeLevels = array();
            foreach ($levels as $level) {
                $safeLevels[] = "'" . $engine->escape($level) . "'";
            }
            $where[] = "$levelField IN (" . implode(',', $safeLevels) . ")";
        }

        $sql = "SELECT * FROM $table WHERE " . implode(' AND ', $where) . " ORDER BY $dateField DESC LIMIT " . (int)$limit;

        $rows = $db->rows($sql);

        $context['report_logs'] = array(
            'table' => $table,
            'date_field' => $dateField,
            'since_hours' => $sinceHours,
            'count' => count($rows),
            'rows' => $rows
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
