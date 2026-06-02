<?
$arr_browsers = ["Firefox", "Chrome", "Safari", "Opera", 
                    "MSIE", "Trident", "Edge"];
 
$agent = $_SERVER['HTTP_USER_AGENT'];
 
$user_browser = '';
foreach ($arr_browsers as $browser) {
    if (strpos($agent, $browser) !== false) {
        $user_browser = $browser;
        break;
    }   
}
 
switch ($user_browser) {
    case 'MSIE':
        $user_browser = 'Internet Explorer';
        break;
 
    case 'Trident':
        $user_browser = 'Internet Explorer';
        break;
 
    case 'Edge':
        $user_browser = 'Internet Explorer';
        break;
}
 
echo "You are using ".$user_browser." browser";
?>