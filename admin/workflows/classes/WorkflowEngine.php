<?php
/**
 * User Manager 3.0 Workflow Engine
 * Complete Phase 2 Fixed Version
 *
 * Expected location:
 * /admin/workflows/classes/WorkflowEngine.php
 *
 * Expected folder layout:
 * /admin/workflows/
 *     registry.php
 *     actions/
 *     classes/
 *         WorkflowActionInterface.php
 *         WorkflowEngine.php
 *     database/
 *     examples/
 *
 * Expected database tables:
 * - workflows_main
 * - workflows_steps
 * - workflows_runs
 * - workflows_logs
 *
 * Important fixes in this version:
 * - Constructor accepts one argument: new WorkflowEngine($d)
 * - Constructor still supports two arguments: new WorkflowEngine($d, $actions)
 * - Loads /admin/workflows/registry.php automatically when actions are not passed
 * - Uses workflows_main instead of um_workflows
 * - Uses workflows_steps instead of um_workflow_steps
 * - Uses workflows_runs instead of um_workflow_runs
 * - Uses workflows_logs instead of um_workflow_run_logs
 * - Uses workflows_steps.parameters instead of parameters_json
 * - Uses workflows_runs.key, started, finished, created
 * - Uses workflows_logs.data, created
 * - Loads WorkflowActionInterface automatically
 * - Loads action files automatically from /admin/workflows/actions/
 * - Logs failed steps before marking the workflow run as failed
 */
class WorkflowEngine
{
    /**
     * Existing User Manager database helper, usually $d.
     */
    protected $db;

    /**
     * Registry of action keys to PHP class names.
     */
    protected $actions = array();

    /**
     * Current workflows_runs.id value.
     */
    protected $runId = 0;

    /**
     * Workflow root folder.
     */
    protected $workflowRoot = '';

    /**
     * Constructor.
     *
     * Safe usage:
     *
     *     $engine = new WorkflowEngine($d);
     *
     * Optional explicit registry usage:
     *
     *     $registry = require(DOCROOT . '/admin/workflows/registry.php');
     *     $engine = new WorkflowEngine($d, $registry);
     *
     * @param object $db
     * @param array  $actions
     */
    public function __construct($db, $actions = array())
    {
        $this->db = $db;

        if (defined('DOCROOT')) {
            $this->workflowRoot = DOCROOT . '/admin/workflows';
        } else {
            $this->workflowRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/admin/workflows';
        }

        $this->loadInterface();

        if (empty($actions)) {
            $actions = $this->loadRegistry();
        }

        if (!is_array($actions)) {
            throw new Exception('Workflow registry must return an array.');
        }

        $this->actions = $actions;
    }

    /**
     * Run a workflow by key.
     *
     * Example:
     *
     *     $engine->run('test.workflow');
     *     $engine->run('cleanup.old.sessions');
     *
     * @param string $workflowKey
     * @param array  $context
     *
     * @return array
     */
    public function run($workflowKey, $context = array())
    {
        $workflowKey = $this->safeKey($workflowKey);

        if (!is_array($context)) {
            $context = array();
        }

        $workflow = $this->db->row(
            "SELECT *
             FROM workflows_main
             WHERE `key` = '" . $this->escape($workflowKey) . "'
               AND enabled = 1
             LIMIT 1"
        );

        if (!$workflow) {
            throw new Exception('Workflow not found or disabled: ' . $workflowKey);
        }

        $this->runId = $this->startRun(
            (int)$workflow->id,
            $workflowKey
        );

        $context['_workflow'] = array(
            'id'      => (int)$workflow->id,
            'key'     => $workflowKey,
            'name'    => isset($workflow->name) ? $workflow->name : '',
            'run_id'  => (int)$this->runId,
            'started' => date('Y-m-d H:i:s')
        );

        try {
            $steps = $this->db->rows(
                "SELECT *
                 FROM workflows_steps
                 WHERE workflow_id = '" . (int)$workflow->id . "'
                   AND enabled = 1
                 ORDER BY position ASC, id ASC"
            );

            if (!is_array($steps)) {
                $steps = array();
            }

            foreach ($steps as $step) {
                $context = $this->runStep(
                    $step,
                    $context
                );
            }

            $this->finishRun(
                'success',
                'Workflow completed.'
            );

            return $context;
        } catch (Exception $e) {
            $this->finishRun(
                'failed',
                $e->getMessage()
            );

            throw $e;
        }
    }

