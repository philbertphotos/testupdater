<?php
/*************************************************************************
 * Action_SendEmail
 *************************************************************************
 * Workflow action key:
 * email.send
 *
 * Purpose:
 * Send a workflow email using the User Manager mail API and place the
 * result into the workflow context.
 *
 * Expected location:
 * /admin/workflows/actions/sendemail.php
 *
 * Expected PHP class:
 * Action_SendEmail
 *
 * Expected registry entry:
 * 'email.send' => 'Action_SendEmail'
 *
 * Expected workflow URL test:
 * /admin/workflows/api.php?key=email.send
 *
 * Basic step parameters example:
 *
 * {
 *     "to":"admin@example.com",
 *     "from":"noreply@example.com",
 *     "from_name":"User Manager",
 *     "subject":"User Manager Workflow Report",
 *     "body":"Workflow completed."
 * }
 *
 * Context body example:
 *
 * {
 *     "to":"admin@example.com",
 *     "from":"noreply@example.com",
 *     "from_name":"User Manager",
 *     "subject":"New User Standards Report",
 *     "body_key":"email_body"
 * }
 *
 * Attachments example:
 *
 * {
 *     "to":"admin@example.com",
 *     "from":"noreply@example.com",
 *     "subject":"User Manager Report",
 *     "body":"Attached is the workflow report.",
 *     "attachments":["/tmp/report.pdf"]
 * }
 *************************************************************************/
class Action_SendEmail implements WorkflowActionInterface
{
	/**
	 * Run action.
	 *
	 * @param array  $context
	 * @param array  $params
	 * @param object $engine
	 *
	 * @return array
	 */
	public function run($context, $params, $engine)
	{
		global $m;
		//return echo array('Mail API class not found: pssm_Mail');
		$this->loadMailApi();

		if (!class_exists('pssm_Mail')) {
			throw new Exception('Mail API class not found: pssm_Mail');
		}

		$mail = new pssm_Mail();
		$to = isset($params['to']) ? $params['to'] : '';
		$from = isset($params['from']) ? trim((string)$params['from']) : '';
		$fromName = isset($params['from_name']) ? trim((string)$params['from_name']) : '';
		$subject = $this->getTextValue($params, $context, 'subject', 'subject_key');
		$body = $this->getTextValue($params, $context, 'body', 'body_key');

		if ($body === '') {
			$body = $this->buildDefaultBody($context);
		}

		if ($from === '') {
			throw new Exception('Action_SendEmail requires a from address.');
		}

		if ($subject === '') {
			throw new Exception('Action_SendEmail requires a subject.');
		}

		if ($body === '') {
			throw new Exception('Action_SendEmail requires a body or body_key.');
		}

		$this->addRecipients($mail, $to);
		$this->setFrom($mail, $from, $fromName);

		$mail->setSubject($this->cleanHeaderValue($subject));
		$mail->setMessage($body);

		if (isset($params['attachments']) && is_array($params['attachments'])) {
			$this->addAttachments($mail, $params['attachments']);
		}

		if (isset($params['params']) && trim((string)$params['params']) !== '') {
			$mail->setParameters(trim((string)$params['params']));
		}

		$mail->addGenericHeader('X-Mailer', 'User Manager 3.0');

		$sent = $mail->send();

		if (!$sent) {
			throw new Exception('Action_SendEmail failed. mail() returned false.');
		}

		$context['email'] = array(
			'sent' => true,
			'to' => $this->normaliseRecipients($to),
			'from' => $from,
			'subject' => $subject
		);

		if (!isset($context['stats']) || !is_array($context['stats'])) {
			$context['stats'] = array();
		}

		if (!isset($context['stats']['emails_sent'])) {
			$context['stats']['emails_sent'] = 0;
		}

		$context['stats']['emails_sent']++;

		return $context;
	}

	/*************************************************************************
	 * Mail API Helpers
	 *************************************************************************/

	/**
	 * Load the User Manager mail API.
	 */
	protected function loadMailApi()
	{
		if (class_exists('pssm_Mail')) {
			return;
		}

		$paths = array();

		if (defined('DOCROOT')) {
			$paths[] = DOCROOT . '/api/api_mail.php';
		}

		foreach ($paths as $file) {
			if (is_file($file)) {
				require_once($file);

				if (class_exists('pssm_Mail')) {
					return;
				}
			}
		}
	}

	/**
	 * Set sender.
	 *
	 * @param object $mail
	 * @param string $email
	 * @param string $name
	 */
	protected function setFrom($mail, $email, $name)
	{
		$email = $this->cleanEmail($email);
		$name = $this->cleanHeaderValue($name);

		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw new Exception('Invalid from email address: ' . $email);
		}

