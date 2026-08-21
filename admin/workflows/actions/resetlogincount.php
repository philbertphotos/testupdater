<?php
class Action_ResetLoginCount implements WorkflowActionInterface
{
	public function run($context, $params, $engine)
	{
		global $d;

		$minutes = (isset($params['minutes']) ? (int)$params['minutes'] : 20);

		if ($minutes <= 0) {
			$minutes = 20;
		}

		$d->query(
			'UPDATE sessions
			 SET attempts = 0,
				 flag = 0
			 WHERE flag = 1
			 AND lastlogin < NOW() - INTERVAL ' . $minutes . ' MINUTE'
		);

		$context['reset_login_count'] = array(
			'minutes' => $minutes
		);

		return $context;
	}
}