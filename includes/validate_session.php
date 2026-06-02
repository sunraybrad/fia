<?PHP
session_start();

// check if session variable exists and exit if not
if(!isset($_SESSION['usrID'])) {
	unset($_SESSION['start']);
	unset($_SESSION['usrID']); 
	unset($_SESSION['usrName']); 
	$_SESSION = array();
	if (ini_get("session.use_cookies")) {
		$params = session_get_cookie_params();
		setcookie(session_name(), '', time() - 42000,
		$params["path"], $params["domain"],
		$params["secure"], $params["httponly"]
		);
	}
	session_destroy();
	
	// redirect to login
	if ($check == 'Admin') {
	header("Location: admin_login.php?session-timeout");
	} else if ($check == 'Inspect') {
	header("Location: search.php?session-timeout");
	}
}
?>