<?php
// #################################################################################
//
// File : mail.class.inc.php
// Class Description : This class is used to produce HTML format emails, with
// the ability to attach files and embed images.
//
/*
 * Mail API
 * @author Joseph Philbert <joe@philbertphotos.com>
 * @license http://opensource.org/licenses/MIT
 * @version 2.2.0
 */
// #################################################################################
class pssm_Mail {
	/**
	 * @var int $_wrap
	 */
	protected $_wrap = 78;

	protected $_to = array();
	protected $_subject;
	protected $_message;
	protected $_headers = array();
	protected $_params;
	protected $_attachments = array();
	protected $_uid;
	protected $_reluid;
	protected $_altuid;

	// SMTP settings are declared to prevent dynamic property notices in newer PHP versions.
	public $server;
	public $port;
	public $user;
	public $pw;
	public $server_ehlo = array();
	public $type;
	public $hostname;
	public $crypto;
	public $smtp_try;
	public $srv_ret = array();

	public function __construct() {
		$this->reset();
	}

	/**
	 * reset
	 * Resets all properties to initial state.
	 */
	public function reset() {
		$this->_to          = array();
		$this->_headers     = array();
		$this->_subject     = null;
		$this->_message     = null;
		$this->_wrap        = 78;
		$this->_params      = null;
		$this->_attachments = array();
		$this->_uid         = $this->getUniqueId();
		$this->_reluid      = $this->getUniqueId();
		$this->_altuid      = $this->getUniqueId();

		// SMTP settings are kept for future SMTP transport support.
		$this->server      = null;
		$this->port        = null;
		$this->user        = null;
		$this->pw          = null;
		$this->server_ehlo = array();
		$this->type        = 1; // 1 = custom smtp() class, 0 = PHP mail(). Current send() still uses PHP mail().
		$this->hostname    = php_uname('n'); // Best guess. Some servers may require a valid RDNS name.
		$this->crypto      = 'starttls';
		$this->smtp_try    = true;
		$this->srv_ret     = array(
			'last' => '',
			'all'  => '',
			'full' => ''
		);

		return $this;
	}

	/**
	 * specify the crypto type for the connection. defaults to STARTTLS
	 * @param string $type=starttls can be [none],[tls],[starttls],[ssl]
	 */
	public function set_crypto($type = 'starttls') {
		$type = strtolower((string) $type);

		switch ($type) {
			case 'none':
				$this->crypto = 'none';
				break;
			case 'tls':
				$this->crypto = 'tls';
				break;
			case 'ssl':
				$this->crypto = 'ssl';
				break;
			default:
				$this->crypto = 'starttls';
				break;
		}

		return $this;
	}

	/**
	 * will reorder an array by placing $first_string as key [0] then the rest but
	 * skipping any $value == $first_string
	 * @param array &$source_array array to work with
	 * @param string $first_string string to be placed at position zero
	 */
	private function reorder_array(&$source_array, $first_string) {
		$ret = array($first_string);

		foreach ($source_array as $val) {
			if ($val != $first_string) {
				$ret[] = $val;
			}
		}

		$source_array = $ret;
	}

