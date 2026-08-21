<?php
/*************************************************************************
 * Action_SendEmail
 *************************************************************************
 * Workflow action key:
 * email.send
 *
 * Replacement token format:
 * {context.path}
 *
 * Examples:
 * {verification_cleanup.hours}
 * {reset_login_count.minutes}
 * {stats.new_users}
 *
 * Replacement tokens are supported in:
 * to, from, from_name, subject, body, attachments and params.
 *
 * Body token values are HTML escaped before replacement. A body loaded with
 * body_key remains unchanged so a previous action can provide prepared HTML.
 *	 {
 *		"to":"{notification.email}",
 *		"from":"noreply@vi.gov",
 *		"subject":"Workflow completed for {notification.name}",
 *		"body":"<p>The workflow completed successfully.</p>"
 *	}
 *	{
 *		"to":"joseph.philbert@bit.vi.gov",
 *		"from":"noreply@vi.gov",
 *		"from_name":"User Manager",
 *		"subject_key":"email_subject",
 *		"body_key":"email_body"
 *	}
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
		$this->loadMailApi();

		if (!class_exists('pssm_Mail')) {
			throw new Exception('Mail API class not found: pssm_Mail');
		}

		$mail = new pssm_Mail();
		$to = isset($params['to']) ? $this->replaceValueTokens($params['to'], $context) : '';
		$from = isset($params['from']) ? $this->replaceTokens(trim((string)$params['from']), $context) : '';
		$fromName = isset($params['from_name']) ? $this->replaceTokens(trim((string)$params['from_name']), $context) : '';
		$subject = $this->getTextValue($params, $context, 'subject', 'subject_key');
		$body = $this->getTextValue($params, $context, 'body', 'body_key', true);

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
			$this->addAttachments($mail, $this->replaceValueTokens($params['attachments'], $context));
		}

		if (isset($params['params']) && trim((string)$params['params']) !== '') {
			$mail->setParameters($this->replaceTokens(trim((string)$params['params']), $context));
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
			$paths[] = DOCROOT . '/api_mail.php';
			$paths[] = DOCROOT . '/api/api_mail.php';
			$paths[] = DOCROOT . '/classes/api_mail.php';
			$paths[] = DOCROOT . '/includes/api_mail.php';
			$paths[] = DOCROOT . '/admin/api_mail.php';
			$paths[] = DOCROOT . '/admin/includes/api_mail.php';
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
	 * Replacement Token Helpers
	 *************************************************************************/

	/**
	 * Replace context tokens in a string.
	 *
	 * Token format:
	 * {context.path}
	 *
	 * Missing context paths are left unchanged so configuration mistakes are
	 * visible in the delivered message instead of being silently removed.
	 *
	 * @param string $value
	 * @param array  $context
	 * @param bool   $htmlEscape
	 *
	 * @return string
	 */
	protected function replaceTokens($value, $context, $htmlEscape = false)
	{
		$value = (string)$value;

		if ($value === '' || strpos($value, '{') === false) {
			return $value;
		}

		return preg_replace_callback(
			'/\{([a-zA-Z0-9_\.\-]+)\}/',
			function($matches) use ($context, $htmlEscape) {
				$replacement = $this->contextValue($context, $matches[1]);

				if ($replacement === null) {
					return $matches[0];
				}

				$replacement = $this->tokenValue($replacement);

				if ($htmlEscape) {
					$replacement = htmlspecialchars($replacement, ENT_QUOTES, 'UTF-8');
				}

				return $replacement;
			},
			$value
		);
	}

	/**
	 * Replace tokens recursively in string or array values.
	 *
	 * @param mixed $value
	 * @param array $context
	 *
	 * @return mixed
	 */
	protected function replaceValueTokens($value, $context)
	{
		if (is_array($value)) {
			$replaced = array();

			foreach ($value as $key => $item) {
				$newKey = is_string($key) ? $this->replaceTokens($key, $context) : $key;
				$replaced[$newKey] = $this->replaceValueTokens($item, $context);
			}

			return $replaced;
		}

		if (is_string($value)) {
			return $this->replaceTokens($value, $context);
		}

		return $value;
	}

	/**
	 * Convert token value to text.
	 *
	 * @param mixed $value
	 *
	 * @return string
	 */
	protected function tokenValue($value)
	{
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}

		if (is_array($value) || is_object($value)) {
			return json_encode($value);
		}

		return (string)$value;
	}

	/*************************************************************************
	 * Value Helpers
	 *************************************************************************/

	/**
	 * Get direct parameter value or context value by key.
	 *
	 * Direct subject and body values support replacement tokens. Values loaded
	 * through subject_key or body_key are returned unchanged for backwards
	 * compatibility with actions that already prepare complete email content.
	 *
	 * @param array  $params
	 * @param array  $context
	 * @param string $paramKey
	 * @param string $contextKey
	 * @param bool   $htmlEscapeTokens
	 *
	 * @return string
	 */
	protected function getTextValue($params, $context, $paramKey, $contextKey, $htmlEscapeTokens = false)
	{
		if (isset($params[$paramKey])) {
			return trim($this->replaceTokens((string)$params[$paramKey], $context, $htmlEscapeTokens));
		}

		if (isset($params[$contextKey])) {
			$value = $this->contextValue($context, trim((string)$params[$contextKey]));

			if ($value !== null) {
				return trim($this->tokenValue($value));
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
