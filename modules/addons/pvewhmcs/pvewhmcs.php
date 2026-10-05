<?php

/*  
	Proxmox VE for WHMCS - Addon/Server Modules for WHMCS (& PVE)
	https://github.com/MasterMindTIBR/Proxmox-VE-for-WHMCS/ (MasterMind TI fork)
	Upstream: https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/
	File: /modules/addons/pvewhmcs/pvewhmcs.php (GUI Work)

	Copyright (C) The Network Crew Pty Ltd (TNC) & Co.
	Modified since 2026-09-25 by MasterMind TI (https://mastermindti.com.br).
	For other Contributors to PVEWHMCS, see CONTRIBUTORS.md

	This program is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program.  If not, see <https://www.gnu.org/licenses/>. 
*/

// Pull in the WHMCS database handler Capsule for SQL
use Illuminate\Database\Capsule\Manager as Capsule;

if (!defined('WHMCS')) {
	die('This file cannot be accessed directly');
}

// Define where the module operates in the Admin GUI
define( 'pvewhmcs_BASEURL', 'addonmodules.php?module=pvewhmcs' );

// This fork's repository: releases, docs and the update checker point here.
define('PVEWHMCS_REPO_URL', 'https://github.com/MasterMindTIBR/Proxmox-VE-for-WHMCS');

// DEP: Require the PHP API Class to interact with Proxmox VE
require_once('proxmox.php');

// CONFIG: Declare key options to the WHMCS Addon Module framework.
function pvewhmcs_config() {
	$configarray = array(
		"name" => "Proxmox VE for WHMCS",
		"description" => "Proxmox VE (Virtual Environment) & WHMCS, integrated & open-source! Provisioning & Management of VMs/CTs. Fork maintained by MasterMind TI.".is_pvewhmcs_outdated(),
		"version" => pvewhmcs_version(),
		"author" => "MasterMind TI (fork of The Network Crew Pty Ltd)",
		'language' => 'English'
	);
	return $configarray;
}

// VERSION: also stored in repo/version (for update-available checker)
function pvewhmcs_version(){
	return "1.3.7";
}

function pvewhmcs_verify_server_tls($secure) {
	if ($secure === null) {
		// WHMCS leaves serversecure NULL/absent on legacy servers: keep TLS
		// verification on. Everything else follows FILTER_VALIDATE_BOOLEAN, so
		// an explicitly empty/unchecked box ('') turns verification off.
		return true;
	}

	return filter_var($secure, FILTER_VALIDATE_BOOLEAN);
}

function pvewhmcs_action_log_service_labels(array $service_ids) {
	$service_ids = array_values(array_unique(array_filter(array_map('intval', $service_ids))));
	$labels = array();
	if (empty($service_ids)) {
		return $labels;
	}

	$rows = Capsule::table('tblhosting')
		->leftJoin('tblclients', 'tblclients.id', '=', 'tblhosting.userid')
		->whereIn('tblhosting.id', $service_ids)
		->select('tblhosting.id', 'tblhosting.domain', 'tblclients.firstname', 'tblclients.lastname')
		->get();

	foreach ($rows as $row) {
		$name = trim(($row->firstname ?? '') . ' ' . ($row->lastname ?? ''));
		$domain = ($row->domain !== null && $row->domain !== '') ? $row->domain : ('Service #' . $row->id);
		$labels[(int) $row->id] = ($name !== '' ? $name . ' — ' : '') . $domain;
	}

	return $labels;
}

/**
 * Maps every Proxmox guest (vtype:vmid) provisioned through this specific
 * WHMCS server to its linked service/client, so the Nodes and Guests admin
 * tabs can distinguish customer-owned guests from ones that exist on the
 * cluster but aren't tracked in mod_pvewhmcs_vms (manually created, imported
 * without linking, or left behind after a service was deleted).
 */
function pvewhmcs_guest_hosting_map($server_id) {
	$map = array();
	$rows = Capsule::table('mod_pvewhmcs_vms')
		->join('tblhosting', 'tblhosting.id', '=', 'mod_pvewhmcs_vms.id')
		->leftJoin('tblclients', 'tblclients.id', '=', 'tblhosting.userid')
		->where('tblhosting.server', '=', $server_id)
		->select(
			'mod_pvewhmcs_vms.vmid',
			'mod_pvewhmcs_vms.vtype',
			'tblhosting.id as hosting_id',
			'tblhosting.domain',
			'tblhosting.domainstatus',
			'tblclients.id as client_id',
			'tblclients.firstname',
			'tblclients.lastname',
			'tblclients.companyname'
		)
		->get();

	foreach ($rows as $row) {
		$name = trim((string) $row->companyname) !== ''
			? $row->companyname
			: trim(($row->firstname ?? '') . ' ' . ($row->lastname ?? ''));
		$key = $row->vtype . ':' . (int) $row->vmid;
		$map[$key] = array(
			'hosting_id'  => (int) $row->hosting_id,
			'client_id'   => (int) $row->client_id,
			'client_name' => $name !== '' ? $name : null,
			'domain'      => $row->domain,
			'status'      => $row->domainstatus,
		);
	}

	return $map;
}

function pvewhmcs_action_log_admin_labels(array $auth_ids) {
	$auth_ids = array_values(array_unique(array_filter(array_map('intval', $auth_ids))));
	$labels = array();
	if (empty($auth_ids)) {
		return $labels;
	}

	foreach (Capsule::table('tbladmins')->whereIn('id', $auth_ids)->get(array('id', 'username')) as $admin) {
		$labels[(int) $admin->id] = $admin->username;
	}

	return $labels;
}

/**
 * One page of the module action log for one server, plus its total row count.
 * `$level` null lists every level; page and size are clamped server-side so a
 * tampered query cannot ask for unbounded rows.
 */
function pvewhmcs_action_log_page($serverId, $level, $page, $perPage) {
	$query = Capsule::table('mod_pvewhmcs_logs')->where('server_id', (int) $serverId);
	if ($level !== null) {
		$query->where('level', $level);
	}
	$total = (clone $query)->count();
	$pages = max(1, (int) ceil($total / $perPage));
	$page = min(max(1, (int) $page), $pages);
	$entries = $query->orderBy('id', 'desc')->forPage($page, $perPage)->get();

	return array($entries, $total, $page, $pages);
}

/**
 * Prev/next pagination links for one action-log panel. The page parameter is
 * keyed per server and panel, so panels paginate independently.
 */
function pvewhmcs_action_log_pager($serverId, $pageParam, $page, $pages, $perPage, $total) {
	if ($pages <= 1) {
		return;
	}

	$link = static function ($targetPage) use ($serverId, $pageParam, $perPage) {
		return htmlspecialchars(pvewhmcs_BASEURL . '&tab=actions&per_page=' . (int) $perPage . '&' . $pageParam . '=' . (int) $targetPage, ENT_QUOTES, 'UTF-8');
	};

	echo '<div style="margin:8px 0 20px;">'
		. 'Page ' . (int) $page . ' of ' . (int) $pages . ' (' . (int) $total . ' entries on Server #' . (int) $serverId . ') — '
		. ($page > 1 ? '<a href="' . $link(1) . '">First</a> · <a href="' . $link($page - 1) . '">Previous</a>' : 'First · Previous')
		. ' · '
		. ($page < $pages ? '<a href="' . $link($page + 1) . '">Next</a> · <a href="' . $link($pages) . '">Last</a>' : 'Next · Last')
		. '</div>';
}

function pvewhmcs_render_action_log_table($entries, array $labels, array $admins = array()) {
	$html = '<table class="pve-table"><thead><tr>'
		. '<th>Time</th><th>Action</th><th>Service</th><th>VMID</th><th>Admin</th><th>Result</th><th>Details</th>'
		. '</tr></thead><tbody>';

	foreach ($entries as $entry) {
		$service_id = (int) $entry->service;
		$service_label = $service_id > 0
			? ($labels[$service_id] ?? ('Service #' . $service_id))
			: '—';
		$auth_id = (int) ($entry->auth_id ?? 0);
		$admin_label = $auth_id > 0 ? ($admins[$auth_id] ?? ('Admin #' . $auth_id)) : '—';
		$target_id = (int) $entry->target_id;
		$is_error = $entry->level === 'error';

		$html .= '<tr>';
		$html .= '<td>' . htmlspecialchars((string) $entry->timestamp) . '</td>';
		$html .= '<td><code>' . htmlspecialchars((string) $entry->action) . '</code></td>';
		$html .= '<td>' . htmlspecialchars($service_label) . '</td>';
		$html .= '<td>' . ($target_id > 0 ? (string) $target_id : '—') . '</td>';
		$html .= '<td>' . htmlspecialchars($admin_label) . '</td>';
		$html .= '<td>' . ($is_error ? '❌' : '✅') . ' ' . htmlspecialchars(ucfirst((string) $entry->level)) . '</td>';
		$html .= '<td>' . htmlspecialchars((string) $entry->response) . '</td>';
		$html .= '</tr>';
	}

	$html .= '</tbody></table>';

	return $html;
}

function pvewhmcs_plan_network_input($required) {
	$bridge = trim((string) ($_POST['bridge'] ?? ''));
	$suffix = trim((string) ($_POST['vmbr'] ?? ''));

	if ($bridge === '') {
		if ($required) {
			throw new InvalidArgumentException('Network name is required for bridged networking.');
		}

		return array('', '');
	}

	$network = $bridge . $suffix;
	if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$/', $network)) {
		throw new InvalidArgumentException('Network name may contain only letters, numbers, dots, hyphens, and underscores.');
	}

	return array($bridge, $suffix);
}

function pvewhmcs_csrf_token() {
	if (empty($_SESSION['pvewhmcs_csrf_token'])) {
		$_SESSION['pvewhmcs_csrf_token'] = bin2hex(random_bytes(32));
	}

	return $_SESSION['pvewhmcs_csrf_token'];
}