	/**
	 * this connection functions can try different connection methods before failing
	 * NOTE: SMTP transport is not wired into send() yet.
	 */
	private function smtp_connect() {
		if ($this->smtp_try === true) {
			$order = array('starttls', 'tls', 'ssl'); // Do not default to no encryption.

			switch ($this->crypto) {
				case 'starttls':
					$this->reorder_array($order, 'starttls');
					break;
				case 'tls':
					$this->reorder_array($order, 'tls');
					break;
				case 'ssl':
					$this->reorder_array($order, 'ssl');
					break;
				case 'none':
					$this->reorder_array($order, 'none');
					break;
			}
		} else {
			$order = array($this->crypto);
		}

		foreach ($order as $crypto_type) {
			switch ($crypto_type) {
				case 'starttls':
					$server_type = '';
					$this->set_crypto('starttls');
					$this->srv_ret['full'] .= "notice: will attempt to switch to a tls secured connection after EHLO\n";
					break;
				case 'tls':
					$server_type = 'tls://';
					$this->set_crypto('tls');
					$this->srv_ret['full'] .= "notice: will create a tls secured connection to your server\n";
					break;
				case 'ssl':
					$server_type = 'ssl://';
					$this->set_crypto('ssl');
					$this->srv_ret['full'] .= "notice: will create a SSL secured connection to your server\n";
					break;
				case 'none':
					$server_type = '';
					$this->set_crypto('none');
					$this->srv_ret['full'] .= "WARNING: your connection will NOT be encrypted! Please consider a different configuration!\n";
					break;
				default:
					$this->srv_ret['full'] .= "ERROR: Invalid crypto type: " . $crypto_type . "\n";
					return false;
			}

			if (!($socket = @fsockopen($server_type . $this->server, $this->port, $errno, $errstr, 5))) {
				if ($errno == 10060) {
					$this->srv_ret['last'] = 'ERROR: Unable to connect to SMTP server ' . $server_type . $this->server . '. Please check the URL and the port setting. (' . $errstr . ')';
				} else {
					$this->srv_ret['last'] = 'ERROR: Unable to connect to SMTP server ' . $server_type . $this->server . ' (' . $errstr . ')';
				}

				$this->srv_ret['all']  .= $this->srv_ret['last'] . "\n";
				$this->srv_ret['full'] .= $this->srv_ret['last'] . "\n";

				if ($this->smtp_try === false) {
					return false;
				}
			} else {
				$ret = $this->server_parse($socket);
				$this->srv_ret['last'] = trim($ret);
				$this->srv_ret['all']  .= $this->srv_ret['last'] . "\n";
				$this->srv_ret['full'] .= "notice: connected to server\nreceived: " . $this->srv_ret['last'] . "\n";

				if ($this->expected_return($ret, '220') !== true) {
					return false;
				}

				return $socket;
			}

			$server_type = '';
			unset($socket);
		}

		$this->srv_ret['last'] = "ERROR: All connection attempts failed! Please check your configuration. Aborting\n";
		$this->srv_ret['all']  .= $this->srv_ret['last'] . "\n";
		$this->srv_ret['full'] .= $this->srv_ret['last'] . "\n";

		return false;
	}

	/**
	 * setTo
	 * @param string $email The email address to send to.
	 * @param string $name  The name of the person to send to.
	 */
	public function setTo($email, $name = '') {
		$this->_to[] = $this->formatHeader((string) $email, (string) $name);
		return $this;
	}

	/**
	 * getTo
	 * Return an array of formatted To addresses.
	 */
	public function getTo() {
		return $this->_to;
	}

	/**
	 * setSubject
	 * @param string $subject The email subject
	 */
	public function setSubject($subject) {
		$this->_subject = $this->filterOther((string) $subject);
		return $this;
	}

	/**
	 * getSubject function.
	 * @return string
	 */
	public function getSubject() {
		return $this->_subject;
	}

	/**
	 * setMessage
	 * @param string $message The message to send.
	 */
	public function setMessage($message, $inline = false) {
		if ($inline) {
			$this->_message = $this->getBase64Image($message);
		} else {
			$this->_message = preg_replace("(\r\n|\r|\n)", PHP_EOL, $message);
		}

		return $this;
	}

	/**
	 * getMessage
	 * @return string
	 */
	public function getMessage() {
		return $this->_message;
	}

	/**
	 * getBase64Image
	 * Converts base64 inline image sources to CID embedded images.
	 */
	public function getBase64Image($message) {
		$str = '';

		if (!empty($message)) {
			preg_match_all('/<img[^>]+>/i', stripcslashes($message), $imgTags);

			for ($i = 0; $i < count($imgTags[0]); $i++) {
				preg_match('/src="([^"]+)/i', $imgTags[0][$i], $withSrc);

				if (empty($withSrc[0])) {
					continue;
				}

				$withoutSrc = str_ireplace('src="', '', $withSrc[0]);

				if (strpos($withoutSrc, ';base64,') !== false) {
					list($type, $data) = explode(';base64,', $withoutSrc, 2);
					list($part, $ext)  = explode('/', $type, 2);

					$cid = $this->addEmbedbase64($data, $ext);
					$str = str_replace($withoutSrc, 'cid:' . $cid, $message);
					$str = preg_replace("(\r\n|\r|\n)", PHP_EOL, $str);
				}
			}
		}

		return (!empty($str) ? $str : $message);
	}

	/**
	 * addAttachment
	 * @param string $path     The file path to the attachment.
	 * @param string $filename The filename of the attachment when emailed.
	 */
	public function addAttachment($path, $filename = null) {
		if (!is_file($path) || !is_readable($path)) {
			throw new RuntimeException('Unable to attach file. File does not exist or is not readable: ' . $path);
		}

		$filename = empty($filename) ? basename($path) : $filename;

		$this->_attachments[] = array(
			'path' => $path,
			'file' => $filename,
			'cid'  => '',
			'ext'  => '',
			'size' => filesize($path),
			'data' => $this->getAttachmentData($path)
		);

		return $this;
	}

