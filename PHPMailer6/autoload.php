<?php
/**
 * PHPMailer6/autoload.php — single-file loader for manual (non-Composer) installs.
 * Require this file once; it pulls in Exception, PHPMailer, and SMTP.
 *
 * Usage:
 *   require_once __DIR__ . '/../PHPMailer6/autoload.php';
 */

require_once __DIR__ . '/src/Exception.php';
require_once __DIR__ . '/src/PHPMailer.php';
require_once __DIR__ . '/src/SMTP.php';
