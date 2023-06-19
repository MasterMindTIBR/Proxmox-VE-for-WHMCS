<?php
if (isset($_GET['pveticket']) && isset($_GET['host']) && isset($_GET['path']) && isset($_GET['csrf_token'])) {
	$pveticket = $_GET['pveticket'];
	$host = $_GET['host'];
	$path = $_GET['path'];
    $csrf_token = $_GET['csrf_token'];
    $combined_cookie = $csrf_token . ':' . $pveticket;

	setrawcookie('PVEAuthCookie', $combined_cookie, 0, '/', $host);

	$redirect_url = '/modules/servers/pvewhmcs/novnc/vnc.html?host=' . $host . '&port=8006&path=' . urlencode($path);

	header('Location: ' . $redirect_url);
	exit;
} else {
	echo 'Error: Missing information. Please try again.';
}
?>