<?PHP
// Upload file if Any
// Check that we have a file
// $resumefile = $_POST['resumefile'];

$ip = $_SERVER['REMOTE_ADDR'];
$backpage = 'opportunities.php';
$name = $_POST["Name"];
$email = $_POST["email"];

if (isset($_POST['g-recaptcha-response'])) {
  $recaptcha_secret = '6LfUWHkqAAAAAPFSUFYsC3DALhEA0lDURfjvEy2t';
  $recaptcha_response = $_POST['g-recaptcha-response'];

  $verify = file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret=$recaptcha_secret&response=$recaptcha_response");
  $response_data = json_decode($verify);
  if ($response_data->success) {
      // Successful validation
      // Process the form data

      // check to see if at least the name is submitted
      if (empty($name) || empty($email)) {
        $error = "error";
        header('location: http://fiainspectors.com/opportunities.php?name='.$name.'&email='.$email.'&error='.$error.'#submit');
      } else {

        require_once('PHPMailer-master/class.phpmailer.php');
        
        // create an array of all the POST variables to be used
        $fields = array('Name','address','city','state','zip','email','phonehm','phonecell','phonepage','phoneoff','phonefax','education','cert1','cert12','cert13','cert14','certdate1','certdate12','certdate13','certdate14','knowledge','digital','digital2','comments');
        
        // convert each REQUEST variable to a local clean variable
        foreach($fields as $field) {
          ${$field} = htmlspecialchars($_POST[$field]);
          }
        
        //Save file info to database
        $FM_NAME = 'Contacts';
        $layout = 'Applicants';
        require_once('Connections/public.php');
        $addValues = array(
          "Inspector"=>$Name,
          "Address"=>$address,
          "City"=>$city,
          "State"=>$state,
          "Zip"=>$zip,
          "EMail"=>$email,
          "Primary Phone"=>$phonehm,
          "Cell Phone"=>$phonecell,
          "Pager"=>$phonepage,
          "Alternate Phone"=>$phoneoff,
          "Area"=>$phonefax,
          "Education"=>$education,
          "Cert1"=>$cert1,
          "Cert12"=>$cert12,
          "Cert13"=>$cert13,
          "Cert14"=>$cert14,
          "CertDate1"=>$certdate1,
          "CertDate12"=>$certdate12,
          "CertDate13"=>$certdate13,
          "CertDate14"=>$certdate14,
          "Knowledge"=>$knowledge,
          "Digital"=>$digital,
          "Digital2"=>$digital2,
          "Comments"=>$comments,
          "IPaddress"=>$ip);
        $rec = $fm->newAddCommand($layout, $addValues);
        $add_result = $rec->execute();
        
        // read back resulting data to variables
        $newRecord = current($add_result->getRecords());
        $Inspector = $newRecord->getField('Inspector');
        $Address = $newRecord->getField('Address');
        $City = $newRecord->getField('City');
        $State = $newRecord->getField('State');
        $Zip = $newRecord->getField('Zip');
        $EMail = $newRecord->getField('EMail');
        $PrimaryPhone = $newRecord->getField('Primary Phone');
        $CellPhone = $newRecord->getField('Cell Phone');
        $Pager = $newRecord->getField('Pager');
        $AlternatePhone = $newRecord->getField('Alternate Phone');
        $Area = $newRecord->getField('Area');
        $Education = $newRecord->getField('Education');
        $cert1 = $newRecord->getField('Cert1');
        $cert12 = $newRecord->getField('Cert12');
        $cert13 = $newRecord->getField('Cert13');
        $cert14 = $newRecord->getField('Cert14');
        $certdate1 = $newRecord->getField('CertDate1');
        $certdate12 = $newRecord->getField('CertDate12');
        $certdate13 = $newRecord->getField('CertDate13');
        $certdate14 = $newRecord->getField('CertDate14');
        $Knowledge = $newRecord->getField('Knowledge');
        $Digital = $newRecord->getField('Digital');
        $Digital2 = $newRecord->getField('Digital2');
        $comments = $newRecord->getField('Comments');
        
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
        //$mail->SMTPDebug  = 0;
        //$mail->Debugoutput = 'html';
        
        ob_start();
        include 'AppRequest.php'; //execute the file as php
        $body = ob_get_clean();
        
        $subject 		= 'FIA Inspectors Application Confirmation';
        $bcc			= 'bill@fiainspectors.com';
        $from 		= 'webmaster@fiainspectors.com';
        $mail->From 	= $from;
        $mail->FromName = 'FIA Nationwide Inspectors and Appraisers';
        $mail->AddReplyTo($from);
        $mail->AddAddress($email);
        //$mail->AddAddress('brad@sunraydatasolutions.com');
        $mail->AddBCC($bcc);
        $mail->Subject = $subject;
        //Read an HTML message body from an external file, convert referenced images to embedded, convert HTML into a basic plain-text alternative body
        $mail->MsgHTML($body);
        //Replace the plain text body with one created manually
        //$mail->AltBody = 'This is a plain-text message body';
        //Attach an image file
        //$mail->AddAttachment('images/phpmailer-mini.gif');
        
        //Send the message, check for errors
        if(!$mail->Send()) {
        echo "Mailer Error: " . $mail->ErrorInfo;
        } else {
        //  echo "Message sent!";
        }
        // end of Send Mail ******************************************
        
        header('location: http://www.fiainspectors.com/thankyou_resume.php');

      } // end of required field check
  } else {
      // reCaptcha failed
      echo 'reCaptcha verification failed. Please try again.';
  }
} else {
  // reCaptcha not submitted
  echo 'reCaptcha verification not submitted.';
}

?>