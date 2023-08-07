<?php
// FILE: novnc_router.php
// REPO: https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/
// TASK: Take WHMCS request, add browser cookie, then redirect to noVNC
// NOTE: Anti-CSRF means the Domain for both WHMCS and PVE must be the same, ONLY the Subdomain can differ.

if (isset($_GET['pveticket']) && isset($_GET['host']) && isset($_GET['path']) && isset($_GET['vncticket'])) {
	// Take passed-in variables and re-assign for usage
	$pveticket = $_GET['pveticket'];
	$vncticket = $_GET['vncticket'];
	$host = $_GET['host'];
	$path = $_GET['path'];

	// Get the requesting hostname/domain from the WHMCS-originated request
	$whmcsdomain = parse_url($_SERVER['HTTP_HOST']);
	// Now extract just the domain parts we need (FUTURE: capacity/option for multi-part TLDs)
	$domainonly = preg_replace("/^(.*?)\.(.*)$/","$2",$whmcsdomain['path']);
	// Set the cookie as Proxmox will be expecting it, so it is WHMCS to VNC without further login
	setrawcookie('PVEAuthCookie', $pveticket, 0, '/', $domainonly);

	// Create the final noVNC URL with the re-encoded vncticket
	$hostname = gethostbyaddr($host);
	$redirect_url = '/modules/servers/pvewhmcs/novnc/vnc.html?autoconnect=true&encrypt=true&host=' . $hostname . '&port=8006&password=' . urlencode($vncticket) . '&path=' . urlencode($path);

	// Redirect the visitor to noVNC & we're done
	header('Location: ' . $redirect_url);
	exit;
} else {
	// Passed in values not present, exit
	echo 'Error: Missing required info to route your request. Please try again.';
	exit;
}
?>
