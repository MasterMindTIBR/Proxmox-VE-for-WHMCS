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
$_PVEWHMCS_LANG['btn_console'] = 'Console';

$_PVEWHMCS_LANG['uptime_prefix'] = 'Up';
$_PVEWHMCS_LANG['memory'] = 'Memory';
$_PVEWHMCS_LANG['memory_sub'] = '(RAM)';
$_PVEWHMCS_LANG['compute'] = 'Compute';
$_PVEWHMCS_LANG['compute_sub'] = '(CPU)';
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
$_PVEWHMCS_LANG['os'] = 'Operating system';
$_PVEWHMCS_LANG['kernel'] = 'Kernel';
$_PVEWHMCS_LANG['usage_title'] = 'Usage';
$_PVEWHMCS_LANG['range_hour'] = 'Last hour';
$_PVEWHMCS_LANG['range_day'] = 'Last day';
$_PVEWHMCS_LANG['range_week'] = 'Last week';
$_PVEWHMCS_LANG['chart_mem'] = 'Memory';
$_PVEWHMCS_LANG['chart_net'] = 'Network';
$_PVEWHMCS_LANG['chart_disk'] = 'Disk (read and write)';
$_PVEWHMCS_LANG['net_in'] = 'In';
$_PVEWHMCS_LANG['net_out'] = 'Out';
$_PVEWHMCS_LANG['disk_read'] = 'Read';
$_PVEWHMCS_LANG['disk_write'] = 'Write';
$_PVEWHMCS_LANG['chart_nodata'] = 'No data for this period yet.';
$_PVEWHMCS_LANG['chart_error'] = 'Could not load the usage data.';
$_PVEWHMCS_LANG['chart_updated'] = 'Updated at';
$_PVEWHMCS_LANG['rootpw_open'] = 'Change root password';
$_PVEWHMCS_LANG['rootpw_cancel'] = 'Cancel';
$_PVEWHMCS_LANG['rootpw_title'] = 'Root password';
$_PVEWHMCS_LANG['rootpw_help'] = 'Change the root user password. If the VPS is running, it restarts to apply the password; files and SSH keys stay the same.';
$_PVEWHMCS_LANG['rootpw_new'] = 'New password';
$_PVEWHMCS_LANG['rootpw_repeat'] = 'Repeat the new password';
$_PVEWHMCS_LANG['rootpw_submit'] = 'Change password';
$_PVEWHMCS_LANG['rootpw_rule'] = 'The root password must be at least 12 characters long and include letters and numbers.';
$_PVEWHMCS_LANG['rootpw_mismatch'] = 'The passwords do not match.';
$_PVEWHMCS_LANG['rootpw_confirm'] = 'The VPS will restart now to apply the new password. Continue?';
$_PVEWHMCS_LANG['rootpw_post_only'] = 'Use the form on the service page to change the root password.';
$_PVEWHMCS_LANG['rootpw_qemu_only'] = 'Root password reset is only available for virtual machines.';
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
$_PVEWHMCS_LANG['novnc_unavailable'] = 'The console is unavailable right now. Please try again in a few minutes or contact support.';

$_PVEWHMCS_LANG['service_not_active'] = 'Service is not active.';
$_PVEWHMCS_LANG['service_inactive_notice'] = 'This service is %s; the VPS details and actions are not available.';
$_PVEWHMCS_LANG['hypervisor_unavailable'] = 'Error: Unable to gather data from Hypervisor. Please contact Tech Support!';