	/**
	 * addEmbedbase64
	 * Adds a base64 image as an embedded CID attachment.
	 */
	public function addEmbedbase64($data, $ext) {
		$basename = $this->getRandomFilename();
		$filename = $basename . '.' . $ext;
		$cid      = md5(uniqid(time()));

		$this->_attachments[] = array(
			'path' => '',
			'file' => $filename,
			'cid'  => $cid,
			'ext'  => $ext,
			'size' => strlen(base64_decode($data)),
			'data' => chunk_split($data)
		);

		return $cid;
	}

	/**
	 * getAttachmentData
	 * @param string $path The path to the attachment file.
	 */
	public function getAttachmentData($path) {
		$filesize   = filesize($path);
		$handle     = fopen($path, 'r');
		$attachment = fread($handle, $filesize);
		fclose($handle);

		return chunk_split(base64_encode($attachment));
	}

	/**
	 * getRandomFilename
	 * Creates a random filename for embedded image attachments.
	 */
	public function getRandomFilename() {
		$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ123456789_';
		$name  = '';
		$max   = strlen($chars) - 1;

		for ($i = 0; $i < 13; $i++) {
			$name .= $chars[rand(0, $max)];
		}

		return $name;
	}

	/**
	 * setFrom
	 * @param string $email The email to send as from.
	 * @param string $name  The name to send as from.
	 */
	public function setFrom($email, $name = '') {
		$this->addMailHeader('From', (string) $email, (string) $name);
		return $this;
	}

	/**
	 * addMailHeader
	 * @param string $header The header to add.
	 * @param string $email  The email to add.
	 * @param string $name   The name to add.
	 */
	public function addMailHeader($header, $email = null, $name = null) {
		$address          = $this->formatHeader((string) $email, (string) $name);
		$this->_headers[] = sprintf('%s: %s', (string) $header, $address);

		return $this;
	}

	/**
	 * addGenericHeader
	 * @param string $header The generic header to add.
	 * @param mixed  $value  The value of the header.
	 */
	public function addGenericHeader($header, $value) {
		$this->_headers[] = sprintf('%s: %s', (string) $header, (string) $value);
		return $this;
	}

	/**
	 * getHeaders
	 * Return the headers registered so far as an array.
	 */
	public function getHeaders() {
		return $this->_headers;
	}

	/**
	 * setAdditionalParameters
	 * Such as "-f youremail@yourserver.com"
	 * @param string $additionalParameters The additional mail parameter.
	 */
	public function setParameters($additionalParameters) {
		$this->_params = (string) $additionalParameters;
		return $this;
	}

	/**
	 * getAdditionalParameters
	 */
	public function getParameters() {
		return $this->_params;
	}

	/**
	 * setWrap
	 * @param int $wrap The number of characters at which the message will wrap.
	 */
	public function setWrap($wrap = 78) {
		$wrap = (int) $wrap;

		if ($wrap < 1) {
			$wrap = 78;
		}

		$this->_wrap = $wrap;
		return $this;
	}

	/**
	 * getWrap
	 */
	public function getWrap() {
		return $this->_wrap;
	}

	/**
	 * hasAttachments
	 * Checks if the email has any registered attachments and returns bool
	 */
	public function hasAttachments() {
		return !empty($this->_attachments);
	}

	/**
	 * assemble Message Headers
	 */
	public function assembleMesageHeaders() {
		$head   = array();
		$head[] = 'MIME-Version: 1.0';

		if ($this->hasAttachments()) {
			$head[] = "Content-Type: multipart/related; boundary=\"{$this->_uid}\"";
		} else {
			$head[] = "Content-Type: multipart/alternative; boundary=\"{$this->_uid}\"";
		}

		return join(PHP_EOL, $head);
	}