function pvewhmcs_csrf_field() {
	return '<input type="hidden" name="pvewhmcs_csrf_token" value="' . htmlspecialchars(pvewhmcs_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function pvewhmcs_valid_csrf() {
	$submitted = $_POST['pvewhmcs_csrf_token'] ?? '';

	return is_string($submitted) && hash_equals(pvewhmcs_csrf_token(), $submitted);
}

// WHMCS MODULE: ACTIVATION of the ADDON MODULE
// Imports db.sql (every statement is CREATE TABLE IF NOT EXISTS / INSERT IGNORE,
// so re-activating is safe) and reports the first failing statement.
function pvewhmcs_activate() {
	try {
		pvewhmcs_ensure_schema();
	} catch (\Throwable $e) {
		return array('status'=>'error','description'=>'Proxmox VE for WHMCS was not activated properly: ' . $e->getMessage());
	}

	return array('status'=>'success','description'=>'Proxmox VE for WHMCS was installed successfully!');
}

// WHMCS MODULE: DEACTIVATION
function pvewhmcs_deactivate() {
	// Return the assumed result (change?)
	return array('status'=>'success','description'=>'Proxmox VE for WHMCS successfully deactivated. Database tables/data retained.');
}

// WHMCS MODULE: Upgrade
function pvewhmcs_upgrade($vars) {
	// This function gets passed the old ver once post-update, hence lt check
	$currentlyInstalledVersion = $vars['version'];
	if (version_compare($currentlyInstalledVersion, '1.3.7', 'lt')) {
		pvewhmcs_ensure_schema();
		return;
	}

	// SQL Operations for v1.2.9/10 version
	if (version_compare($currentlyInstalledVersion, '1.2.10', 'lt')) {
		$schema = Capsule::schema();

		// Add the column "start_vmid" to the mod_pvewhmcs table
		if (!$schema->hasColumn('mod_pvewhmcs', 'start_vmid')) {
			$schema->table('mod_pvewhmcs', function ($table) {
				$table->integer('start_vmid')->default(100)->after('vnc_secret');
			});
		}

		// Add the column "vmid" to the mod_pvewhmcs_vms table
		if (!$schema->hasColumn('mod_pvewhmcs_vms', 'vmid')) {
			$schema->table('mod_pvewhmcs_vms', function ($table) {
				$table->integer('vmid')->default(0)->after('id');
			});
			// Populate ID into VMID for all previous guests
			Capsule::table('mod_pvewhmcs_vms')
				->where('vmid', 0)
				->update(['vmid' => Capsule::raw('id')]);
		}
	}

	// SQL Operations for v1.2.12 version
	if (version_compare($currentlyInstalledVersion, '1.2.12', 'lt')) {
		$schema = Capsule::schema();

		// Add the column "unpriv" to the mod_pvewhmcs_plans table
		if (!$schema->hasColumn('mod_pvewhmcs_plans', 'unpriv')) {
			$schema->table('mod_pvewhmcs_plans', function ($table) {
				$table->integer('unpriv')->default(0)->after('balloon');
			});
		}
	}

	// SQL Operations for v1.2.17 version
	if (version_compare($currentlyInstalledVersion, '1.2.17', 'lt')) {
	    try {
	        Capsule::schema()->table('mod_pvewhmcs_plans', function ($table) {
	            $table->smallInteger('cpus')->unsigned()->nullable()->change();
	            $table->smallInteger('cores')->unsigned()->nullable()->change();
	            $table->integer('memory')->unsigned()->default(0)->change();
	            $table->integer('swap')->unsigned()->nullable()->change();
	            $table->integer('disk')->unsigned()->nullable()->change();
	            $table->tinyInteger('vmbr')->unsigned()->nullable()->change();
	            $table->integer('netrate')->default(0)->change();
	            $table->integer('bw')->unsigned()->default(0)->change();
	            $table->integer('vlanid')->nullable()->change();
	            $table->integer('balloon')->default(0)->change();
	            $table->tinyInteger('unpriv')->unsigned()->default(0)->change();
	        });
	    } catch (\Throwable $e) {
	        // Debug logging (same style as ClientArea)
			if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
				logModuleCall(
					'pvewhmcs',
					__FUNCTION__,
					'Attempting v1.2.17 database upgrade failed.',
					$e->getMessage()
				);
			}
	    }
	}

	// SQL Operations for v1.3.6
	if (version_compare($currentlyInstalledVersion, '1.3.6', 'lt')) {
		Capsule::schema()->table('mod_pvewhmcs_plans', function ($table) {
			$table->string('vmbr', 64)->nullable()->default(null)->change();
		});

		if (!Capsule::schema()->hasTable('mod_pvewhmcs_logs')) {
			Capsule::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `mod_pvewhmcs_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `auth_id` int(11) NOT NULL DEFAULT '0',
  `user_id` int(11) NOT NULL DEFAULT '0',
  `service` int(11) NOT NULL DEFAULT '0',
  `timestamp` datetime NOT NULL,
  `node_id` int(11) NOT NULL DEFAULT '0',
  `target_id` int(11) NOT NULL DEFAULT '0',
  `level` varchar(10) NOT NULL,
  `type` text NOT NULL,
  `action` text NOT NULL,
  `request` text NOT NULL,
  `response` text NOT NULL,
  `raw` text NOT NULL,
  PRIMARY KEY (`id`)
)
SQL
			);
		}

		if (!Capsule::schema()->hasColumn('mod_pvewhmcs', 'console_relay_secret')) {
			Capsule::schema()->table('mod_pvewhmcs', function ($table) {
				$table->string('console_relay_secret', 255)->nullable()->default(null)->after('debug_mode');
			});
		}

		if (!Capsule::schema()->hasColumn('mod_pvewhmcs', 'console_relay_host')) {
			Capsule::schema()->table('mod_pvewhmcs', function ($table) {
				$table->string('console_relay_host', 255)->nullable()->default(null)->after('console_relay_secret');
			});
		}

		if (!Capsule::schema()->hasColumn('mod_pvewhmcs', 'console_relay_port')) {
			Capsule::schema()->table('mod_pvewhmcs', function ($table) {
				$table->integer('console_relay_port')->unsigned()->nullable()->default(null)->after('console_relay_host');
			});
		}

		if (!Capsule::schema()->hasColumn('mod_pvewhmcs', 'name_pattern')) {
			Capsule::schema()->table('mod_pvewhmcs', function ($table) {
				$table->string('name_pattern', 255)->nullable()->default(null)->after('console_relay_port');
			});
		}

		// Tracks whether SuspendAccount itself flipped a pre-existing HA
		// resource from 'started' to 'stopped', so UnsuspendAccount only
		// restores HA state the module actually changed.
		if (!Capsule::schema()->hasColumn('mod_pvewhmcs_vms', 'ha_suspended')) {
			Capsule::schema()->table('mod_pvewhmcs_vms', function ($table) {
				$table->boolean('ha_suspended')->default(0)->after('v6prefix');
			});
		}
	}
}

// UPDATE CHECKER: HTML notice when this fork published a newer version, else ''.
function is_pvewhmcs_outdated() {
	$latest = get_pvewhmcs_latest_version();
	if ($latest === null || !version_compare($latest, pvewhmcs_version(), '>')) {
		return '';
	}

	return "<br><span style='float:right;'><b>Proxmox VE for WHMCS is outdated: <a style='color:red' href='" . PVEWHMCS_REPO_URL . "/releases/latest' target='_blank'>Download v" . htmlspecialchars($latest, ENT_QUOTES, 'UTF-8') . "!</a></b></span>";
}

// UPDATE CHECKER: version published in this fork's `version` file, or null when
// unknown. Fetched at most once per request with short timeouts and cached
// (WHMCS TransientData: 12 h, failures 1 h), so a slow GitHub never stalls the
// admin area. Only a plain version number is accepted.
function get_pvewhmcs_latest_version() {
	static $latest = false;
	if ($latest !== false) {
		return $latest;
	}

	$cacheKey = 'pvewhmcs_latest_version';
	$cache = class_exists('\WHMCS\TransientData') ? \WHMCS\TransientData::getInstance() : null;
	if ($cache !== null) {
		$cached = $cache->retrieve($cacheKey);
		if (is_string($cached) && $cached !== '') {
			return $latest = ($cached === 'unknown' ? null : $cached);
		}
	}

	$ch = curl_init('https://raw.githubusercontent.com/MasterMindTIBR/Proxmox-VE-for-WHMCS/master/version');
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 3,
		CURLOPT_TIMEOUT => 5,
	));
	$body = curl_exec($ch);
	$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	curl_close($ch);

	$version = trim((string) $body);
	$latest = ($status === 200 && preg_match('/^\d+(\.\d+){1,3}$/', $version)) ? $version : null;
	if ($cache !== null) {
		$cache->store($cacheKey, $latest ?? 'unknown', $latest === null ? 3600 : 43200);
	}

	return $latest;
}

/**
 * Fetch RRD statistics from Proxmox with graceful error handling (Admin Area).
 * 
 * Proxmox RRD schema changed in PVE 9 from pve2-{type} to pve-{type}-9.0.
 * The ds parameter names (cpu, mem, netin, netout, etc.) remain valid.
 * 
 * RRD data may be unavailable when:
 *   - Node/VM was just created (RRD takes ~60s to populate)
 *   - RRD schema migration is incomplete on the PVE host
 *   - RRD files are corrupted or missing
 * 
 * @param PVE2_API $proxmox    The Proxmox API client instance
 * @param string   $path       The RRD API path (e.g., /nodes/{node}/rrd)
 * @param string   $timeframe  RRD timeframe: 'hour', 'day', 'week', 'month', 'year'
 * @param string   $ds         Data source(s): 'cpu', 'mem', 'netin,netout', etc.
 * @return string|null         Base64-encoded PNG image, or null if unavailable
 */
function pvewhmcs_addon_fetch_rrd($proxmox, $path, $timeframe, $ds) {
	$rrd_params = '?timeframe=' . $timeframe . '&ds=' . $ds . '&cf=AVERAGE';
	
	try {
		$rrd_data = $proxmox->get($path . $rrd_params);
		
		if (isset($rrd_data['image']) && !empty($rrd_data['image'])) {
			return base64_encode(pvewhmcs_rrd_image_bytes($rrd_data['image']));
		}
	} catch (Exception $e) {
		// RRD data unavailable - log if debug mode on
		if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
			logModuleCall(
				'pvewhmcs',
				'pvewhmcs_addon_fetch_rrd',
				'RRD fetch failed: ' . $path . ' (' . $ds . ', ' . $timeframe . ')',
				$e->getMessage()
			);
		}
	}
	
	return null;
}

// ADMIN MODULE GUI: output (HTML etc)
function pvewhmcs_output($vars) {
	pvewhmcs_ensure_schema();
	$modulelink = $vars['modulelink'];

	// Check for update and report if available
	if (!empty(is_pvewhmcs_outdated())) {
		$_SESSION['pvewhmcs']['infomsg']['title']='Proxmox VE for WHMCS: New version available!' ;
		$_SESSION['pvewhmcs']['infomsg']['message']='<a href="' . PVEWHMCS_REPO_URL . '/releases/latest" target="_blank">' . PVEWHMCS_REPO_URL . '/releases/latest</a>' ;
	}
		
	// Print Messages to GUI before anything else
	if (isset($_SESSION['pvewhmcs']['infomsg'])) {
		echo '
		<div class="infobox">
		<strong>
		<span class="title">' . $_SESSION['pvewhmcs']['infomsg']['title'] . '</span>
		</strong><br/>
		' . $_SESSION['pvewhmcs']['infomsg']['message'] . '
		</div>
		';
		unset($_SESSION['pvewhmcs']);
	}

	// CSRF: every state-changing request of this module is a POST carrying
	// pvewhmcs_csrf_field(). A POST without a valid token is dropped here,
	// before any handler below can act on it.
	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !pvewhmcs_valid_csrf()) {
		echo '<div class="alert alert-danger">Invalid or missing security token: the request was ignored and nothing was changed. Reload the page and try again.</div>';
		$_POST = array();
	}

	// Set the active tab based on the GET parameter, default to 'nodes'
	if (!isset($_GET['tab'])) {
		$_GET['tab'] = 'nodes';
	}

	// Start the HTML output for the Admin GUI
	echo '
	<div id="clienttabs">
	<ul class="nav nav-tabs admin-tabs">
	<li class="'.($_GET['tab']=="nodes" ? "active" : "").'"><a id="tabLink1" role="tab" href="'. pvewhmcs_BASEURL .'&amp;tab=nodes">Nodes</a></li>
	<li class="'.($_GET['tab']=="guests" ? "active" : "").'"><a id="tabLink2" role="tab" href="'. pvewhmcs_BASEURL .'&amp;tab=guests">Guests</a></li>
	<li class="'.($_GET['tab']=="vmplans" ? "active" : "").'"><a id="tabLink3" role="tab" href="'. pvewhmcs_BASEURL .'&amp;tab=vmplans">Plans</a></li>
	<li class="'.($_GET['tab']=="ippools" ? "active" : "").'"><a id="tabLink4" role="tab" href="'. pvewhmcs_BASEURL .'&amp;tab=ippools">IPv4</a></li>
	<li class="'.($_GET['tab']=="actions" ? "active" : "").'"><a id="tabLink5" role="tab" href="'. pvewhmcs_BASEURL .'&amp;tab=actions">Actions</a></li>
	<li class="'.($_GET['tab']=="support" ? "active" : "").'"><a id="tabLink6" role="tab" href="'. pvewhmcs_BASEURL .'&amp;tab=support">Support</a></li>
	<li class="'.($_GET['tab']=="config" ? "active" : "").'"><a id="tabLink7" role="tab" href="'. pvewhmcs_BASEURL .'&amp;tab=config">Config</a></li>
	<li class="'.($_GET['tab']=="logs" ? "active" : "").'"><a id="tabLink8" role="tab" href="'. pvewhmcs_BASEURL .'&amp;tab=logs">Logs</a></li>
	</ul>
	</div>
	<style>
	.pve-table {
		width: 100%;
		border-collapse: separate;
		border-spacing: 0;
		background: #fff;
		font-size: 13px;
	}
	.pve-table thead th {
		background: #f8f8f8;
		color: #333;
		font-weight: 600;
		padding: 12px 15px;
		text-align: left;
		border-bottom: 2px solid #e0e0e0;
		font-size: 12px;
		text-transform: uppercase;
		letter-spacing: 0.5px;
	}
	.pve-table tbody tr {
		transition: background 0.15s ease;
	}
	.pve-table tbody tr:hover {
		background: #faf8fc;
	}
	.pve-table tbody tr:not(:last-child) td {
		border-bottom: 1px solid #eee;
	}
	.pve-table tbody td {
		padding: 10px 15px;
		color: #333;
		vertical-align: middle;
	}
	.pve-table code {
		background: #f4f0f7;
		padding: 2px 8px;
		border-radius: 3px;
		font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
		font-size: 12px;
		color: #5c3d7a;
		font-weight: 600;
	}
	</style>
	<div class="tab-content admin-tabs">';

	// Handle form submissions for plans and Module Config (CSRF already checked)
	if (isset($_POST['plan_save_qemu']))
	{
		pvewhmcs_save_plan('kvm') ;
	}

	if (isset($_POST['plan_update_qemu']))
	{
		pvewhmcs_update_plan('kvm', (int) ($_GET['id'] ?? 0)) ;
	}

	if (isset($_POST['plan_save_lxc']))
	{
		pvewhmcs_save_plan('lxc') ;
	}

	if (isset($_POST['plan_update_lxc']))
	{
		pvewhmcs_update_plan('lxc', (int) ($_GET['id'] ?? 0)) ;
	}

	if (isset($_POST['save_config']))
	{
		save_config() ;
	}

	// NODES / GUESTS tab in ADMIN GUI
	// Every tab is real navigation (`&tab=...`) and only the requested tab's
	// body runs. Nodes, Guests and Logs call Proxmox (login + /cluster/resources
	// or /cluster/tasks, plus per-node RRD graphs on Nodes), so they must stay
	// behind their `$_GET['tab']` gate.
	echo '<div id="nodes" class="tab-pane '.($_GET['tab']=="nodes" ? "active" : "").'" >' ;

	if ($_GET['tab'] === 'nodes') {
	// Fetch all enabled Servers that use pvewhmcs
	$servers = Capsule::table('tblservers')
		->where('type', '=', 'pvewhmcs')
		->where('disabled', '=', 0)
		->orderBy('id', 'asc')
		->get();

	// Catch no-servers case early
	if ($servers->isEmpty()) {
		echo '<div class="alert alert-warning">No enabled WHMCS servers found for module type <code>pvewhmcs</code>. Add/enable a server in <em>Setup &gt; Products/Services &gt; Servers</em>.</div>';
	} else {
		foreach ($servers as $pve) {
			// One unreachable/misconfigured server must not take the whole tab
			// down: buffer this server's output and swap it for an error box
			// if anything below throws (finally also runs on `continue`).
			ob_start();
			try {
			// Decrypt server password (same approach as ClientArea)
			$api_data = array('password2' => $pve->password);
			$serverpassword = localAPI('DecryptPassword', $api_data);
			$serverpassword_plain = html_entity_decode($serverpassword['password'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$serverip       = pvewhmcs_connection_host($pve->hostname ?? '', $pve->ipaddress ?? '');
			$serverusername = $pve->username;
			$serverlabel    = !empty($pve->name) ? $pve->name : ('Server #' . $pve->id);
			$serverport     = pvewhmcs_connection_port($pve->port ?? '');
			$verify_ssl     = pvewhmcs_verify_server_tls($pve->secure ?? null);

			// Login + get cluster/resources
			$proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword_plain, $serverport, $verify_ssl);
			if (!$proxmox->login()) {
				echo '<div class="alert alert-danger">Unable to log in to PVE API on ' . htmlspecialchars($serverip) . '. Check credentials, connectivity & configurations.</div><center><img src="../modules/addons/pvewhmcs/img/forbidden.png"><br><a href="' . PVEWHMCS_REPO_URL . '" target="_blank"><img src="../modules/addons/pvewhmcs/img/logo-stacked.png" style="max-height:150px;"></a></center>';
				continue;
			}

			$cluster_resources = $proxmox->get('/cluster/resources'); // returns nodes, qemu, lxc, storage, pools, etc.

			// Debug logging (same style as ClientArea)
			if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
				logModuleCall(
					'pvewhmcs',
					__FUNCTION__,
					'CLUSTER RESOURCES [' . $serverlabel . ']:',
					json_encode($cluster_resources)
				);
			}

			if (!is_array($cluster_resources) || empty($cluster_resources)) {
				echo '<div class="alert alert-info">No resources returned.</div>';
				continue;
			}

			// Split resources
			$nodes = [];
			$guests = []; // qemu + lxc
			foreach ($cluster_resources as $resource) {
				if (!isset($resource['type'])) {
					continue;
				}
				if ($resource['type'] === 'node') {
					$nodes[] = $resource;
				} elseif ($resource['type'] === 'qemu' || $resource['type'] === 'lxc') {
					$guests[] = $resource;
				}
			}

			// Count running guests
			$running_guests = array_filter($guests, function($g) {
				return isset($g['status']) && $g['status'] === 'running';
			});

			// Cross-reference with mod_pvewhmcs_vms so each node can show how
			// many of its guests are actually tied to a WHMCS service, vs.
			// existing on the cluster untracked (manually created, imported
			// without linking, or orphaned after a service was deleted).
			$hosting_map = pvewhmcs_guest_hosting_map($pve->id);

			// ======== CLUSTER HEADER PANEL ========
			echo '<div class="panel panel-default" style="margin-bottom:20px;">';
			echo '<div class="panel-heading" style="background:#5c3d7a;color:#fff;">';
			echo '<h3 class="panel-title" style="margin:0;"><i class="fa fa-server"></i> '.htmlspecialchars($serverlabel).' <small style="color:#ccc;">('.htmlspecialchars($serverip).')</small></h3>';
			echo '</div>';
			echo '<div class="panel-body">';
			$server_version = $proxmox->get_version();

			// -------- Per-Node Info with RRD Graphs --------
			foreach ($nodes as $n) {
				$n_name    = isset($n['node']) ? $n['node'] : '(node)';
				$n_status  = isset($n['status']) ? $n['status'] : 'unknown';
				$n_uptime  = isset($n['uptime']) ? time2format($n['uptime']) : '—';
				$n_version = $server_version;
				$n_cpu_pct = isset($n['cpu']) ? round($n['cpu'] * 100, 1) : 0;
				$n_maxcpu  = isset($n['maxcpu']) ? $n['maxcpu'] : 0;
				$n_mem_pct = (isset($n['mem']) && isset($n['maxmem']) && $n['maxmem'] > 0)
					? round($n['mem'] * 100 / $n['maxmem'], 1)
					: 0;
				$n_mem_used = isset($n['mem']) ? round($n['mem'] / 1073741824, 1) : 0;
				$n_mem_max  = isset($n['maxmem']) ? round($n['maxmem'] / 1073741824, 1) : 0;

				// QEMU/LXC guests on this node, and how many are linked to a
				// WHMCS service ("customer-owned (total)").
				$node_qemu = 0;
				$node_qemu_linked = 0;
				$node_lxc = 0;
				$node_lxc_linked = 0;
				foreach ($guests as $g) {
					if (($g['node'] ?? null) !== $n_name) {
						continue;
					}
					$g_key = ($g['type'] ?? '') . ':' . (isset($g['vmid']) ? (int) $g['vmid'] : 0);
					$g_linked = isset($hosting_map[$g_key]);
					if (($g['type'] ?? '') === 'qemu') {
						$node_qemu++;
						$node_qemu_linked += $g_linked ? 1 : 0;
					} elseif (($g['type'] ?? '') === 'lxc') {
						$node_lxc++;
						$node_lxc_linked += $g_linked ? 1 : 0;
					}
				}

				$status_color = ($n_status === 'online') ? '#5cb85c' : '#d9534f';

				echo '<div style="border:1px solid #ddd;border-radius:4px;padding:15px;margin-bottom:15px;background:#fafafa;">';
				
				// Node Header Row
				echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;border-bottom:1px solid #eee;padding-bottom:10px;">';
				echo '<div>';
				echo '<h4 style="margin:0 0 5px 0;"><i class="fa fa-cube" style="color:#5c3d7a;"></i> <strong>' . htmlspecialchars($n_name) . '</strong> [<code>v' . htmlspecialchars($n_version) . '</code>]</h4>';
				echo '<span style="color:#555;font-size:12px;"><strong>Last Booted:</strong> <code>' . htmlspecialchars($n_uptime) . '</code></span>';
				echo '</div>';
				echo '<div style="text-align:right;">';
				echo '<span style="display:inline-block;padding:4px 12px;border-radius:3px;background:' . $status_color . ';color:#fff;font-weight:bold;text-transform:uppercase;font-size:11px;">' . htmlspecialchars($n_status) . '</span>';
				echo '</div>';
				echo '</div>';

				// Live Stats Grid (2x2: CPU+RAM on top, QEMU+LXC below)
				echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:15px;">';
				echo '<div style="text-align:center;padding:10px;background:#fff;border-radius:4px;border:1px solid #eee;">';
				echo '<div style="font-size:24px;font-weight:bold;color:#5c3d7a;">CPU: <code>' . $n_cpu_pct . '%</code></div>';
				echo '<div style="font-size:11px;color:#555;"><strong>' . $n_maxcpu . ' Cores</strong></div>';
				echo '</div>';
				echo '<div style="text-align:center;padding:10px;background:#fff;border-radius:4px;border:1px solid #eee;">';
				echo '<div style="font-size:24px;font-weight:bold;color:#5c3d7a;">RAM: <code>' . $n_mem_pct . '%</code></div>';
				echo '<div style="font-size:11px;color:#555;"><strong>' . $n_mem_used . ' of ' . $n_mem_max . 'GB</strong></div>';
				echo '</div>';
				echo '<div style="text-align:center;padding:10px;background:#fff;border-radius:4px;border:1px solid #eee;">';
				echo '<div style="font-size:20px;font-weight:bold;color:#5c3d7a;">QEMU: <code>' . $node_qemu_linked . ' (' . $node_qemu . ')</code></div>';
				echo '<div style="font-size:10px;color:#999;text-transform:uppercase;margin-top:2px;">Customers (all)</div>';
				echo '</div>';
				echo '<div style="text-align:center;padding:10px;background:#fff;border-radius:4px;border:1px solid #eee;">';
				echo '<div style="font-size:20px;font-weight:bold;color:#5c3d7a;">LXC: <code>' . $node_lxc_linked . ' (' . $node_lxc . ')</code></div>';
				echo '<div style="font-size:10px;color:#999;text-transform:uppercase;margin-top:2px;">Customers (all)</div>';
				echo '</div>';
				echo '</div>';

				// RRD Graphs Section
				$rrd_path = '/nodes/' . $n_name . '/rrd';
				$rrd_cpu = pvewhmcs_addon_fetch_rrd($proxmox, $rrd_path, 'hour', 'cpu');
				$rrd_mem = pvewhmcs_addon_fetch_rrd($proxmox, $rrd_path, 'hour', 'memused');
				$rrd_net = pvewhmcs_addon_fetch_rrd($proxmox, $rrd_path, 'hour', 'netin,netout');
				$rrd_io  = pvewhmcs_addon_fetch_rrd($proxmox, $rrd_path, 'hour', 'iowait');

				if ($rrd_cpu || $rrd_mem || $rrd_net || $rrd_io) {
					echo '<div style="margin-top:10px;">';
					echo '<div style="font-size:12px;color:#555;margin-bottom:8px;"><i class="fa fa-line-chart"></i> <strong>Performance Graphs</strong> (Last Hour)</div>';
					// Row 1: CPU and Memory
					echo '<div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:10px;">';
					if ($rrd_cpu) {
						echo '<div style="flex:1;min-width:45%;"><img src="data:image/png;base64,' . $rrd_cpu . '" style="width:100%;border-radius:3px;border:1px solid #ddd;"/></div>';
					}
					if ($rrd_mem) {
						echo '<div style="flex:1;min-width:45%;"><img src="data:image/png;base64,' . $rrd_mem . '" style="width:100%;border-radius:3px;border:1px solid #ddd;"/></div>';
					}
					echo '</div>';
					// Row 2: Network and I/O
					echo '<div style="display:flex;flex-wrap:wrap;gap:10px;">';
					if ($rrd_net) {
						echo '<div style="flex:1;min-width:45%;"><img src="data:image/png;base64,' . $rrd_net . '" style="width:100%;border-radius:3px;border:1px solid #ddd;"/></div>';
					}
					if ($rrd_io) {
						echo '<div style="flex:1;min-width:45%;"><img src="data:image/png;base64,' . $rrd_io . '" style="width:100%;border-radius:3px;border:1px solid #ddd;"/></div>';
					}
					echo '</div>';
					echo '</div>';
				} else {
					echo '<div class="alert alert-warning" style="margin:10px 0 0 0;padding:10px;font-size:12px;">';
					echo '<i class="fa fa-exclamation-triangle"></i> RRD data unavailable. Please ask Support to upgrade RRD stored data from 2.x to 9.0 format on their Nodes.';
					echo '</div>';
				}

				echo '</div>'; // End node panel
			}

			echo '</div>'; // panel-body
			echo '</div>'; // panel
			} catch (\Throwable $e) {
				ob_clean();
				echo '<div class="alert alert-danger">' . htmlspecialchars(!empty($pve->name) ? $pve->name : ('Server #' . $pve->id)) . ': ' . htmlspecialchars($e->getMessage()) . '</div>';
			} finally {
				ob_end_flush();
			}
		}
	}
	}
	echo '</div>';

	// ======== GUESTS TAB ========
	echo '<div id="guests" class="tab-pane '.($_GET['tab']=="guests" ? "active" : "").'" >';

	if ($_GET['tab'] === 'guests') {
	$show_all = isset($_GET['show_all']) && $_GET['show_all'] == '1';
	$toggle_url_linked = pvewhmcs_BASEURL . '&tab=guests';
	$toggle_url_all = pvewhmcs_BASEURL . '&tab=guests&show_all=1';
	echo '<div style="margin-bottom:15px;">';
	echo '<div class="btn-group" role="group">';
	echo '<a class="btn ' . (!$show_all ? 'btn-primary' : 'btn-default') . '" href="' . htmlspecialchars($toggle_url_linked, ENT_QUOTES, 'UTF-8') . '">Customers Only</a>';
	echo '<a class="btn ' . ($show_all ? 'btn-primary' : 'btn-default') . '" href="' . htmlspecialchars($toggle_url_all, ENT_QUOTES, 'UTF-8') . '">Show All</a>';
	echo '</div>';
	echo '</div>';

	// Re-use servers data for guests tab
	$servers = Capsule::table('tblservers')
		->where('type', '=', 'pvewhmcs')
		->where('disabled', '=', 0)
		->orderBy('id', 'asc')
		->get();

	if ($servers->isEmpty()) {
		echo '<div class="alert alert-warning">No enabled WHMCS servers found for module type <code>pvewhmcs</code>.</div>';
	} else {
		foreach ($servers as $pve) {
			// Same per-server isolation as the Nodes tab.
			ob_start();
			try {
			$api_data = array('password2' => $pve->password);
			$serverpassword = localAPI('DecryptPassword', $api_data);
			$serverpassword_plain = html_entity_decode($serverpassword['password'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$serverip       = pvewhmcs_connection_host($pve->hostname ?? '', $pve->ipaddress ?? '');
			$serverusername = $pve->username;
			$serverlabel    = !empty($pve->name) ? $pve->name : ('Server #' . $pve->id);
			$serverport     = pvewhmcs_connection_port($pve->port ?? '');
			$verify_ssl     = pvewhmcs_verify_server_tls($pve->secure ?? null);

			$proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword_plain, $serverport, $verify_ssl);
			if (!$proxmox->login()) {
				echo '<div class="alert alert-danger">Unable to log in to PVE API on ' . htmlspecialchars($serverip) . '. Check credentials, connectivity & configurations.</div><center><img src="../modules/addons/pvewhmcs/img/forbidden.png"><br><a href="' . PVEWHMCS_REPO_URL . '" target="_blank"><img src="../modules/addons/pvewhmcs/img/logo-stacked.png" style="max-height:150px;"></a></center>';
				continue;
			}

			$cluster_resources = $proxmox->get('/cluster/resources');
			if (!is_array($cluster_resources) || empty($cluster_resources)) {
				echo '<div class="alert alert-info">No resources returned.</div>';
				continue;
			}

			// Filter guests only
			$all_guests = [];
			foreach ($cluster_resources as $resource) {
				if (isset($resource['type']) && ($resource['type'] === 'qemu' || $resource['type'] === 'lxc')) {
					$all_guests[] = $resource;
				}
			}

			$hosting_map = pvewhmcs_guest_hosting_map($pve->id);
			$is_linked = function ($g) use ($hosting_map) {
				$key = ($g['type'] ?? '') . ':' . (isset($g['vmid']) ? (int) $g['vmid'] : 0);
				return isset($hosting_map[$key]);
			};
			$linked_total = count(array_filter($all_guests, $is_linked));
			$guests = $show_all ? $all_guests : array_values(array_filter($all_guests, $is_linked));

			$running_count = count(array_filter($guests, function($g) { return ($g['status'] ?? '') === 'running'; }));
			$stopped_count = count(array_filter($guests, function($g) { return ($g['status'] ?? '') === 'stopped'; }));

			echo '<div class="panel panel-default" style="margin-bottom:20px;">';
			echo '<div class="panel-heading" style="background:#5c3d7a;color:#fff;">';
			echo '<h3 class="panel-title" style="margin:0;"><i class="fa fa-desktop"></i> ' . htmlspecialchars($serverlabel) . ' <small style="color:#ddd;">(' . count($guests) . ' shown: ' . $running_count . ' running, ' . $stopped_count . ' stopped &bull; ' . $linked_total . ' of ' . count($all_guests) . ' linked to a customer)</small></h3>';
			echo '</div>';
			echo '<div class="panel-body" style="padding:0;padding-top:8px;">';

			if (count($guests) > 0) {
				echo '<table class="pve-table">';
				echo '<thead><tr>
						<th>VMID</th>
						<th>Name</th>
						<th>Customer</th>
						<th>Status</th>
						<th>Type</th>
						<th>Node</th>
						<th>CPU %</th>
						<th>RAM %</th>
						<th>Disk %</th>
						<th>Uptime</th>
					</tr></thead><tbody>';

				foreach ($guests as $g) {
					$g_node   = $g['node']  ?? '—';
					$g_type   = $g['type']  ?? '—';
					$g_vmid   = isset($g['vmid']) ? (int)$g['vmid'] : 0;
					$g_name   = $g['name']  ?? '';
					$g_status = $g['status'] ?? 'unknown';
					$g_uptime = isset($g['uptime']) ? time2format($g['uptime']) : '—';
					$g_cpu_pct = isset($g['cpu']) ? round($g['cpu'] * 100, 1) : 0;
					$g_mem_pct = (isset($g['maxmem']) && $g['maxmem'] > 0)
						? round(($g['mem'] ?? 0) * 100 / $g['maxmem'], 1)
						: 0;
					$g_dsk_pct = (isset($g['maxdisk']) && $g['maxdisk'] > 0)
						? round(($g['disk'] ?? 0) * 100 / $g['maxdisk'], 1)
						: 0;

					$type_icon = ($g_type === 'qemu') ? 'fa-desktop' : 'fa-cube';
					$status_color = ($g_status === 'running') ? '#5cb85c' : '#999';

					$linked = $hosting_map[$g_type . ':' . $g_vmid] ?? null;
					if ($linked) {
						$customer_label = htmlspecialchars((string) ($linked['client_name'] ?? ('Client #' . $linked['client_id'])), ENT_QUOTES, 'UTF-8');
						$domain_label = htmlspecialchars((string) ($linked['domain'] ?? ''), ENT_QUOTES, 'UTF-8');
						$customer_cell = '<a href="clientssummary.php?userid=' . (int) $linked['client_id'] . '" style="color:#5c3d7a;">' . $customer_label . '</a>'
							. ($domain_label !== '' ? '<div style="color:#888;font-size:11px;">' . $domain_label . '</div>' : '');
					} else {
						$customer_cell = '<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:#f0ad4e;color:#fff;font-size:10px;text-transform:uppercase;">Unlinked</span>';
					}

					echo '<tr>';
					echo '<td><code>' . $g_vmid . '</code></td>';
					echo '<td><i class="fa ' . $type_icon . '" style="color:#666;"></i> <strong>' . htmlspecialchars($g_name) . '</strong></td>';
					echo '<td>' . $customer_cell . '</td>';
					echo '<td><span style="display:inline-block;padding:2px 8px;border-radius:3px;background:' . $status_color . ';color:#fff;font-size:10px;text-transform:uppercase;">' . htmlspecialchars($g_status) . '</span></td>';
					echo '<td><span style="text-transform:uppercase;font-size:10px;background:#eee;padding:2px 6px;border-radius:3px;">' . htmlspecialchars($g_type) . '</span></td>';
					echo '<td>' . htmlspecialchars($g_node) . '</td>';
					echo '<td>' . $g_cpu_pct . '%</td>';
					echo '<td>' . $g_mem_pct . '%</td>';
					echo '<td>' . $g_dsk_pct . '%</td>';
					echo '<td>' . htmlspecialchars($g_uptime) . '</td>';
					echo '</tr>';
				}
				echo '</tbody></table>';
			} else {
				echo '<div class="alert alert-info" style="margin:15px;">' . ($show_all
					? 'No guests found on this cluster.'
					: 'No customer-linked guests found on this cluster. <a href="' . htmlspecialchars($toggle_url_all, ENT_QUOTES, 'UTF-8') . '">Show all guests</a> to include unlinked ones.') . '</div>';
			}

			echo '</div>'; // panel-body
			echo '</div>'; // panel
			} catch (\Throwable $e) {
				ob_clean();
				echo '<div class="alert alert-danger">' . htmlspecialchars(!empty($pve->name) ? $pve->name : ('Server #' . $pve->id)) . ': ' . htmlspecialchars($e->getMessage()) . '</div>';
			} finally {
				ob_end_flush();
			}
		}
	}
	}
	echo '</div>';

	// VM / CT PLANS tab in ADMIN GUI
	echo '<div id="plans" class="tab-pane '.($_GET['tab']=="vmplans" ? "active" : "").'">';
	if ($_GET['tab'] === 'vmplans') {
	echo '
	<div class="btn-group" role="group" aria-label="...">
	<a class="btn btn-default" href="'. pvewhmcs_BASEURL .'&amp;tab=vmplans&amp;action=add_qemu_plan">
	<i class="fa fa-plus-square"></i>&nbsp; Add: QEMU Plan
	</a>
	<a class="btn btn-default" href="'. pvewhmcs_BASEURL .'&amp;tab=vmplans&amp;action=add_lxc_plan">
	<i class="fa fa-plus-square"></i>&nbsp; Add: LXC Plan
	</a>
	<a class="btn btn-default" href="'. pvewhmcs_BASEURL .'&amp;tab=vmplans&amp;action=import_guest">
	<i class="fa fa-upload"></i>&nbsp; Import: Guest
	</a>
	</div>
	';

	// Handle actions based on the 'action' GET parameter. POST handlers run
	// only after the CSRF gate at the top of pvewhmcs_output().
	$plan_action = $_GET['action'] ?? '';
	if ($plan_action == 'import_guest') {
		import_guest() ;
	}
	
	if ($plan_action == 'add_qemu_plan') {
		qemu_plan_add() ;
	}

	if ($plan_action == 'editplan') {
		if (($_GET['vmtype'] ?? '') == 'kvm')
			qemu_plan_edit((int) ($_GET['id'] ?? 0)) ;
		else
			lxc_plan_edit((int) ($_GET['id'] ?? 0)) ;
	}

	if (($_POST['pvewhmcs_action'] ?? '') === 'removeplan') {
		remove_plan((int) $_POST['id']);
	}

	if ($plan_action == 'add_lxc_plan') {
		lxc_plan_add() ;
	}

	// List of VM / CT Plans (default view when the tab opens with no action yet)
	if (!isset($_GET['action']) || $_GET['action']=='planlist') {
		echo '
		<table class="datatable" border="0" cellpadding="3" cellspacing="1" width="100%">
		<tbody>
		<tr>
		<th>ID</th>
		<th>Name</th>
		<th>Guest</th>
		<th>OS Type</th>
		<th>CPUs</th>
		<th>Cores</th>
		<th>RAM</th>
		<th>Balloon</th>
		<th>Swap</th>
		<th>Disk</th>
		<th>Disk Type</th>
		<th>Disk I/O</th>
		<th>PVE Store</th>
		<th>Net Mode</th>
		<th>Bridge</th>
		<th>NIC</th>
		<th>VLAN ID</th>
		<th>Net Rate</th>
		<th>Net BW</th>
		<th>IPv6</th>
		<th>Unpriv.</th>
		<th>Actions</th>
		</tr>';
		foreach (Capsule::table('mod_pvewhmcs_plans')->get() as $vm) {
			echo '<tr>';
			echo '<td>' . (int) $vm->id . '</td>';
			echo '<td>' . htmlspecialchars((string) $vm->title, ENT_QUOTES, 'UTF-8') . '</td>';
			echo '<td>' . htmlspecialchars((string) $vm->vmtype, ENT_QUOTES, 'UTF-8') . '</td>';
			echo '<td>' . htmlspecialchars((string) $vm->ostype, ENT_QUOTES, 'UTF-8') . '</td>';
			echo '<td>' . $vm->cpus . '</td>';
			echo '<td>' . $vm->cores . '</td>';
			echo '<td>' . $vm->memory . '</td>';
			echo '<td>' . $vm->balloon . '</td>';
			echo '<td>' . $vm->swap . '</td>';
			echo '<td>' . $vm->disk . '</td>';
			echo '<td>' . htmlspecialchars((string) $vm->disktype, ENT_QUOTES, 'UTF-8') . '</td>';
			echo '<td>' . htmlspecialchars((string) $vm->diskio, ENT_QUOTES, 'UTF-8') . '</td>';
			echo '<td>' . htmlspecialchars((string) $vm->storage, ENT_QUOTES, 'UTF-8') . '</td>';
			echo '<td>' . htmlspecialchars((string) $vm->netmode, ENT_QUOTES, 'UTF-8') . '</td>';
			echo '<td>' . htmlspecialchars((string) $vm->bridge . (string) $vm->vmbr, ENT_QUOTES, 'UTF-8') . '</td>';
			echo '<td>' . htmlspecialchars((string) $vm->netmodel, ENT_QUOTES, 'UTF-8') . '</td>';
			echo '<td>' . $vm->vlanid . '</td>';
			echo '<td>' . $vm->netrate . '</td>';
			echo '<td>' . $vm->bw . '</td>';
			echo '<td>' . htmlspecialchars((string) $vm->ipv6, ENT_QUOTES, 'UTF-8') . '</td>';
			echo '<td>' . $vm->unpriv . '</td>';
			echo '<td>
			<a href="' . pvewhmcs_BASEURL . '&amp;tab=vmplans&amp;action=editplan&amp;id=' . (int) $vm->id . '&amp;vmtype=' . htmlspecialchars((string) $vm->vmtype, ENT_QUOTES, 'UTF-8') . '"><img height="16" width="16" border="0" alt="Edit" src="images/edit.gif"></a>
			<form method="post" style="display:inline" onsubmit="return confirm(\'Plan will be deleted, continue?\')">
			<input type="hidden" name="pvewhmcs_action" value="removeplan"><input type="hidden" name="id" value="' . (int) $vm->id . '">' . pvewhmcs_csrf_field() . '
			<button type="submit" style="border:0;background:transparent;padding:0"><img height="16" width="16" border="0" alt="Delete" src="images/delete.gif"></button>
			</form></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
	}
	echo '
	</div>
	';

	// IPv4 POOLS tab in ADMIN GUI
	echo '<div id="ippools" class="tab-pane '.($_GET['tab']=="ippools" ? "active" : "").'" >';
	if ($_GET['tab'] === 'ippools') {
	echo '
	<div class="btn-group">
	<a class="btn btn-default" href="'. pvewhmcs_BASEURL .'&amp;tab=ippools&amp;action=new_ip_pool">
	<i class="fa fa-plus-square"></i>&nbsp; New: IPv4 Pool
	</a>
	<a class="btn btn-default" href="'. pvewhmcs_BASEURL .'&amp;tab=ippools&amp;action=newip">
	<i class="fa fa-plus"></i>&nbsp; Add: IPv4 to Pool
	</a>
	<a class="btn btn-default" href="'. pvewhmcs_BASEURL .'&amp;tab=ippools&amp;action=reserved_ips">
	<i class="fa fa-lock"></i>&nbsp; Reserved IPv4
	</a>
	</div>
	';
	$ip_action = $_GET['action'] ?? '';
	if ($ip_action === '' || $ip_action == 'list_ip_pools') {
		list_ip_pools() ;
	}
	if ($ip_action == 'new_ip_pool') {
		add_ip_pool() ;
	}
	if ($ip_action == 'newip') {
		add_ip_2_pool() ;
	}
	if (isset($_POST['newIPpool'])) {
		save_ip_pool() ;
	}
	// POST handlers below run only after the CSRF gate in pvewhmcs_output().
	if (($_POST['pvewhmcs_action'] ?? '') === 'removeippool') {
		removeIpPool((int) $_POST['id']);
	}
	if (($_POST['pvewhmcs_action'] ?? '') === 'release_ipv4_reservation') {
		pvewhmcs_release_ipv4_reservation($_POST);
	}
	if ($ip_action == 'list_ips') {
		list_ips();
	}
	if ($ip_action == 'reserved_ips') {
		list_reserved_ips();
	}
	if (isset($_POST['single_delete_id']) && $_POST['single_delete_id'] !== '') {
		removeip((int) $_POST['single_delete_id'], (int) $_POST['pool_id']);
	} elseif (($_POST['pvewhmcs_action'] ?? '') === 'removeip_bulk') {
		removeip_bulk((array) ($_POST['ids'] ?? []), (int) $_POST['pool_id']);
	}
	}
	echo '</div>';

	// ACTIONS tab in ADMIN GUI
	echo '<div id="actions" class="tab-pane '.($_GET['tab']=="actions" ? "active" : "").'" >' ;
	if ($_GET['tab'] === 'actions') {

	// Every enabled pvewhmcs server gets its own isolated panels: rows are
	// filtered by the immutable server_id recorded with the action, so a
	// service later moved to another server keeps its history where it ran.
	$perPageChoices = array(25, 50, 100, 200);
	$perPage = (int) ($_GET['per_page'] ?? 50);
	if (!in_array($perPage, $perPageChoices, true)) {
		$perPage = 50;
	}

	$servers = Capsule::table('tblservers')
		->where('type', 'pvewhmcs')
		->where('disabled', 0)
		->orderBy('id')
		->get(array('id', 'name', 'hostname', 'ipaddress'));
	if ($servers->isEmpty()) {
		echo '<div class="alert alert-info">No enabled WHMCS server of module type pvewhmcs was found.</div>';
	}

	foreach ($servers as $server) {
		$serverLabel = trim((string) $server->name) !== ''
			? (string) $server->name
			: pvewhmcs_connection_host($server->hostname ?? '', $server->ipaddress ?? '');
		if ($serverLabel === '') {
			$serverLabel = 'Server #' . (int) $server->id;
		}
		$serverId = (int) $server->id;
		$historyPage = max(1, (int) ($_GET['page_' . $serverId] ?? 1));
		$failedPage = max(1, (int) ($_GET['fpage_' . $serverId] ?? 1));

		echo '<h2>Module: Action History — ' . htmlspecialchars($serverLabel) . '</h2>';
		list($action_history, $history_total, $history_page, $history_pages) = pvewhmcs_action_log_page($serverId, null, $historyPage, $perPage);
		list($failed_actions, $failed_total, $failed_page, $failed_pages) = pvewhmcs_action_log_page($serverId, 'error', $failedPage, $perPage);
		$action_log_labels = pvewhmcs_action_log_service_labels(
			array_merge($action_history->pluck('service')->all(), $failed_actions->pluck('service')->all())
		);
		$action_admin_labels = pvewhmcs_action_log_admin_labels(
			array_merge($action_history->pluck('auth_id')->all(), $failed_actions->pluck('auth_id')->all())
		);

		if ($action_history->isEmpty()) {
			echo '<div class="alert alert-info">No module actions have been recorded for this server yet.</div>';
		} else {
			echo pvewhmcs_render_action_log_table($action_history, $action_log_labels, $action_admin_labels);
		}
		pvewhmcs_action_log_pager($serverId, 'page_' . $serverId, $history_page, $history_pages, $perPage, $history_total);

		echo '<h2 style="margin-top:25px;">Module: Failed Actions — ' . htmlspecialchars($serverLabel) . '</h2>';
		if ($failed_actions->isEmpty()) {
			echo '<div class="alert alert-info">No failed actions recorded for this server.</div>';
		} else {
			echo pvewhmcs_render_action_log_table($failed_actions, $action_log_labels, $action_admin_labels);
		}
		pvewhmcs_action_log_pager($serverId, 'fpage_' . $serverId, $failed_page, $failed_pages, $perPage, $failed_total);
	}

	}
	echo '</div>';

	// SUPPORT tab in ADMIN GUI
	echo '<div id="support" class="tab-pane '.($_GET['tab']=="support" ? "active" : "").'" >';
	if ($_GET['tab'] === 'support') {
	echo '
	<div style="max-width:800px;">
		<div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:25px;margin-bottom:20px;">
			<h3 style="margin:0 0 15px 0;color:#5c3d7a;font-weight:600;"><span style="font-size:24px;">&#9881;</span> System Environment</h3>
			<table style="width:100%;font-size:14px;">
				<tr><td style="padding:6px 0;color:#666;width:150px;"><strong>Module Version</strong></td><td style="padding:6px 0;"><code style="background:#f4f0f7;padding:3px 8px;border-radius:3px;color:#5c3d7a;">v' . pvewhmcs_version() . '</code></td></tr>
				<tr><td style="padding:6px 0;color:#666;"><strong>Latest Version</strong></td><td style="padding:6px 0;"><code style="background:#f4f0f7;padding:3px 8px;border-radius:3px;color:#5c3d7a;">' . htmlspecialchars(get_pvewhmcs_latest_version() !== null ? 'v' . get_pvewhmcs_latest_version() : 'unknown (GitHub unreachable)', ENT_QUOTES, 'UTF-8') . '</code></td></tr>
				<tr><td style="padding:6px 0;color:#666;"><strong>Web Server</strong></td><td style="padding:6px 0;"><code style="background:#f4f0f7;padding:3px 8px;border-radius:3px;color:#5c3d7a;">' . htmlspecialchars($_SERVER['SERVER_SOFTWARE']) . '</code></td></tr>
				<tr><td style="padding:6px 0;color:#666;"><strong>PHP Version</strong></td><td style="padding:6px 0;"><code style="background:#f4f0f7;padding:3px 8px;border-radius:3px;color:#5c3d7a;">v' . phpversion() . '</code></td></tr>
				<tr><td style="padding:6px 0;color:#666;"><strong>Server Name</strong></td><td style="padding:6px 0;"><code style="background:#f4f0f7;padding:3px 8px;border-radius:3px;color:#5c3d7a;">' . htmlspecialchars($_SERVER['SERVER_NAME']) . '</code></td></tr>
			</table>
		</div>
		
		<div style="background:#faf8fc;border:1px solid #e0d4e8;border-radius:8px;padding:25px;margin-bottom:20px;">
			<h3 style="margin:0 0 15px 0;color:#5c3d7a;font-weight:600;"><span style="font-size:24px;">&#9829;</span> Open Source</h3>
			<p style="margin:0 0 12px 0;font-size:14px;line-height:1.6;color:#333;">PVEWHMCS is open-source and free to use &amp; improve on!</p>
			<p style="margin:0 0 12px 0;font-size:14px;line-height:1.6;color:#333;">This fork is maintained by <a href="https://mastermindti.com.br/" target="_blank" style="color:#5c3d7a;">MasterMind TI</a>. It is based on <a href="https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS" target="_blank" style="color:#5c3d7a;">Proxmox VE for WHMCS</a> by The Network Crew Pty Ltd (TNC) &amp; Co.</p>
			<p style="margin:0;">
				<a href="' . PVEWHMCS_REPO_URL . '/" target="_blank" style="color:#5c3d7a;">&#10132; GitHub Repository</a>
			</p>
		</div>
		
		<div style="background:#f8fff8;border:1px solid #c3e6c3;border-radius:8px;padding:25px;margin-bottom:20px;">
			<h3 style="margin:0 0 15px 0;color:#2d7a2d;font-weight:600;"><span style="font-size:24px;">&#9733;</span> Leave a Review</h3>
			<p style="margin:0 0 12px 0;font-size:14px;line-height:1.6;color:#333;">Your 5-star review on WHMCS Marketplace helps the module grow!</p>
			<p style="margin:0;">
				<a href="https://marketplace.whmcs.com/product/6935-proxmox-ve-for-whmcs" target="_blank" style="color:#2d7a2d;">&#9733;&#9733;&#9733;&#9733;&#9733; Rate on WHMCS Marketplace</a>
			</p>
		</div>
		
		<div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:25px;">
			<h3 style="margin:0 0 15px 0;color:#5c3d7a;font-weight:600;"><span style="font-size:24px;">&#9881;</span> Technical Support</h3>
			<p style="margin:0 0 12px 0;font-size:14px;line-height:1.6;">Our README contains a wealth of information. Please review it before raising issues.</p>
			<p style="margin:0 0 15px 0;">
				<a href="' . PVEWHMCS_REPO_URL . '#readme" target="_blank" style="color:#5c3d7a;">&#10132; View Documentation</a>
			</p>
			<p style="margin:0 0 12px 0;font-size:14px;line-height:1.6;">Only raise a GitHub Issue &mdash; including logs &mdash; if you have properly tried to resolve it first.</p>
			<p style="margin:0 0 15px 0;">
				<a href="' . PVEWHMCS_REPO_URL . '/issues/new/choose" target="_blank" style="color:#5c3d7a;">&#10132; Open an Issue</a>
			</p>
			<p style="margin:0;padding:12px;background:#fff8f0;border-radius:6px;font-size:13px;color:#856404;border:1px solid #ffc107;">&#9888; Help is not guaranteed (FOSS). We will need your assistance to troubleshoot.</p>
		</div>
		<a href="' . PVEWHMCS_REPO_URL . '" target="_blank"><img src="../modules/addons/pvewhmcs/img/logo-stacked.png" style="max-height:150px;"></a>
	</div>';
	}
	echo '</div>';

	// Config Tab
	echo '<div id="config" class="tab-pane '.($_GET['tab']=="config" ? "active" : "").'" >' ;
	if ($_GET['tab'] === 'config') {
	$config= Capsule::table('mod_pvewhmcs')->where('id', '=', '1')->get()[0];
	echo '
	<div style="max-width:800px;">
	<div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:25px;">
	<h3 style="margin:0 0 20px 0;color:#5c3d7a;font-weight:600;"><span style="font-size:24px;">&#9881;</span> Module Configuration</h3>
	<form method="post">' . pvewhmcs_csrf_field() . '
	<table style="width:100%;border-collapse:collapse;">
	<tr>
		<td style="padding:15px 0;border-bottom:1px solid #eee;width:150px;vertical-align:top;">
			<label style="font-weight:600;color:#333;">VNC Secret</label>
		</td>
		<td style="padding:15px 0;border-bottom:1px solid #eee;">
			<input type="password" autocomplete="new-password" style="width:100%;max-width:300px;padding:8px 12px;border:1px solid #ddd;border-radius:4px;font-size:14px;" name="vnc_secret" id="vnc_secret" value="" placeholder="' . (strlen((string) $config->vnc_secret) > 0 ? '••••••••••••••••' : 'Not set') . '">
			<p style="margin:8px 0 0 0;font-size:13px;color:#666;">Password for <code style="background:#f4f0f7;padding:2px 6px;border-radius:3px;color:#5c3d7a;">vnc@pve</code> user (minimum 15 characters). Required for VNC proxying &mdash; different from the Console Relay Secret below (this one is a Proxmox credential; the relay secret is unrelated). Leave blank to keep the current value. <a href="' . PVEWHMCS_REPO_URL . '#readme" target="_blank" style="color:#5c3d7a;"><u>View README</u></a></p>
		</td>
	</tr>
	<tr>
		<td style="padding:15px 0;border-bottom:1px solid #eee;vertical-align:top;">
			<label style="font-weight:600;color:#333;">Console Relay Secret</label>
		</td>
		<td style="padding:15px 0;border-bottom:1px solid #eee;">
			<input type="password" autocomplete="new-password" style="width:100%;max-width:300px;padding:8px 12px;border:1px solid #ddd;border-radius:4px;font-size:14px;" name="console_relay_secret" id="console_relay_secret" value="" placeholder="' . (strlen((string) $config->console_relay_secret) > 0 ? '••••••••••••••••' : 'Not set') . '">
			<p style="margin:8px 0 0 0;font-size:13px;color:#666;">Shared secret with the noVNC console relay (32+ random characters, e.g. <code style="background:#f4f0f7;padding:2px 6px;border-radius:3px;color:#5c3d7a;">openssl rand -hex 32</code>). Paste the same value into the relay\'s <code style="background:#f4f0f7;padding:2px 6px;border-radius:3px;color:#5c3d7a;">config.json</code>. Required for VNC proxying without exposing Proxmox publicly. Leave blank to keep the current value.</p>
		</td>
	</tr>
	<tr>
		<td style="padding:15px 0;border-bottom:1px solid #eee;vertical-align:top;">
			<label style="font-weight:600;color:#333;">Console Relay Host</label>
		</td>
		<td style="padding:15px 0;border-bottom:1px solid #eee;">
			<input type="text" style="width:100%;max-width:300px;padding:8px 12px;border:1px solid #ddd;border-radius:4px;font-size:14px;" name="console_relay_host" id="console_relay_host" placeholder="e.g. vnc.example.com" value="' . htmlspecialchars((string) $config->console_relay_host, ENT_QUOTES, 'UTF-8') . '">
			<p style="margin:8px 0 0 0;font-size:13px;color:#666;">Hostname the browser opens the console WebSocket against. Leave blank to use the WHMCS domain. Set this when the relay is deployed on its own subdomain.</p>
		</td>
	</tr>
	<tr>
		<td style="padding:15px 0;border-bottom:1px solid #eee;vertical-align:top;">
			<label style="font-weight:600;color:#333;">Console Relay Port</label>
		</td>
		<td style="padding:15px 0;border-bottom:1px solid #eee;">
			<input type="text" style="width:100%;max-width:300px;padding:8px 12px;border:1px solid #ddd;border-radius:4px;font-size:14px;" name="console_relay_port" id="console_relay_port" placeholder="443" value="' . htmlspecialchars((string) $config->console_relay_port, ENT_QUOTES, 'UTF-8') . '">
			<p style="margin:8px 0 0 0;font-size:13px;color:#666;">Leave blank for the default HTTPS port (443). Only set this if the relay subdomain serves HTTPS on a non-standard port.</p>
		</td>
	</tr>
	<tr>
		<td style="padding:15px 0;border-bottom:1px solid #eee;vertical-align:top;">
			<label style="font-weight:600;color:#333;">VMID Start</label>
		</td>
		<td style="padding:15px 0;border-bottom:1px solid #eee;">
			<input type="text" style="width:100%;max-width:300px;padding:8px 12px;border:1px solid #ddd;border-radius:4px;font-size:14px;" name="start_vmid" id="start_vmid" value="' . htmlspecialchars((string) $config->start_vmid, ENT_QUOTES, 'UTF-8') . '">
			<p style="margin:8px 0 0 0;font-size:13px;color:#666;">For Guests. Increments until a vacant VMID found. Default is <code style="background:#f4f0f7;padding:2px 6px;border-radius:3px;color:#5c3d7a;">100</code></p>
		</td>
	</tr>
	<tr>
		<td style="padding:15px 0;border-bottom:1px solid #eee;vertical-align:top;">
			<label style="font-weight:600;color:#333;">VM Name Pattern (Global Default)</label>
		</td>
		<td style="padding:15px 0;border-bottom:1px solid #eee;">
			<input type="text" style="width:100%;max-width:400px;padding:8px 12px;border:1px solid #ddd;border-radius:4px;font-size:14px;" name="name_pattern" id="name_pattern" placeholder="e.g. vm{vmid}-{clientname}-{hostname}" value="' . htmlspecialchars((string) $config->name_pattern, ENT_QUOTES, 'UTF-8') . '">
			<p style="margin:8px 0 0 0;font-size:13px;color:#666;">Used for any product whose own "VM Name Pattern" (per-product config) is left blank. Leave both blank to keep the legacy <code style="background:#f4f0f7;padding:2px 6px;border-radius:3px;color:#5c3d7a;">&lt;order/service id&gt;-&lt;hostname&gt;</code> name. Tokens: <code style="background:#f4f0f7;padding:2px 6px;border-radius:3px;color:#5c3d7a;">{vmid} {node} {serviceid} {orderid} {clientid} {clientname} {hostname} {pid} {plan} {date}</code></p>
		</td>
	</tr>
	<tr>
		<td style="padding:15px 0;vertical-align:top;">
			<label style="font-weight:600;color:#333;">Debug Mode</label>
		</td>
		<td style="padding:15px 0;">
			<label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
				<input type="checkbox" name="debug_mode" value="1" '. ($config->debug_mode=="1" ? "checked" : "").' style="width:18px;height:18px;">
				<span style="font-size:14px;color:#333;">Enable Debug Logging</span>
			</label>
			<p style="margin:8px 0 0 0;font-size:13px;color:#666;">Must also enable WHMCS Module Log. <a href="/admin/index.php?rp=/admin/logs/module-log" style="color:#5c3d7a;"><u>View Module Logs</u></a></p>
		</td>
	</tr>
	</table>
	<div style="margin-top:25px;padding-top:20px;border-top:1px solid #eee;">
		<input type="submit" style="background:#5c3d7a;color:#fff;border:none;padding:10px 24px;border-radius:4px;font-size:14px;font-weight:500;cursor:pointer;margin-right:10px;" value="Save Changes" name="save_config" id="save_config">
		<input type="reset" style="background:#f5f5f5;color:#333;border:1px solid #ddd;padding:10px 24px;border-radius:4px;font-size:14px;font-weight:500;cursor:pointer;" value="Cancel">
	</div>
	</form>
	</div>
	<a href="' . PVEWHMCS_REPO_URL . '" target="_blank"><img src="../modules/addons/pvewhmcs/img/logo-stacked.png" style="max-height:150px;"></a>
	</div>
	';
	}
	echo '</div>';

	// LOGS tab in ADMIN GUI
	echo '<div id="logs" class="tab-pane ' . (isset($_GET['tab']) && $_GET['tab'] === 'logs' ? 'active' : '') . '">';

	if ($_GET['tab'] === 'logs') {

	// Every enabled pvewhmcs server gets its own panel; one unreachable or
	// misconfigured server cannot blank the whole tab (same pattern as Nodes).
	$servers = Capsule::table('tblservers')
		->where('type', 'pvewhmcs')
		->where('disabled', 0)
		->orderBy('id', 'asc')
		->get();

	if ($servers->isEmpty()) {
		echo '<div class="alert alert-info">No enabled WHMCS server of module type pvewhmcs was found.</div>';
	}

	foreach ($servers as $pve) {
		$serverLabel = trim((string) $pve->name) !== ''
			? (string) $pve->name
			: pvewhmcs_connection_host($pve->hostname ?? '', $pve->ipaddress ?? '');
		if ($serverLabel === '') {
			$serverLabel = 'Server #' . (int) $pve->id;
		}

		echo '<div class="panel panel-default" style="margin-bottom:20px;">';
		echo '<div class="panel-heading" style="background:#5c3d7a;color:#fff;"><h3 class="panel-title" style="margin:0;"><i class="fa fa-history"></i> Cluster task history — ' . htmlspecialchars($serverLabel) . '</h3></div>';
		echo '<div class="panel-body">';
		ob_start();
		try {
			pvewhmcs_render_cluster_task_log($pve);
			ob_end_flush();
		} catch (Throwable $e) {
			ob_end_clean();
			echo '<div class="alert alert-danger">Could not retrieve PVE Cluster history: '
				. htmlspecialchars($e->getMessage()) . '</div>';
		}
		echo '</div></div>';
	}
	}
	echo '</div></div>'; 
	// End of tabbed content
}

// LOGS tab: cluster task history of ONE enabled pvewhmcs server, rendered into
// the current output buffer. Throws on any per-server failure so the caller can
// isolate it inside its own panel.
function pvewhmcs_render_cluster_task_log($pve) {
	$dec = localAPI('DecryptPassword', ['password2' => $pve->password]);
	$serverpassword = html_entity_decode($dec['password'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
	if (!$serverpassword) {
		throw new Exception('Could not decrypt Proxmox server password.');
	}

	$serverip = pvewhmcs_connection_host($pve->hostname ?? '', $pve->ipaddress ?? '');
	$serverport = pvewhmcs_connection_port($pve->port ?? '');
	$verify_ssl = pvewhmcs_verify_server_tls($pve->secure ?? null);
	$proxmox = new PVE2_API($serverip, $pve->username, "pam", $serverpassword, $serverport, $verify_ssl);
	if (!$proxmox->login()) {
		throw new Exception('Unable to log in to PVE API on ' . $serverip . '. Check credentials, connectivity & configurations.');
	}

	// /cluster/tasks takes no parameters; show the 150 newest entries.
	$limit = 150;
	$tasks = $proxmox->get('/cluster/tasks');

	// Optional debug logging
	if (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1) {
		logModuleCall('pvewhmcs', 'ADMIN LOGS: /cluster/tasks', 'limit=' . $limit, json_encode($tasks));
	}

	if (!is_array($tasks) || empty($tasks)) {
		echo '<div class="alert alert-info">No recent cluster tasks were returned.</div>';
		return;
	}

	// Sort newest first (defensive)
	usort($tasks, function ($a, $b) {
		return (intval($b['starttime'] ?? 0)) <=> (intval($a['starttime'] ?? 0));
	});
	$tasks = array_slice($tasks, 0, $limit);

	echo '<table class="pve-table">';
	echo '<thead><tr>
			<th>Task</th>
			<th>VMID</th>
			<th>Status</th>
			<th>Node</th>
			<th>User</th>
			<th>Duration</th>
			<th>Start</th>
			<th>End</th>
		  </tr></thead><tbody>';

	foreach ($tasks as $t) {
		$node   = $t['node'] ?? '—';
		$type   = $t['type'] ?? '';
		$user   = $t['user'] ?? '';
		$upid   = $t['upid'] ?? '';

		// Derive VMID:
		// 1) Prefer numeric $t['id'] when available
		// 2) Otherwise parse from UPID ("...:type:<vmid>:user@realm:")
		$vmid = '—';
		if (isset($t['id']) && preg_match('/^\d+$/', (string)$t['id'])) {
			$vmid = (string)$t['id'];
		} elseif (is_string($upid) && $upid !== '') {
			// UPID format: UPID:node:pid:pstart:starttime:type:vmid:user@realm:
			if (preg_match('/^UPID:[^:]*:[^:]*:[^:]*:[^:]*:[^:]*:([^:]*):/', $upid, $m)) {
				if ($m[1] !== '' && ctype_digit($m[1])) {
					$vmid = $m[1];
				}
			}
		}

		$startTs = (int)($t['starttime'] ?? 0);
		$endTs   = isset($t['endtime']) ? (int)$t['endtime'] : null;

		$start = $startTs ? date('Y-m-d H:i:s', $startTs) : '—';
		$end   = $endTs   ? date('Y-m-d H:i:s', $endTs)   : '—';

		$durSec = $startTs ? (is_null($endTs) ? (time() - $startTs) : max(0, $endTs - $startTs)) : null;
		$durH   = is_null($durSec)
			? '—'
			: sprintf('%02d:%02d:%02d', intdiv($durSec, 3600), intdiv($durSec % 3600, 60), $durSec % 60);

		$status = $t['status'] ?? (is_null($endTs) ? 'running' : '');
		$badge  = ($status === 'OK')
			? '✅'
			: ((preg_match('/(error|fail|aborted|unknown)/i', (string)$status)) ? '❌' : '⏳');

		echo '<tr>';
		echo '<td><code>' . htmlspecialchars($type) . '</code></td>';
		echo '<td><code>' . htmlspecialchars($vmid) . '</code></td>';
		echo '<td>' . $badge . ' ' . htmlspecialchars($status) . '</td>';
		echo '<td>' . htmlspecialchars($node) . '</td>';
		echo '<td>' . htmlspecialchars($user) . '</td>';
		echo '<td>' . htmlspecialchars($durH) . '</td>';
		echo '<td>' . htmlspecialchars($start) . '</td>';
		echo '<td>' . htmlspecialchars($end) . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
}

function pvewhmcs_is_ipv4_netmask($mask) {
	if (!filter_var($mask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
		return false;
	}
	$inverted = ~ip2long($mask) & 0xFFFFFFFF;

	return ($inverted & ($inverted + 1)) === 0;
}

// Import Guest sub-page handler (standalone, outside pvewhmcs_output)
// This function associates an existing PVE Guest in WHMCS as a new Client Service.
function import_guest() {
	$resultMsg = '';
	if (!empty($_POST['import_existing_guest'])) {
		$vmid = (int) ($_POST['import_vmid'] ?? 0);
		$userid = (int) ($_POST['import_clientid'] ?? 0);
		$productid = (int) ($_POST['import_productid'] ?? 0);
		$ipaddress = trim((string) ($_POST['import_ipv4'] ?? ''));
		$subnetmask = trim((string) ($_POST['import_subnet'] ?? ''));
		$gateway = trim((string) ($_POST['import_gateway'] ?? ''));
		$hostname = trim((string) ($_POST['import_hostname'] ?? ''));
		$vtype = (($_POST['import_vtype'] ?? '') === 'lxc') ? 'lxc' : 'qemu';

		$client = Capsule::table('tblclients')->where('id', $userid)->where('status', 'Active')->first();
		$product = Capsule::table('tblproducts')->where('id', $productid)->where('retired', 0)->first();
		// The new service uses the first server of the product's server group.
		$serverRel = $product ? Capsule::table('tblservergroupsrel')->where('groupid', $product->servergroup)->first() : null;
		$serverID = $serverRel ? (int) $serverRel->serverid : 0;
		// A VMID may back only one service per Proxmox server, or two customers
		// would power/suspend/cancel the same guest.
		$linked = Capsule::table('mod_pvewhmcs_vms')
			->join('tblhosting', 'tblhosting.id', '=', 'mod_pvewhmcs_vms.id')
			->where('mod_pvewhmcs_vms.vmid', $vmid)
			->where('tblhosting.server', $serverID)
			->value('tblhosting.id');

		if ($vmid < 100) {
			$resultMsg = '<div class="errorbox">PVE VMID must be a number of 100 or more.</div>';
		} elseif (!$client) {
			$resultMsg = '<div class="errorbox">No active WHMCS Client found with ID ' . $userid . '</div>';
		} elseif (!$product) {
			$resultMsg = '<div class="errorbox">No active WHMCS Product found with ID ' . $productid . '</div>';
		} elseif ($hostname === '') {
			$resultMsg = '<div class="errorbox">Hostname is required.</div>';
		} elseif (!filter_var($ipaddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !pvewhmcs_is_ipv4_netmask($subnetmask)) {
			$resultMsg = '<div class="errorbox">IPv4 and Gateway must be IPv4 addresses, and Subnet a dotted netmask such as 255.255.255.0.</div>';
		} elseif ($linked) {
			$resultMsg = '<div class="errorbox">VMID ' . $vmid . ' is already linked to Service #' . (int) $linked . ' on this server. Nothing was imported.</div>';
		} else {
			try {
				// Service and guest link are created together or not at all.
				$serviceID = Capsule::connection()->transaction(function ($connection) use ($userid, $productid, $hostname, $serverID, $ipaddress, $vmid, $vtype, $subnetmask, $gateway) {
					/** @var \Illuminate\Database\Connection $connection */
					$serviceID = $connection->table('tblhosting')->insertGetId([
						'userid' => $userid,
						'packageid' => $productid,
						'regdate' => date('Y-m-d'),
						'domain' => $hostname,
						'paymentmethod' => 'banktransfer',
						'firstpaymentamount' => '0.00',
						'amount' => '0.00',
						'billingcycle' => 'Monthly',
						'nextduedate' => date('Y-m-d'),
						'nextinvoicedate' => date('Y-m-d'),
						'orderid' => 0,
						'domainstatus' => 'Active',
						'username' => 'root',
						'password' => '',
						'subscriptionid' => '',
						'promoid' => 0,
						'server' => $serverID,
						'dedicatedip' => $ipaddress,
						'assignedips' => $ipaddress,
						'ns1' => '',
						'ns2' => '',
						'diskusage' => 0,
						'disklimit' => 0,
						'bwusage' => 0,
						'bwlimit' => 0,
						'lastupdate' => date('Y-m-d H:i:s'),
						'suspendreason' => '',
						'overideautosuspend' => 0,
						'overidesuspenduntil' => '',
						'notes' => 'PVEWHMCS: Imported from Proxmox Guest VMID ' . $vmid,
					]);
					$connection->table('mod_pvewhmcs_vms')->insert([
						'id' => $serviceID,
						'vmid' => $vmid,
						'user_id' => $userid,
						'vtype' => $vtype,
						'ipaddress' => $ipaddress,
						'subnetmask' => $subnetmask,
						'gateway' => $gateway,
						'created' => date('Y-m-d H:i:s'),
					]);

					return $serviceID;
				});
				$resultMsg = '<div class="successbox">Successfully imported PVE VMID ' . $vmid . ' (' . $vtype . ') as Service ' . (int) $serviceID . ' (' . htmlspecialchars((string) $product->name) . ') for ' . htmlspecialchars(trim($client->firstname . ' ' . $client->lastname . ' ' . $client->companyname)) . '</div>';
			} catch (\Throwable $e) {
				$resultMsg = '<div class="errorbox">Could not import the guest; nothing was saved: ' . htmlspecialchars($e->getMessage()) . '</div>';
			}
		}
	}

	// Always show the form for easy further imports
	if (!empty($resultMsg)) echo $resultMsg;
	echo '<form method="post">' . pvewhmcs_csrf_field();
	echo '<table class="form" border="0" cellpadding="3" cellspacing="1" width="100%">';
	echo '<tr><td class="fieldlabel">PVE VMID</td><td class="fieldarea"><input type="text" name="import_vmid" required></td></tr>';
	echo '<tr><td class="fieldlabel">Hostname</td><td class="fieldarea"><input type="text" name="import_hostname" required></td></tr>';

	// Active clients dropdown
	$clients = Capsule::table('tblclients')->where('status', 'Active')->orderBy('companyname')->orderBy('firstname')->orderBy('lastname')->get();
	echo '<tr><td class="fieldlabel">Target Client</td><td class="fieldarea"><select name="import_clientid" required>';
	foreach ($clients as $client) {
		$label = $client->id . ' - ' . ($client->companyname ? $client->companyname . ' - ' : '') . $client->firstname . ' ' . $client->lastname;
		echo '<option value="' . $client->id . '">' . htmlspecialchars($label) . '</option>';
	}
	echo '</select></td></tr>';
	
	// Product/Service dropdown (only Active products of Server type)
	$products = Capsule::table('tblproducts')->where('type', 'server')->where('retired', 0)->orderBy('name')->get();
	echo '<tr><td class="fieldlabel">Service</td><td class="fieldarea"><select name="import_productid" required>';
	foreach ($products as $product) {
		echo '<option value="' . $product->id . '">' . htmlspecialchars($product->name) . '</option>';
	}
	echo '</select></td></tr>';
	
	// Guest Type dropdown
	echo '<tr><td class="fieldlabel">VM / CT</td><td class="fieldarea"><select name="import_vtype" required>';
	echo '<option value="qemu">(VM) QEMU</option>';
	echo '<option value="lxc">(CT) LXC</option>';
	echo '</select></td></tr>';
	
	// IPv4, Subnet, Gateway
	echo '<tr><td class="fieldlabel">IPv4</td><td class="fieldarea"><input type="text" name="import_ipv4" required></td></tr>';
	echo '<tr><td class="fieldlabel">Subnet</td><td class="fieldarea"><input type="text" name="import_subnet" required></td></tr>';
	echo '<tr><td class="fieldlabel">Gateway</td><td class="fieldarea"><input type="text" name="import_gateway" required></td></tr>';
	echo '</table>';
	echo '<div class="btn-container"><input type="submit" class="btn btn-primary" value="Import Guest" name="import_existing_guest" id="import_existing_guest"></div>';
	echo '</form>';
}

// MODULE CONFIG: Commit changes to the database
function save_config() {
	try {
		$start_vmid = filter_var(trim((string) ($_POST['start_vmid'] ?? '')), FILTER_VALIDATE_INT, array('options' => array('min_range' => 100, 'max_range' => 999999999)));
		if ($start_vmid === false) {
			throw new InvalidArgumentException('VMID Start must be a whole number from 100 to 999999999.');
		}

		$relay_host = trim((string) ($_POST['console_relay_host'] ?? ''));
		if ($relay_host !== '' && filter_var($relay_host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false && filter_var($relay_host, FILTER_VALIDATE_IP) === false) {
			throw new InvalidArgumentException('Console Relay Host must be a hostname or IP address (no scheme, port or path).');
		}

		$relay_port = trim((string) ($_POST['console_relay_port'] ?? ''));
		if ($relay_port !== '') {
			$relay_port = filter_var($relay_port, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 65535)));
			if ($relay_port === false) {
				throw new InvalidArgumentException('Console Relay Port must be empty or a port number from 1 to 65535.');
			}
		}

		$name_pattern = trim((string) ($_POST['name_pattern'] ?? ''));
		if (strlen($name_pattern) > 255) {
			throw new InvalidArgumentException('VM Name Pattern must be at most 255 characters.');
		}

		$update = [
			'start_vmid' => $start_vmid,
			'debug_mode' => (int) !empty($_POST['debug_mode']),
			'console_relay_host' => $relay_host !== '' ? $relay_host : null,
			'console_relay_port' => $relay_port !== '' ? $relay_port : null,
			'name_pattern' => $name_pattern !== '' ? $name_pattern : null,
		];

		// Secrets are masked (blank) in the form; only overwrite the stored
		// value when the admin actually typed a new one. Minimum lengths match
		// what noVNC (pvewhmcs_prepare_noVNC) and the console token require.
		$vnc_secret = trim((string) ($_POST['vnc_secret'] ?? ''));
		if ($vnc_secret !== '') {
			if (strlen($vnc_secret) < 15) {
				throw new InvalidArgumentException('VNC Secret must be at least 15 characters.');
			}
			$update['vnc_secret'] = $vnc_secret;
		}
		$console_relay_secret = trim((string) ($_POST['console_relay_secret'] ?? ''));
		if ($console_relay_secret !== '') {
			if (strlen($console_relay_secret) < 32) {
				throw new InvalidArgumentException('Console Relay Secret must be at least 32 characters.');
			}
			$update['console_relay_secret'] = $console_relay_secret;
		}

		Capsule::table('mod_pvewhmcs')->where('id', 1)->update($update);
		$_SESSION['pvewhmcs']['infomsg']['title']='Module Config saved.' ;
		$_SESSION['pvewhmcs']['infomsg']['message']='New options have been successfully saved.' ;
		header("Location: ".pvewhmcs_BASEURL."&tab=config");
	} catch (\Throwable $e) {
		echo '<div class="alert alert-danger">Module Config was not saved: ' . htmlspecialchars($e->getMessage()) . '</div>';
	}
}

// MODULE FORM: Add new QEMU Plan
function qemu_plan_add() {
	echo '
	<form method="post">' . pvewhmcs_csrf_field() . '
	<table class="form" border="0" cellpadding="3" cellspacing="1" width="100%">
	<tr>
	<td class="fieldlabel">Plan Title</td>
	<td class="fieldarea">
	<input type="text" size="35" name="title" id="title" required>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">OS - Type</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="ostype">
	<option value="l26">Linux 6.x - 2.6 Kernel</option>
	<option value="l24">Linux 2.4 Kernel</option>
	<option value="solaris">Solaris Kernel</option>
	<option value="win11">Windows 11 / 2022 / 2025</option>
	<option value="win10">Windows 10 / 2016 / 2019</option>
	<option value="win8">Windows 8 / 2012 / 2012r2</option>
	<option value="win7">Windows 7 / 2008r2</option>
	<option value="wvista">Windows Vista / 2008</option>
	<option value="wxp">Windows XP / 2003</option>
	<option value="w2k">Windows 2000</option>
	<option value="other">Other</option>
	</select>
	Kernel type (Linux, Windows, etc).
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">CPU - Emulation</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="cpuemu">
	<option value="host">(Host) Host</option>
	<option value="kvm32">(QEMU) kvm32</option>
	<option value="kvm64">(QEMU) kvm64</option>
	<option value="max">(QEMU) Max</option>
	<option value="qemu32">(QEMU) qemu32</option>
	<option value="qemu64">(QEMU) qemu64</option>
	<option value="x86-64-v2">(x86-64 psABI) v2 (Nehalem/Opteron_G3 on)</option>
	<option value="x86-64-v2-AES" selected="">(x86-64 psABI) v2-AES (Westmere/Opteron_G4 on)</option>
	<option value="x86-64-v3">(x86-64 psABI) v3 (Broadwell/EPYC on)</option>
	<option value="x86-64-v4">(x86-64 psABI) v4 (Skylake/EPYCv4 on)</option>
	<option value="486">(Intel) 486</option>
	<option value="Broadwell">(Intel) Broadwell</option>
	<option value="Broadwell-IBRS">(Intel) Broadwell-IBRS</option>
	<option value="Broadwell-noTSX">(Intel) Broadwell-noTSX</option>
	<option value="Broadwell-noTSX-IBRS">(Intel) Broadwell-noTSX-IBRS</option>
	<option value="Cascadelake-Server">(Intel) Cascadelake-Server</option>
	<option value="Cascadelake-Server-noTSX">(Intel) Cascadelake-Server-noTSX</option>
	<option value="Cascadelake-Server-v2">(Intel) Cascadelake-Server-v2</option>
	<option value="Cascadelake-Server-v4">(Intel) Cascadelake-Server-v4</option>
	<option value="Cascadelake-Server-v5">(Intel) Cascadelake-Server-v5</option>
	<option value="Conroe">(Intel) Conroe</option>
	<option value="Cooperlake">(Intel) Cooperlake</option>
	<option value="Cooperlake-v2">(Intel) Cooperlake-v2</option>
	<option value="Haswell">(Intel) Haswell</option>
	<option value="Haswell-IBRS">(Intel) Haswell-IBRS</option>
	<option value="Haswell-noTSX">(Intel) Haswell-noTSX</option>
	<option value="Haswell-noTSX-IBRS">(Intel) Haswell-noTSX-IBRS</option>
	<option value="Icelake-Client">(Intel) Icelake-Client</option>
	<option value="Icelake-Client-noTSX">(Intel) Icelake-Client-noTSX</option>
	<option value="Icelake-Server">(Intel) Icelake-Server</option>
	<option value="Icelake-Server-noTSX">(Intel) Icelake-Server-noTSX</option>
	<option value="Icelake-Server-v3">(Intel) Icelake-Server-v3</option>
	<option value="Icelake-Server-v4">(Intel) Icelake-Server-v4</option>
	<option value="Icelake-Server-v5">(Intel) Icelake-Server-v5</option>
	<option value="Icelake-Server-v6">(Intel) Icelake-Server-v6</option>
	<option value="IvyBridge">(Intel) IvyBridge</option>
	<option value="IvyBridge-IBRS">(Intel) IvyBridge-IBRS</option>
	<option value="KnightsMill">(Intel) KnightsMill</option>
	<option value="Nehalem">(Intel) Nehalem</option>
	<option value="Nehalem-IBRS">(Intel) Nehalem-IBRS</option>
	<option value="Penryn">(Intel) Penryn</option>
	<option value="SandyBridge">(Intel) SandyBridge</option>
	<option value="SandyBridge-IBRS">(Intel) SandyBridge-IBRS</option>
	<option value="SapphireRapids">(Intel) SapphireRapids</option>
	<option value="Skylake-Client">(Intel) Skylake-Client</option>
	<option value="Skylake-Client-IBRS">(Intel) Skylake-Client-IBRS</option>
	<option value="Skylake-Client-noTSX-IBRS">(Intel) Skylake-Client-noTSX-IBRS</option>
	<option value="Skylake-Client-v4">(Intel) Skylake-Client-v4</option>
	<option value="Skylake-Server">(Intel) Skylake-Server</option>
	<option value="Skylake-Server-IBRS">(Intel) Skylake-Server-IBRS</option>
	<option value="Skylake-Server-noTSX-IBRS">(Intel) Skylake-Server-noTSX-IBRS</option>
	<option value="Skylake-Server-v4">(Intel) Skylake-Server-v4</option>
	<option value="Skylake-Server-v5">(Intel) Skylake-Server-v5</option>
	<option value="Westmere">(Intel) Westmere</option>
	<option value="Westmere-IBRS">(Intel) Westmere-IBRS</option>
	<option value="pentium">(Intel) Pentium I</option>
	<option value="pentium2">(Intel) Pentium II</option>
	<option value="pentium3">(Intel) Pentium III</option>
	<option value="coreduo">(Intel) Core Duo</option>
	<option value="core2duo">(Intel) Core 2 Duo</option>
	<option value="athlon">(AMD) Athlon</option>
	<option value="phenom">(AMD) Phenom</option>
	<option value="EPYC">(AMD) EPYC</option>
	<option value="EPYC-IBPB">(AMD) EPYC-IBPB</option>
	<option value="EPYC-Milan">(AMD) EPYC-Milan</option>
	<option value="EPYC-Milan-v2">(AMD) EPYC-Milan-v2</option>
	<option value="EPYC-Rome">(AMD) EPYC-Rome</option>
	<option value="EPYC-Rome-v2">(AMD) EPYC-Rome-v2</option>
	<option value="EPYC-v3">(AMD) EPYC-v3</option>
	<option value="Opteron_G1">(AMD) Opteron_G1</option>
	<option value="Opteron_G2">(AMD) Opteron_G2</option>
	<option value="Opteron_G3">(AMD) Opteron_G3</option>
	<option value="Opteron_G4">(AMD) Opteron_G4</option>
	<option value="Opteron_G5">(AMD) Opteron_G5</option>
	</select>
	Host is best. Read the <a href="https://pve.proxmox.com/pve-docs/pve-admin-guide.html#_qemu_vcpu_list" target="_blank" style="color:#5c3d7a;"><u>Docs</u></a>.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">CPU - Sockets</td>
	<td class="fieldarea">
	<input type="text" size="8" name="cpus" id="cpus" value="1" required>
	The number of CPU Sockets (typically 1-4). Governed by your physical Server.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">CPU - Cores</td>
	<td class="fieldarea">
	<input type="text" size="8" name="cores" id="cores" value="1" required>
	The number of CPU Cores per Socket (1-N). Guest Compute = allocated Sockets * Cores.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">CPU - Limit</td>
	<td class="fieldarea">
	<input type="text" size="8" name="cpulimit" id="cpulimit" value="0" required>
	Limit of CPU Usage. Note if the Server has 2 CPUs, it has total of "2" CPU time. Value "0" indicates no CPU limit.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">CPU - Weighting</td>
	<td class="fieldarea">
	<input type="text" size="8" name="cpuunits" id="cpuunits" value="1024" required>
	Number is relative to weights of all the other running VMs. 8 - 500000, recommend 1024. Disable fair-scheduler by setting this to 0.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">RAM - Memory</td>
	<td class="fieldarea">
	<input type="text" size="8" name="memory" id="memory" value="2048" required>
	RAM capacity in Megabytes eg. 1024 = 1GB (default is 2GB)
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">RAM - Balloon</td>
	<td class="fieldarea">
	<input type="text" size="8" name="balloon" id="balloon" value="0" required>
	Balloon capacity in Megabytes eg. 1024 = 1GB (0 = disabled)
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Disk - Capacity</td>
	<td class="fieldarea">
	<input type="text" size="8" name="disk" id="disk" value="10240" required>
	HDD/SSD storage in Gigabytes eg. 1024 = 1TB (default is 10GB)
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Disk - Format</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="diskformat">
	<option value="raw">Disk Image (raw)</option>
	<option selected="" value="qcow2">QEMU Image (qcow2)</option>
	<option value="vmdk">VMware Image (vmdk)</option>
	</select>
	Recommend "QEMU/qcow2" (supports Snapshots)
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Disk - Cache</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="diskcache">
	<option selected="" value="none">No Cache</option>
	<option value="directsync">Direct Sync</option>
	<option value="writethrough">Write Through</option>
	<option value="writeback">Write Back</option>
	<option value="unsafe">Write Back (Unsafe)</option>
	</select>
	Before overriding the default, read &amp; understand the <a href="https://pve.proxmox.com/wiki/Performance_Tweaks#Disk_Cache" target="_blank" style="color:#5c3d7a;"><u>Docs</u></a>.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Disk - Type</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="disktype">
	<option selected="" value="virtio">Virtio</option>
	<option value="scsi">SCSI</option>
	<option value="sata">SATA</option>
	<option value="ide">IDE</option>
	</select>
	Virtio is the fastest option, then SCSI, then SATA, etc.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Disk - I/O Cap</td>
	<td class="fieldarea">
	<input type="text" size="8" name="diskio" id="diskio" value="0" required>
	Limit of Disk I/O in KiB/s. 0 for unrestricted storage access for Guests.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">PVE Store - Name</td>
	<td class="fieldarea">
	<input type="text" size="8" name="storage" id="storage" value="local" required>
	Name of VM/CT Storage on Proxmox VE hypervisor. <code>local/local-lvm/etc</code>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - NIC Type</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="netmodel">
	<option selected="" value="virtio">VirtIO (Paravirtualised)</option>
	<option value="e1000">Intel E1000 (Stable)</option>
	<option value="rtl8139">Realtek RTL8139</option>
	<option value="vmxnet3">VMware vmxnet3</option>
	</select>
	Recommend VirtIO, unless you need others.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Rate</td>
	<td class="fieldarea">
	<input type="text" size="8" name="netrate" id="netrate" value="0">
	Network Rate Limit in Megabits/Second. Zero for unlimited.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Cap</td>
	<td class="fieldarea">
	<input type="text" size="8" name="bw" id="bw">
	Monthly Data Transfer Cap in Gigabytes. Blank for unlimited.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - IPv6</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="ipv6">
	<option value="0">Off</option>
	<option value="auto">SLAAC</option>
	<option value="dhcp">DHCPv6</option>
	<option value="prefix">Prefix</option>
	</select>
	SLAAC & DHCPv6 working. Prefix in future.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Mode</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="netmode">
	<option value="bridge">Bridge</option>
	<option value="nat">NAT</option>
	<option value="none">No Network</option>
	</select>
	Bridge, NAT or disconnect (no link) the Guest.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Interface</td>
	<td class="fieldarea">
	<input type="text" size="8" name="bridge" id="bridge" value="vmbr">
	Network / Bridge / NIC name. PVE default bridge prefix is "vmbr".
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Suffix (optional)</td>
	<td class="fieldarea">
	<input type="text" size="8" name="vmbr" id="vmbr" value="" placeholder="0">
	Optional suffix appended to the network name. Enter 0 for "vmbr0"; leave blank for a complete network name such as "private".
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - VLAN ID</td>
	<td class="fieldarea">
	<input type="text" size="8" name="vlanid" id="vlanid">
	VLAN ID for Plan Services. Default forgoes tagging (VLAN ID), blank for untagged.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">
	Hardware Virt?
	</td>
	<td class="fieldarea">
	<label class="checkbox-inline">
	<input type="checkbox" name="kvm" value="1" checked> Enable KVM hardware virtualisation. Requires support/enablement in BIOS. (Recommended)
	</label>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">
	On-boot VM?
	</td>
	<td class="fieldarea">
	<label class="checkbox-inline">
	<input type="checkbox" name="onboot" value="1" checked> Specifies whether a VM will be started during hypervisor boot-up. (Recommended)
	</label>
	</td>
	</tr>
	</table>

	<div class="btn-container">
	<input type="submit" class="btn btn-primary" value="Save Changes" name="plan_save_qemu" id="plan_save_qemu">
	<input type="reset" class="btn btn-default" value="Cancel Changes">
	</div>
	</form>
	';
}

// MODULE FORM: Edit a QEMU Plan
function qemu_plan_edit($id) {
	$plan= Capsule::table('mod_pvewhmcs_plans')->where('id', '=', $id)->get()[0];
	if (empty($plan)) {
		echo 'Plan Not found' ;
		return false ;
	}
	echo '
	<form method="post">' . pvewhmcs_csrf_field() . '
	<table class="form" border="0" cellpadding="3" cellspacing="1" width="100%">
	<tr>
	<td class="fieldlabel">Plan Title</td>
	<td class="fieldarea">
	<input type="text" size="35" name="title" id="title" required value="' . $plan->title . '">
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">OS - Type</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="ostype">
	<option value="l26" ' . ($plan->ostype == "l26" ? "selected" : "") . '>Linux 6.x - 2.6 Kernel</option>
	<option value="l24" ' . ($plan->ostype == "l24" ? "selected" : "") . '>Linux 2.4 Kernel</option>
	<option value="solaris" ' . ($plan->ostype == "solaris" ? "selected" : "") . '>Solaris Kernel</option>
	<option value="win11" ' . ($plan->ostype == "win11" ? "selected" : "") . '>Windows 11 / 2022 / 2025</option>
	<option value="win10" ' . ($plan->ostype == "win10" ? "selected" : "") . '>Windows 10 / 2016 / 2019</option>
	<option value="win8" ' . ($plan->ostype == "win8" ? "selected" : "") . '>Windows 8 / 2012 / 2012r2</option>
	<option value="win7" ' . ($plan->ostype == "win7" ? "selected" : "") . '>Windows 7 / 2008r2</option>
	<option value="wvista" ' . ($plan->ostype == "wvista" ? "selected" : "") . '>Windows Vista / 2008</option>
	<option value="wxp" ' . ($plan->ostype == "wxp" ? "selected" : "") . '>Windows XP / 2003</option>
	<option value="w2k" ' . ($plan->ostype == "w2k" ? "selected" : "") . '>Windows 2000</option>
	<option value="other" ' . ($plan->ostype == "other" ? "selected" : "") . '>Other</option>
	</select>
	Kernel type (Linux, Windows, etc).
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">CPU - Emulation</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="cpuemu">
	<option value="host" ' . ($plan->cpuemu == "host" ? "selected" : "") . '>Host</option>
	<option value="kvm32" ' . ($plan->cpuemu == "kvm32" ? "selected" : "") . '>(QEMU) kvm32</option>
	<option value="kvm64" ' . ($plan->cpuemu == "kvm64" ? "selected" : "") . '>(QEMU) kvm64</option>
	<option value="max" ' . ($plan->cpuemu == "max" ? "selected" : "") . '>(QEMU) Max</option>
	<option value="qemu32" ' . ($plan->cpuemu == "qemu32" ? "selected" : "") . '>(QEMU) qemu32</option>
	<option value="qemu64" ' . ($plan->cpuemu == "qemu64" ? "selected" : "") . '>(QEMU) qemu64</option>
	<option value="x86-64-v2" ' . ($plan->cpuemu == "x86-64-v2" ? "selected" : "") . '>(x86-64 psABI) v2 (Nehalem/Opteron_G3 on)</option>
	<option value="x86-64-v2-AES" ' . ($plan->cpuemu == "x86-64-v2-AES" ? "selected" : "") . '>(x86-64 psABI) v2-AES (Westmere/Opteron_G4 on)</option>
	<option value="x86-64-v3" ' . ($plan->cpuemu == "x86-64-v3" ? "selected" : "") . '>(x86-64 psABI) v3 (Broadwell/EPYC on)</option>
	<option value="x86-64-v4" ' . ($plan->cpuemu == "x86-64-v4" ? "selected" : "") . '>(x86-64 psABI) v4 (Skylake/EPYCv4 on)</option>
	<option value="486" ' . ($plan->cpuemu == "486" ? "selected" : "") . '>(Intel) 486</option>
	<option value="Broadwell" ' . ($plan->cpuemu == "Broadwell" ? "selected" : "") . '>(Intel) Broadwell</option>
	<option value="Broadwell-IBRS" ' . ($plan->cpuemu == "Broadwell-IBRS" ? "selected" : "") . '>(Intel) Broadwell-IBRS</option>
	<option value="Broadwell-noTSX" ' . ($plan->cpuemu == "Broadwell-noTSX" ? "selected" : "") . '>(Intel) Broadwell-noTSX</option>
	<option value="Broadwell-noTSX-IBRS" ' . ($plan->cpuemu == "Broadwell-noTSX-IBRS" ? "selected" : "") . '>(Intel) Broadwell-noTSX-IBRS</option>
	<option value="Cascadelake-Server" ' . ($plan->cpuemu == "Cascadelake-Server" ? "selected" : "") . '>(Intel) Cascadelake-Server</option>
	<option value="Cascadelake-Server-noTSX" ' . ($plan->cpuemu == "Cascadelake-Server-noTSX" ? "selected" : "") . '>(Intel) Cascadelake-Server-noTSX</option>
	<option value="Cascadelake-Server-v2" ' . ($plan->cpuemu == "Cascadelake-Server-v2" ? "selected" : "") . '>(Intel) Cascadelake-Server V2</option>
	<option value="Cascadelake-Server-v4" ' . ($plan->cpuemu == "Cascadelake-Server-v4" ? "selected" : "") . '>(Intel) Cascadelake-Server V4</option>
	<option value="Cascadelake-Server-v5" ' . ($plan->cpuemu == "Cascadelake-Server-v5" ? "selected" : "") . '>(Intel) Cascadelake-Server V5</option>
	<option value="Conroe" ' . ($plan->cpuemu == "Conroe" ? "selected" : "") . '>(Intel) Conroe</option>
	<option value="Cooperlake" ' . ($plan->cpuemu == "Cooperlake" ? "selected" : "") . '>(Intel) Cooperlake</option>
	<option value="Cooperlake-v2" ' . ($plan->cpuemu == "Cooperlake-v2" ? "selected" : "") . '>(Intel) Cooperlake V2</option>
	<option value="Haswell" ' . ($plan->cpuemu == "Haswell" ? "selected" : "") . '>(Intel) Haswell</option>
	<option value="Haswell-IBRS" ' . ($plan->cpuemu == "Haswell-IBRS" ? "selected" : "") . '>(Intel) Haswell-IBRS</option>
	<option value="Haswell-noTSX" ' . ($plan->cpuemu == "Haswell-noTSX" ? "selected" : "") . '>(Intel) Haswell-noTSX</option>
	<option value="Haswell-noTSX-IBRS" ' . ($plan->cpuemu == "Haswell-noTSX-IBRS" ? "selected" : "") . '>(Intel) Haswell-noTSX-IBRS</option>
	<option value="Icelake-Client" ' . ($plan->cpuemu == "Icelake-Client" ? "selected" : "") . '>(Intel) Icelake-Client</option>
	<option value="Icelake-Client-noTSX" ' . ($plan->cpuemu == "Icelake-Client-noTSX" ? "selected" : "") . '>(Intel) Icelake-Client-noTSX</option>
	<option value="Icelake-Server" ' . ($plan->cpuemu == "Icelake-Server" ? "selected" : "") . '>(Intel) Icelake-Server</option>
	<option value="Icelake-Server-noTSX" ' . ($plan->cpuemu == "Icelake-Server-noTSX" ? "selected" : "") . '>(Intel) Icelake-Server-noTSX</option>
	<option value="Icelake-Server-v3" ' . ($plan->cpuemu == "Icelake-Server-v3" ? "selected" : "") . '>(Intel) Icelake-Server V3</option>
	<option value="Icelake-Server-v4" ' . ($plan->cpuemu == "Icelake-Server-v4" ? "selected" : "") . '>(Intel) Icelake-Server V4</option>
	<option value="Icelake-Server-v5" ' . ($plan->cpuemu == "Icelake-Server-v5" ? "selected" : "") . '>(Intel) Icelake-Server V5</option>
	<option value="Icelake-Server-v6" ' . ($plan->cpuemu == "Icelake-Server-v6" ? "selected" : "") . '>(Intel) Icelake-Server V6</option>
	<option value="IvyBridge" ' . ($plan->cpuemu == "IvyBridge" ? "selected" : "") . '>(Intel) IvyBridge</option>
	<option value="IvyBridge-IBRS" ' . ($plan->cpuemu == "IvyBridge-IBRS" ? "selected" : "") . '>(Intel) IvyBridge-IBRS</option>
	<option value="KnightsMill" ' . ($plan->cpuemu == "KnightsMill" ? "selected" : "") . '>(Intel) KnightsMill</option>
	<option value="Nehalem" ' . ($plan->cpuemu == "Nehalem" ? "selected" : "") . '>(Intel) Nehalem</option>
	<option value="Nehalem-IBRS" ' . ($plan->cpuemu == "Nehalem-IBRS" ? "selected" : "") . '>(Intel) Nehalem-IBRS</option>
	<option value="Penryn" ' . ($plan->cpuemu == "Penryn" ? "selected" : "") . '>(Intel) Penryn</option>
	<option value="SandyBridge" ' . ($plan->cpuemu == "SandyBridge" ? "selected" : "") . '>(Intel) SandyBridge</option>
	<option value="SandyBridge-IBRS" ' . ($plan->cpuemu == "SandyBridge-IBRS" ? "selected" : "") . '>(Intel) SandyBridge-IBRS</option>
	<option value="SapphireRapids" ' . ($plan->cpuemu == "SapphireRapids" ? "selected" : "") . '>(Intel) Sapphire Rapids</option>
	<option value="Skylake-Client" ' . ($plan->cpuemu == "Skylake-Client" ? "selected" : "") . '>(Intel) Skylake-Client</option>
	<option value="Skylake-Client-IBRS" ' . ($plan->cpuemu == "Skylake-Client-IBRS" ? "selected" : "") . '>(Intel) Skylake-Client-IBRS</option>
	<option value="Skylake-Client-noTSX-IBRS" ' . ($plan->cpuemu == "Skylake-Client-noTSX-IBRS" ? "selected" : "") . '>(Intel) Skylake-Client-noTSX-IBRS</option>
	<option value="Skylake-Client-v4" ' . ($plan->cpuemu == "Skylake-Client-v4" ? "selected" : "") . '>(Intel) Skylake-Client V4</option>
	<option value="Skylake-Server" ' . ($plan->cpuemu == "Skylake-Server" ? "selected" : "") . '>(Intel) Skylake-Server</option>
	<option value="Skylake-Server-IBRS" ' . ($plan->cpuemu == "Skylake-Server-IBRS" ? "selected" : "") . '>(Intel) Skylake-Server-IBRS</option>
	<option value="Skylake-Server-noTSX-IBRS" ' . ($plan->cpuemu == "Skylake-Server-noTSX-IBRS" ? "selected" : "") . '>(Intel) Skylake-Server-noTSX-IBRS</option>
	<option value="Skylake-Server-v4" ' . ($plan->cpuemu == "Skylake-Server-v4" ? "selected" : "") . '>(Intel) Skylake-Server V4</option>
	<option value="Skylake-Server-v5" ' . ($plan->cpuemu == "Skylake-Server-v5" ? "selected" : "") . '>(Intel) Skylake-Server V5</option>
	<option value="Westmere" ' . ($plan->cpuemu == "Westmere" ? "selected" : "") . '>(Intel) Westmere</option>
	<option value="Westmere-IBRS" ' . ($plan->cpuemu == "Westmere-IBRS" ? "selected" : "") . '>(Intel) Westmere-IBRS</option>
	<option value="pentium" ' . ($plan->cpuemu == "pentium" ? "selected" : "") . '>(Intel) Pentium I</option>
	<option value="pentium2" ' . ($plan->cpuemu == "pentium2" ? "selected" : "") . '>(Intel) Pentium II</option>
	<option value="pentium3" ' . ($plan->cpuemu == "pentium3" ? "selected" : "") . '>(Intel) Pentium III</option>
	<option value="coreduo" ' . ($plan->cpuemu == "coreduo" ? "selected" : "") . '>(Intel) Core Duo</option>
	<option value="core2duo" ' . ($plan->cpuemu == "core2duo" ? "selected" : "") . '>(Intel) Core 2 Duo</option>
	<option value="athlon" ' . ($plan->cpuemu == "athlon" ? "selected" : "") . '>(AMD) Athlon</option>
	<option value="phenom" ' . ($plan->cpuemu == "phenom" ? "selected" : "") . '>(AMD) Phenom</option>
	<option value="EPYC" ' . ($plan->cpuemu == "EPYC" ? "selected" : "") . '>(AMD) EPYC</option>
	<option value="EPYC-IBPB" ' . ($plan->cpuemu == "EPYC-IBPB" ? "selected" : "") . '>(AMD) EPYC-IBPB</option>
	<option value="EPYC-Milan" ' . ($plan->cpuemu == "EPYC-Milan" ? "selected" : "") . '>(AMD) EPYC-Milan</option>
	<option value="EPYC-Milan-v2" ' . ($plan->cpuemu == "EPYC-Milan-v2" ? "selected" : "") . '>(AMD) EPYC-Milan-v2</option>
	<option value="EPYC-Rome" ' . ($plan->cpuemu == "EPYC-Rome" ? "selected" : "") . '>(AMD) EPYC-Rome</option>
	<option value="EPYC-Rome-v2" ' . ($plan->cpuemu == "EPYC-Rome-v2" ? "selected" : "") . '>(AMD) EPYC-Rome-v2</option>
	<option value="EPYC-v3" ' . ($plan->cpuemu == "EPYC-v3" ? "selected" : "") . '>(AMD) EPYC-v3</option>
	<option value="Opteron_G1" ' . ($plan->cpuemu == "Opteron_G1" ? "selected" : "") . '>(AMD) Opteron_G1</option>
	<option value="Opteron_G2" ' . ($plan->cpuemu == "Opteron_G2" ? "selected" : "") . '>(AMD) Opteron_G2</option>
	<option value="Opteron_G3" ' . ($plan->cpuemu == "Opteron_G3" ? "selected" : "") . '>(AMD) Opteron_G3</option>
	<option value="Opteron_G4" ' . ($plan->cpuemu == "Opteron_G4" ? "selected" : "") . '>(AMD) Opteron_G4</option>
	<option value="Opteron_G5" ' . ($plan->cpuemu == "Opteron_G5" ? "selected" : "") . '>(AMD) Opteron_G5</option>
	</select>
	Host is best. Read the <a href="https://pve.proxmox.com/pve-docs/pve-admin-guide.html#_qemu_vcpu_list" target="_blank" style="color:#5c3d7a;"><u>Docs</u></a>.
	</td>
	</tr>

	<tr>
	<td class="fieldlabel">CPU - Sockets</td>
	<td class="fieldarea">
	<input type="text" size="8" name="cpus" id="cpus" value="' . $plan->cpus . '" required>
	The number of CPU Sockets (typically 1-4). Governed by your physical Server.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">CPU - Cores</td>
	<td class="fieldarea">
	<input type="text" size="8" name="cores" id="cores" value="' . $plan->cores . '" required>
	The number of CPU Cores per Socket (1-N). Guest Compute = allocated Sockets * Cores.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">CPU - Limit</td>
	<td class="fieldarea">
	<input type="text" size="8" name="cpulimit" id="cpulimit" value="' . $plan->cpulimit . '" required>
	Limit of CPU usage. Note if the computer has 2 CPUs, it has total of "2" CPU time. Value "0" indicates no CPU limit.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">CPU - Weighting</td>
	<td class="fieldarea">
	<input type="text" size="8" name="cpuunits" id="cpuunits" value="' . $plan->cpuunits . '" required>
	Number is relative to weights of all the other running VMs. 8 - 500000 recommended 1024. Disable fair-scheduler by setting this to 0.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">RAM - Memory</td>
	<td class="fieldarea">
	<input type="text" size="8" name="memory" id="memory" required value="' . $plan->memory . '">
	RAM capacity in Megabytes eg. 1024 = 1GB
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">RAM - Balloon</td>
	<td class="fieldarea">
	<input type="text" size="8" name="balloon" id="balloon" required value="' . $plan->balloon . '">
	Balloon capacity in Megabytes eg. 1024 = 1GB (0 = disabled)
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Disk - Capacity</td>
	<td class="fieldarea">
	<input type="text" size="8" name="disk" id="disk" required value="' . $plan->disk . '">
	HDD/SSD storage in Gigabytes eg. 1024 = 1TB
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Disk - Format</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="diskformat">
	<option value="raw" ' . ($plan->diskformat == "raw" ? "selected" : "") . '>Disk Image (raw)</option>
	<option value="qcow2" ' . ($plan->diskformat == "qcow2" ? "selected" : "") . '>QEMU image (qcow2)</option>
	<option value="vmdk" ' . ($plan->diskformat == "vmdk" ? "selected" : "") . '>VMware image (vmdk)</option>
	</select>
	Recommend "QEMU/qcow2 format" (supports Snapshots)
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Disk - Cache</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="diskcache">
	<option value="none" ' . ($plan->diskcache == "none" ? "selected" : "") . '>No Cache</option>
	<option value="directsync" ' . ($plan->diskcache == "directsync" ? "selected" : "") . '>Direct Sync</option>
	<option value="writethrough" ' . ($plan->diskcache == "writethrough" ? "selected" : "") . '>Write Through</option>
	<option value="writeback" ' . ($plan->diskcache == "writeback" ? "selected" : "") . '>Write Back</option>
	<option value="unsafe" ' . ($plan->diskcache == "unsafe" ? "selected" : "") . '>Write Back (Unsafe)</option>
	</select>
	Before overriding the default, read &amp; understand the <a href="https://pve.proxmox.com/wiki/Performance_Tweaks#Disk_Cache" target="_blank" style="color:#5c3d7a;"><u>Docs</u></a>.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Disk - Type</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="disktype">
	<option value="virtio" ' . ($plan->disktype == "virtio" ? "selected" : "") . '>Virtio</option>
	<option value="scsi" ' . ($plan->disktype == "scsi" ? "selected" : "") . '>SCSI</option>
	<option value="sata" ' . ($plan->disktype == "sata" ? "selected" : "") . '>SATA</option>
	<option value="ide" ' . ($plan->disktype == "ide" ? "selected" : "") . '>IDE</option>
	</select>
	Virtio is the fastest option, then SCSI, then SATA, etc.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Disk - I/O Cap</td>
	<td class="fieldarea">
	<input type="text" size="8" name="diskio" id="diskio" required value="' . $plan->diskio . '">
	Limit of Disk I/O in KiB/s. 0 for unrestricted storage access for Guests.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">PVE Store - Name</td>
	<td class="fieldarea">
	<input type="text" size="8" name="storage" id="storage" required value="' . $plan->storage . '">
	Name of VM/CT Storage on Proxmox VE hypervisor. <code>local/local-lvm/etc</code>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - NIC Type</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="netmodel">
	<option value="virtio" ' . ($plan->netmodel == "virtio" ? "selected" : "") . '>VirtIO (Paravirtualised)</option>
	<option value="e1000" ' . ($plan->netmodel == "e1000" ? "selected" : "") . '>Intel E1000 (Stable)</option>
	<option value="rtl8139" ' . ($plan->netmodel == "rtl8139" ? "selected" : "") . '>Realtek RTL8139</option>
	<option value="vmxnet3" ' . ($plan->netmodel == "vmxnet3" ? "selected" : "") . '>VMware vmxnet3</option>
	</select>
	Recommend VirtIO, unless you need others.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Rate</td>
	<td class="fieldarea">
	<input type="text" size="8" name="netrate" id="netrate" value="' . $plan->netrate . '">
	Network Rate Limit in Megabits/Second. Zero for unlimited.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Cap</td>
	<td class="fieldarea">
	<input type="text" size="8" name="bw" id="bw" value="' . $plan->bw . '">
	Monthly Data Transfer Cap in Gigabytes. Blank for unlimited.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - IPv6</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="ipv6">
	<option value="0" ' . ($plan->ipv6 == "0" ? "selected" : "") . '>Off</option>
	<option value="auto" ' . ($plan->ipv6 == "auto" ? "selected" : "") . '>SLAAC</option>
	<option value="dhcp" ' . ($plan->ipv6 == "dhcp" ? "selected" : "") . '>DHCPv6</option>
	<option value="prefix" ' . ($plan->ipv6 == "prefix" ? "selected" : "") . '>Prefix</option>
	</select>
	SLAAC & DHCPv6 working. Prefix in future.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Mode</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="netmode">
	<option value="bridge" ' . ($plan->netmode == "bridge" ? "selected" : "") . '>Bridge</option>
	<option value="nat" ' . ($plan->netmode == "nat" ? "selected" : "") . '>NAT</option>
	<option value="none" ' . ($plan->netmode == "none" ? "selected" : "") . '>No network</option>
	</select>
	Bridge, NAT or disconnect (no link) the Guest.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Interface</td>
	<td class="fieldarea">
	<input type="text" size="8" name="bridge" id="bridge" value="' . $plan->bridge . '">
	Network / Bridge / NIC name. PVE default bridge prefix is "vmbr".
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Suffix (optional)</td>
	<td class="fieldarea">
	<input type="text" size="8" name="vmbr" id="vmbr" value="' . $plan->vmbr . '" placeholder="0">
	Optional suffix appended to the network name. Enter 0 for "vmbr0"; leave blank for a complete network name such as "private".
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - VLAN ID</td>
	<td class="fieldarea">
	<input type="text" size="8" name="vlanid" id="vlanid" value="' . htmlspecialchars((string) $plan->vlanid, ENT_QUOTES, 'UTF-8') . '">
	VLAN ID for Plan Services. Default forgoes tagging (VLAN ID), blank for untagged.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">
	Hardware Virt?
	</td>
	<td class="fieldarea">
	<label class="checkbox-inline">
	<input type="checkbox" name="kvm" value="1" ' . ($plan->kvm == "1" ? "checked" : "") . '> Enable KVM hardware virtualisation. Requires support/enablement in BIOS. (Recommended)
	</label>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">
	On-boot VM?
	</td>
	<td class="fieldarea">
	<label class="checkbox-inline">
	<input type="checkbox" name="onboot" value="1" ' . ($plan->onboot == "1" ? "checked" : "") . '> Specifies whether a VM will be started during hypervisor boot-up. (Recommended)
	</label>
	</td>
	</tr>
	</table>

	<div class="btn-container">
	<input type="submit" class="btn btn-primary" value="Save Changes" name="plan_update_qemu" id="plan_update_qemu">
	<input type="reset" class="btn btn-default" value="Cancel Changes">
	</div>
	</form>
	';
}

// MODULE FORM: Add an LXC Plan
function lxc_plan_add() {
	echo '
	<form method="post">' . pvewhmcs_csrf_field() . '
	<table class="form" border="0" cellpadding="3" cellspacing="1" width="100%">
	<tr>
	<td class="fieldlabel">Plan Title</td>
	<td class="fieldarea">
	<input type="text" size="35" name="title" id="title" required>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">CPU - Limit</td>
	<td class="fieldarea">
	<input type="text" size="8" name="cpulimit" id="cpulimit" value="1" required>
	Limit of CPU usage. Default is 1. If the computer has 2 CPUs, it has total of "2" CPU time. Value "0" indicates no CPU limit.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">CPU - Weighting</td>
	<td class="fieldarea">
	<input type="text" size="8" name="cpuunits" id="cpuunits" value="1024" required>
	Number is relative to weights of all the other running VMs. 8 - 500000, recommend 1024.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">RAM - Memory</td>
	<td class="fieldarea">
	<input type="text" size="8" name="memory" id="memory" required>
	RAM capacity in Megabytes eg. 1024 = 1GB
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Swap - Space</td>
	<td class="fieldarea">
	<input type="text" size="8" name="swap" id="swap">
	Swap capacity in Megabytes eg. 1024 = 1GB
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Disk - Capacity</td>
	<td class="fieldarea">
	<input type="text" size="8" name="disk" id="disk" required>
	HDD/SSD storage in Gigabytes eg. 1024 = 1TB
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Disk - I/O Cap</td>
	<td class="fieldarea">
	<input type="text" size="8" name="diskio" id="diskio" value="0" required>
	Limit of Disk I/O in KiB/s. 0 for unrestricted storage access for Guests.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">PVE Store - Name</td>
	<td class="fieldarea">
	<input type="text" size="8" name="storage" id="storage" value="local" required>
	Name of VM/CT Storage on Proxmox VE hypervisor. <code>local/local-lvm/etc</code>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Interface</td>
	<td class="fieldarea">
	<input type="text" size="8" name="bridge" id="bridge" value="vmbr">
	Network / Bridge / NIC name. PVE default bridge prefix is "vmbr".
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Suffix (optional)</td>
	<td class="fieldarea">
	<input type="text" size="8" name="vmbr" id="vmbr" value="" placeholder="0">
	Optional suffix appended to the network name. Enter 0 for "vmbr0"; leave blank for a complete network name such as "private".
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - VLAN ID</td>
	<td class="fieldarea">
	<input type="text" size="8" name="vlanid" id="vlanid">
	VLAN ID for Plan Services. Default forgoes tagging (VLAN ID), blank for untagged.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Rate</td>
	<td class="fieldarea">
	<input type="text" size="8" name="netrate" id="netrate" value="0">
	Network Rate Limit in Megabits/Second. Zero for unlimited.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Cap</td>
	<td class="fieldarea">
	<input type="text" size="8" name="bw" id="bw">
	Monthly Data Transfer Cap in Gigabytes. Blank for unlimited.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - IPv6</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="ipv6">
	<option value="0">Off</option>
	<option value="auto">SLAAC</option>
	<option value="dhcp">DHCPv6</option>
	<option value="prefix">Prefix</option>
	</select>
	SLAAC & DHCPv6 working. Prefix in future.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">
	On-boot CT?
	</td>
	<td class="fieldarea">
	<label class="checkbox-inline">
	<input type="checkbox" name="onboot" value="1" checked> Specifies whether a CT will be started during hypervisor boot-up. (Recommended)
	</label>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">
	Unpriv.
	</td>
	<td class="fieldarea">
	<label class="checkbox-inline">
	<input type="checkbox" name="unpriv" value="1"> Specifies whether a CT will be unprivileged. (Recommended) <strong>Set at-create only!</strong>
	</label>
	</td>
	</tr>
	</table>

	<div class="btn-container">
	<input type="submit" class="btn btn-primary" value="Save Changes" name="plan_save_lxc" id="plan_save_lxc">
	<input type="reset" class="btn btn-default" value="Cancel Changes">
	</div>
	</form>
	';
}

// MODULE FORM: Edit an LXC Plan
function lxc_plan_edit($id) {
	$plan= Capsule::table('mod_pvewhmcs_plans')->where('id', '=', $id)->get()[0];
	if (empty($plan)) {
		echo 'Plan Not found' ;
		return false ;
	}
	echo '
	<form method="post">' . pvewhmcs_csrf_field() . '
	<table class="form" border="0" cellpadding="3" cellspacing="1" width="100%">
	<tr>
	<td class="fieldlabel">Plan Title</td>
	<td class="fieldarea">
	<input type="text" size="35" name="title" id="title" required value="' . $plan->title . '">
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">CPU - Limit</td>
	<td class="fieldarea">
	<input type="text" size="8" name="cpulimit" id="cpulimit" value="' . $plan->cpulimit . '" required>
	Limit of CPU usage. Default is 1. If the computer has 2 CPUs, it has total of "2" CPU time. Value "0" indicates no CPU limit.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">CPU - Weighting</td>
	<td class="fieldarea">
	<input type="text" size="8" name="cpuunits" id="cpuunits" value="' . $plan->cpuunits . '" required>
	Number is relative to weights of all the other running VMs. 8 - 500000, recommend 1024.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">RAM - Memory</td>
	<td class="fieldarea">
	<input type="text" size="8" name="memory" id="memory" required value="' . $plan->memory . '">
	RAM capacity in Megabytes eg. 1024 = 1GB
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Swap - Space</td>
	<td class="fieldarea">
	<input type="text" size="8" name="swap" id="swap" value="' . $plan->swap . '">
	Swap capacity in Megabytes eg. 1024 = 1GB
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Disk - Capacity</td>
	<td class="fieldarea">
	<input type="text" size="8" name="disk" id="disk" value="' . $plan->disk . '" required>
	HDD/SSD storage in Gigabytes eg. 1024 = 1TB
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Disk - I/O Cap</td>
	<td class="fieldarea">
	<input type="text" size="8" name="diskio" id="diskio" value="' . $plan->diskio . '" required>
	Limit of Disk I/O in KiB/s. 0 for unrestricted storage access for Guests.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">PVE Store - Name</td>
	<td class="fieldarea">
	<input type="text" size="8" name="storage" id="storage" value="' . $plan->storage . '" required>
	Name of VM/CT Storage on Proxmox VE hypervisor. <code>local/local-lvm/etc</code>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Interface</td>
	<td class="fieldarea">
	<input type="text" size="8" name="bridge" id="bridge" value="' . $plan->bridge . '">
	Network / Bridge / NIC name. PVE default bridge prefix is "vmbr".
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Suffix (optional)</td>
	<td class="fieldarea">
	<input type="text" size="8" name="vmbr" id="vmbr" value="' . $plan->vmbr . '" placeholder="0">
	Optional suffix appended to the network name. Enter 0 for "vmbr0"; leave blank for a complete network name such as "private".
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - VLAN ID</td>
	<td class="fieldarea">
	<input type="text" size="8" name="vlanid" id="vlanid" value="' . htmlspecialchars((string) $plan->vlanid, ENT_QUOTES, 'UTF-8') . '">
	VLAN ID for Plan Services. Default forgoes tagging (VLAN ID), blank for untagged.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Rate</td>
	<td class="fieldarea">
	<input type="text" size="8" name="netrate" id="netrate" value="' . $plan->netrate . '">
	Network Rate Limit in Megabits/Second. Zero for unlimited.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - Cap</td>
	<td class="fieldarea">
	<input type="text" size="8" name="bw" id="bw" value="' . $plan->bw . '">
	Monthly Data Transfer Cap in Gigabytes. Blank for unlimited.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Network - IPv6</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="ipv6">
	<option value="0" ' . ($plan->ipv6 == "0" ? "selected" : "") . '>Off</option>
	<option value="auto" ' . ($plan->ipv6 == "auto" ? "selected" : "") . '>SLAAC</option>
	<option value="dhcp" ' . ($plan->ipv6 == "dhcp" ? "selected" : "") . '>DHCPv6</option>
	<option value="prefix" ' . ($plan->ipv6 == "prefix" ? "selected" : "") . '>Prefix</option>
	</select>
	SLAAC & DHCPv6 working. Prefix in future.
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">
	On-boot CT?
	</td>
	<td class="fieldarea">
	<label class="checkbox-inline">
	<input type="checkbox" value="1" name="onboot" ' . ($plan->onboot == "1" ? "checked" : "") . '> Specifies whether a CT will be started during hypervisor boot-up. (Recommended)
	</label>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">
	Unpriv.
	</td>
	<td class="fieldarea">
	<label class="checkbox-inline">
	<input type="checkbox" value="1" name="unpriv" ' . ($plan->unpriv == "1" ? "checked" : "") . '> Specifies whether a CT will be unprivileged. (Recommended) <strong>Set at-create only!</strong>
	</label>
	</td>
	</tr>
	</table>

	<div class="btn-container">
	<input type="submit" class="btn btn-primary" value="Save Changes" name="plan_update_lxc" id="plan_update_lxc">
	<input type="reset" class="btn btn-default" value="Cancel Changes">
	</div>
	</form>
	';
}

/**
 * Whole-number plan field. $blankValue === false makes the field required;
 * otherwise a blank input is stored as $blankValue (e.g. null = untagged VLAN).
 */
function pvewhmcs_plan_int($field, $label, $min, $max, $blankValue = false) {
	$raw = trim((string) ($_POST[$field] ?? ''));
	if ($raw === '') {
		if ($blankValue === false) {
			throw new InvalidArgumentException("{$label} is required.");
		}

		return $blankValue;
	}

	$value = filter_var($raw, FILTER_VALIDATE_INT, array('options' => array('min_range' => $min, 'max_range' => $max)));
	if ($value === false) {
		throw new InvalidArgumentException("{$label} must be a whole number from {$min} to {$max}.");
	}

	return $value;
}

function pvewhmcs_plan_choice($field, $label, array $allowed) {
	$value = (string) ($_POST[$field] ?? '');
	if (!in_array($value, $allowed, true)) {
		throw new InvalidArgumentException("{$label} has an unsupported value.");
	}

	return $value;
}

/**
 * Server-side validation of the QEMU (`kvm`) and LXC plan forms (AGENTS rule 3).
 * Returns the mod_pvewhmcs_plans columns to store, or throws
 * InvalidArgumentException naming the first invalid field. Ranges follow the
 * db.sql column types; blanks follow the form hints; checkboxes become 0/1.
 */
function pvewhmcs_plan_input($vmtype) {
	list($bridge, $vmbr) = pvewhmcs_plan_network_input($vmtype === 'lxc' || ($_POST['netmode'] ?? 'bridge') === 'bridge');

	$title = trim((string) ($_POST['title'] ?? ''));
	if ($title === '' || strlen($title) > 255) {
		throw new InvalidArgumentException('Plan Title is required (up to 255 characters).');
	}
	$storage = trim((string) ($_POST['storage'] ?? ''));
	if (!preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,18}[A-Za-z0-9]$/', $storage)) {
		throw new InvalidArgumentException('Storage must be a Proxmox storage ID such as local or local-lvm.');
	}

	$plan = array(
		'title' => $title,
		'vmtype' => $vmtype,
		'cpulimit' => pvewhmcs_plan_int('cpulimit', 'CPU Limit', 0, 8192),
		'cpuunits' => pvewhmcs_plan_int('cpuunits', 'CPU Units', 0, 65535),
		'memory' => pvewhmcs_plan_int('memory', 'RAM', 16, 4294967295),
		'disk' => pvewhmcs_plan_int('disk', 'Disk', 1, 4294967295),
		'diskio' => (string) pvewhmcs_plan_int('diskio', 'Disk I/O', 0, 2147483647, 0),
		'storage' => $storage,
		'bridge' => $bridge,
		'vmbr' => $vmbr,
		'vlanid' => pvewhmcs_plan_int('vlanid', 'VLAN ID', 1, 4094, null),
		'netrate' => pvewhmcs_plan_int('netrate', 'Network Rate', 0, 2147483647, 0),
		'bw' => pvewhmcs_plan_int('bw', 'Bandwidth', 0, 4294967295, 0),
		'ipv6' => pvewhmcs_plan_choice('ipv6', 'IPv6', array('0', 'auto', 'dhcp', 'prefix')),
		'onboot' => (int) !empty($_POST['onboot']),
	);

	if ($vmtype === 'lxc') {
		return $plan + array(
			'swap' => pvewhmcs_plan_int('swap', 'Swap', 0, 4294967295, null),
			'unpriv' => (int) !empty($_POST['unpriv']),
		);
	}

	$cpuemu = (string) ($_POST['cpuemu'] ?? '');
	if (!preg_match('/^[A-Za-z0-9_.+-]{1,30}$/', $cpuemu)) {
		throw new InvalidArgumentException('CPU Emulation has an unsupported value.');
	}

	return $plan + array(
		'ostype' => pvewhmcs_plan_choice('ostype', 'OS Type', array('l26', 'l24', 'solaris', 'win11', 'win10', 'win8', 'win7', 'wvista', 'wxp', 'w2k', 'other')),
		'cpus' => pvewhmcs_plan_int('cpus', 'CPU Sockets', 1, 4096),
		'cpuemu' => $cpuemu,
		'cores' => pvewhmcs_plan_int('cores', 'CPU Cores', 1, 4096),
		'balloon' => pvewhmcs_plan_int('balloon', 'Balloon', 0, 2147483647, 0),
		'diskformat' => pvewhmcs_plan_choice('diskformat', 'Disk Format', array('raw', 'qcow2', 'vmdk')),
		'diskcache' => pvewhmcs_plan_choice('diskcache', 'Disk Cache', array('none', 'directsync', 'writethrough', 'writeback', 'unsafe')),
		'disktype' => pvewhmcs_plan_choice('disktype', 'Disk Type', array('virtio', 'scsi', 'sata', 'ide')),
		'netmode' => pvewhmcs_plan_choice('netmode', 'Network Mode', array('bridge', 'nat', 'none')),
		'netmodel' => pvewhmcs_plan_choice('netmodel', 'NIC', array('virtio', 'e1000', 'rtl8139', 'vmxnet3')),
		'kvm' => (int) !empty($_POST['kvm']),
	);
}

function pvewhmcs_plan_label($vmtype) {
	return $vmtype === 'lxc' ? 'LXC' : 'QEMU';
}

// MODULE FORM ACTION: Save a new QEMU (`kvm`) or LXC plan
function pvewhmcs_save_plan($vmtype) {
	try {
		Capsule::table('mod_pvewhmcs_plans')->insert(pvewhmcs_plan_input($vmtype));
	} catch (\Throwable $e) {
		echo '<div class="alert alert-danger">' . pvewhmcs_plan_label($vmtype) . ' Plan was not saved: ' . htmlspecialchars($e->getMessage()) . '</div>';
		return;
	}

	$_SESSION['pvewhmcs']['infomsg']['title'] = pvewhmcs_plan_label($vmtype) . ' Plan added.';
	$_SESSION['pvewhmcs']['infomsg']['message'] = 'Saved the ' . pvewhmcs_plan_label($vmtype) . ' Plan successfully.';
	header("Location: ".pvewhmcs_BASEURL."&tab=vmplans&action=planlist");
}

// MODULE FORM ACTION: Update a QEMU (`kvm`) or LXC plan
function pvewhmcs_update_plan($vmtype, $id) {
	try {
		$plan = pvewhmcs_plan_input($vmtype);
		if (!Capsule::table('mod_pvewhmcs_plans')->where('id', $id)->where('vmtype', $vmtype)->exists()) {
			throw new InvalidArgumentException("Plan #{$id} was not found.");
		}
		Capsule::table('mod_pvewhmcs_plans')->where('id', $id)->update($plan);
	} catch (\Throwable $e) {
		echo '<div class="alert alert-danger">' . pvewhmcs_plan_label($vmtype) . ' Plan was not updated: ' . htmlspecialchars($e->getMessage()) . '</div>';
		return;
	}

	$_SESSION['pvewhmcs']['infomsg']['title'] = pvewhmcs_plan_label($vmtype) . ' Plan updated.';
	$_SESSION['pvewhmcs']['infomsg']['message'] = 'Updated the ' . pvewhmcs_plan_label($vmtype) . ' Plan successfully. (Updating plans will not alter existing guests)';
	header("Location: ".pvewhmcs_BASEURL."&tab=vmplans&action=planlist");
}

// MODULE FORM ACTION: Remove Plan (refused while a product still uses it,
// since CreateAccount/UnsuspendAccount read the product's plan)
function remove_plan($id) {
	$products = Capsule::table('tblproducts')
		->where('servertype', 'pvewhmcs')
		->where('configoption1', (string) $id)
		->pluck('name')
		->all();
	if (!empty($products)) {
		echo '<div class="alert alert-danger">Plan #' . (int) $id . ' is used by: ' . htmlspecialchars(implode(', ', $products)) . '. Assign another plan to those products first.</div>';
		return;
	}

	Capsule::table('mod_pvewhmcs_plans')->where('id', '=', $id)->delete();
	header("Location: ".pvewhmcs_BASEURL."&tab=vmplans&action=planlist");
	$_SESSION['pvewhmcs']['infomsg']['title']='Plan Deleted.' ;
	$_SESSION['pvewhmcs']['infomsg']['message']='Selected Item deleted successfully.' ;
}

// IP POOLS: List all Pools
function list_ip_pools() {
	echo '<table class="datatable"><tr><th>ID</th><th>Pool</th><th>Gateway</th><th>Action</th></tr>';
	foreach (Capsule::table('mod_pvewhmcs_ip_pools')->get() as $pool) {
		echo '<tr>';
		echo '<td>' . $pool->id . '</td>';
		echo '<td>' . $pool->title . '</td>';
		echo '<td>' . $pool->gateway . '</td>';
		echo '<td>
		<a href="' . pvewhmcs_BASEURL . '&amp;tab=ippools&amp;action=list_ips&amp;id=' . $pool->id . '"><img height="16" width="16" border="0" alt="Info" src="images/edit.gif"></a>
		<form method="post" style="display:inline" onsubmit="return confirm(\'Pool and all IPv4 addresses assigned to it will be deleted, continue?\')">
		<input type="hidden" name="pvewhmcs_action" value="removeippool"><input type="hidden" name="id" value="' . (int) $pool->id . '">' . pvewhmcs_csrf_field() . '
		<button type="submit" style="border:0;background:transparent;padding:0"><img height="16" width="16" border="0" alt="Delete" src="images/delete.gif"></button>
		</form></td>';
		echo '</tr>';
	}
	echo '</table>';
}

// IP POOL FORM: Add IP Pool
function add_ip_pool() {
	echo '
	<form method="post">' . pvewhmcs_csrf_field() . '
	<table class="form" border="0" cellpadding="3" cellspacing="1" width="100%">
	<tr>
	<td class="fieldlabel">Pool Title</td>
	<td class="fieldarea">
	<input type="text" size="35" name="title" id="title" required>
	</td>
	<td class="fieldlabel">IPv4 Gateway</td>
	<td class="fieldarea">
	<input type="text" size="25" name="gateway" id="gateway" required>
	Gateway address of the pool
	</td>
	</tr>
	</table>
	<input type="submit" class="btn btn-primary" name="newIPpool" value="Save"/>
	</form>
	';
}

// IP POOL FORM ACTION: Save Pool
function save_ip_pool() {
	try {
		$title = trim((string) ($_POST['title'] ?? ''));
		$gateway = trim((string) ($_POST['gateway'] ?? ''));
		if ($title === '' || strlen($title) > 255) {
			throw new InvalidArgumentException('Pool Title is required (up to 255 characters).');
		}
		if (!filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			throw new InvalidArgumentException('IPv4 Gateway must be an IPv4 address.');
		}
		Capsule::table('mod_pvewhmcs_ip_pools')->insert(['title' => $title, 'gateway' => $gateway]);
	} catch (\Throwable $e) {
		echo '<div class="alert alert-danger">IPv4 Pool was not saved: ' . htmlspecialchars($e->getMessage()) . '</div>';
		return;
	}

	$_SESSION['pvewhmcs']['infomsg']['title']='New IPv4 Pool added.' ;
	$_SESSION['pvewhmcs']['infomsg']['message']='New IPv4 Pool saved successfully.' ;
	header("Location: ".pvewhmcs_BASEURL."&tab=ippools&action=list_ip_pools");
}

// Service statuses that keep an IPv4 pool address in use (tblhosting.dedicatedip).
function pvewhmcs_ip_busy_statuses() {
	return array('Active', 'Suspended', 'Completed', 'Pending');
}

// The service holding an IPv4 address, or null when the address is free.
function pvewhmcs_ip_service($ipaddress) {
	return Capsule::table('tblhosting')
		->where('dedicatedip', '=', $ipaddress)
		->whereIn('domainstatus', pvewhmcs_ip_busy_statuses())
		->first();
}

// A cancelled service retains its current pool address only while its guest link
// remains. vms.ipaddress is intentionally not consulted: it is guest history.
function pvewhmcs_ip_reservation_service($ipaddress) {
	return Capsule::table('tblhosting as h')
		->join('mod_pvewhmcs_vms as v', 'v.id', '=', 'h.id')
		->where('h.dedicatedip', '=', $ipaddress)
		->where('h.domainstatus', '=', 'Terminated')
		->select('h.id', 'h.userid', 'h.dedicatedip', 'v.vmid')
		->first();
}

// Pool-address rows must be locked before their service rows. The caller has
// already locked those address rows; this locks every service currently using
// one, then the guest links needed to distinguish a cancelled reservation.
function pvewhmcs_locked_ip_blockers(array $addresses) {
	$addresses = array_values(array_unique(array_filter($addresses, function ($address) {
		return is_string($address) && $address !== '';
	})));
	if (empty($addresses)) {
		return array();
	}

	$services = Capsule::table('tblhosting')
		->whereIn('dedicatedip', $addresses)
		->orderBy('id')
		->lock('for update')
		->get();
	$serviceIds = array();
	foreach ($services as $service) {
		$serviceIds[] = (int) $service->id;
	}
	$guestRows = empty($serviceIds) ? array() : Capsule::table('mod_pvewhmcs_vms')
		->whereIn('id', $serviceIds)
		->orderBy('id')
		->lock('for update')
		->get(array('id'));
	$guestIds = array();
	foreach ($guestRows as $guest) {
		$guestIds[(int) $guest->id] = true;
	}
	$blockers = array();
	foreach ($services as $service) {
		if (in_array($service->domainstatus, pvewhmcs_ip_busy_statuses(), true)) {
			$blockers[(string) $service->dedicatedip] = array('type' => 'in_use', 'service' => $service);
		} elseif ($service->domainstatus === 'Terminated' && isset($guestIds[(int) $service->id])) {
			$blockers[(string) $service->dedicatedip] = array('type' => 'reservation', 'service' => $service);
		}
	}

	return $blockers;
}

function pvewhmcs_ip_blocker_message($blocker) {
	$service = $blocker['service'];
	return htmlspecialchars((string) $service->dedicatedip) . ' is ' . ($blocker['type'] === 'reservation' ? 'reserved for cancelled' : 'in use by') . ' Service #' . (int) $service->id . '.';
}

// IP POOL FORM ACTION: Remove Pool (refused while an address is in use or reserved).
function removeIpPool($id) {
	$result = Capsule::connection()->transaction(function () use ($id) {
		$addresses = Capsule::table('mod_pvewhmcs_ip_addresses')
			->where('pool_id', '=', $id)
			->orderBy('id')
			->lock('for update')
			->get();
		$blockers = pvewhmcs_locked_ip_blockers($addresses->pluck('ipaddress')->all());
		if (!empty($blockers)) {
			return array('blocker' => reset($blockers));
		}

		Capsule::table('mod_pvewhmcs_ip_addresses')->where('pool_id', '=', $id)->delete();
		Capsule::table('mod_pvewhmcs_ip_pools')->where('id', '=', $id)->delete();
		return array('deleted' => true);
	});
	if (isset($result['blocker'])) {
		echo '<div class="alert alert-danger">IPv4 Pool #' . (int) $id . ' was not deleted: ' . pvewhmcs_ip_blocker_message($result['blocker']) . '</div>';
		return;
	}

	header("Location: ".pvewhmcs_BASEURL."&tab=ippools&action=list_ip_pools");
	$_SESSION['pvewhmcs']['infomsg']['title']='IPv4 Pool Deleted.' ;
	$_SESSION['pvewhmcs']['infomsg']['message']='Deleted the IPv4 Pool successfully.' ;
}

/**
 * Adds one IPv4 address (no prefix, or /32) or every usable host of a /22-/30
 * block to a pool, in one transaction. Addresses already present (in any pool)
 * and pool gateways are skipped. Returns array('inserted' => n, 'skipped' => n).
 */
function pvewhmcs_add_ips_to_pool($pool_id, $block) {
	require_once(ROOTDIR.'/modules/addons/pvewhmcs/Ipv4/Subnet.php');
	if (!Capsule::table('mod_pvewhmcs_ip_pools')->where('id', $pool_id)->exists()) {
		throw new InvalidArgumentException('Select an existing IPv4 pool.');
	}

	$address = $block;
	$prefix = 32;
	if (preg_match('#^(.+)/(\d{1,2})$#', $block, $match)) {
		$address = $match[1];
		$prefix = (int) $match[2];
	}
	if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
		throw new InvalidArgumentException('Enter an IPv4 address (e.g. 203.0.113.10) or a block with its prefix (e.g. 203.0.113.0/27).');
	}

	if ($prefix === 32) {
		$entries = array(array('ipaddress' => $address, 'mask' => '255.255.255.255'));
	} elseif ($prefix === 31) {
		throw new InvalidArgumentException('/31 blocks have no usable host range here: add the two addresses individually.');
	} elseif ($prefix < 22 || $prefix > 30) {
		throw new InvalidArgumentException('Blocks must be between /22 and /30 (at most 1022 addresses per request).');
	} else {
		$subnet = Ipv4_Subnet::fromString($address . '/' . $prefix);
		$mask = $subnet->getNetmask();
		$entries = array();
		foreach ($subnet->getIterator() as $ip) {
			$entries[] = array('ipaddress' => (string) $ip, 'mask' => $mask);
		}
	}

	$gateways = Capsule::table('mod_pvewhmcs_ip_pools')->pluck('gateway')->all();
	$inserted = 0;
	Capsule::connection()->transaction(function ($connection) use ($entries, $gateways, $pool_id, &$inserted) {
		/** @var \Illuminate\Database\Connection $connection */
		foreach ($entries as $entry) {
			if (in_array($entry['ipaddress'], $gateways, true)) {
				continue;
			}
			$inserted += $connection->table('mod_pvewhmcs_ip_addresses')->insertOrIgnore(array('pool_id' => $pool_id) + $entry);
		}
	});

	return array('inserted' => $inserted, 'skipped' => count($entries) - $inserted);
}

// IP POOL FORM ACTION: Add IP to Pool
function add_ip_2_pool() {
	if (isset($_POST['assignIP2pool'])) {
		$pool_id = (int) ($_POST['pool_id'] ?? 0);
		try {
			$result = pvewhmcs_add_ips_to_pool($pool_id, trim((string) ($_POST['ipblock'] ?? '')));
			header("Location: " . pvewhmcs_BASEURL . "&tab=ippools&action=list_ips&id=" . $pool_id);
			$_SESSION['pvewhmcs']['infomsg']['title'] = 'IPv4 Addresses added to Pool.';
			$_SESSION['pvewhmcs']['infomsg']['message'] = $result['inserted'] . ' added, ' . $result['skipped'] . ' skipped (already in a pool, or a pool gateway).';
			return;
		} catch (\Throwable $e) {
			echo '<div class="alert alert-danger">No IPv4 addresses were added: ' . htmlspecialchars($e->getMessage()) . '</div>';
		}
	}

	echo '<form method="post">' . pvewhmcs_csrf_field() . '
	<table class="form" border="0" cellpadding="3" cellspacing="1" width="100%">
	<tr>
	<td class="fieldlabel">IPv4 Pool</td>
	<td class="fieldarea">
	<select class="form-control select-inline" name="pool_id">';
	foreach (Capsule::table('mod_pvewhmcs_ip_pools')->get() as $pool) {
		echo '<option value="' . (int) $pool->id . '">' . $pool->title . '</option>';
	}
	echo '</select>
	</td>
	</tr>
	<tr>
	<td class="fieldlabel">Address/Prefix</td>
	<td class="fieldarea">
	<input type="text" name="ipblock"/>
	A single IPv4 address (e.g. 203.0.113.10), or a /22 to /30 block with its prefix (e.g. 172.16.255.224/27; usable hosts only)
	</td>
	</tr>
	</table>
	<input type="submit" name="assignIP2pool" value="Add"/>
	</form>';
}

// IP POOL FORM: List IPs in Pool
function list_ips() {
    // Determine the WHMCS Admin Directory URL for the link
    $adminUrl = 'clientsservices.php'; 

    $pool_id = (int) $_GET['id'];

    echo '<form method="post" onsubmit="return confirm(\'Selected IPv4 addresses will be deleted from the pool, continue?\')">
            <input type="hidden" name="pvewhmcs_action" value="removeip_bulk">
            <input type="hidden" name="pool_id" value="' . $pool_id . '">' . pvewhmcs_csrf_field() . '
            <div style="margin-bottom:10px;">
                <button type="submit" class="btn btn-default btn-sm"><i class="fa fa-trash"></i>&nbsp; Delete Selected</button>
            </div>
            <table class="datatable">
            <tr>
                <th><input type="checkbox" onclick="var c=this.checked;document.querySelectorAll(\'.pvewhmcs-ip-checkbox\').forEach(function(cb){cb.checked=c;});"></th>
                <th>IPv4 Address</th>
                <th>Subnet Mask</th>
                <th>Action</th>
            </tr>';

    // Loop through IPs in the pool
    foreach (Capsule::table('mod_pvewhmcs_ip_addresses')->where('pool_id', '=', $pool_id)->get() as $ip) {
        
        // An address is also unavailable when its cancelled service still has
        // the retained guest link. Never infer that from vms.ipaddress.
        $service = pvewhmcs_ip_service($ip->ipaddress);
		$reservation = $service ? null : pvewhmcs_ip_reservation_service($ip->ipaddress);

        echo '<tr>
                <td>';
        if (!$service && !$reservation) {
            echo '<input type="checkbox" class="pvewhmcs-ip-checkbox" name="ids[]" value="' . (int) $ip->id . '">';
        }
        echo '</td>
                <td>' . $ip->ipaddress . '</td>
                <td>' . $ip->mask . '</td>
                <td>';

        if ($service) {
            // IP is in use: Create a link to the related service
            $serviceLink = $adminUrl . '?userid=' . $service->userid . '&id=' . $service->id;
            echo 'In use: <a href="' . htmlspecialchars($serviceLink, ENT_QUOTES, 'UTF-8') . '" target="_blank">Service #' . (int) $service->id . '</a>';
		} elseif ($reservation) {
			$serviceLink = $adminUrl . '?userid=' . $reservation->userid . '&id=' . $reservation->id;
			echo 'Reserved: <a href="' . htmlspecialchars($serviceLink, ENT_QUOTES, 'UTF-8') . '" target="_blank">Service #' . (int) $reservation->id . '</a>';
        } else {
            // IP is free: an individual delete button, scoped to just this
            // row by its own name/value pair (not the shared "ids[]"
            // checkboxes), so it works standalone regardless of what's
            // ticked elsewhere in the same form.
            echo '<button type="submit" name="single_delete_id" value="' . (int) $ip->id . '" onclick="return confirm(\'IPv4 address will be deleted from the pool, continue?\')" style="border:0;background:transparent;padding:0;cursor:pointer;"><img height="16" width="16" border="0" alt="Delete" src="images/delete.gif"></button>';
        }

        echo '</td></tr>';
    }
    echo '</table>
            <div style="margin-top:10px;">
                <button type="submit" class="btn btn-default btn-sm"><i class="fa fa-trash"></i>&nbsp; Delete Selected</button>
            </div>
          </form>';
}

// IP POOL FORM: List retained IPv4 reservations for cancelled guests.
function list_reserved_ips() {
	$adminUrl = 'clientsservices.php';
	$reservations = Capsule::table('mod_pvewhmcs_ip_addresses as i')
		->join('mod_pvewhmcs_ip_pools as p', 'p.id', '=', 'i.pool_id')
		// tblhosting.dedicatedip inherits WHMCS's utf8mb3_unicode_ci, while older
		// module tables may be utf8mb3_general_ci. IPv4 addresses are ASCII, so a
		// bytewise comparison is exact and avoids a server-wide collation migration.
		->join('tblhosting as h', function ($join) {
			$join->on('h.dedicatedip', '=', Capsule::raw('BINARY i.ipaddress'));
		})
		->join('mod_pvewhmcs_vms as v', 'v.id', '=', 'h.id')
		->leftJoin('tblclients as c', 'c.id', '=', 'h.userid')
		->where('h.domainstatus', '=', 'Terminated')
		->orderBy('i.ipaddress')
		->select(
			'i.id as address_id', 'i.pool_id', 'i.ipaddress', 'p.title as pool_title',
			'h.id as service_id', 'h.userid', 'v.vmid', 'c.firstname', 'c.lastname', 'c.companyname'
		)
		->get();

	echo '<table class="datatable"><tr><th>IPv4</th><th>Pool</th><th>Service</th><th>VMID</th><th>Client</th><th>Action</th></tr>';
	foreach ($reservations as $reservation) {
		$ipaddress = (string) $reservation->ipaddress;
		$poolTitle = (string) $reservation->pool_title;
		$serviceUrl = $adminUrl . '?userid=' . (int) $reservation->userid . '&id=' . (int) $reservation->service_id;
		$client = trim((string) $reservation->firstname . ' ' . (string) $reservation->lastname);
		if ($client === '') {
			$client = (string) $reservation->companyname;
		}
		echo '<tr>'
			. '<td>' . htmlspecialchars($ipaddress, ENT_QUOTES, 'UTF-8') . '</td>'
			. '<td>' . htmlspecialchars($poolTitle, ENT_QUOTES, 'UTF-8') . '</td>'
			. '<td><a href="' . htmlspecialchars($serviceUrl, ENT_QUOTES, 'UTF-8') . '">Service #' . (int) $reservation->service_id . '</a></td>'
			. '<td>' . (int) $reservation->vmid . '</td>'
			. '<td>' . htmlspecialchars($client, ENT_QUOTES, 'UTF-8') . '</td>'
			. '<td><form method="post">'
			. '<input type="hidden" name="pvewhmcs_action" value="release_ipv4_reservation">'
			. '<input type="hidden" name="address_id" value="' . (int) $reservation->address_id . '">'
			. '<input type="hidden" name="pool_id" value="' . (int) $reservation->pool_id . '">'
			. '<input type="hidden" name="service_id" value="' . (int) $reservation->service_id . '">'
			. '<input type="hidden" name="ipaddress" value="' . htmlspecialchars($ipaddress, ENT_QUOTES, 'UTF-8') . '">'
			. pvewhmcs_csrf_field()
			. '<label><input type="checkbox" name="release_confirmation" value="1" required> I confirm this retained CANCELADO guest cannot return to the network using this IPv4 after release.</label> '
			. '<button type="submit" class="btn btn-danger btn-sm">Release reservation</button>'
			. '</form></td>'
			. '</tr>';
	}
	echo '</table>';
}

function pvewhmcs_release_ipv4_reservation(array $post) {
	$addressId = (int) ($post['address_id'] ?? 0);
	$poolId = (int) ($post['pool_id'] ?? 0);
	$serviceId = (int) ($post['service_id'] ?? 0);
	$ipaddress = trim((string) ($post['ipaddress'] ?? ''));
	if (($post['release_confirmation'] ?? '') !== '1' || $addressId < 1 || $poolId < 1 || $serviceId < 1 || !filter_var($ipaddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
		echo '<div class="alert alert-danger">Reservation was not released: confirm the cancelled-guest warning and submit the unchanged reservation details.</div>';
		return;
	}

	try {
		Capsule::connection()->transaction(function () use ($addressId, $poolId, $serviceId, $ipaddress) {
			$address = Capsule::table('mod_pvewhmcs_ip_addresses')
				->where('id', '=', $addressId)
				->where('pool_id', '=', $poolId)
				->lock('for update')
				->first();
			if ($address === null || (string) $address->ipaddress !== $ipaddress) {
				throw new RuntimeException('The requested pool address no longer matches this reservation.');
			}

			$service = Capsule::table('tblhosting')->where('id', '=', $serviceId)->lock('for update')->first();
			if ($service === null || $service->domainstatus !== 'Terminated' || (string) $service->dedicatedip !== $ipaddress) {
				throw new RuntimeException('The service is no longer a cancelled reservation for this address.');
			}
			$guest = Capsule::table('mod_pvewhmcs_vms')->where('id', '=', $serviceId)->lock('for update')->first();
			if ($guest === null) {
				throw new RuntimeException('The retained guest link no longer exists for this service.');
			}

			Capsule::table('tblhosting')->where('id', '=', $serviceId)->update(array('dedicatedip' => ''));
		});
	} catch (\Throwable $e) {
		echo '<div class="alert alert-danger">Reservation was not released: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
		return;
	}

	$_SESSION['pvewhmcs']['infomsg']['title'] = 'IPv4 reservation released.';
	$_SESSION['pvewhmcs']['infomsg']['message'] = 'The cancelled service no longer reserves this IPv4 address. Its guest record and historical address were retained.';
	header('Location: ' . pvewhmcs_BASEURL . '&tab=ippools&action=reserved_ips');
}

// IP POOL FORM ACTION: Remove a single IP from Pool (same checks as the bulk path)
function removeip($id, $pool_id) {
	removeip_bulk(array($id), $pool_id);
}

// IP POOL FORM ACTION: Remove multiple IPs from a Pool at once. Re-checks
// pool membership and occupancy server-side (rather than trusting which
// checkboxes were rendered) so a tampered request can't delete an IP still
// assigned to a live service.
function removeip_bulk(array $ids, $pool_id) {
	$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
	$deleted = 0;
	$in_use = 0;
	$reserved = 0;

	if (!empty($ids)) {
		$result = Capsule::connection()->transaction(function () use ($ids, $pool_id) {
			$candidates = Capsule::table('mod_pvewhmcs_ip_addresses')
				->where('pool_id', '=', $pool_id)
				->whereIn('id', $ids)
				->orderBy('id')
				->lock('for update')
				->get();
			$blockers = pvewhmcs_locked_ip_blockers($candidates->pluck('ipaddress')->all());
			$deletableIds = array();
			$inUse = 0;
			$reserved = 0;
			foreach ($candidates as $candidate) {
				$blocker = $blockers[(string) $candidate->ipaddress] ?? null;
				if ($blocker === null) {
					$deletableIds[] = (int) $candidate->id;
				} elseif ($blocker['type'] === 'reservation') {
					$reserved++;
				} else {
					$inUse++;
				}
			}
			$deleted = empty($deletableIds) ? 0 : Capsule::table('mod_pvewhmcs_ip_addresses')
				->where('pool_id', '=', $pool_id)
				->whereIn('id', $deletableIds)
				->delete();
			return compact('deleted', 'inUse', 'reserved');
		});
		$deleted = $result['deleted'];
		$in_use = $result['inUse'];
		$reserved = $result['reserved'];
	}

	header("Location: " . pvewhmcs_BASEURL . "&tab=ippools&action=list_ips&id=" . $pool_id);
	$_SESSION['pvewhmcs']['infomsg']['title'] = 'IPv4 Addresses deleted.';
	$_SESSION['pvewhmcs']['infomsg']['message'] = $deleted . ' address(es) removed from the pool.' . ($in_use > 0 ? ' ' . $in_use . ' kept because a service still uses them.' : '') . ($reserved > 0 ? ' ' . $reserved . ' kept as cancelled-guest reservation(s).' : '');
}

function time2format($s) {
	$str = '';
	$d = intval( $s / 86400 );
	if ($d < '10') {
		$d = '0' . $d;
	}
	$s -= $d * 86400;
	$h = intval( $s / 3600 );
	if ($h < '10') {
		$h = '0' . $h;
	}
	$s -= $h * 3600;
	$m = intval( $s / 60 );
	if ($m < '10') {
		$m = '0' . $m;
	}
	$s -= $m * 60;
	if ($s < '10') {
		$s = '0' . $s;
	}
	if ($d) {
		$str = $d . ' days ';
	}
	if ($h) {
		$str .= $h . ':';
	}
	if ($m) {
		$str .= $m . ':';
	}
	if ($s) {
		$str .= $s . '';
	}
	return $str;
}
?>
