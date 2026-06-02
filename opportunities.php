<? 
$page = 'home'; 
$error  = isset($_REQUEST['error']) ? htmlspecialchars($_REQUEST['error']) : '';
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <link rel="icon" type="image/x-icon" href="favicon.ico" />
  <meta charset="utf-8" />
	<base href="https://www.fiainspectors.com/" />
	<meta name="author" content="Sunray Data Solutions" />
	<meta name="description" content="FIA Inspectors provides vehicle appraisals following accidents, car inspections, and general car appraisals. " />
	<title>Florida Inspection Associates</title>
    <link rel="stylesheet" href="/bootstrap/css/bootstrap.css">
    <link rel="stylesheet" href="/bootstrap/css/bootstrap-custom.css">
    <link rel="stylesheet" href="/bootstrap/css/bs-clearfix.css">
	<link rel="stylesheet" href="fia.css" type="text/css">
    
  <script src="https://www.google.com/recaptcha/api.js?render=6LfUWHkqAAAAAP5dbkBhqZ6CEe_tG_zZu2aluKhe"></script>

  <script>
    function onSubmit(token) {
      // Run the validation function
      MM_validateForm('NameFirst', '', 'R', 'NameLast', '', 'R', 'address', '', 'R', 'city', '', 'R', 'state', '', 'R', 'zip', '', 'R', 'email', '', 'RisEmail', 'phoneoff', '', 'R', 'education', '', 'R');
      
      // Check the validation result before submitting the form
      if (document.MM_returnValue) {
        document.getElementsByName('form1')[0].submit();
      } else {
        alert('Please fix the errors in the form.');
      }
    }
  </script>

    
    <!--[if (gte IE 8)&(lte IE 9)]>
        <script type="text/javascript" src="/media/gantry5/assets/js/html5shiv-printshiv.min.js"></script>
        <link rel="stylesheet" href="/media/gantry5/engines/nucleus/css/nucleus-ie9.css" type="text/css"/>
        <script type="text/javascript" src="/media/gantry5/assets/js/matchmedia.polyfill.js"></script>
        <![endif]-->
  <script language="JavaScript" type="text/JavaScript">

    function MM_validateForm() { //v4.0
    var i,p,q,nm,test,num,min,max,errors='',args=MM_validateForm.arguments;
    for (i=0; i<(args.length-2); i+=3) { test=args[i+2]; val=MM_findObj(args[i]);
      if (val) { nm=val.name; if ((val=val.value)!="") {
      if (test.indexOf('isEmail')!=-1) { p=val.indexOf('@');
        if (p<1 || p==(val.length-1)) errors+='- '+nm+' must contain an e-mail address.\n';
      } else if (test!='R') { num = parseFloat(val);
        if (isNaN(val)) errors+='- '+nm+' must contain a number.\n';
        if (test.indexOf('inRange') != -1) { p=test.indexOf(':');
        min=test.substring(8,p); max=test.substring(p+1);
        if (num<min || max<num) errors+='- '+nm+' must contain a number between '+min+' and '+max+'.\n';
      } } } else if (test.charAt(0) == 'R') errors += '- '+nm+' is required.\n'; }
    } if (errors) alert('The following error(s) occurred:\n'+errors);
    document.MM_returnValue = (errors == '');
    }

    function MM_findObj(n, d) { //v4.01
    var p,i,x;  if(!d) d=document; if((p=n.indexOf("?"))>0&&parent.frames.length) {
      d=parent.frames[n.substring(p+1)].document; n=n.substring(0,p);}
    if(!(x=d[n])&&d.all) x=d.all[n]; for (i=0;!x&&i<d.forms.length;i++) x=d.forms[i][n];
    for(i=0;!x&&d.layers&&i<d.layers.length;i++) x=MM_findObj(n,d.layers[i].document);
    if(!x && d.getElementById) x=d.getElementById(n); return x;
    }
	</script>
</head>
<body>
<div class="container-fluid">
<? include('includes/topper.php'); ?>

