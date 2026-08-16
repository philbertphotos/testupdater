<?php
/*
 * Custom Action: importdopusers
 * ------------------------------------------------------------
 * Move the body of case "importdopusers": from the old custom.php here.
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

customError('importdopusers action file is ready. Move the original importdopusers case body into this file.');
?>
