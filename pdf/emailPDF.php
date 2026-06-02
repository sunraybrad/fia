<?PHP
require_once('C:\inetpub\wwwroot\FIA\PHPMailer-master\class.phpmailer.php');
  
// Setup Email ***********************************************
$mail = new PHPMailer();
$mail->IsSMTP();
$mail->SMTPSecure = "ssl";
$mail->Host = "smtp.ionos.com";
$mail->SMTPAuth = true;
$mail->Port = 465; // SMTP server
$mail->Username = "webmaster@fiainspectors.com";
$mail->Password = "FIA,adm1n";
$mail->CharSet = "UTF-8";
$mail->SMTPDebug  = 0;
$mail->Debugoutput = 'html';

$body			= '<html><body>See attached for your inspection report from Florida Inspection Associates.<br>
<a href="https://fiainspectors.com/NWCreport.php?uid='.$uid.'">View Report Online</a><br><br>
FIA Inspection Associates, Inc.<br>
888-342-4678
</body></html>';
$subject = 'FIA Report Complete';
$cc	= 'linda@fiainspectors.com';
//$bcc = 'brad@sunraydatasolutions.com';
$bcc2	= 'keli@fiainspectors.com';
$bcc3	= 'brad@sunraydatasolutions.com';
$from = 'webmaster@fiainspectors.com';
$mail->From = $from;
$mail->FromName = 'FIA Nationwide Inspectors and Appraisers';
$mail->AddReplyTo('linda@fiainspectors.com');
$mail->AddAddress('mbidispatch@geico.com');
$mail->AddCC($cc);
//$mail->AddBCC($bcc);
$mail->AddBCC($bcc2);
$mail->AddBCC($bcc3);
$mail->Subject = $subject;
$mail->MsgHTML($body);
//Replace the plain text body with one created manually
$mail->AltBody = 'See attached for your inspection report from Florida Inspection Associates.\n<a href="https://fiainspectors.com/NWCreport.php?uid='.$uid.'">View Report Online</a>\n\nFIA Inspection Associates, Inc.\n
888-342-4678';
$mail->addAttachment($publicPdfPath);
//Send the message, check for errors
if(!$mail->Send()) {
  echo "Mailer Error: " . $mail->ErrorInfo;
} else {
  echo "Message sent!";
}

?>