<?php
class Action_VerificationCleanup implements WorkflowActionInterface
{
	public function run($context, $params, $engine)
	{
		global $d;

		$hours = (isset($params['hours']) ? (int)$params['hours'] : 1);

		if ($hours <= 0) {
			$hours = 1;
		}

		$d->query(
			'DELETE
			 FROM verification
			 WHERE created < NOW() - INTERVAL ' . $hours . ' HOUR'
		);

		$context['verification_cleanup'] = array(
			'hours' => $hours
		);

		return $context;
	}
}