<div class="row" style="margin-top:12px">
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
<div class="col-lg-10 col-md-10 col-sm-10 col-xs-10 h1 text-center">
Here’s an Opportunity to Join Our Team!
</div>
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
</div>

<div class="row" style="margin-top:12px">
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
<div class="col-lg-10 col-md-10 col-sm-10 col-xs-10 h2 text-center">
Do You Have What It Takes to Be an FIA Mechanical Inspector?
</div>
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
</div>

<div class="row" style="margin-top:12px">
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
<div class="col-lg-10 col-md-10 col-sm-10 col-xs-10 body2">
When you’re a mechanical inspector for Florida Inspection Associates, our clients will count on your eyes, ears and expertise to provide actionable intelligence regarding the automotive issues they face. At any given time, you may be working for a warranty company, a government agency, the Better Business Bureau … or just average person trying to avoid an expensive mistake.<br />
<br />
In working for us, you’ll need to be unbiased in your inspection duties, obtaining as much information as possible on the target vehicle. Typically, this will include verifying the mileage, noting the VIN, checking fuel levels and the general condition of the vehicle. When you identify a faulty or damaged part, we will also expect you to determine the extent and cause of the failure.
</div>
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
</div>

<div class="row" style="margin-top:45px">
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
<div class="col-lg-6 col-md-6 col-sm-6 col-xs-6 body2">
<img class="img-responsive" src="/images/article-images/opportunities.png" alt="Interested? You must have:"/>
</div>
<div class="col-lg-4 col-md-4 col-sm-4 col-xs-4">
  <div class="h3">Interested? You must have:</div>
  <div class="body2" style="margin-top:24px">
  <ul>
  <li>Extensive knowledge of all aspects of automotive function and repair</li>
<li>The ability accurately and concisely document findings in written reports</li>
<li>Good verbal communication skills</li>
<li>Reliable transportation that’s available to you at all times</li>
<li>Your own digital camera</li>
<li>Ready access to an Internet-enable computer for filing reports and uploading picture</li>
</ul>
  </div>
  <div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
</div>

<div class="row" style="margin-top:12px">
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
<div class="col-lg-10 col-md-10 col-sm-10 col-xs-10 h3 text-center">
What do we provide?
</div>
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
</div>

<div class="row" style="margin-top:12px">
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
<div class="col-lg-10 col-md-10 col-sm-10 col-xs-10 body2" style="margin-top:24px">
As a member of the FIA mechanical inspection team, we will provide you with assignments, all necessary paperwork, support, and relocation suggestions if desirable.</div>
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
</div>

<div class="row" style="margin-top:12px">
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
<div class="col-lg-10 col-md-10 col-sm-10 col-xs-10 h3 text-center">
<a name="submit">Submit Your Resume and Career Experience Now</a></div>
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
</div>

<div class="row" style="margin-top:12px">
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
<div class="col-lg-10 col-md-10 col-sm-10 col-xs-10">
<? include('employ_form.php'); ?>
</div>
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
</div>

<div class="row" style="margin-top:24px">
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
<div class="col-lg-10 col-md-10 col-sm-10 col-xs-10 h3 text-center">
Learn More About Florida Inspection Associates
</div>
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
</div>

<div class="row" style="margin-top:12px">
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
<div class="col-lg-10 col-md-10 col-sm-10 col-xs-10 body2" style="margin-top:24px">
Interested in the nuts and bolts of working with Florida Inspection Associates?<br />
Read through our Inspector Guidelines now. <a href="/Docs/fia1.pdf" target="_blank">[View PDF]</a></div>
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
</div>

<div class="row" style="margin-top:12px; margin-bottom:12px">
<div class="col-lg-12 col-md-12 col-sm-12 col-xs-12 h2 about-CTA">
ARE YOU AN EXPERT OTHERS CAN COUNT ON?<br />
E-MAIL YOUR RÉSUMÉ OR FAX IT TO (727) 588-0580
</div>
</div>

<? include('includes/footer.php'); ?>

</div> <!-- end container -->

</body>
</html>