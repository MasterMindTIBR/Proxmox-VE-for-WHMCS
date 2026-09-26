<?php

/*
	Proxmox VE for WHMCS - Client Area language file (English, default/fallback)
	File: /modules/servers/pvewhmcs/lang/english.php

	Loaded by pvewhmcs_load_client_lang() (modules/servers/pvewhmcs/pvewhmcs.php)
	based on the client's selected WHMCS language, with this file as the
	fallback when no matching translation exists. Keys are consumed by
	clientarea.tpl (as $lang['key']) and by the PHP-side client-facing
	strings (button labels, noVNC launcher).
*/

if (!defined('WHMCS')) {
	die('This file cannot be accessed directly');
}

$_PVEWHMCS_LANG['btn_start'] = 'Start';
$_PVEWHMCS_LANG['btn_reboot'] = 'Reboot';
$_PVEWHMCS_LANG['btn_poweroff'] = 'Power Off';
$_PVEWHMCS_LANG['btn_hardstop'] = 'Hard Stop';
$_PVEWHMCS_LANG['btn_statistics'] = 'Statistics';
$_PVEWHMCS_LANG['btn_checkstatus'] = 'Check Status';
$_PVEWHMCS_LANG['btn_console'] = 'Console (HTML5)';

$_PVEWHMCS_LANG['uptime_prefix'] = 'Up';
$_PVEWHMCS_LANG['memory'] = 'Memory';
$_PVEWHMCS_LANG['memory_sub'] = '(RAM)';
$_PVEWHMCS_LANG['compute'] = 'Compute';
$_PVEWHMCS_LANG['compute_sub'] = '(CPU)';
$_PVEWHMCS_LANG['cores_suffix'] = 'core(s)';
$_PVEWHMCS_LANG['sockets_on'] = 'on';
$_PVEWHMCS_LANG['sockets_suffix'] = 'socket(s)';
$_PVEWHMCS_LANG['storage'] = 'Storage';
$_PVEWHMCS_LANG['storage_sub'] = '(SSD/HDD)';
$_PVEWHMCS_LANG['ipv4'] = 'IPv4';
$_PVEWHMCS_LANG['ipv4_sub'] = '(Networking)';
$_PVEWHMCS_LANG['mask_label'] = 'Mask';
$_PVEWHMCS_LANG['gateway_label'] = 'Gateway';
$_PVEWHMCS_LANG['ip_config'] = 'IP Config';
$_PVEWHMCS_LANG['ip_config_sub'] = '(IPv4/v6)';
$_PVEWHMCS_LANG['nic0'] = 'NIC #0';
$_PVEWHMCS_LANG['nic0_sub'] = '(Primary)';
$_PVEWHMCS_LANG['nic1'] = 'NIC #1';
$_PVEWHMCS_LANG['nic1_sub'] = '(Secondary)';
$_PVEWHMCS_LANG['ssh_keys'] = 'SSH Keys';
$_PVEWHMCS_LANG['ssh_keys_sub'] = '(Public)';
$_PVEWHMCS_LANG['kernel'] = 'Kernel';
$_PVEWHMCS_LANG['kernel_sub'] = '(OS)';
$_PVEWHMCS_LANG['net_io'] = 'Network I/O';
$_PVEWHMCS_LANG['disk_io'] = 'Disk I/O';

$_PVEWHMCS_LANG['guest_statistics'] = 'Guest Statistics';
$_PVEWHMCS_LANG['daily'] = 'Daily';
$_PVEWHMCS_LANG['weekly'] = 'Weekly';
$_PVEWHMCS_LANG['monthly'] = 'Monthly';
$_PVEWHMCS_LANG['yearly'] = 'Yearly';
$_PVEWHMCS_LANG['stats_error'] = 'Stats Error: RRD Unavailable. Ask Support to upgrade/migrate RRD Data using:';

$_PVEWHMCS_LANG['novnc_opening'] = 'Opening the noVNC console...';
$_PVEWHMCS_LANG['novnc_manual_prefix'] = "If it doesn't open automatically,";
$_PVEWHMCS_LANG['novnc_manual_link'] = 'click here to open the console';
$_PVEWHMCS_LANG['novnc_prepare_failed'] = 'Failed to prepare noVNC.';