	/**
	 * assembleAttachmentBody
	 */
	public function assembleAttachmentBody() {
		$body   = array();
		$body[] = "--{$this->_uid}";
		$body[] = "Content-Type: multipart/alternative; boundary=\"{$this->_altuid}\"" . PHP_EOL;
		$body[] = "--{$this->_altuid}";
		$body[] = 'Content-type:text/plain; charset="UTF-8"';
		$body[] = 'Content-Transfer-Encoding: 7bit' . PHP_EOL;
		$body[] = $this->strip_html_tags($this->getMessage()) . PHP_EOL;
		$body[] = "--{$this->_altuid}";
		$body[] = 'Content-type:text/html; charset="UTF-8"';
		$body[] = 'Content-Transfer-Encoding: 7bit' . PHP_EOL;
		$body[] = $this->getMessage() . PHP_EOL;
		$body[] = "--{$this->_altuid}--" . PHP_EOL;

		foreach ($this->_attachments as $attachment) {
			$body[] = $this->getAttachmentMimeTemplate($attachment);
		}

		$body[] = "--{$this->_uid}--";

		return implode(PHP_EOL, $body);
	}

	/**
	 * assembleHtmlBody
	 */
	public function assembleHtmlBody() {
		$body   = array();
		$body[] = 'This is a multi-part message in MIME format.' . PHP_EOL;
		$body[] = "--{$this->_uid}";
		$body[] = 'Content-type:text/plain; charset="UTF-8"';
		$body[] = 'Content-Transfer-Encoding: 7bit' . PHP_EOL;
		$body[] = $this->strip_html_tags($this->getMessage()) . PHP_EOL;
		$body[] = "--{$this->_uid}";
		$body[] = 'Content-type:text/html; charset="UTF-8"';
		$body[] = 'Content-Transfer-Encoding: 7bit' . PHP_EOL;
		$body[] = $this->getMessage() . PHP_EOL;
		$body[] = "--{$this->_uid}--";

		return implode(PHP_EOL, $body);
	}

	/**
	 * getAttachmentMimeTemplate
	 * @param array $attachment An array containing attachment data.
	 */
	public function getAttachmentMimeTemplate($attachment) {
		$file = isset($attachment['file']) ? $attachment['file'] : '';
		$data = isset($attachment['data']) ? $attachment['data'] : '';
		$cid  = isset($attachment['cid']) ? $attachment['cid'] : '';
		$ext  = isset($attachment['ext']) ? $attachment['ext'] : '';
		$size = isset($attachment['size']) ? $attachment['size'] : 0;
		$head = array();

		$head[] = "--{$this->_uid}";

		if (!empty($cid)) {
			// Embedded images should use Content-ID and inline disposition.
			$head[] = "Content-ID: <{$cid}>";
			$head[] = "Content-Type: image/{$ext}; name=\"{$file}\"; size={$size};";
			$head[] = "Content-Disposition: inline; filename=\"{$file}\"";
			$head[] = 'Content-Transfer-Encoding: base64' . PHP_EOL;
		} else {
			$head[] = 'Content-Type: ' . $this->getMimeType($file) . "; name=\"{$file}\"";
			$head[] = "Content-Disposition: attachment; filename=\"{$file}\"";
			$head[] = 'Content-Transfer-Encoding: base64' . PHP_EOL;
		}

		$head[] = $data . PHP_EOL;

		return implode(PHP_EOL, $head);
	}

	/**
	 * send
	 * @throws RuntimeException when no To address has been set.
	 * @return boolean
	 */
	public function send() {
		$to      = $this->getToForSend();
		$headers = $this->getHeadersForSend();

		if (empty($to)) {
			throw new RuntimeException('Unable to send, no To address has been set.');
		}

		$headers .= PHP_EOL . $this->assembleMesageHeaders();

		if ($this->hasAttachments()) {
			$message = $this->assembleAttachmentBody();
		} else {
			$message = $this->assembleHtmlBody();
		}

		/*
		 * SMTP transport.
		 * type = 1 means use custom SMTP transport when a server is configured.
		 * type = 0 means force PHP mail().
		 */
		if ((int) $this->type === 1 && !empty($this->server)) {
			return $this->smtp_send($to, $this->_subject, $message, $headers);
		}

		return mail($to, $this->_subject, $message, $headers, $this->_params);
	}

	/**
	 * debug
	 */
	public function debug() {
		return '<pre>' . print_r($this, true) . '</pre>';
	}

	/**
	 * magic __toString function
	 */
	public function __toString() {
		return print_r($this, true);
	}

	/**
	 * formatHeader
	 * Formats a display address for emails according to RFC2822.
	 * @param string $email The email address.
	 * @param string $name  The display name.
	 */
	public function formatHeader($email, $name = null) {
		$email = $this->filterEmail($email);

		if (empty($name)) {
			return $email;
		}

		$name = $this->filterName($name);
		return sprintf('"%s" <%s>', $name, $email);
	}