    /**
     * Run one workflow step.
     *
     * @param object $step
     * @param array  $context
     *
     * @return array
     */
    protected function runStep($step, $context)
    {
        if (!isset($step->id)) {
            throw new Exception('Invalid workflow step record.');
        }

        if (!isset($step->action_key)) {
            throw new Exception('Workflow step is missing action_key.');
        }

        $stepId = (int)$step->id;
        $actionKey = $this->safeKey($step->action_key);

        if (!isset($this->actions[$actionKey])) {
            throw new Exception('Action is not registered: ' . $actionKey);
        }

        $paramsJson = '';

        if (isset($step->parameters)) {
            $paramsJson = $step->parameters;
        } elseif (isset($step->parameters_json)) {
            /**
             * Backward-compatible fallback.
             * The current Phase 1 schema uses parameters.
             */
            $paramsJson = $step->parameters_json;
        }

        $params = $this->decodeParams($paramsJson);

        $className = trim((string)$this->actions[$actionKey]);
        $className = $this->safeClassName($className);

        $this->loadActionClass($className);

        if (!class_exists($className)) {
            throw new Exception('Action class not found: ' . $className);
        }

        $action = new $className();

        if (!($action instanceof WorkflowActionInterface)) {
            throw new Exception('Action must implement WorkflowActionInterface: ' . $className);
        }

        $this->logStep(
            $stepId,
            $actionKey,
            'started',
            'Step started.'
        );

        try {
            $result = $action->run(
                $context,
                $params,
                $this
            );

            if ($result !== null) {
                if (!is_array($result)) {
                    throw new Exception('Workflow action must return an array or null: ' . $className);
                }

                $context = $result;
            }

            $this->logStep(
                $stepId,
                $actionKey,
                'success',
                'Step completed.'
            );

            return $context;
        } catch (Exception $e) {
            $this->logStep(
                $stepId,
                $actionKey,
                'failed',
                $e->getMessage()
            );

            throw $e;
        }
    }

    /**
     * Load the workflow action registry.
     *
     * Expected file:
     * /admin/workflows/registry.php
     *
     * Expected return:
     *
     * return array(
     *     'test.echo' => 'Action_TestEcho'
     * );
     *
     * @return array
     */
    protected function loadRegistry()
    {
        $registryFile = $this->workflowRoot . '/registry.php';

        if (!is_file($registryFile)) {
            throw new Exception('Workflow registry not found: ' . $registryFile);
        }

        $registry = require($registryFile);

        if (!is_array($registry)) {
            throw new Exception('Workflow registry must return an array: ' . $registryFile);
        }

        return $registry;
    }

    /**
     * Load WorkflowActionInterface if needed.
     */
    protected function loadInterface()
    {
        if (interface_exists('WorkflowActionInterface')) {
            return;
        }

        $interfaceFile = $this->workflowRoot . '/classes/WorkflowActionInterface.php';

        if (is_file($interfaceFile)) {
            require_once($interfaceFile);
        }

        if (!interface_exists('WorkflowActionInterface')) {
            throw new Exception('WorkflowActionInterface not found: ' . $interfaceFile);
        }
    }

    /**
     * Load an action class file when the class is not already loaded.
     *
     * Example:
     * Action_TestEcho loads:
     * /admin/workflows/actions/Action_TestEcho.php
     *
     * @param string $className
     */
    protected function loadActionClass($className)
    {
        if (class_exists($className)) {
            return;
        }

        $file = $this->workflowRoot . '/actions/' . $className . '.php';

        if (is_file($file)) {
            require_once($file);
        }
    }

