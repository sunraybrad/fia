<?php
require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/mailer.php';

$name      = trim($_POST['Name']         ?? '');
$email     = trim($_POST['Email']        ?? '');
$phone     = trim($_POST['Phone']        ?? '');
$preferred = trim($_POST['Preferred']    ?? '');
$concern   = trim($_POST['Concern']      ?? '');
$type      = trim($_POST['Concern_type'] ?? '');
$message   = trim($_POST['Message']      ?? '');

$back = SITE_URL . '/connectform.php';

if (!isset($_POST['g-recaptcha-response'])) {
    header('Location: ' . $back . '?error=nocaptcha');
    exit;
}

$verify  = file_get_contents(
    'https://www.google.com/recaptcha/api/siteverify?secret=' . urlencode(RECAPTCHA_SECRET)
    . '&response=' . urlencode($_POST['g-recaptcha-response'])
);
$captcha = json_decode($verify);

if (!$captcha || !$captcha->success) {
    header('Location: ' . $back . '?error=nocaptcha');
    exit;
}

if (empty($name) || empty($email) || empty($phone)) {
    header('Location: ' . $back . '?error=required');
    exit;
}

$lines = [
    "Name:              {$name}",
    "Email:             {$email}",
    "Phone:             {$phone}",
    "Preferred Contact: {$preferred}",
    "Needs:             {$concern}" . ($type ? " — {$type}" : ''),
];
if ($message !== '') {
    $lines[] = '';
    $lines[] = "Message:\n{$message}";
}

$db = get_db();
fia_send_email($db, [
    'to'            => SITE_EMAIL,
    'to_name'       => SITE_NAME,
    'subject'       => 'Website Contact Request — ' . $name,
    'body'          => implode("\n", $lines),
    'sent_by_email' => $email,
    'sent_by_name'  => $name,
]);

header('Location: ' . SITE_URL . '/thankyou.php');
exit;