	/**
	 * encodeUtf8
	 * @param string $value The value to encode.
	 */
	public function encodeUtf8($value) {
		$value = trim($value);

		if (preg_match('/(\s)/', $value)) {
			return $this->encodeUtf8Words($value);
		}

		return $this->encodeUtf8Word($value);
	}

	/**
	 * encodeUtf8Word
	 * @param string $value The word to encode.
	 */
	public function encodeUtf8Word($value) {
		return sprintf('=?UTF-8?B?%s?=', base64_encode($value));
	}

	/**
	 * encodeUtf8Words
	 * @param string $value The words to encode.
	 */
	public function encodeUtf8Words($value) {
		$words   = explode(' ', $value);
		$encoded = array();

		foreach ($words as $word) {
			$encoded[] = $this->encodeUtf8Word($word);
		}

		return join($this->encodeUtf8Word(' '), $encoded);
	}

	/**
	 * filterEmail
	 * Removes header injection characters before sanitizing the email address.
	 * @param string $email The email to filter.
	 */
	public function filterEmail($email) {
		$rule  = array(
			"\r" => '',
			"\n" => '',
			"\t" => '',
			'"'  => '',
			','  => '',
			'<'  => '',
			'>'  => ''
		);

		$email = strtr($email, $rule);
		$email = filter_var($email, FILTER_SANITIZE_EMAIL);

		return $email;
	}

	/**
	 * getMimeType
	 * Returns a MIME type based on file extension.
	 */
	public function getMimeType($file) {
		$mime_types = array(
			'pdf'  => 'application/pdf',
			'exe'  => 'application/octet-stream',
			'zip'  => 'application/zip',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'doc'  => 'application/msword',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'xls'  => 'application/vnd.ms-excel',
			'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'ppt'  => 'application/vnd.ms-powerpoint',
			'gif'  => 'image/gif',
			'png'  => 'image/png',
			'jpeg' => 'image/jpeg',
			'jpg'  => 'image/jpeg',
			'mp3'  => 'audio/mpeg',
			'wav'  => 'audio/x-wav',
			'mpeg' => 'video/mpeg',
			'mpg'  => 'video/mpeg',
			'mpe'  => 'video/mpeg',
			'mov'  => 'video/quicktime',
			'avi'  => 'video/x-msvideo',
			'3gp'  => 'video/3gpp',
			'css'  => 'text/css',
			'jsc'  => 'application/javascript',
			'js'   => 'application/javascript',
			'php'  => 'text/plain',
			'txt'  => 'text/plain',
			'csv'  => 'text/csv',
			'htm'  => 'text/html',
			'html' => 'text/html'
		);

		$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

		if (isset($mime_types[$extension])) {
			return $mime_types[$extension];
		}

		return 'application/octet-stream';
	}

	/**
	 * Remove HTML tags, including invisible text such as style and
	 * script code, and embedded objects. Add line breaks around
	 * block-level tags to prevent word joining after tag removal.
	 */
	public function strip_html_tags($message) {
		$message = preg_replace(
			array(
				'@<head[^>]*?>.*?</head>@siu',
				'@<style[^>]*?>.*?</style>@siu',
				'@<script[^>]*?.*?</script>@siu',
				'@<object[^>]*?.*?</object>@siu',
				'@<embed[^>]*?.*?</embed>@siu',
				'@<applet[^>]*?.*?</applet>@siu',
				'@<noframes[^>]*?.*?</noframes>@siu',
				'@<noscript[^>]*?.*?</noscript>@siu',
				'@<noembed[^>]*?.*?</noembed>@siu',
				'@<((br)|(hr))@iu',
				'@</?((address)|(blockquote)|(center)|(del))@iu',
				'@</?((div)|(h[1-9])|(ins)|(isindex)|(p)|(pre))@iu',
				'@</?((dir)|(dl)|(dt)|(dd)|(li)|(menu)|(ol)|(ul))@iu',
				'@</?((table)|(th)|(td)|(caption))@iu',
				'@</?((form)|(button)|(fieldset)|(legend)|(input))@iu',
				'@</?((label)|(select)|(optgroup)|(option)|(textarea))@iu',
				'@</?((frameset)|(frame)|(iframe))@iu'
			),
			array(
				' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ',
				"\n$0", "\n$0", "\n$0", "\n$0", "\n$0", "\n$0", "\n$0", "\n$0"
			),
			$message
		);

		return strip_tags($message);
	}

