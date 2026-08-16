<?php
/**
 * User Manager 3.0 Workflow Action Interface
 *
 * Every reusable workflow action must implement this interface.
 *
 * The engine does not need to know how each action works.
 * It only needs to know that every action has a run() method.
 */
interface WorkflowActionInterface
{
    /**
     * Run one workflow step.
     *
     * @param array $context Shared workflow data passed between steps.
     * @param array $params  Step parameters from workflow_steps.parameters_json.
     * @param object $engine Current workflow engine instance.
     *
     * @return array Updated workflow context.
     */
    public function run($context, $params, $engine);
}
?>
