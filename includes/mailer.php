<?php
/**
 * mailer.php — Shared email helper for fiainspectors.com
 *
 * Usage:
 *   require_once __DIR__ . '/../includes/mailer.php';  // adjust path as needed
 *   $result = fia_send_email($db, [
 *       'to'             => 'inspector@example.com',
 *       'to_name'        => 'John Smith',        // optional
 *       'subject'        => 'Inspection Assignment ...',
 *       'body'           => 'Plain text body...',
 *       'body_html'      => '<p>HTML body...</p>', // optional
 *       'fia_number'     => 345890,               // optional
 *       'inspector_id'   => 1184,                 // optional
 *       'warranty_co_id' => 1000,                 // optional
 *       'cc'             => 'cc@example.com',     // optional
 *   ]);
 *   // Returns ['ok' => bool, 'error' => string|null, 'email_id' => int|null]
 *
 * Requires config.php to be loaded first (provides SMTP_* and MAIL_BCC_ADMIN constants).
 */

require_once __DIR__ . '/../PHPMailer6/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailerException;

/**
 * Send an email and log it to the emails table.
 *
 * @param  mysqli  $db      Active DB connection
 * @param  array   $params  See file header for keys
 * @return array   ['ok' => bool, 'error' => string|null, 'email_id' => int|null]
 */
function fia_send_email(mysqli $db, array $params): array {

    $to             = trim($params['to']             ?? '');
    $to_name        = trim($params['to_name']        ?? '');
    $subject        = trim($params['subject']        ?? '');
    $body           = trim($params['body']           ?? '');
    $body_html      = trim($params['body_html']      ?? '');
    $cc             = trim($params['cc']             ?? '');
    $sent_by_email  = trim($params['sent_by_email']  ?? '');
    $sent_by_name   = trim($params['sent_by_name']   ?? '');
    $fia_number     = isset($params['fia_number'])     ? (int)$params['fia_number']     : null;
    $inspector_id   = isset($params['inspector_id'])   ? (int)$params['inspector_id']   : null;
    $warranty_co_id = isset($params['warranty_co_id']) ? (int)$params['warranty_co_id'] : null;

    if (!$to || !$subject || !$body) {
        return ['ok' => false, 'error' => 'to, subject, and body are required', 'email_id' => null];
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);

        if ($to_name) {
            $mail->addAddress($to, $to_name);
        } else {
            $mail->addAddress($to);
        }

        if ($cc) {
            $mail->addCC($cc);
        }

        if ($sent_by_email) {
            $mail->addReplyTo($sent_by_email, $sent_by_name ?: $sent_by_email);
        }

        if (defined('MAIL_BCC_ADMIN') && MAIL_BCC_ADMIN) {
            $mail->addBCC(MAIL_BCC_ADMIN);
        }

        $mail->Subject = $subject;
        $mail->Body    = $body;

        if ($body_html) {
            $mail->isHTML(true);
            $mail->Body    = $body_html;
            $mail->AltBody = $body;
        }

        $mail->send();
        $status = 'SENT';
        $error  = null;

    } catch (MailerException $e) {
        $status = 'FAILED';
        $error  = $mail->ErrorInfo;
    }

    // Log to emails table
    $stmt = $db->prepare(
        "INSERT INTO emails
            (fia_number, inspector_id, warranty_co_id, sent_date, sent_at,
             from_address, to_address, cc, subject, body_text, status, is_archived)
         VALUES (?, ?, ?, CURDATE(), NOW(), ?, ?, ?, ?, ?, ?, FALSE)"
    );
    $from = SMTP_FROM_EMAIL;
    $stmt->bind_param(
        'iiisssssss',
        $fia_number, $inspector_id, $warranty_co_id,
        $from, $to, $cc, $subject, $body, $status
    );
    $stmt->execute();
    $email_id = (int)$db->insert_id;
    $stmt->close();

    if ($status === 'FAILED') {
        return ['ok' => false, 'error' => $error, 'email_id' => $email_id];
    }

    return ['ok' => true, 'error' => null, 'email_id' => $email_id];
}