	/**
	 * filterName
	 * Removes header injection characters and strips HTML tags.
	 * @param string $name The name to filter.
	 */
	public function filterName($name) {
		$rule = array(
			"\r" => '',
			"\n" => '',
			"\t" => '',
			'"'  => "'",
			'<'  => '[',
			'>'  => ']'
		);

		// FILTER_SANITIZE_STRING is deprecated in newer PHP versions.
		$filtered = strip_tags($name);

		return trim(strtr($filtered, $rule));
	}

	/**
	 * filterOther
	 * Removes ASCII control characters including carriage return, line feed or tab characters.
	 * @param string $data The data to filter.
	 */
	public function filterOther($data) {
		return filter_var($data, FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW);
	}

	/**
	 * getHeadersForSend
	 */
	public function getHeadersForSend() {
		if (empty($this->_headers)) {
			return '';
		}

		return join(PHP_EOL, $this->_headers);
	}

	/**
	 * getToForSend
	 */
	public function getToForSend() {
		if (empty($this->_to)) {
			return '';
		}

		return join(', ', $this->_to);
	}

	/**
	 * getUniqueId
	 */
	public function getUniqueId() {
		return md5(uniqid(time()));
	}

	/**
	 * getWrapMessage
	 */
	public function getWrapMessage() {
		return wordwrap($this->_message, $this->_wrap);
	}

	/**
	 * setSMTP
	 * Convenience method for setting SMTP connection values.
	 *
	 * @param string $server SMTP host.
	 * @param int    $port SMTP port.
	 * @param string $user SMTP username.
	 * @param string $pw SMTP password.
	 * @param string $crypto none, tls, ssl, or starttls.
	 * @param string $hostname EHLO hostname.
	 */
	public function setSMTP($server, $port = 587, $user = '', $pw = '', $crypto = 'starttls', $hostname = '') {
		$this->server = (string) $server;
		$this->port   = (int) $port;
		$this->user   = (string) $user;
		$this->pw     = (string) $pw;

		$this->set_crypto($crypto);

		if (!empty($hostname)) {
			$this->hostname = (string) $hostname;
		}

		$this->type = 1;

		return $this;
	}

	/**
	 * smtp_send
	 * Sends the assembled MIME message over SMTP.
	 *
	 * @param string $to Recipient list for message header.
	 * @param string $subject Message subject.
	 * @param string $message MIME body.
	 * @param string $headers MIME and mail headers.
	 * @return boolean
	 */
	private function smtp_send($to, $subject, $message, $headers) {
		$socket = $this->smtp_connect();

		if ($socket === false) {
			return false;
		}

		$ehlo = $this->smtp_command($socket, 'EHLO ' . $this->hostname, array('250'));

		if ($ehlo === false) {
			$ehlo = $this->smtp_command($socket, 'HELO ' . $this->hostname, array('250'));

			if ($ehlo === false) {
				$this->smtp_close($socket);
				return false;
			}
		}

		if ($this->crypto === 'starttls') {
			if ($this->smtp_command($socket, 'STARTTLS', array('220')) === false) {
				$this->smtp_close($socket);
				return false;
			}

			if (!$this->smtp_enable_tls($socket)) {
				$this->smtp_close($socket);
				return false;
			}

			// EHLO must be repeated after STARTTLS.
			if ($this->smtp_command($socket, 'EHLO ' . $this->hostname, array('250')) === false) {
				$this->smtp_close($socket);
				return false;
			}
		}

		if (!empty($this->user)) {
			if (!$this->smtp_auth_login($socket)) {
				$this->smtp_close($socket);
				return false;
			}
		}

		$from = $this->getFromForSmtp();

		if (empty($from)) {
			if (!empty($this->user) && filter_var($this->user, FILTER_VALIDATE_EMAIL)) {
				$from = $this->user;
			}
		}

		if (empty($from)) {
			$this->srv_ret['last'] = 'ERROR: Unable to send through SMTP. No From address was found.';
			$this->srv_ret['all']  .= $this->srv_ret['last'] . "\n";
			$this->srv_ret['full'] .= $this->srv_ret['last'] . "\n";
			$this->smtp_close($socket);
			return false;
		}

		if ($this->smtp_command($socket, 'MAIL FROM:<' . $from . '>', array('250')) === false) {
			$this->smtp_close($socket);
			return false;
		}

		$recipients = $this->getRecipientsForSmtp();

		if (empty($recipients)) {
			$this->srv_ret['last'] = 'ERROR: Unable to send through SMTP. No recipient address was found.';
			$this->srv_ret['all']  .= $this->srv_ret['last'] . "\n";
			$this->srv_ret['full'] .= $this->srv_ret['last'] . "\n";
			$this->smtp_close($socket);
			return false;
		}

		foreach ($recipients as $recipient) {
			if ($this->smtp_command($socket, 'RCPT TO:<' . $recipient . '>', array('250', '251')) === false) {
				$this->smtp_close($socket);
				return false;
			}
		}

		if ($this->smtp_command($socket, 'DATA', array('354')) === false) {
			$this->smtp_close($socket);
			return false;
		}

		$data = $this->buildSmtpData($to, $subject, $headers, $message);
		$data = $this->smtp_dot_stuff($data);

		fwrite($socket, $data . "\r\n.\r\n");

		$ret = $this->server_parse($socket);
		$this->srv_ret['last'] = trim($ret);
		$this->srv_ret['all']  .= $this->srv_ret['last'] . "\n";
		$this->srv_ret['full'] .= "sent: [message data]\nreceived: " . $this->srv_ret['last'] . "\n";

		if ($this->expected_return($ret, '250') !== true) {
			$this->smtp_close($socket);
			return false;
		}

		$this->smtp_command($socket, 'QUIT', array('221'));
		$this->smtp_close($socket);

		return true;
	}