		if ($name !== '') {
			$mail->setFrom($email, $name);
		} else {
			$mail->setFrom($email);
		}
	}

	/**
	 * Add recipients.
	 *
	 * @param object $mail
	 * @param mixed  $recipients
	 */
	protected function addRecipients($mail, $recipients)
	{
		$list = $this->normaliseRecipients($recipients);

		if (count($list) == 0) {
			throw new Exception('Action_SendEmail requires at least one recipient.');
		}

		foreach ($list as $item) {
			if ($item['name'] !== '') {
				$mail->setTo($item['email'], $item['name']);
			} else {
				$mail->setTo($item['email']);
			}
		}
	}

	/**
	 * Add attachments.
	 *
	 * @param object $mail
	 * @param array  $attachments
	 */
	protected function addAttachments($mail, $attachments)
	{
		foreach ($attachments as $file) {
			$file = trim((string)$file);

			if ($file === '') {
				continue;
			}

			if (!is_file($file) || !is_readable($file)) {
				throw new Exception('Email attachment not found or not readable: ' . $file);
			}

			$mail->addAttachment($file);
		}
	}

	/*************************************************************************
	 * Value Helpers
	 *************************************************************************/

	/**
	 * Get direct parameter value or context value by key.
	 *
	 * @param array  $params
	 * @param array  $context
	 * @param string $paramKey
	 * @param string $contextKey
	 *
	 * @return string
	 */
	protected function getTextValue($params, $context, $paramKey, $contextKey)
	{
		if (isset($params[$paramKey])) {
			return trim((string)$params[$paramKey]);
		}

		if (isset($params[$contextKey])) {
			$value = $this->contextValue($context, trim((string)$params[$contextKey]));

			if ($value !== null) {
				return trim((string)$value);
			}
		}

		return '';
	}

	/**
	 * Read a value from workflow context using a simple dot path.
	 *
	 * @param array  $context
	 * @param string $path
	 *
	 * @return mixed
	 */
	protected function contextValue($context, $path)
	{
		if ($path === '') {
			return null;
		}

		$parts = explode('.', $path);
		$value = $context;

		foreach ($parts as $part) {
			if (!is_array($value) || !array_key_exists($part, $value)) {
				return null;
			}

			$value = $value[$part];
		}

		return $value;
	}

	/**
	 * Build a default HTML body from workflow context.
	 *
	 * @param array $context
	 *
	 * @return string
	 */
	protected function buildDefaultBody($context)
	{
		$count = 0;
		$users = array();

		if (isset($context['new_users']) && is_array($context['new_users'])) {
			if (isset($context['new_users']['count'])) {
				$count = (int)$context['new_users']['count'];
			}

			if (isset($context['new_users']['users']) && is_array($context['new_users']['users'])) {
				$users = $context['new_users']['users'];
			}
		}

		$body = '<p>User Manager workflow completed.</p>';
		$body .= '<p>New users found: ' . (int)$count . '</p>';

		if (count($users) > 0) {
			$body .= '<ul>';

			foreach ($users as $user) {
				$name = isset($user['displayname']) ? $user['displayname'] : '';
				$sam = isset($user['samaccountname']) ? $user['samaccountname'] : '';

				$body .= '<li>' . htmlspecialchars($name . ' (' . $sam . ')', ENT_QUOTES, 'UTF-8') . '</li>';
			}

			$body .= '</ul>';
		}

		return $body;
	}

	/*************************************************************************
	 * Sanitizing Helpers
	 *************************************************************************/

	/**
	 * Normalize recipient input.
	 *
	 * @param mixed $recipients
	 *
	 * @return array
	 */
	protected function normaliseRecipients($recipients)
	{
		$list = array();

		if (is_string($recipients)) {
			$parts = explode(',', $recipients);

			foreach ($parts as $email) {
				$email = $this->cleanEmail($email);

				if ($email !== '') {
					$list[] = array('email' => $this->validateEmail($email), 'name' => '');
				}
			}
		}

		if (is_array($recipients)) {
			foreach ($recipients as $key => $value) {
				if (is_array($value)) {
					$email = isset($value['email']) ? $this->cleanEmail($value['email']) : '';
					$name = isset($value['name']) ? $this->cleanHeaderValue($value['name']) : '';

					if ($email !== '') {
						$list[] = array('email' => $this->validateEmail($email), 'name' => $name);
					}
				} elseif (is_numeric($key)) {
					$email = $this->cleanEmail($value);

					if ($email !== '') {
						$list[] = array('email' => $this->validateEmail($email), 'name' => '');
					}
				} else {
					$email = $this->cleanEmail($key);
					$name = $this->cleanHeaderValue($value);

					if ($email !== '') {
						$list[] = array('email' => $this->validateEmail($email), 'name' => $name);
					}
				}
			}
		}

		return $list;
	}

	/**
	 * Clean email string.
	 *
	 * @param string $email
	 *
	 * @return string
	 */
	protected function cleanEmail($email)
	{
		$email = trim((string)$email);
		$email = str_replace(array("\r", "\n"), '', $email);

		return $email;
	}

	/**
	 * Validate email string.
	 *
	 * @param string $email
	 *
	 * @return string
	 */
	protected function validateEmail($email)
	{
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw new Exception('Invalid email address: ' . $email);
		}

		return $email;
	}

	/**
	 * Clean header value.
	 *
	 * @param string $value
	 *
	 * @return string
	 */
	protected function cleanHeaderValue($value)
	{
		$value = trim((string)$value);
		$value = str_replace(array("\r", "\n"), ' ', $value);

		return $value;
	}
}
?>
