<?php
require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/mailer.php';

$Name  = trim($_POST['Name']  ?? '');
$email = trim($_POST['email'] ?? '');

if (!isset($_POST['g-recaptcha-response'])) {
    echo 'reCaptcha verification not submitted.';
    exit;
}

$verify  = file_get_contents(
    'https://www.google.com/recaptcha/api/siteverify?secret=' . urlencode(RECAPTCHA_SECRET)
    . '&response=' . urlencode($_POST['g-recaptcha-response'])
);
$captcha = json_decode($verify);

if (!$captcha || !$captcha->success) {
    echo 'reCaptcha verification failed. Please try again.';
    exit;
}

if (empty($Name) || empty($email)) {
    header('Location: ' . SITE_URL . '/opportunities.php?name=' . urlencode($Name) . '&email=' . urlencode($email) . '&error=error#submit');
    exit;
}

// Sanitise all form fields into local variables
$fields = [
    'Name', 'address', 'city', 'state', 'zip', 'email',
    'phonehm', 'phonecell', 'phonepage', 'phoneoff', 'phonefax',
    'education', 'cert1', 'cert12', 'cert13', 'cert14',
    'certdate1', 'certdate12', 'certdate13', 'certdate14',
    'knowledge', 'digital', 'digital2', 'comments',
];
foreach ($fields as $field) {
    $$field = htmlspecialchars($_POST[$field] ?? '');
}

// AppRequest.php template expects title-cased variable names
$Address = $address;
$City    = $city;
$State   = $state;
$Zip     = $zip;
$Area    = $phonefax;   // "coverage area" was historically submitted in the phonefax field

ob_start();
include __DIR__ . '/AppRequest.php';
$body_html = ob_get_clean();

$db = get_db();
fia_send_email($db, [
    'to'        => $email,
    'to_name'   => $Name,
    'subject'   => 'FIA Inspectors Application Confirmation',
    'body'      => strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body_html)),
    'body_html' => $body_html,
]);

header('Location: ' . SITE_URL . '/thankyou_resume.php');
exit;