	/**
	 * smtp_auth_login
	 * Performs AUTH LOGIN authentication.
	 *
	 * @param resource $socket
	 * @return boolean
	 */
	private function smtp_auth_login($socket) {
		if ($this->smtp_command($socket, 'AUTH LOGIN', array('334')) === false) {
			return false;
		}

		if ($this->smtp_command($socket, base64_encode($this->user), array('334')) === false) {
			return false;
		}

		if ($this->smtp_command($socket, base64_encode($this->pw), array('235')) === false) {
			return false;
		}

		return true;
	}

	/**
	 * smtp_command
	 * Sends a single SMTP command and validates the response code.
	 *
	 * @param resource $socket
	 * @param string   $command
	 * @param array    $expected
	 * @return string|false
	 */
	private function smtp_command($socket, $command, $expected = array()) {
		fwrite($socket, $command . "\r\n");

		$ret = $this->server_parse($socket);
		$this->srv_ret['last'] = trim($ret);
		$this->srv_ret['all']  .= $this->srv_ret['last'] . "\n";
		$this->srv_ret['full'] .= "sent: " . $this->maskSmtpCommand($command) . "\nreceived: " . $this->srv_ret['last'] . "\n";

		foreach ($expected as $code) {
			if ($this->expected_return($ret, $code) === true) {
				return $ret;
			}
		}

		return false;
	}

	/**
	 * server_parse
	 * Reads SMTP server response, including multiline responses.
	 *
	 * @param resource $socket
	 * @return string
	 */
	private function server_parse($socket) {
		$response = '';

		while (!feof($socket)) {
			$line = fgets($socket, 515);

			if ($line === false) {
				break;
			}

			$response .= $line;

			// Multiline responses have a hyphen after the code, like 250-.
			// The final response line has a space after the code, like 250 .
			if (preg_match('/^[0-9]{3}\s/', $line)) {
				break;
			}
		}

		return $response;
	}

	/**
	 * expected_return
	 * Checks whether an SMTP response starts with the expected code.
	 *
	 * @param string $return
	 * @param string $expected
	 * @return boolean
	 */
	private function expected_return($return, $expected) {
		$return   = trim((string) $return);
		$expected = (string) $expected;

		return (substr($return, 0, 3) === $expected);
	}

	/**
	 * smtp_enable_tls
	 * Enables TLS after STARTTLS.
	 *
	 * @param resource $socket
	 * @return boolean
	 */
	private function smtp_enable_tls($socket) {
		if (!function_exists('stream_socket_enable_crypto')) {
			$this->srv_ret['last'] = 'ERROR: stream_socket_enable_crypto() is not available.';
			$this->srv_ret['all']  .= $this->srv_ret['last'] . "\n";
			$this->srv_ret['full'] .= $this->srv_ret['last'] . "\n";
			return false;
		}

		$methods = 0;

		if (defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')) {
			$methods |= STREAM_CRYPTO_METHOD_TLS_CLIENT;
		}

		if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
			$methods |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
		}