    /**
     * Decode JSON step parameters.
     *
     * Empty value returns an empty array.
     *
     * @param string $json
     *
     * @return array
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
     *
     * Note:
     * The workflows_runs table has a column named `key`.
     * Because the existing database helper does not backtick insert fields,
     * this method uses a manual INSERT so `key` is quoted safely.
     *
     * @param int    $workflowId
     * @param string $workflowKey
     *
     * @return int
     */
    protected function startRun($workflowId, $workflowKey)
    {
        $now = date('Y-m-d H:i:s');

        $this->db->query(
            "INSERT INTO workflows_runs
             (
                 workflow_id,
                 `key`,
                 status,
                 started,
                 created
             )
             VALUES
             (
                 '" . (int)$workflowId . "',
                 '" . $this->escape($workflowKey) . "',
                 'running',
                 '" . $this->escape($now) . "',
                 '" . $this->escape($now) . "'
             )"
        );

        $runId = (int)$this->db->field('SELECT LAST_INSERT_ID()');

        if ($runId <= 0) {
            throw new Exception('Unable to create workflow run record.');
        }

        return $runId;
    }

    /**
     * Finish the current workflow run.
     *
     * @param string $status
     * @param string $message
     */
    protected function finishRun($status, $message)
    {
        if (!$this->runId) {
            return;
        }

        $this->db->update('workflows_runs', array(
            'status'   => $status,
            'message'  => $message,
            'finished' => date('Y-m-d H:i:s')
        ), array(
            'id' => (int)$this->runId
        ));
    }

    /**
     * Write a workflow log entry.
     *
     * @param int    $stepId
     * @param string $actionKey
     * @param string $status
     * @param string $message
     * @param mixed  $data
     */
    public function logStep($stepId, $actionKey, $status, $message, $data = null)
    {
        if (!$this->runId) {
            return;
        }

        $this->db->insert('workflows_logs', array(
            'run_id'     => (int)$this->runId,
            'step_id'    => $stepId ? (int)$stepId : null,
            'action_key' => $actionKey,
            'status'     => $status,
            'message'    => $message,
            'data'       => $data === null ? null : json_encode($data),
            'created'    => date('Y-m-d H:i:s')
        ));
    }

    /**
     * General workflow log helper for non-step logs.
     *
     * @param string $status
     * @param string $message
     * @param mixed  $data
     */
    public function log($status, $message, $data = null)
    {
        $this->logStep(
            0,
            null,
            $status,
            $message,
            $data
        );
    }

    /**
     * Return current run id.
     *
     * @return int
     */
    public function getRunId()
    {
        return (int)$this->runId;
    }

    /**
     * Return database helper.
     * Useful for action classes that need controlled database access.
     *
     * @return object
     */
    public function getDb()
    {
        return $this->db;
    }

    /**
     * Escape text using the existing DB helper.
     *
     * @param mixed $value
     *
     * @return string
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
     *
     * @param string $key
     *
     * @return string
     */
    public function safeKey($key)
    {
        $key = trim((string)$key);

        if ($key === '') {
            throw new Exception('Key cannot be empty.');
        }

        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $key)) {
            throw new Exception('Invalid key: ' . $key);
        }

        return $key;
    }

    /**
     * Validate action class names.
     *
     * This prevents registry values from becoming arbitrary file paths.
     *
     * @param string $className
     *
     * @return string
     */
    public function safeClassName($className)
    {
        $className = trim((string)$className);

        if ($className === '') {
            throw new Exception('Action class name cannot be empty.');
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $className)) {
            throw new Exception('Invalid action class name: ' . $className);
        }

        return $className;
    }

    /**
     * Validate SQL identifier such as table or field name.
     *
     * This is useful for action classes that accept table/field names in
     * workflow step JSON parameters.
     *
     * @param string $name
     *
     * @return string
     */
    public function safeIdentifier($name)
    {
        $name = trim((string)$name);

        if ($name === '') {
            throw new Exception('SQL identifier cannot be empty.');
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new Exception('Invalid SQL identifier: ' . $name);
        }

        return $name;
    }
}
?>
