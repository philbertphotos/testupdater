<?php

class Action_TestEcho
    implements WorkflowActionInterface
{
    public function run(
        $context,
        $params,
        $engine
    )
    {
        echo '<pre>';
        print_r($context);
        echo '</pre>';

        return $context;
    }
}