		if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
			$methods |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
		}

		if ($methods === 0) {
			$methods = STREAM_CRYPTO_METHOD_TLS_CLIENT;
		}

		$result = @stream_socket_enable_crypto($socket, true, $methods);

		if ($result !== true) {
			$this->srv_ret['last'] = 'ERROR: Unable to enable STARTTLS encryption.';
			$this->srv_ret['all']  .= $this->srv_ret['last'] . "\n";
			$this->srv_ret['full'] .= $this->srv_ret['last'] . "\n";
			return false;
		}

		$this->srv_ret['full'] .= "notice: STARTTLS encryption enabled\n";

		return true;
	}

	/**
	 * buildSmtpData
	 * Builds the full message sent after DATA.
	 *
	 * @param string $to
	 * @param string $subject
	 * @param string $headers
	 * @param string $message
	 * @return string
	 */
	private function buildSmtpData($to, $subject, $headers, $message) {
		$lines = array();

		$lines[] = 'Date: ' . date('r');
		$lines[] = 'To: ' . $to;
		$lines[] = 'Subject: ' . $this->filterOther((string) $subject);

		if (!empty($headers)) {
			$headers = $this->normalizeLineEndings($headers);
			$lines[] = $headers;
		}

		$lines[] = '';
		$lines[] = $message;

		return $this->normalizeLineEndings(implode("\r\n", $lines));
	}

	/**
	 * smtp_dot_stuff
	 * Escapes lines beginning with a dot during SMTP DATA.
	 *
	 * @param string $data
	 * @return string
	 */
	private function smtp_dot_stuff($data) {
		$data  = $this->normalizeLineEndings($data);
		$lines = explode("\r\n", $data);

		foreach ($lines as $key => $line) {
			if (isset($line[0]) && $line[0] === '.') {
				$lines[$key] = '.' . $line;
			}
		}

		return implode("\r\n", $lines);
	}

	/**
	 * normalizeLineEndings
	 * Converts mixed line endings to SMTP CRLF.
	 *
	 * @param string $data
	 * @return string
	 */
	private function normalizeLineEndings($data) {
		$data = str_replace(array("\r\n", "\r"), "\n", (string) $data);
		return str_replace("\n", "\r\n", $data);
	}

	/**
	 * getFromForSmtp
	 * Extracts the From address from registered headers.
	 *
	 * @return string
	 */
	private function getFromForSmtp() {
		foreach ($this->_headers as $header) {
			if (stripos($header, 'From:') === 0) {
				return $this->extractEmailAddress(substr($header, 5));
			}
		}

		return '';
	}

	/**
	 * getRecipientsForSmtp
	 * Extracts SMTP recipient addresses from the formatted To array.
	 *
	 * @return array
	 */
	private function getRecipientsForSmtp() {
		$recipients = array();

		foreach ($this->_to as $to) {
			$email = $this->extractEmailAddress($to);

			if (!empty($email)) {
				$recipients[] = $email;
			}
		}

		return array_unique($recipients);
	}

	/**
	 * extractEmailAddress
	 * Extracts an email address from "Name <email@example.com>" or plain address.
	 *
	 * @param string $value
	 * @return string
	 */
	private function extractEmailAddress($value) {
		$value = trim((string) $value);

		if (preg_match('/<([^>]+)>/', $value, $match)) {
			$value = $match[1];
		}

		$value = $this->filterEmail($value);

		if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
			return '';
		}

		return $value;
	}

	/**
	 * maskSmtpCommand
	 * Prevents passwords from appearing in debug output.
	 *
	 * @param string $command
	 * @return string
	 */
	private function maskSmtpCommand($command) {
		if (trim($command) === base64_encode($this->pw)) {
			return '[password hidden]';
		}

		return $command;
	}

	/**
	 * smtp_close
	 * Closes the socket safely.
	 *
	 * @param resource $socket
	 */
	private function smtp_close($socket) {
		if (is_resource($socket)) {
			fclose($socket);
		}
	}
}
class MailService {
    public static function send($to, $subject, $html) {
        $mail = new pssm_Mail();

        $mail->setSMTP(
            SMTP_HOST,
            SMTP_PORT,
            SMTP_USER,
            SMTP_PASS,
            SMTP_CRYPTO
        );

        return $mail
            ->setFrom(SMTP_FROM, APP_NAME)
            ->setTo($to)
            ->setSubject($subject)
            ->setMessage($html)
            ->send();
    }
}
/*MailService::send(
    $user_email,
    'Password Reset',
    $html
);*/
?>
