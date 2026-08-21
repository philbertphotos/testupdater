<?php
/*************************************************************************
 * Action_ClearAttempts
 *************************************************************************/
class Action_ClearAttempts implements WorkflowActionInterface
{
	public function run($context, $params, $engine)
	{
		global $d;

		$attempts = (isset($params['attempts']) ? (int)$params['attempts'] : 0);

		$d->query(
			'UPDATE sessions
			 SET attempts = 0,
				 flag = 0
			 WHERE attempts > ' . $attempts
		);

		$context['clear_attempts'] = array(
			'threshold' => $attempts
		);

		return $context;
	}
}