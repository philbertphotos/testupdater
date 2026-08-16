<?php
/**
 * User Manager 3.0 Workflow Engine
 *
 * This is the core instruction-matrix engine.
 *
 * It loads a workflow by key, reads the ordered steps from the database,
 * finds the matching reusable PHP action, and executes each action.
 *
 * The goal is to move process-specific logic into data:
 * - workflow key
 * - step order
 * - action key
 * - JSON parameters
 *
 * You should not edit this class for every new task.
 * Add new workflows in the database and add only reusable actions as needed.
 */
class WorkflowEngine
{
    protected $db;
    protected $actions;
    protected $runId = 0;

    /**
     * @param object $db      Existing User Manager database helper, usually $d.
     * @param array  $actions Action registry from config/workflow_actions.php.
     */
    public function __construct($db, $actions)
    {
        $this->db = $db;
        $this->actions = $actions;
    }

    /**
     * Run a workflow by workflow key.
     *
     * Example:
     * $engine->run('report.admin.logs');
     */
    public function run($workflowKey, $context = array())
    {
        $workflowKey = $this->safeKey($workflowKey);

        $workflow = $this->db->row("SELECT * FROM um_workflows WHERE workflow_key = '" . $this->escape($workflowKey) . "' AND enabled = 1 LIMIT 1");

        if (!$workflow) {
            throw new Exception('Workflow not found or disabled: ' . $workflowKey);
        }

        $this->runId = $this->startRun($workflow->id, $workflowKey);

        $context['_workflow'] = array(
            'id' => (int)$workflow->id,
            'key' => $workflowKey,
            'name' => $workflow->name,
            'run_id' => $this->runId,
            'started' => date('Y-m-d H:i:s')
        );

        try {
            $steps = $this->db->rows("SELECT * FROM um_workflow_steps WHERE workflow_id = '" . (int)$workflow->id . "' AND enabled = 1 ORDER BY position ASC, id ASC");

            foreach ($steps as $step) {
                $context = $this->runStep($step, $context);
            }

            $this->finishRun('success', 'Workflow completed.');
            return $context;
        } catch (Exception $e) {
            $this->finishRun('failed', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Run one workflow step.
     */
    protected function runStep($step, $context)
    {
        $actionKey = $this->safeKey($step->action_key);

        if (!isset($this->actions[$actionKey])) {
            throw new Exception('Action is not registered: ' . $actionKey);
        }

        $params = $this->decodeParams($step->parameters_json);
        $className = $this->actions[$actionKey];

        if (!class_exists($className)) {
            throw new Exception('Action class not found: ' . $className);
        }

        $action = new $className();

        if (!($action instanceof WorkflowActionInterface)) {
            throw new Exception('Action must implement WorkflowActionInterface: ' . $className);
        }

        $this->logStep((int)$step->id, $actionKey, 'started', 'Step started.');
        $context = $action->run($context, $params, $this);
        $this->logStep((int)$step->id, $actionKey, 'success', 'Step completed.');

        return $context;
    }

    /**
     * Decode JSON step parameters.
     */
    protected function decodeParams($json)
    {
        $json = trim((string)$json);

        if ($json === '') {
            return array();
        }

        $params = json_decode($json, true);

        if (!is_array($params)) {
            throw new Exception('Invalid workflow step JSON parameters.');
        }

        return $params;
    }

    /**
     * Start a workflow run record.
     */
    protected function startRun($workflowId, $workflowKey)
    {
        return $this->db->insert('um_workflow_runs', array(
            'workflow_id' => (int)$workflowId,
            'workflow_key' => $workflowKey,
            'status' => 'running',
            'started_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s')
        ));
    }

    /**
     * Finish a workflow run record.
     */
    protected function finishRun($status, $message)
    {
        if (!$this->runId) {
            return;
        }

        $this->db->update('um_workflow_runs', array(
            'status' => $status,
            'message' => $message,
            'finished_at' => date('Y-m-d H:i:s')
        ), array(
            'id' => (int)$this->runId
        ));
    }

    /**
     * Write step log.
     */
    public function logStep($stepId, $actionKey, $status, $message, $data = null)
    {
        $this->db->insert('um_workflow_run_logs', array(
            'run_id' => (int)$this->runId,
            'step_id' => (int)$stepId,
            'action_key' => $actionKey,
            'status' => $status,
            'message' => $message,
            'data_json' => $data === null ? null : json_encode($data),
            'created_at' => date('Y-m-d H:i:s')
        ));
    }

    /**
     * Escape text using the existing DB helper.
     */
    public function escape($value)
    {
        if (method_exists($this->db, 'escapeString')) {
            return $this->db->escapeString($value);
        }

        return addslashes($value);
    }

    /**
     * Validate workflow/action keys.
     *
     * Allows:
     * - letters
     * - numbers
     * - dot
     * - underscore
     * - dash
     */
    public function safeKey($key)
    {
        $key = trim((string)$key);

        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $key)) {
            throw new Exception('Invalid key: ' . $key);
        }

        return $key;
    }

    /**
     * Validate SQL identifier such as table or field name.
     *
     * This prevents JSON parameters from injecting raw SQL.
     */
    public function safeIdentifier($name)
    {
        $name = trim((string)$name);

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new Exception('Invalid SQL identifier: ' . $name);
        }

        return $name;
    }
}
?>
