<? $page = 'home'; 
	$email  = isset($_REQUEST['email']) ? htmlspecialchars($_REQUEST['email']) : '';
	$fname  = isset($_REQUEST['fname']) ? htmlspecialchars($_REQUEST['fname']) : '';
	$lname  = isset($_REQUEST['lname']) ? htmlspecialchars($_REQUEST['lname']) : '';
	$error  = isset($_REQUEST['error']) ? htmlspecialchars($_REQUEST['error']) : '';
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="icon" type="image/x-icon" href="favicon.ico" />
    <meta charset="utf-8" />
	<base href="https://fiainspectors.com" />
	<meta name="author" content="Sunray Data Solutions" />
	<meta name="description" content="FIA Inspectors provides vehicle appraisals following accidents, car inspections, and general car appraisals. " />
	<title>Florida Inspection Associates</title>
    <link rel="stylesheet" href="/bootstrap/css/bootstrap.css">
    <link rel="stylesheet" href="/bootstrap/css/bootstrap-custom.css">
    <link rel="stylesheet" href="/bootstrap/css/bs-clearfix.css">
	<link rel="stylesheet" href="fia.css" type="text/css">
    <script type="text/javascript" src="/js/usableforms.js"></script>
    <script src="https://www.google.com/recaptcha/api.js"></script>
    
    <!--[if (gte IE 8)&(lte IE 9)]>
        <script type="text/javascript" src="/media/gantry5/assets/js/html5shiv-printshiv.min.js"></script>
        <link rel="stylesheet" href="/media/gantry5/engines/nucleus/css/nucleus-ie9.css" type="text/css"/>
        <script type="text/javascript" src="/media/gantry5/assets/js/matchmedia.polyfill.js"></script>
        <![endif]-->
</head>
<body>
<div class="container-fluid">
<? include ('includes/topper.php'); ?>

<div class="row" style="margin-top:12px">
  <div class="col-lg-4 col-md-4 col-sm-4 col-xs-4" style="padding:0px"><img class="img-responsive" src="/images/FP-Tracks/FP-Track-2-a.jpg" width="100%" /></div>
  <div class="col-lg-4 col-md-4 col-sm-4 col-xs-4" style="padding:0px"><img class="img-responsive" src="/images/FP-Tracks/AboutUs.jpg" width="100%" /></div>
  <div class="col-lg-4 col-md-4 col-sm-4 col-xs-4" style="padding:0px"><img class="img-responsive" src="/images/FP-Tracks/FP-Track-1.jpg" width="100%" /></div>
</div>

<div class="row" style="margin-top:12px">
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
<div class="col-lg-10 col-md-10 col-sm-10 col-xs-10 h1 text-center">
Guesswork is Expensive
</div>
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
</div>

<div class="row" style="margin-top:12px">
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
<div class="col-lg-10 col-md-10 col-sm-10 col-xs-10 h2 text-center">
If you need an auto inspection or appraisal, you need FIA Inspectors.
</div>
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
</div>

<div class="row" style="margin-top:12px">
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
<div class="col-lg-10 col-md-10 col-sm-10 col-xs-10 body2">
Just about any motor vehicle transaction is going to involve hundreds—or more likely—thousands of dollars. When all goes well, everyone walks away getting fair value for their part in the deal. When things don’t go well, someone loses a lot of money, time and sleep. We make sure our clients have the reliable information they need to achieve the most accurate possible outcome in automobile-related matters.
</div>
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
</div>

<div class="row" style="margin-top:12px">
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
<div class="col-lg-10 col-md-10 col-sm-10 col-xs-10 h2 text-center">
Cars, Trucks, Motorcycles — Classic or Late Model
</div>
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
</div>

<div class="row" style="margin-top:12px">
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
<div class="col-lg-5 col-md-5 col-sm-5 col-xs-5">
  <div style="margin-bottom:8px"><img class="img-responsive" src="/images/Inspections/warranty.png" width="100%" /></div>
  <div class="h3">Place Your Confidence in Florida Inspection Associates, Inc.</div>
  <div style="margin-top:8px; margin-bottom:8px">
    <p>An independent appraisal company, FIA does not sell or repair motor vehicles, so there’s never a conflict of interest as we provide our clients with precise:</p>
    <ul>
    <li>Expert witness testimony</li>
    <li>Diminished Value assessments</li>
    <li>Total Loss Appraisal</li>
    <li>Forensic Mechanics</li>
    <li>Cause and Extent Examinations</li>
    <li>Mechanical Inspections</li>
    </ul>
    <p>Call <a href="tel:727-588-0331">727-588-0331</a> or <a href="tel:888-342-4678">888-342-4678</a> for a quote. We’ll come to you within 72 hours!
  </div>
</div>
<div class="col-lg-5 col-md-5 col-sm-5 col-xs-5">
<div style="margin-bottom:8px"><img class="img-responsive" src="/images/article-images/be_sure.png.jpg" width="100%" /></div>
<div class="h3">We are certified appraisers of:</div>
<div style="margin-top:8px; margin-bottom:8px; margin-left:10%">
  <ul>
  <li>Antique/classic cars</li>
  <li>Bankruptcies</li>
  <li>Diminished Value</li>
  <li>Donation value</li>
  <li>Estate vehicles</li>
  <li>Mediation </li>
  <li>Recent model cars, trucks and motorcycles</li>
  <li>Reconstruction work</li>
  <li>Special interest vehicles</li>
  <li>Total Loss</li>
  <li>Umpire Services</li>
  </ul>
</div>
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
</div>

<div class="row" style="margin-top:12px; margin-bottom:12px">
<div class="col-lg-12 col-md-12 col-sm-12 col-xs-12 h2 about-CTA">
CALL 727-588-0331 OR 888-342-4678 FOR A QUOTE. WE'LL COME TO YOU WITHIN 72 HOURS!
</div>
</div>

<div class="row" style="margin-top:12px; margin-bottom:12px">
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
<div class="col-lg-3 col-md-3 col-sm-3 col-xs-3">
<img src="/images/contact/Form-photo.jpg" alt="Contact FIA" width="100%" />
</div>
<div class="col-lg-7 col-md-7 col-sm-7 col-xs-7">
<h2>Connect with us today!</h2>
<? include('includes/connectform.php'); ?>

</div>
<div class="col-lg-1 col-md-1 col-sm-1 col-xs-1"></div>
</div>

<? include('includes/footer.php'); ?>

</div> <!-- end container -->
</body>
</html>