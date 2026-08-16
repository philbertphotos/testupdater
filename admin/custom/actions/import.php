<?php
/*
 * Custom Action: importusers
 * ------------------------------------------------------------
 * Move the body of case "importusers": from the old custom.php here.
 * Do not include the case line or final break.
 *
 * The dispatcher already provides:
 * - env.php
 * - $vars = (object)$_REQUEST
 * - login check
 * - ACL check
 * - customJson(), customSuccess(), customError()
 */
require_once DOCROOT . '/class/class.nameparser.php';

customError('importusers action file is ready. Move the original importusers case body into this file.');
?>
