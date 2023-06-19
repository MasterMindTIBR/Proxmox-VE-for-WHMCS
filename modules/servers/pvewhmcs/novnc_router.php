<?php
// FILE: novnc_router.php
// TASK: Take WHMCS request, add browser cookie, then redirect to noVNC
if (isset($_GET['pveticket']) && isset($_GET['host']) && isset($_GET['path'])) {
	$pveticket = $_GET['pveticket'];
	$host = $_GET['host'];
	$path = $_GET['path'];

	// Get the requesting hostname/domain from request
    $whmcsdomain = parse_url($_SERVER['HTTP_HOST']);
    $domainonly = preg_replace("/^(.*?)\.(.*)$/","$2",$whmcsdomain['path']);
	setrawcookie('PVEAuthCookie', $pveticket, 0, '/', $domainonly);

	// Create the final noVNC URL with the re-encoded vncticket
	$hostname = gethostbyaddr($host);
	$redirect_url = '/modules/servers/pvewhmcs/novnc/vnc.html?autoconnect=true&encrypt=true&host=' . $hostname . '&port=8006&path=' . urlencode($path);

	header('Location: ' . $redirect_url);
	exit;
} else {
	echo 'Error: Missing required info to route your request. Please try again.';
}
?>