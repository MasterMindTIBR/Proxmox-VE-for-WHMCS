
/*  
	Proxmox VE for WHMCS - Addon/Server Modules for WHMCS (& PVE)
	https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/
	File: /modules/servers/pvewhmcs/tigervnc.php (VNC)

	Copyright (C) The Network Crew Pty Ltd (TNC) & Co.

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see https://www.gnu.org/licenses
*/

<html>
	<head>
		<title>Serial Console</title>
	</head>
	<body>
		<script>
			PVE_vnc_console_event = function(appletid, action, err) {
				
			};
		</script>
		<?php
		$applet='<APPLET id=\'pveKVMConsole-1018-vncapp\' CODE=\'com.tigervnc.vncviewer.VncViewer\' ARCHIVE=\'VncViewer.jar\' WIDTH=100% HEIGHT=100%>
				<param value=\''.$_GET['0'].'\' name=\'host\'>
				<param value=\''.$_GET['1'].'\' name=\'PVECert\'>
				<param value=\''.$_GET['2'].'\' name=\'Port\'>
				<param name=\'USERNAME\' value=\''.$_GET['3'].'\'>
				<param name=\'PASSWORD\' value=\''.$_GET['4'].'\'>
				</APPLET>';
		?>
		<?php echo $applet ; ?>
	</body>
</html>