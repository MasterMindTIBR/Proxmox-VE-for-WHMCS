<?php

/*
	Proxmox VE for WHMCS - Addon/Server Modules for WHMCS (& PVE)
	https://github.com/MasterMindTIBR/Proxmox-VE-for-WHMCS/ (MasterMind TI fork)
	Upstream: https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/
	File: /modules/servers/pvewhmcs/pvewhmcs.php (PVE Work)

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

if (!defined('WHMCS')) {
	die('This file cannot be accessed directly');
}

// DEP: Proxmox API Class - make sure we can access via PVE via API
if (file_exists('../modules/addons/pvewhmcs/proxmox.php'))
	require_once('../modules/addons/pvewhmcs/proxmox.php');
else
	require_once(ROOTDIR . '/modules/addons/pvewhmcs/proxmox.php');

// Import SQL Connectivity (WHMCS)
use Illuminate\Database\Capsule\Manager as Capsule;

// Prepare to source Guest type
global $guest;

// Fix the Server Test showing "Pvewhmcs" instead of pretty name
// ref: https://developers.whmcs.com/provisioning-modules/meta-data-params/
function pvewhmcs_MetaData() {
    return array(
        'DisplayName' => 'Proxmox VE',
        'APIVersion' => '1.1',
        'RequiresServer' => 'true',
        'DefaultSSLPort' => 8006,
	);
}

function pvewhmcs_verify_tls_setting($secure) {
	if ($secure === null) {
		// WHMCS leaves serversecure NULL/absent on legacy servers: keep TLS
		// verification on. Everything else follows FILTER_VALIDATE_BOOLEAN, so
		// an explicitly empty/unchecked box ('') turns verification off.
		return true;
	}

	return filter_var($secure, FILTER_VALIDATE_BOOLEAN);
}

function pvewhmcs_verify_tls(array $params) {
	return pvewhmcs_verify_tls_setting($params['serversecure'] ?? null);
}

function pvewhmcs_plan_network_name($plan) {
	$network = trim((string) $plan->bridge) . trim((string) $plan->vmbr);
	if ($network === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$/', $network)) {
		throw new InvalidArgumentException('The plan has an invalid Proxmox network name.');
	}

	return $network;
}

function pvewhmcs_replace_qemu_bridge($network_config, $network) {
	$replaced = false;
	$parts = $network_config === '' ? array() : explode(',', (string) $network_config);
	foreach ($parts as $index => $part) {
		if (strpos($part, 'bridge=') === 0) {
			$parts[$index] = 'bridge=' . $network;
			$replaced = true;
		}
	}

	if (!$replaced) {
		$parts[] = 'bridge=' . $network;
	}

	return implode(',', $parts);
}

function pvewhmcs_with_vmid_lock($server_id, $callback) {
	$lock_name = 'pvewhmcs:vmid:' . (int) $server_id;
	$result = Capsule::select('SELECT GET_LOCK(?, 30) AS acquired', array($lock_name));
	if (empty($result) || (int) $result[0]->acquired !== 1) {
		$holder = Capsule::select('SELECT IS_USED_LOCK(?) AS connection_id', array($lock_name));
		$connection_id = $holder[0]->connection_id ?? null;
		$detail = $connection_id
			? "Currently held by MySQL connection #{$connection_id}. If that connection is stuck/idle (check SHOW PROCESSLIST), killing it releases the lock immediately."
			: 'No holder was reported; a concurrent request most likely just released it — retry the order.';
		throw new Exception("Timed out waiting to allocate a Proxmox VMID. {$detail}");
	}

	try {
		return call_user_func($callback);
	} finally {
		Capsule::select('SELECT RELEASE_LOCK(?)', array($lock_name));
	}
}

function pvewhmcs_guest_vmid($service_id) {
	$service_id = (int) $service_id;
	if (!$service_id) {
		return 0;
	}

	return (int) (Capsule::table('mod_pvewhmcs_vms')->where('id', $service_id)->value('vmid') ?? 0);
}

/**
 * Replaces the module-managed lifecycle tag while preserving unrelated tags.
 * Proxmox stores guest tags as a semicolon-separated string.
 */
function pvewhmcs_lifecycle_tags($existingTags, $lifecycleTag) {
	$managedTags = array('CANCELADO' => true, 'SUSPENSO' => true);
	$tags = array();

	foreach (explode(';', (string) $existingTags) as $tag) {
		$tag = trim($tag);
		if ($tag === '' || isset($managedTags[strtoupper($tag)])) {
			continue;
		}
		$tags[$tag] = true;
	}

	if ($lifecycleTag !== null) {
		$tags[$lifecycleTag] = true;
	}

	return implode(';', array_keys($tags));
}

function pvewhmcs_guest_api_path($node, $guest) {
	if (!in_array($guest->vtype, array('qemu', 'lxc'), true)) {
		throw new InvalidArgumentException("Unsupported Proxmox guest type {$guest->vtype}.");
	}

	return '/nodes/' . $node . '/' . $guest->vtype . '/' . $guest->vmid;
}

/**
 * True when a semicolon-separated Proxmox tag list contains $tag (case-insensitive).
 */
function pvewhmcs_guest_has_tag($existingTags, $tag) {
	foreach (explode(';', (string) $existingTags) as $existing) {
		if (strcasecmp(trim($existing), $tag) === 0) {
			return true;
		}
	}

	return false;
}

/**
 * Writes the lifecycle tag, and optionally `onboot`, with ONE synchronous
 * `PUT {guestPath}/config`. LXC has no POST on /config; PUT exists for QEMU and
 * LXC and reports failures (e.g. a locked guest) in the HTTP response.
 * $lifecycleTag null removes the module-managed tag; $onboot null leaves
 * `onboot` untouched. Proxmox rejects a PUT without options, so nothing is sent
 * when there is nothing to change.
 */
function pvewhmcs_update_guest_lifecycle_config(PVE2_API $proxmox, $guestPath, array $config, $lifecycleTag, $onboot = null) {
	$changes = array();
	if ($onboot !== null) {
		$changes['onboot'] = (int) $onboot;
	}

	$currentTags = trim((string) ($config['tags'] ?? ''));
	$tags = pvewhmcs_lifecycle_tags($currentTags, $lifecycleTag);
	if ($tags !== '') {
		$changes['tags'] = $tags;
	} elseif ($currentTags !== '') {
		$changes['delete'] = 'tags';
	}

	if (empty($changes)) {
		return null;
	}

	return $proxmox->put($guestPath . '/config', $changes);
}

/**
 * Waits for an asynchronous Proxmox task (UPID) to finish. The node is the
 * second `:` field of the UPID. Succeeds on exitstatus `OK` or `WARNINGS...`;
 * any other exitstatus, or the timeout, throws RuntimeException.
 */
function pvewhmcs_wait_task(PVE2_API $proxmox, string $upid, int $timeoutSeconds) {
	$upid = trim($upid);
	$parts = explode(':', $upid);
	if (($parts[0] ?? '') !== 'UPID' || ($parts[1] ?? '') === '') {
		throw new RuntimeException("Proxmox did not return a task ID (UPID); got '{$upid}'.");
	}

	$statusPath = '/nodes/' . $parts[1] . '/tasks/' . $upid . '/status';
	$deadline = time() + max(1, $timeoutSeconds);
	while (true) {
		$task = $proxmox->get($statusPath);
		if (($task['status'] ?? null) === 'stopped') {
			$exitStatus = (string) ($task['exitstatus'] ?? '');
			if ($exitStatus === 'OK' || strpos($exitStatus, 'WARNINGS') === 0) {
				return $exitStatus;
			}

			throw new RuntimeException("Proxmox task {$upid} failed: " . ($exitStatus === '' ? 'no exit status' : $exitStatus));
		}

		if (time() >= $deadline) {
			throw new RuntimeException("Proxmox task {$upid} did not finish within {$timeoutSeconds} seconds.");
		}
		sleep(1);
	}
}

function pvewhmcs_is_upid($response) {
	if (!is_string($response)) {
		return false;
	}
	$parts = explode(':', trim($response));

	return ($parts[0] ?? '') === 'UPID' && ($parts[1] ?? '') !== '';
}

/**
 * A marker is authoritative even when the PVE request's outcome is unknown.
 * Never let a runtime path touch PVE for anything except a ready guest.
 */
function pvewhmcs_guest_provisioning_error($guest) {
	$state = (string) ($guest->provisioning_state ?? 'ready');
	if ($state === '' || $state === 'ready') {
		return null;
	}

	$vmid = (int) ($guest->vmid ?? 0);
	if ($state === 'pending') {
		return "Error: provisioning for VMID {$vmid} is pending; retry CreateAccount to finish the recorded Proxmox task.";
	}
	if ($state === 'allocating') {
		return "Error: provisioning for VMID {$vmid} has an uncertain allocation outcome; it is blocked pending administrative investigation.";
	}
	if ($state === 'failed') {
		return "Error: provisioning for VMID {$vmid} failed and remains reserved; resolve the recorded allocation before operating the guest.";
	}

	return "Error: provisioning for VMID {$vmid} is in unsupported state '{$state}' and is blocked.";
}

function pvewhmcs_provisioning_set_pending($serviceId, $upid) {
	Capsule::table('mod_pvewhmcs_vms')->where('id', (int) $serviceId)->update(array(
		'provisioning_state' => 'pending',
		'provisioning_upid' => trim((string) $upid),
		'provisioning_error' => null,
		'provisioning_updated_at' => date('Y-m-d H:i:s'),
	));
}

function pvewhmcs_provisioning_set_ready($serviceId) {
	Capsule::table('mod_pvewhmcs_vms')->where('id', (int) $serviceId)->update(array(
		'provisioning_state' => 'ready',
		'provisioning_error' => null,
		'provisioning_updated_at' => date('Y-m-d H:i:s'),
	));
}

function pvewhmcs_provisioning_set_failed($serviceId, $error) {
	Capsule::table('mod_pvewhmcs_vms')->where('id', (int) $serviceId)->update(array(
		'provisioning_state' => 'failed',
		'provisioning_error' => substr((string) $error, 0, 65535),
		'provisioning_updated_at' => date('Y-m-d H:i:s'),
	));
}

/**
 * Wait for a recorded UPID. A stopped task with an error is terminal and is
 * retained as failed. Transport failures and deadlines leave it pending so a
 * later callback can safely retry the same task.
 */
function pvewhmcs_wait_recorded_provisioning_task(PVE2_API $proxmox, $serviceId, $upid) {
	try {
		pvewhmcs_wait_task($proxmox, $upid, 150);
	} catch (PVE2_Exception $e) {
		throw $e;
	} catch (RuntimeException $e) {
		if (strpos($e->getMessage(), 'did not finish within') !== false) {
			throw $e;
		}
		pvewhmcs_provisioning_set_failed($serviceId, $e->getMessage());
		throw $e;
	}
}

/**
 * Under the server VMID lock, lock a candidate pool address before the target
 * service and write the irreversible marker before a create/clone POST. The
 * dedicated IP changes in this same transaction, so a process death cannot
 * expose it for reuse.
 */
function pvewhmcs_write_provisioning_marker($poolId, $serviceId, $serverId, $vmid, $vtype, $node, $mode, $userId, $ipv6) {
	// This is only an optimistic candidate list. Every item is revalidated once
	// its address row is locked, before the marker or dedicated IP is changed.
	$candidateIds = Capsule::table('mod_pvewhmcs_ip_addresses')
		->where('pool_id', '=', $poolId)
		->orderBy('id')
		->pluck('id')
		->all();
	foreach ($candidateIds as $candidateId) {
		$result = Capsule::connection()->transaction(function () use ($candidateId, $poolId, $serviceId, $serverId, $vmid, $vtype, $node, $mode, $userId, $ipv6) {
			// Keep this first: release and deletion take the same lock order.
			$ip = Capsule::table('mod_pvewhmcs_ip_addresses as i')
				->join('mod_pvewhmcs_ip_pools as p', 'p.id', '=', 'i.pool_id')
				->where('i.id', '=', (int) $candidateId)
				->where('i.pool_id', '=', $poolId)
				->select('i.id', 'i.pool_id', 'i.ipaddress', 'i.mask', 'p.gateway')
				->lock('for update')
				->first();
			if ($ip === null) {
				return null;
			}

			$service = Capsule::table('tblhosting')->where('id', (int) $serviceId)->lock('for update')->first();
			if ($service === null) {
				throw new RuntimeException("Service #{$serviceId} no longer exists.");
			}
			if ((int) ($service->server ?? 0) !== (int) $serverId) {
				throw new RuntimeException("Service #{$serviceId} changed Proxmox servers while provisioning was prepared; retry with its current server.");
			}
			$linked = Capsule::table('mod_pvewhmcs_vms')->where('id', (int) $serviceId)->lock('for update')->first();
			if ($linked !== null) {
				return array('existing' => $linked);
			}
			if (trim((string) ($service->dedicatedip ?? '')) !== '') {
				throw new RuntimeException("Service #{$serviceId} already has a dedicated IP but no provisioning marker; allocation is blocked for investigation.");
			}

			// Revalidate this exact locked address. A terminated service reserves it
			// only when it still has a guest row identified by the service ID; the
			// guest's historical vms.ipaddress is never used for this decision.
			$addressServices = Capsule::table('tblhosting')
				->where('dedicatedip', '=', $ip->ipaddress)
				->orderBy('id')
				->lock('for update')
				->get();
			$addressServiceIds = array();
			$busy = false;
			foreach ($addressServices as $addressService) {
				$addressServiceIds[] = (int) $addressService->id;
				if (in_array($addressService->domainstatus, array('Active', 'Suspended', 'Completed', 'Pending'), true)) {
					$busy = true;
				}
			}
			$guestServiceIds = empty($addressServiceIds) ? array() : Capsule::table('mod_pvewhmcs_vms')
				->whereIn('id', $addressServiceIds)
				->orderBy('id')
				->lock('for update')
				->pluck('id')
				->all();
			$guestServiceIds = array_flip(array_map('intval', $guestServiceIds));
			$reserved = false;
			foreach ($addressServices as $addressService) {
				if ($addressService->domainstatus === 'Terminated' && isset($guestServiceIds[(int) $addressService->id])) {
					$reserved = true;
				}
			}
			$unreadyMarker = Capsule::table('mod_pvewhmcs_vms')
				->where('ipaddress', '=', $ip->ipaddress)
				->whereRaw("COALESCE(provisioning_state, 'ready') <> 'ready'")
				->lock('for update')
				->exists();
			if ($busy || $reserved || $unreadyMarker) {
				return null;
			}

			Capsule::table('mod_pvewhmcs_vms')->insert(array(
				'id' => (int) $serviceId,
				'vmid' => (int) $vmid,
				'user_id' => (int) $userId,
				'vtype' => $vtype,
				'ipaddress' => $ip->ipaddress,
				'subnetmask' => $ip->mask,
				'gateway' => $ip->gateway,
				'created' => date('Y-m-d H:i:s'),
				'v6prefix' => $ipv6,
				'provisioning_state' => 'allocating',
				'provisioning_node' => $node,
				'provisioning_mode' => $mode,
				'provisioning_updated_at' => date('Y-m-d H:i:s'),
			));
			Capsule::table('tblhosting')->where('id', (int) $serviceId)->update(array('dedicatedip' => $ip->ipaddress));

			return array('ip' => $ip);
		});
		if ($result !== null) {
			return $result;
		}
	}

	throw new RuntimeException('No free IP addresses available in the selected pool.');
}

/**
 * Lifecycle power change: POST status/{start|stop} and wait for the task.
 */
function pvewhmcs_guest_power_and_wait(PVE2_API $proxmox, $guestPath, $command) {
	$upid = $proxmox->post($guestPath . '/status/' . $command, array());

	return pvewhmcs_wait_task($proxmox, (string) $upid, 60);
}

function pvewhmcs_guest_ha_sid($guest) {
	$haType = $guest->vtype === 'qemu' ? 'vm' : ($guest->vtype === 'lxc' ? 'ct' : null);
	if ($haType === null) {
		throw new InvalidArgumentException("Unsupported Proxmox guest type {$guest->vtype}.");
	}

	return $haType . ':' . $guest->vmid;
}

function pvewhmcs_guest_ha_resource(PVE2_API $proxmox, $guest) {
	$sid = pvewhmcs_guest_ha_sid($guest);
	foreach ((array) $proxmox->get('/cluster/ha/resources') as $resource) {
		if (($resource['sid'] ?? '') === $sid) {
			return $resource;
		}
	}

	return null;
}

/**
 * Current HA state of the guest's resource, or null when it has none. A resource
 * without an explicit state (or with the `enabled` alias) is `started`, as in
 * the Proxmox HA config defaults.
 */
function pvewhmcs_guest_ha_state(PVE2_API $proxmox, $guest) {
	$resource = pvewhmcs_guest_ha_resource($proxmox, $guest);
	if ($resource === null) {
		return null;
	}

	$state = (string) ($resource['state'] ?? 'started');

	return ($state === '' || $state === 'enabled') ? 'started' : $state;
}

function pvewhmcs_set_guest_ha_state(PVE2_API $proxmox, $guest, $state) {
	return $proxmox->put('/cluster/ha/resources/' . pvewhmcs_guest_ha_sid($guest), array('state' => $state));
}

/**
 * WHMCS server (tblservers.id) of the service: $params['serverid'], falling
 * back to tblhosting.server for callers that do not receive it.
 */
function pvewhmcs_service_server_id(array $params) {
	$serverId = (int) ($params['serverid'] ?? 0);
	if ($serverId === 0 && !empty($params['serviceid'])) {
		$serverId = (int) Capsule::table('tblhosting')->where('id', $params['serviceid'])->value('server');
	}

	return $serverId;
}

/**
 * Another mod_pvewhmcs_vms row, on the same WHMCS server, linked to the same
 * VMID (e.g. a cancelled service kept its row and Proxmox reused the VMID).
 */
function pvewhmcs_vmid_conflict($guest, array $params) {
	$serverId = pvewhmcs_service_server_id($params);
	$others = Capsule::table('mod_pvewhmcs_vms')
		->where('vmid', $guest->vmid)
		->where('id', '!=', $guest->id)
		->get();
	foreach ($others as $other) {
		if ((int) Capsule::table('tblhosting')->where('id', $other->id)->value('server') === $serverId) {
			return $other;
		}
	}

	return null;
}

function pvewhmcs_vmid_conflict_error($guest, array $params) {
	$other = pvewhmcs_vmid_conflict($guest, $params);
	if ($other === null) {
		return null;
	}

	return "Error: VMID {$guest->vmid} is also linked to Service #{$other->id}. Resolve the duplicate link before acting on this guest.";
}

/**
 * Strings WHMCS must mask in the module log: server password, service
 * password, the customer's Password custom field, VNC secret and relay secret
 * (plus the JSON-escaped form, since logModuleCall JSON-encodes arrays).
 */
function pvewhmcs_log_secrets(array $params, array $extraSecrets = array()) {
	$config = Capsule::table('mod_pvewhmcs')->where('id', '1')->first();
	$candidates = array_merge(array(
		$params['serverpassword'] ?? null,
		$params['password'] ?? null,
		$params['customfields']['Password'] ?? null,
		$config->vnc_secret ?? null,
		$config->console_relay_secret ?? null,
	), $extraSecrets);

	$secrets = array();
	foreach ($candidates as $secret) {
		if (!is_string($secret) || $secret === '') {
			continue;
		}
		$secrets[] = $secret;
		$escaped = substr((string) json_encode($secret), 1, -1);
		if ($escaped !== '' && $escaped !== $secret) {
			$secrets[] = $escaped;
		}
	}

	return array_values(array_unique($secrets));
}

function pvewhmcs_log_module_call($action, $request, $response, array $params, $processed = '', array $extraSecrets = array()) {
	logModuleCall('pvewhmcs', $action, $request, $response, $processed, pvewhmcs_log_secrets($params, $extraSecrets));
}

function pvewhmcs_debug_enabled() {
	return Capsule::table('mod_pvewhmcs')->where('id', '1')->value('debug_mode') == 1;
}

/**
 * Exception trace for mod_pvewhmcs_logs.raw without call arguments (they can
 * carry passwords): class, message, file:line, then `file:line function` frames.
 */
function pvewhmcs_compact_trace(\Throwable $e) {
	$lines = array(get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
	foreach ($e->getTrace() as $index => $frame) {
		$where = isset($frame['file']) ? $frame['file'] . ':' . ($frame['line'] ?? '?') : '[internal]';
		$function = (isset($frame['class']) ? $frame['class'] . ($frame['type'] ?? '::') : '') . ($frame['function'] ?? '');
		$lines[] = '#' . $index . ' ' . $where . ' ' . $function;
	}

	return implode("\n", $lines);
}

/**
 * F10: a client (no admin session) may only drive an Active service.
 */
function pvewhmcs_client_service_inactive(array $params) {
	return empty($_SESSION['adminid']) && ($params['status'] ?? '') !== 'Active';
}

/**
 * Build a Proxmox guest name/hostname. Resolution order:
 *   1. Per-product pattern (`$params['configoption3']`, field "VM Name Pattern").
 *   2. Global default pattern (`mod_pvewhmcs.name_pattern`, addon Config tab), when (1) is blank.
 *   3. Legacy hardcoded `<orderid-or-serviceid>-<hostname>` layout, when both (1) and (2) are blank.
 *
 * Supported pattern tokens:
 *   {vmid}        Proxmox VMID allocated for this guest (pass once known; see $vmid param).
 *   {node}        Proxmox node selected for creation (pass once known; see $node param).
 *   {serviceid}   WHMCS service ID (tblhosting.id).
 *   {orderid}     WHMCS order ID.
 *   {clientid}    WHMCS client ID (tblclients.id).
 *   {clientname}  Client's first + last name, sanitized.
 *   {hostname}    Customer-entered hostname (the standard `domain` provisioning param).
 *   {pid}         WHMCS product ID (tblproducts.id).
 *   {plan}        Selected PVE plan title (mod_pvewhmcs_plans.title).
 *   {date}        Creation date (Y-m-d).
 *
 * Call this ONLY once each token's source value is actually known -- in particular,
 * {vmid}/{node} are resolved by the caller (inside the VMID allocation lock) and must be
 * passed in explicitly; calling this before then will render them as empty strings.
 *
 * QEMU `name` and LXC `hostname` use the Proxmox `dns-name` format: labels of
 * [A-Za-z0-9-] that neither start nor end with `-`, joined by single dots. Every
 * token and the final string go through pvewhmcs_dns_name_sanitize(), and the
 * result is capped at 63 characters.
 */
function pvewhmcs_guest_name(array $params, ?int $vmid = null, ?string $node = null, ?string $planTitle = null) {
	$identifier = (int) ($params['orderid'] ?? ($params['serviceid'] ?? 0));
	$hostname = pvewhmcs_dns_name_sanitize($params['domain'] ?? '');

	$pattern = trim((string) ($params['configoption3'] ?? ''));

	if ($pattern === '') {
		$pattern = trim((string) (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('name_pattern') ?? ''));
	}

	if ($pattern === '') {
		$name = ($identifier ?: 'vm') . ($hostname !== '' ? '-' . $hostname : '');
	} else {
		$clientName = pvewhmcs_dns_name_sanitize(
			($params['clientsdetails']['firstname'] ?? '') . ' ' . ($params['clientsdetails']['lastname'] ?? '')
		);

		$tokens = array(
			'{vmid}' => $vmid !== null ? (string) $vmid : '',
			'{node}' => $node !== null ? pvewhmcs_dns_name_sanitize($node) : '',
			'{serviceid}' => (string) (int) ($params['serviceid'] ?? 0),
			'{orderid}' => (string) (int) ($params['orderid'] ?? 0),
			'{clientid}' => (string) (int) ($params['clientsdetails']['userid'] ?? $params['userid'] ?? 0),
			'{clientname}' => $clientName,
			'{hostname}' => $hostname,
			'{pid}' => (string) (int) ($params['pid'] ?? 0),
			'{plan}' => $planTitle !== null ? pvewhmcs_dns_name_sanitize($planTitle) : '',
			'{date}' => date('Y-m-d'),
		);

		$name = strtr($pattern, $tokens);
	}

	$name = pvewhmcs_dns_name_sanitize(substr(pvewhmcs_dns_name_sanitize($name), 0, 63));
	if ($name === '') {
		$name = (string) ($identifier ?: 'vm');
	}

	return $name;
}

/**
 * Reduces a string to a valid Proxmox `dns-name`: only [A-Za-z0-9.-], single
 * dots, no label starting or ending with `-`, no leading/trailing `.`/`-`.
 * May return '' (callers supply a fallback).
 */
function pvewhmcs_dns_name_sanitize($value) {
	$value = preg_replace('/[^A-Za-z0-9.-]+/', '-', trim((string) $value));
	// Any run of '.'/'-' that contains a dot becomes one dot, so labels never
	// begin/end with '-' and no empty label remains.
	$value = preg_replace('/[.-]*\.[.-]*/', '.', $value);

	return trim($value, '.-');
}

/**
 * Records every lifecycle/power action in mod_pvewhmcs_logs, whether the
 * handler throws or returns one of this module's "success"/"Error ..." strings.
 * Anything other than the exact string "success" (the WHMCS contract) is a
 * failure. Failures are always re-thrown so WHMCS's own error handling is unaffected.
 */
function pvewhmcs_run_tracked_action($action, $type, array $params, callable $handler) {
	pvewhmcs_ensure_schema();
	$service_id = (int) ($params['serviceid'] ?? 0);
	$user_id = (int) ($params['clientsdetails']['userid'] ?? ($params['userid'] ?? 0));
	// The acting admin (0 for cron/checkout) and the service's Proxmox server
	// at the moment the action STARTED are recorded unchanged, so history stays
	// attributable even if the service moves servers afterwards.
	$auth_id = (int) ($_SESSION['adminid'] ?? 0);
	$server_id = pvewhmcs_service_server_id($params);
	$vmid_before = pvewhmcs_guest_vmid($service_id);

	try {
		$result = $handler();
		$failed = $result !== 'success';
		pvewhmcs_log_action(array(
			'auth_id' => $auth_id,
			'server_id' => $server_id,
			'user_id' => $user_id,
			'service' => $service_id,
			'target_id' => pvewhmcs_guest_vmid($service_id) ?: $vmid_before,
			'level' => $failed ? 'error' : 'info',
			'type' => $type,
			'action' => $action,
			'response' => is_string($result) ? $result : 'Unexpected handler result: ' . json_encode($result),
		));

		return $result;
	} catch (\Throwable $e) {
		pvewhmcs_log_action(array(
			'auth_id' => $auth_id,
			'server_id' => $server_id,
			'user_id' => $user_id,
			'service' => $service_id,
			'target_id' => pvewhmcs_guest_vmid($service_id) ?: $vmid_before,
			'level' => 'error',
			'type' => $type,
			'action' => $action,
			'response' => $e->getMessage(),
			'raw' => pvewhmcs_compact_trace($e),
		));

		throw $e;
	}
}

function pvewhmcs_CreateAccount($params) {
	return pvewhmcs_run_tracked_action('CreateAccount', 'lifecycle', $params, function () use ($params) {
		return pvewhmcs_CreateAccount_impl($params);
	});
}

function pvewhmcs_SuspendAccount(array $params) {
	return pvewhmcs_run_tracked_action('SuspendAccount', 'lifecycle', $params, function () use ($params) {
		return pvewhmcs_SuspendAccount_impl($params);
	});
}

function pvewhmcs_UnsuspendAccount(array $params) {
	return pvewhmcs_run_tracked_action('UnsuspendAccount', 'lifecycle', $params, function () use ($params) {
		return pvewhmcs_UnsuspendAccount_impl($params);
	});
}

function pvewhmcs_TerminateAccount(array $params) {
	return pvewhmcs_run_tracked_action('TerminateAccount', 'lifecycle', $params, function () use ($params) {
		return pvewhmcs_TerminateAccount_impl($params);
	});
}

function pvewhmcs_vmStart($params) {
	return pvewhmcs_run_tracked_action('vmStart', 'power', $params, function () use ($params) {
		return pvewhmcs_vmStart_impl($params);
	});
}

function pvewhmcs_vmReboot($params) {
	return pvewhmcs_run_tracked_action('vmReboot', 'power', $params, function () use ($params) {
		return pvewhmcs_vmReboot_impl($params);
	});
}

function pvewhmcs_vmShutdown($params) {
	return pvewhmcs_run_tracked_action('vmShutdown', 'power', $params, function () use ($params) {
		return pvewhmcs_vmShutdown_impl($params);
	});
}

function pvewhmcs_vmStop($params) {
	return pvewhmcs_run_tracked_action('vmStop', 'power', $params, function () use ($params) {
		return pvewhmcs_vmStop_impl($params);
	});
}

function pvewhmcs_cluster_usage_stats(array $cluster_status, array $resources) {
	$cluster_name = null;
	foreach ($cluster_status as $status) {
		if (!is_array($status) || ($status['type'] ?? '') !== 'cluster') {
			continue;
		}

		$cluster_name = trim((string) ($status['name'] ?? ''));
		break;
	}

	$stats = array(
		'cluster_name' => $cluster_name,
		'nodes' => 0,
		'qemu' => 0,
		'lxc' => 0,
	);
	foreach ($resources as $resource) {
		if (!is_array($resource)) {
			continue;
		}

		$type = $resource['type'] ?? '';
		if ($type === 'node') {
			$stats['nodes']++;
		} elseif ($type === 'qemu' || $type === 'lxc') {
			$stats[$type]++;
		}
	}

	return $stats;
}

function pvewhmcs_cluster_usage_stats_html(array $stats) {
	$cluster = $stats['cluster_name'] === null
		? 'No'
		: 'Yes (' . htmlspecialchars($stats['cluster_name'], ENT_QUOTES, 'UTF-8') . ')';

	$metric_style = 'display:flex;flex-direction:column;gap:2px;';
	$label_style = 'color:#888;font-size:10px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;';
	$value_style = 'color:#333;font-size:14px;font-weight:600;';

	return '<div style="display:grid;grid-template-columns:minmax(140px,2fr) repeat(3,minmax(58px,1fr));gap:12px;">'
		. '<div style="' . $metric_style . '"><span style="' . $label_style . '">Cluster</span><span style="' . $value_style . '">' . $cluster . '</span></div>'
		. '<div style="' . $metric_style . '"><span style="' . $label_style . '">Nodes</span><span style="' . $value_style . '">' . (int) $stats['nodes'] . '</span></div>'
		. '<div style="' . $metric_style . '"><span style="' . $label_style . '">QEMU</span><span style="' . $value_style . '">' . (int) $stats['qemu'] . '</span></div>'
		. '<div style="' . $metric_style . '"><span style="' . $label_style . '">LXC</span><span style="' . $value_style . '">' . (int) $stats['lxc'] . '</span></div>'
		. '</div>';
}

/**
 * AdminLink: show a direct link to the Proxmox UI on :8006.
 * Falls back to server IP if hostname is empty.
 */
function pvewhmcs_AdminLink(array $params) {
	pvewhmcs_ensure_schema();
    $host = pvewhmcs_connection_host($params['serverhostname'] ?? '', $params['serverip'] ?? '');
    $port = pvewhmcs_connection_port($params['serverport'] ?? '');
    if (!$host) {
        // Nothing to link to – return the module page as a safe fallback
        return '<a href="addonmodules.php?module=pvewhmcs">Module Config</a>';
    }

    $url = 'https://' . pvewhmcs_url_host($host) . ':' . $port;
    $stats = '<span style="color:#777;font-size:13px;">Unavailable</span>';
    try {
        $proxmox = new PVE2_API(
            $host,
            $params['serverusername'] ?? '',
            'pam',
            $params['serverpassword'] ?? '',
            $port,
            pvewhmcs_verify_tls($params)
        );
        // Rule 10: an unreachable PVE must not stall the Servers page.
        if (method_exists($proxmox, 'set_timeouts')) {
            $proxmox->set_timeouts(2, 4);
        }
        if ($proxmox->login()) {
            $stats = pvewhmcs_cluster_usage_stats_html(
                pvewhmcs_cluster_usage_stats(
                    $proxmox->get('/cluster/status'),
                    $proxmox->get('/cluster/resources')
                )
            );
        }
    } catch (Throwable $e) {
        // The admin shortcut must remain usable if the stats request fails.
    }

    return '<div style="display:flex;flex-wrap:wrap;gap:20px;align-items:stretch;max-width:760px;padding:10px 0;">'
        . '<div style="display:flex;flex-direction:column;justify-content:center;gap:7px;min-width:120px;padding-right:20px;border-right:1px solid #e5e5e5;">'
        . '<span style="color:#888;font-size:10px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;">PVE Access</span>'
        . '<form action="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" method="get" target="_blank">'
        . '<input type="submit" value="Log in to PVE" class="btn btn-sm btn-default" />'
        . '</form></div>'
        . '<div style="flex:1;min-width:340px;padding:1px 0;">'
        . '<div style="color:#888;font-size:10px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;margin-bottom:7px;">Proxmox Stats</div>'
        . $stats
        . '</div></div>';
}

// WHMCS CONFIG > SERVICES/PRODUCTS > Their Service > Tab #3 (Plan/Pool)
function pvewhmcs_ConfigOptions() {
	pvewhmcs_ensure_schema();
	// Retrieve PVE for WHMCS Cluster
	$server=Capsule::table('tblservers')->where('type', '=', 'pvewhmcs')->get()[0] ;

	// Retrieve Plans
	foreach (Capsule::table('mod_pvewhmcs_plans')->get() as $plan) {
		$plans[$plan->id] = '(' . $plan->vmtype . ')&nbsp;' . $plan->title ;
	}

	// Retrieve IP Pools
	foreach (Capsule::table('mod_pvewhmcs_ip_pools')->get() as $ippool) {
		$ippools[$ippool->id] = $ippool->title ;
	}
	
	// OPTIONS FOR THE QEMU/LXC PACKAGE; ties WHMCS PRODUCT to MODULE PLAN/POOL
	// Ref: https://developers.whmcs.com/provisioning-modules/config-options/
	// SQL/Param: configoption1 configoption2 configoption3
	$configarray = array(
		"Plan" => array(
			"FriendlyName" => "PVE Plan",
			"Type" => "dropdown",
			'Options' => $plans ,
			"Description" => "(QEMU/LXC) Plan Name"
		),
		"IPPool" => array(
			"FriendlyName" => "IPv4 Pool",
			"Type" => "dropdown",
			'Options'=> $ippools,
			"Description" => "(IPv4) Allocation Pool"
		),
		"NamePattern" => array(
			"FriendlyName" => "VM Name Pattern",
			"Type" => "text",
			"Size" => "60",
			"Description" => "Optional. Leave blank to use the Module Config tab's global default; leave both "
				. "blank to keep the legacy '&lt;order/service id&gt;-&lt;hostname&gt;' name. "
				. "Tokens: {vmid} {node} {serviceid} {orderid} {clientid} {clientname} {hostname} {pid} {plan} {date}. "
				. "Example: vm{vmid}-{clientname}-{hostname}"
		),
	);

	// Deliver the options back into WHMCS
	return $configarray;
}

/**
 * Server-side format checks for the product custom fields that CreateAccount
 * puts into Proxmox paths/volume IDs, matching what the README documents:
 *   KVMTemplate  numeric VMID of the template (clone path)
 *   ISO          bare file name; the module composes local:iso/<file>,media=cdrom
 *   Template     LXC volume ID <storage>:vztmpl/<file>
 * TPL_Node_QEMU/TPL_Node_LXC are checked against the cluster node list once logged in.
 * Returns an error string, or null when the fields used by this plan are valid.
 */
function pvewhmcs_create_custom_field_error(array $params, $plan) {
	$fields = (array) ($params['customfields'] ?? array());

	if (!empty($fields['KVMTemplate'])) {
		if (!preg_match('/^\d+$/', (string) $fields['KVMTemplate'])) {
			return "Error: Custom field KVMTemplate must be the template's numeric Proxmox VMID.";
		}

		return null;
	}

	if ($plan->vmtype == 'lxc') {
		if (!preg_match('/^[A-Za-z][A-Za-z0-9_.-]*:vztmpl\/[^\/\\\\,=\s]+$/', (string) ($fields['Template'] ?? ''))) {
			return 'Error: Custom field Template must be a Proxmox volume ID such as local:vztmpl/ubuntu-99.99-standard_amd64.tar.gz.';
		}
	} elseif (isset($fields['ISO'])) {
		$iso = (string) $fields['ISO'];
		if (!preg_match('/^[^\/\\\\,=:\s.][^\/\\\\,=:\s]*$/', $iso)) {
			return 'Error: Custom field ISO must be only the ISO file name (for example debian-13.5.0-amd64-DVD-1.iso); the module attaches it from local:iso/.';
		}
	}

	return null;
}

function pvewhmcs_locked_provisioning_guest($serviceId, $serverId) {
	return Capsule::connection()->transaction(function () use ($serviceId, $serverId) {
		$service = Capsule::table('tblhosting')->where('id', (int) $serviceId)->lock('for update')->first();
		if ($service === null) {
			throw new RuntimeException("Service #{$serviceId} no longer exists.");
		}
		if ((int) ($service->server ?? 0) !== (int) $serverId) {
			throw new RuntimeException("Service #{$serviceId} changed Proxmox servers; retry with its current server.");
		}

		return Capsule::table('mod_pvewhmcs_vms')->where('id', (int) $serviceId)->lock('for update')->first();
	});
}

function pvewhmcs_existing_provisioning_create_result($guest) {
	if ($guest === null) {
		return null;
	}
	$state = (string) ($guest->provisioning_state ?? 'ready');
	if ($state === 'ready' || $state === '') {
		return "Error: Service #{$guest->id} is already linked to {$guest->vtype} VMID {$guest->vmid}. Remove or relink that guest before creating again.";
	}

	return pvewhmcs_guest_provisioning_error($guest);
}

function pvewhmcs_create_direct_settings(array $params, $plan, $ip, $network, $guestType) {
	$settings = array();
	$ipv6Enabled = !empty($plan->ipv6) && $plan->ipv6 != '0';
	$nameservers = pvewhmcs_guest_nameservers($ipv6Enabled);
	if ($guestType === 'lxc') {
		$settings['ostemplate'] = $params['customfields']['Template'];
		$settings['swap'] = $plan->swap;
		$settings['rootfs'] = $plan->storage . ':' . $plan->disk;
		$settings['bwlimit'] = $plan->diskio;
		$settings['nameserver'] = $nameservers;
		$settings['net0'] = 'name=eth0,bridge=' . $network . ',ip=' . $ip->ipaddress . '/' . mask2cidr($ip->mask) . ',gw=' . $ip->gateway . ',rate=' . $plan->netrate;
		if ($ipv6Enabled) {
			$settings['net1'] = 'name=eth1,bridge=' . $network . ',rate=' . $plan->netrate;
			if ($plan->ipv6 === 'auto') {
				$settings['net1'] .= ',ip6=auto';
			} elseif ($plan->ipv6 === 'dhcp') {
				$settings['net1'] .= ',ip6=dhcp';
			}
			if (!empty($plan->vlanid)) {
				$settings['net1'] .= ',tag=' . $plan->vlanid;
			}
		}
		if (!empty($plan->vlanid)) {
			$settings['net0'] .= ',tag=' . $plan->vlanid;
		}
		$settings['onboot'] = $plan->onboot;
		$settings['unprivileged'] = $plan->unpriv;
		$settings['password'] = $params['customfields']['Password'];
	} else {
		$settings['scsihw'] = 'virtio-scsi-single';
		$settings['sockets'] = $plan->cpus;
		$settings['cores'] = $plan->cores;
		$settings['cpu'] = $plan->cpuemu;
		$settings['nameserver'] = $nameservers;
		$settings['ipconfig0'] = 'ip=' . $ip->ipaddress . '/' . mask2cidr($ip->mask) . ',gw=' . $ip->gateway;
		if (($cloudInitUser = pvewhmcs_cloud_init_user()) !== null) {
			$settings['ciuser'] = $cloudInitUser;
		}
		if ($ipv6Enabled) {
			if ($plan->ipv6 === 'auto') {
				$settings['ipconfig1'] = 'ip6=auto';
			} elseif ($plan->ipv6 === 'dhcp') {
				$settings['ipconfig1'] = 'ip6=dhcp';
			}
		}
		$settings['kvm'] = $plan->kvm;
		$settings['onboot'] = $plan->onboot;
		$settings[$plan->disktype . '0'] = $plan->storage . ':' . $plan->disk . ',format=' . $plan->diskformat;
		if (!empty($plan->diskcache)) {
			$settings[$plan->disktype . '0'] .= ',cache=' . $plan->diskcache;
		}
		$settings['bwlimit'] = $plan->diskio;
		if (isset($params['customfields']['ISO'])) {
			$settings['ide2'] = 'local:iso/' . $params['customfields']['ISO'] . ',media=cdrom';
		}
		if ($plan->netmode != 'none') {
			$settings['net0'] = $plan->netmodel;
			if ($plan->netmode == 'bridge') {
				$settings['net0'] .= ',bridge=' . $network;
			}
			$settings['net0'] .= ',firewall=' . $plan->firewall;
			if (!empty($plan->netrate)) {
				$settings['net0'] .= ',rate=' . $plan->netrate;
			}
			if (!empty($plan->vlanid)) {
				$settings['net0'] .= ',tag=' . $plan->vlanid;
			}
			if (isset($settings['ipconfig1'])) {
				$settings['net1'] = $plan->netmodel;
				if ($plan->netmode == 'bridge') {
					$settings['net1'] .= ',bridge=' . $network;
				}
				$settings['net1'] .= ',firewall=' . $plan->firewall;
				if (!empty($plan->netrate)) {
					$settings['net1'] .= ',rate=' . $plan->netrate;
				}
				if (!empty($plan->vlanid)) {
					$settings['net1'] .= ',tag=' . $plan->vlanid;
				}
			}
		}
	}
	$settings['cpuunits'] = $plan->cpuunits;
	$settings['cpulimit'] = $plan->cpulimit;
	$settings['memory'] = $plan->memory;

	return $settings;
}

function pvewhmcs_clone_post_configuration(PVE2_API $proxmox, array $params, $plan, $ip, $network, $node, $vmid) {
	$ipv6Enabled = !empty($plan->ipv6) && $plan->ipv6 != '0';
	$tweaks = array(
		'memory' => $plan->memory, 'ostype' => $plan->ostype,
		'sockets' => $plan->cpus, 'cores' => $plan->cores, 'cpu' => $plan->cpuemu,
		'kvm' => $plan->kvm, 'onboot' => $plan->onboot,
		'nameserver' => pvewhmcs_guest_nameservers($ipv6Enabled),
		'ipconfig0' => 'ip=' . $ip->ipaddress . '/' . mask2cidr($ip->mask) . ',gw=' . $ip->gateway,
	);
	if (($cloudInitUser = pvewhmcs_cloud_init_user()) !== null) {
		$tweaks['ciuser'] = $cloudInitUser;
	}
	$guestPath = '/nodes/' . $node . '/qemu/' . $vmid;
	if ($plan->netmode === 'bridge') {
		$config = $proxmox->get($guestPath . '/config');
		$tweaks['net0'] = pvewhmcs_replace_qemu_bridge($config['net0'] ?? $plan->netmodel, $network);
		if ($ipv6Enabled) {
			$tweaks['net1'] = pvewhmcs_replace_qemu_bridge($config['net1'] ?? $plan->netmodel, $network);
		}
	}
	if ($ipv6Enabled) {
		if ($plan->ipv6 === 'auto') {
			$tweaks['ipconfig1'] = 'ip6=auto';
		} elseif ($plan->ipv6 === 'dhcp') {
			$tweaks['ipconfig1'] = 'ip6=dhcp';
		}
	}
	if (!empty($params['password'])) {
		$tweaks['cipassword'] = $params['password'];
	}
	if (!empty($params['customfields']['Password'])) {
		$tweaks['cipassword'] = $params['customfields']['Password'];
	}

	$response = $proxmox->put($guestPath . '/config', $tweaks);
	if (pvewhmcs_is_upid($response)) {
		pvewhmcs_provisioning_set_pending($params['serviceid'], $response);
		pvewhmcs_wait_recorded_provisioning_task($proxmox, $params['serviceid'], $response);
	}
	if (!empty($plan->onboot)) {
		$status = $proxmox->get($guestPath . '/status/current');
		if (($status['status'] ?? null) !== 'running') {
			$response = $proxmox->post($guestPath . '/status/start', array());
			if (!pvewhmcs_is_upid($response)) {
				throw new RuntimeException('Proxmox did not return a task ID for the clone start; provisioning remains pending.');
			}
			pvewhmcs_provisioning_set_pending($params['serviceid'], $response);
			pvewhmcs_wait_recorded_provisioning_task($proxmox, $params['serviceid'], $response);
		}
	}
}

function pvewhmcs_resume_pending_clone(PVE2_API $proxmox, array $params, $guest) {
	$plan = Capsule::table('mod_pvewhmcs_plans')->where('id', $params['configoption1'] ?? 0)->first();
	if ($plan === null || $plan->vmtype !== 'kvm') {
		throw new RuntimeException('The pending clone cannot be finalized because its selected QEMU plan is unavailable.');
	}
	$node = trim((string) ($guest->provisioning_node ?? ''));
	if ($node === '') {
		throw new RuntimeException("The pending clone for VMID {$guest->vmid} has no recorded Proxmox node.");
	}
	$network = $plan->netmode === 'bridge' ? pvewhmcs_plan_network_name($plan) : null;
	$ip = (object) array(
		'ipaddress' => $guest->ipaddress,
		'mask' => $guest->subnetmask,
		'gateway' => $guest->gateway,
	);

	// Reapplying this PUT is idempotent. It is necessary after a process dies
	// between the clone task and configuration, and is also safe if the saved
	// UPID was already the configuration or start task.
	pvewhmcs_clone_post_configuration($proxmox, $params, $plan, $ip, $network, $node, (int) $guest->vmid);
}

// Recoverable provisioning state machine.
function pvewhmcs_CreateAccount_recoverable_impl($params) {
	if (empty($params['configoption1']) || empty($params['configoption2'])) {
		throw new Exception('PVEWHMCS Error: Missing Config. Service/Product WHMCS Config not saved (Plan/Pool not assigned to WHMCS Service type). Check Support/Health tab in Module Config for info. Quick and easy fix.');
	}
	$serviceId = (int) $params['serviceid'];
	$serverId = pvewhmcs_service_server_id($params);
	$proxmox = pvewhmcs_params_api($params);

	return pvewhmcs_with_vmid_lock($serverId, function () use ($params, $serviceId, $serverId, $proxmox) {
		$existing = pvewhmcs_locked_provisioning_guest($serviceId, $serverId);
		if ($existing !== null) {
			$state = (string) ($existing->provisioning_state ?? 'ready');
			if ($state !== 'pending' || !pvewhmcs_is_upid($existing->provisioning_upid ?? null)) {
				return pvewhmcs_existing_provisioning_create_result($existing);
			}
			if (!$proxmox->login()) {
				return "Error: provisioning for VMID {$existing->vmid} is pending, but PVE login failed; retry CreateAccount to resume the recorded task.";
			}
			try {
				pvewhmcs_wait_recorded_provisioning_task($proxmox, $serviceId, $existing->provisioning_upid);
				if (($existing->provisioning_mode ?? null) === 'clone') {
					pvewhmcs_resume_pending_clone($proxmox, $params, $existing);
				}
				pvewhmcs_provisioning_set_ready($serviceId);
				return 'success';
			} catch (PVE2_Exception $e) {
				return "Error: provisioning for VMID {$existing->vmid} remains pending because its recorded task could not be checked: {$e->getMessage()}";
			} catch (RuntimeException $e) {
				return pvewhmcs_guest_provisioning_error(Capsule::table('mod_pvewhmcs_vms')->where('id', $serviceId)->first());
			}
		}

		$plan = Capsule::table('mod_pvewhmcs_plans')->where('id', $params['configoption1'])->first();
		if ($plan === null) {
			return 'Error: the selected PVE plan no longer exists.';
		}
		if (!in_array($plan->vmtype, array('kvm', 'lxc'), true)) {
			return 'Error: the selected PVE plan has an unsupported guest type.';
		}
		$network = ($plan->vmtype === 'lxc' || $plan->netmode === 'bridge') ? pvewhmcs_plan_network_name($plan) : null;
		$customFieldError = pvewhmcs_create_custom_field_error($params, $plan);
		if ($customFieldError !== null) {
			return $customFieldError;
		}
		if (!$proxmox->login()) {
			return 'Proxmox Error: PVE API login failed. Please check your credentials.';
		}

		$isClone = !empty($params['customfields']['KVMTemplate']);
		$nodes = $proxmox->get_node_list();
		if (!is_array($nodes) || empty($nodes)) {
			return 'Proxmox Error: the cluster returned no nodes.';
		}
		if ($isClone) {
			$templateNode = !empty($params['customfields']['TPL_Node_QEMU'])
				? $params['customfields']['TPL_Node_QEMU']
				: pvewhmcs_find_node_by_vmid($proxmox, $params['customfields']['KVMTemplate']);
			if (!in_array($templateNode, $nodes, true)) {
				return 'Error: Custom field TPL_Node_QEMU does not name a node of this Proxmox cluster.';
			}
			$templateConfig = $proxmox->get('/nodes/' . $templateNode . '/qemu/' . $params['customfields']['KVMTemplate'] . '/config');
			if ((int) ($templateConfig['template'] ?? 0) !== 1) {
				return 'Error: Custom field KVMTemplate must identify a QEMU template (template=1), not a normal VM.';
			}
			$guestType = 'qemu';
			$mode = 'clone';
		} else {
			$templateNode = ($plan->vmtype === 'lxc' && !empty($params['customfields']['TPL_Node_LXC']))
				? $params['customfields']['TPL_Node_LXC'] : $nodes[0];
			if (!in_array($templateNode, $nodes, true)) {
				return 'Error: Custom field TPL_Node_LXC does not name a node of this Proxmox cluster.';
			}
			$guestType = $plan->vmtype === 'kvm' ? 'qemu' : 'lxc';
			$mode = 'create';
		}

		$vmid = pvewhmcs_find_next_available_vmid($proxmox, $templateNode, Capsule::table('mod_pvewhmcs')->where('id', '1')->value('start_vmid'));
		$marker = pvewhmcs_write_provisioning_marker($params['configoption2'], $serviceId, $serverId, $vmid, $guestType, $templateNode, $mode, $params['clientsdetails']['userid'] ?? $params['userid'] ?? 0, $plan->ipv6);
		if (isset($marker['existing'])) {
			return pvewhmcs_existing_provisioning_create_result($marker['existing']);
		}
		$ip = $marker['ip'];
		$settings = $isClone
			? array('newid' => $vmid, 'name' => pvewhmcs_guest_name($params, $vmid, $templateNode, $plan->title), 'full' => true, 'target' => $templateNode)
			: pvewhmcs_create_direct_settings($params, $plan, $ip, $network, $guestType);
		if (!$isClone) {
			$settings['vmid'] = $vmid;
			$settings[$guestType === 'lxc' ? 'hostname' : 'name'] = pvewhmcs_guest_name($params, $vmid, $templateNode, $plan->title);
		}
		$path = $isClone
			? '/nodes/' . $templateNode . '/qemu/' . $params['customfields']['KVMTemplate'] . '/clone'
			: '/nodes/' . $templateNode . '/' . $guestType;
		try {
			$response = $proxmox->post($path, $settings);
		} catch (\Throwable $e) {
			return "Error: provisioning for VMID {$vmid} has an uncertain allocation outcome after the Proxmox request; no retry will create another guest. {$e->getMessage()}";
		}
		if (pvewhmcs_debug_enabled()) {
			pvewhmcs_log_module_call(__FUNCTION__, $path . ' ' . json_encode(array_diff_key($settings, array('password' => true, 'cipassword' => true))), $response, $params);
		}
		if (!pvewhmcs_is_upid($response)) {
			return "Error: provisioning for VMID {$vmid} has an uncertain allocation outcome because Proxmox did not return a task ID; it remains reserved for investigation.";
		}
		pvewhmcs_provisioning_set_pending($serviceId, $response);
		try {
			pvewhmcs_wait_recorded_provisioning_task($proxmox, $serviceId, $response);
			if ($isClone) {
				pvewhmcs_clone_post_configuration($proxmox, $params, $plan, $ip, $network, $templateNode, $vmid);
			}
			pvewhmcs_provisioning_set_ready($serviceId);
			return 'success';
		} catch (PVE2_Exception $e) {
			return "Error: provisioning for VMID {$vmid} remains pending because its recorded task could not be checked: {$e->getMessage()}";
		} catch (RuntimeException $e) {
			return pvewhmcs_guest_provisioning_error(Capsule::table('mod_pvewhmcs_vms')->where('id', $serviceId)->first());
		}
	});
}

function pvewhmcs_CreateAccount_impl($params) {
	return pvewhmcs_CreateAccount_recoverable_impl($params);
}

/**
 * Find the next available VMID in the Proxmox cluster.
 *
 * This function first tries to use Proxmox's /cluster/nextid endpoint directly,
 * which is the most reliable method. If the returned VMID is below the configured
 * start_vmid, it will probe for an available VMID starting from start_vmid.
 * A VMID still referenced by a mod_pvewhmcs_vms row (e.g. a cancelled service
 * whose guest an admin deleted) is never handed out again, even when Proxmox
 * reports it free.
 *
 * @param PVE2_API $proxmox    Proxmox API client (logged in)
 * @param string   $node       Ignored (VMIDs are cluster-wide)
 * @param int      $start_vmid Starting VMID from Module config
 * @return int     The next available VMID
 * @throws Exception on unexpected API errors or if no free VMID found
 */
function pvewhmcs_find_next_available_vmid($proxmox, $node, $start_vmid) {
	$start_vmid = (int) $start_vmid;
	$probe_from = $start_vmid;

	// First, try to get the cluster's next available VMID directly
	try {
		$resp = $proxmox->get('/cluster/nextid');
		$data = (is_array($resp) && array_key_exists('data', $resp)) ? $resp['data'] : $resp;
		$cluster_next = (int) $data;

		// If cluster's next VMID is >= our start and not linked to a service, use it directly
		if ($cluster_next >= $start_vmid) {
			if (!pvewhmcs_vmid_is_linked($cluster_next)) {
				return $cluster_next;
			}
			$probe_from = $cluster_next + 1;
		}
	} catch (\Throwable $e) {
		// If /cluster/nextid fails entirely, fall through to the probe method
	}

	// If cluster's next VMID is below our start_vmid, is linked to a service,
	// or the call failed, probe upwards
	$max_attempts = 1000;
	$vmid = $probe_from;

	for ($i = 0; $i < $max_attempts; $i++, $vmid++) {
		if (pvewhmcs_vmid_is_linked($vmid)) {
			continue;
		}

		try {
			// Ask Proxmox if this specific VMID is available. The API client
			// accepts GET parameters in the action path, not as a second
			// argument to get().
			$resp = $proxmox->get('/cluster/nextid?vmid=' . $vmid);
			$data = (is_array($resp) && array_key_exists('data', $resp)) ? $resp['data'] : $resp;

			// Proxmox confirmed this VMID is available
			if ((int) $data === $vmid) {
				return $vmid;
			}

			// If API returns a different number, that's unexpected but try next
			continue;

		} catch (\Throwable $e) {
			$msg = strtolower($e->getMessage());

			// VMID is occupied - these are expected errors, try next VMID
			if (strpos($msg, 'already exists') !== false ||
				strpos($msg, 'parameter verification failed') !== false ||
				strpos($msg, 'vm ') !== false) {
				continue;
			}

			// Any other error is unexpected; surface it
			throw $e;
		}
	}

	throw new Exception("Unable to find a free VMID starting at {$probe_from} after {$max_attempts} attempts");
}

function pvewhmcs_vmid_is_linked($vmid) {
	return Capsule::table('mod_pvewhmcs_vms')->where('vmid', (int) $vmid)->first() !== null;
}

// PVE API FUNCTION, ADMIN: Test Connection with Proxmox node
function pvewhmcs_TestConnection(array $params) {
	pvewhmcs_ensure_schema();
	$success = false;
	$errorMsg = '';

	try {
		$serverip = pvewhmcs_connection_host($params['serverhostname'] ?? '', $params['serverip'] ?? '');
		$serverusername = $params["serverusername"];
		$serverpassword = $params["serverpassword"];
		$serverport = pvewhmcs_connection_port($params['serverport'] ?? '');
		$proxmox = new PVE2_API($serverip, $serverusername, "pam", $serverpassword, $serverport, pvewhmcs_verify_tls($params));

		if ($proxmox->login()) {
			$success = true;
		} else {
			$errorMsg = $proxmox->get_last_error();
		}
	} catch (Throwable $e) {
		pvewhmcs_log_module_call(
			__FUNCTION__,
			array(
				'host' => $params['serverhostname'] ?? ($params['serverip'] ?? ''),
				'port' => $params['serverport'] ?? '',
				'username' => $params['serverusername'] ?? '',
			),
			$e->getMessage(),
			$params,
			pvewhmcs_compact_trace($e)
		);
		$errorMsg = $e->getMessage();
	}

	return array(
		'success' => $success,
		'error' => $errorMsg,
	);
}

/**
 * PVE2_API for callbacks that receive the WHMCS server credentials in $params.
 */
function pvewhmcs_params_api(array $params) {
	$serverip = pvewhmcs_connection_host($params['serverhostname'] ?? '', $params['serverip'] ?? '');
	$serverport = pvewhmcs_connection_port($params['serverport'] ?? '');

	return new PVE2_API($serverip, $params['serverusername'] ?? '', 'pam', $params['serverpassword'] ?? '', $serverport, pvewhmcs_verify_tls($params));
}

// PVE API FUNCTION, ADMIN: Suspend a Service on the hypervisor.
// Refuses CANCELADO guests. Writes onboot=0 + SUSPENSO first (one config PUT,
// before any HA/power change can hold the guest's config lock); then an HA
// resource in `started` is set to `stopped` and recorded in ha_suspended (kept
// at 1 when a previous partial suspend already did it); finally the guest is
// stopped (task awaited).
function pvewhmcs_SuspendAccount_impl(array $params) {
	$guest = Capsule::table('mod_pvewhmcs_vms')->where('id', '=', $params['serviceid'])->first();
	if ($guest === null) {
		return "Error performing action. Unable to find guest linked to Service ID ({$params['serviceid']})";
	}
	if (($provisioningError = pvewhmcs_guest_provisioning_error($guest)) !== null) {
		return $provisioningError;
	}
	$conflict = pvewhmcs_vmid_conflict_error($guest, $params);
	if ($conflict !== null) {
		return $conflict;
	}

	$proxmox = pvewhmcs_params_api($params);
	if (!$proxmox->login()) {
		return "Error suspending account. Couldn't login to PVE.";
	}
	$guest_node = pvewhmcs_find_guest_node($proxmox, $guest);
	if (empty($guest_node)) {
		return "Error performing action. Unable to determine node for VMID {$guest->vmid}.";
	}

	$guestPath = pvewhmcs_guest_api_path($guest_node, $guest);
	$config = (array) $proxmox->get($guestPath . '/config');
	if (pvewhmcs_guest_has_tag($config['tags'] ?? '', 'CANCELADO')) {
		return 'Error: guest is marked CANCELADO (cancelled service); suspend refused.';
	}

	pvewhmcs_update_guest_lifecycle_config($proxmox, $guestPath, $config, 'SUSPENSO', 0);

	// Only transition HA for a guest that is already HA-managed. `ha_suspended`
	// records that the module itself stopped HA, so UnsuspendAccount knows to
	// restore it. A retry after a partial suspend finds HA already `stopped`
	// with ha_suspended=1 and must not forget that.
	$haState = pvewhmcs_guest_ha_state($proxmox, $guest);
	if ($haState === 'started') {
		pvewhmcs_set_guest_ha_state($proxmox, $guest, 'stopped');
		$haSuspended = 1;
	} elseif ($haState === 'stopped' && (int) ($guest->ha_suspended ?? 0) === 1) {
		$haSuspended = 1;
	} else {
		$haSuspended = 0;
	}
	Capsule::table('mod_pvewhmcs_vms')->where('id', $params['serviceid'])->update(['ha_suspended' => $haSuspended]);

	$status = $proxmox->get($guestPath . '/status/current');
	$stopTask = null;
	if (($status['status'] ?? null) !== 'stopped') {
		$stopTask = pvewhmcs_guest_power_and_wait($proxmox, $guestPath, 'stop');
	}

	if (pvewhmcs_debug_enabled()) {
		pvewhmcs_log_module_call(__FUNCTION__, $guestPath, array('ha_state' => $haState, 'ha_suspended' => $haSuspended, 'stop' => $stopTask), $params);
	}

	return "success";
}

// PVE API FUNCTION, ADMIN: Unsuspend a Service on the hypervisor.
// The HA resource is read BEFORE any config/tag change. Exactly three outcomes
// touch the guest: no HA resource (direct start path), `stopped` recorded by
// this module (ha_suspended=1, restore `started`), or — after those checks —
// no CANCELADO tag. Any other HA state (`started`, `disabled`, `ignored`, …),
// or `stopped` without the module's own suspension, returns an error without
// changing the guest.
function pvewhmcs_UnsuspendAccount_impl(array $params) {
	$guest = Capsule::table('mod_pvewhmcs_vms')->where('id', '=', $params['serviceid'])->first();
	if ($guest === null) {
		return "Error performing action. Unable to find guest linked to Service ID ({$params['serviceid']})";
	}
	if (($provisioningError = pvewhmcs_guest_provisioning_error($guest)) !== null) {
		return $provisioningError;
	}
	$conflict = pvewhmcs_vmid_conflict_error($guest, $params);
	if ($conflict !== null) {
		return $conflict;
	}

	$proxmox = pvewhmcs_params_api($params);
	if (!$proxmox->login()) {
		return "Error unsuspending account. Couldn't login to PVE.";
	}
	$guest_node = pvewhmcs_find_guest_node($proxmox, $guest);
	if (empty($guest_node)) {
		return "Error performing action. Unable to determine node for VMID {$guest->vmid}.";
	}

	$guestPath = pvewhmcs_guest_api_path($guest_node, $guest);
	$haState = pvewhmcs_guest_ha_state($proxmox, $guest);
	$suspendedByModule = (int) ($guest->ha_suspended ?? 0) === 1;
	if ($haState !== null && !($haState === 'stopped' && $suspendedByModule)) {
		$sid = pvewhmcs_guest_ha_sid($guest);
		return "Error unsuspending: HA resource {$sid} is in state '{$haState}' and this module did not record suspending it; guest not changed. Resolve the HA state and retry.";
	}
	$haRestore = $haState === 'stopped';
	// No HA resource (any more) is the only case needing a direct start; the
	// restored `started` resource is started by the HA manager.
	$directStart = $haState === null;

	$config = (array) $proxmox->get($guestPath . '/config');
	if (pvewhmcs_guest_has_tag($config['tags'] ?? '', 'CANCELADO')) {
		return 'Error: guest is marked CANCELADO (cancelled service); unsuspend refused.';
	}

	$planOnboot = !empty($params['configoption1'])
		&& !empty(Capsule::table('mod_pvewhmcs_plans')->where('id', $params['configoption1'])->value('onboot'));
	pvewhmcs_update_guest_lifecycle_config($proxmox, $guestPath, $config, null, $planOnboot ? 1 : null);

	if ($haRestore) {
		pvewhmcs_set_guest_ha_state($proxmox, $guest, 'started');
	}
	$startTask = null;
	if ($directStart) {
		$status = $proxmox->get($guestPath . '/status/current');
		if (($status['status'] ?? null) !== 'running') {
			$startTask = pvewhmcs_guest_power_and_wait($proxmox, $guestPath, 'start');
		}
	}
	Capsule::table('mod_pvewhmcs_vms')->where('id', $params['serviceid'])->update(['ha_suspended' => 0]);

	if (pvewhmcs_debug_enabled()) {
		pvewhmcs_log_module_call(__FUNCTION__, $guestPath, array('ha_state' => $haState, 'direct_start' => $directStart, 'start' => $startTask, 'onboot_restored' => $planOnboot), $params);
	}

	return "success";
}

// PVE API FUNCTION, ADMIN: Cancel a Service on the hypervisor.
// The guest is retained for recovery: onboot=0 + CANCELADO first (one config
// PUT), then the HA resource is set to `disabled` only if one already exists
// (never create one; the CRM then stops the guest, so no direct stop is sent
// for HA guests); other guests are stopped directly (task awaited).
// The service-to-VM mapping is retained, except when the VMID is gone from the
// cluster or now belongs to another service (stale row removed).
function pvewhmcs_TerminateAccount_impl(array $params) {
	$guest = Capsule::table('mod_pvewhmcs_vms')->where('id', '=', $params['serviceid'])->first();
	if ($guest === null) {
		return "Error performing action. Unable to find guest linked to Service ID ({$params['serviceid']})";
	}
	if (($provisioningError = pvewhmcs_guest_provisioning_error($guest)) !== null) {
		return $provisioningError;
	}
	$proxmox = pvewhmcs_params_api($params);
	if (!$proxmox->login()) {
		return "Error cancelling account. Couldn't login to PVE.";
	}

	$guest_node = pvewhmcs_find_guest_node($proxmox, $guest);
	if (empty($guest_node)) {
		Capsule::table('mod_pvewhmcs_vms')->where('id', '=', $params['serviceid'])->delete();
		return "success";
	}

	$vmid_owner = pvewhmcs_vmid_conflict($guest, $params);
	if ($vmid_owner !== null) {
		Capsule::table('mod_pvewhmcs_vms')->where('id', '=', $params['serviceid'])->delete();
		return "Error: VMID {$guest->vmid} is now assigned to Service #{$vmid_owner->id}. Stale record for Service #{$params['serviceid']} cleaned up. VM was NOT changed.";
	}

	$guestPath = pvewhmcs_guest_api_path($guest_node, $guest);
	$config = (array) $proxmox->get($guestPath . '/config');
	pvewhmcs_update_guest_lifecycle_config($proxmox, $guestPath, $config, 'CANCELADO', 0);

	// A direct stop of an HA guest becomes an HA stop request that rewrites the
	// resource state to `stopped`, so HA guests are only set to `disabled`.
	$haState = pvewhmcs_guest_ha_state($proxmox, $guest);
	$stopTask = null;
	if ($haState !== null) {
		pvewhmcs_set_guest_ha_state($proxmox, $guest, 'disabled');
	} else {
		$status = $proxmox->get($guestPath . '/status/current');
		if (($status['status'] ?? null) !== 'stopped') {
			$stopTask = pvewhmcs_guest_power_and_wait($proxmox, $guestPath, 'stop');
		}
	}
	Capsule::table('mod_pvewhmcs_vms')->where('id', $params['serviceid'])->update(['ha_suspended' => 0]);

	if (pvewhmcs_debug_enabled()) {
		pvewhmcs_log_module_call(__FUNCTION__, $guestPath, array('ha_state' => $haState, 'stop' => $stopTask), $params);
	}

	return "success";
}

// MODULE BUTTONS: Admin Interface button regos
function pvewhmcs_AdminCustomButtonArray() {
	$buttonarray = array(
		"Start" => "vmStart",
		"Reboot" => "vmReboot",
		"Soft Stop" => "vmShutdown",
		"Hard Stop" => "vmStop",
	);
	return $buttonarray;
}

/**
 * Resolves the client-facing language code the same way WHMCS itself
 * names its own /lang/*.php files (e.g. "english", "portuguese-br").
 *
 * $params never carries a language code directly (WHMCS's own docs
 * confirm clientsdetails has no 'language' key), so this reads
 * tblclients.language — the same column WHMCS's GetClientsDetails API
 * exposes — keyed by the always-present $params['userid']. That works
 * regardless of session/admin-preview/cron context, unlike
 * $_SESSION['Language'], which only reflects the current HTTP session
 * and is kept here purely as a last-resort fallback.
 */
function pvewhmcs_client_lang_code(array $params = array()) {
	static $dbLangCache = array();

	$code = null;
	$clientId = (int) ($params['userid'] ?? 0);
	if ($clientId > 0) {
		if (!array_key_exists($clientId, $dbLangCache)) {
			try {
				$dbLangCache[$clientId] = Capsule::table('tblclients')->where('id', $clientId)->value('language');
			} catch (\Throwable $e) {
				$dbLangCache[$clientId] = null;
			}
		}
		$code = $dbLangCache[$clientId] ?: null;
	}

	$code = $code
		?? ($_SESSION['Language'] ?? null)
		?? 'english';
	$code = strtolower(preg_replace('/[^a-z0-9-]/i', '', (string) $code));

	return $code !== '' ? $code : 'english';
}

/**
 * Loads modules/servers/pvewhmcs/lang/<code>.php into $_PVEWHMCS_LANG and
 * returns it, falling back to english.php for languages this module
 * hasn't been translated into yet. Used both server-side (button labels,
 * noVNC launcher text) and passed into clientarea.tpl as {$lang.key}.
 */
function pvewhmcs_load_client_lang(array $params = array()) {
	static $cache = array();
	$code = pvewhmcs_client_lang_code($params);
	if (isset($cache[$code])) {
		return $cache[$code];
	}

	$dir = __DIR__ . '/lang/';
	$file = is_file($dir . $code . '.php') ? $dir . $code . '.php' : $dir . 'english.php';

	$_PVEWHMCS_LANG = array();
	if (is_file($file)) {
		include $file;
	}

	$cache[$code] = $_PVEWHMCS_LANG;

	return $_PVEWHMCS_LANG;
}

// MODULE BUTTONS: Client Interface button regos
function pvewhmcs_ClientAreaCustomButtonArray() {
	$lang = pvewhmcs_load_client_lang();
	$buttonarray = array(
		"<i class='fa fa-2x fa-flag-checkered'></i> " . ($lang['btn_start'] ?? 'Start') => "vmStart",
		"<i class='fa fa-2x fa-sync'></i> " . ($lang['btn_reboot'] ?? 'Reboot') => "vmReboot",
		"<i class='fa fa-2x fa-power-off'></i> " . ($lang['btn_poweroff'] ?? 'Power Off') => "vmShutdown",
		"<i class='fa fa-2x fa-stop'></i>  " . ($lang['btn_hardstop'] ?? 'Hard Stop') => "vmStop",
		"<i class='fa fa-2x fa-chart-bar'></i>  " . ($lang['btn_statistics'] ?? 'Statistics') => "vmStat",
		"<i class='fa fa-2x fa-search'></i>  " . ($lang['btn_checkstatus'] ?? 'Check Status') => "vmCheck",
		"<img src='./modules/servers/pvewhmcs/img/novnc.png'/> " . ($lang['btn_console'] ?? 'Console (HTML5)') => "noVNC",
	);
	return $buttonarray;
}

/**
 * Fetch RRD statistics from Proxmox with graceful error handling.
 *
 * Proxmox RRD schema changed in PVE 9 from pve2-{type} to pve-{type}-9.0.
 * The ds parameter names (cpu, mem, netin, netout, diskread, diskwrite) remain valid
 * across both old and new schemas - verified in pve-cluster/src/pmxcfs/status.c.
 *
 * RRD data may be unavailable when:
 *   - VM/CT was just created (RRD takes ~60s to populate)
 *   - RRD schema migration is incomplete on the PVE host
 *   - RRD files are corrupted or missing
 *
 * Refs:
 *   - Issue #162: https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/issues/162
 *   - PVE RRD schema: https://github.com/proxmox/pve-cluster/blob/master/src/pmxcfs/status.c
 *   - Schema change: https://www.mail-archive.com/pve-devel@lists.proxmox.com/msg28317.html
 *
 * @param PVE2_API $proxmox    The Proxmox API client instance
 * @param string   $node       The Proxmox node name
 * @param string   $vtype      Guest type: 'qemu' or 'lxc'
 * @param int      $vmid       The VM/CT ID
 * @param string   $timeframe  RRD timeframe: 'day', 'week', 'month', 'year'
 * @param string   $ds         Data source(s): 'cpu', 'mem', 'netin,netout', 'diskread,diskwrite'
 * @return string|null         Base64-encoded PNG image, or null if unavailable
 */
function pvewhmcs_fetch_rrd_stat($proxmox, $node, $vtype, $vmid, $timeframe, $ds) {
	// Build the API path and query params for RRD image
	$rrd_path = '/nodes/' . $node . '/' . $vtype . '/' . $vmid . '/rrd';
	$rrd_params = '?timeframe=' . $timeframe . '&ds=' . $ds . '&cf=AVERAGE';
	
	try {
		// Attempt to fetch RRD graph image from PVE API
		$vm_rrd = $proxmox->get($rrd_path . $rrd_params);
		
		// Check if we got a valid response with image data
		if (isset($vm_rrd['image']) && !empty($vm_rrd['image'])) {
			// Decode and re-encode the image data for template use
			return base64_encode(pvewhmcs_rrd_image_bytes($vm_rrd['image']));
		}
	} catch (Exception $e) {
		// RRD data unavailable - this is normal for new VMs or during migration.
		// Log if debug mode is on, but don't crash the Client Area.
		if (pvewhmcs_debug_enabled()) {
			pvewhmcs_log_module_call(
				'pvewhmcs_fetch_rrd_stat',
				'RRD fetch failed for ' . $vtype . '/' . $vmid . ' (' . $ds . ', ' . $timeframe . ')',
				$e->getMessage(),
				array()
			);
		}
	}
	
	// Return null when RRD data is unavailable
	return null;
}

/**
 * PVE2_API for the client-area and power paths, which read the service's WHMCS
 * server row (tblhosting.server) instead of $params credentials.
 * Returns array($proxmox, $decryptedServerPassword).
 */
function pvewhmcs_service_server_api(array $params) {
	$pveservice = Capsule::table('tblhosting')->find($params['serviceid']);
	$pveserver = $pveservice ? Capsule::table('tblservers')->where('id', '=', $pveservice->server)->first() : null;
	if ($pveserver === null) {
		throw new Exception("PVEWHMCS Error: No Proxmox server is assigned to Service #{$params['serviceid']}.");
	}

	$serverip = pvewhmcs_connection_host($pveserver->hostname ?? '', $pveserver->ipaddress ?? '');
	// Password access is different in Client Area, so retrieve and decrypt. The
	// stored value is HTML-entity encoded like every WHMCS admin input (same
	// decoding as the addon's Nodes/Guests/Logs tabs).
	$decrypted = localAPI('DecryptPassword', array('password2' => $pveserver->password));
	$serverpassword = html_entity_decode((string) ($decrypted['password'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$serverport = pvewhmcs_connection_port($pveserver->port ?? '');

	return array(
		new PVE2_API($serverip, $pveserver->username, "pam", $serverpassword, $serverport, pvewhmcs_verify_tls_setting($pveserver->secure ?? null)),
		$serverpassword,
	);
}

function pvewhmcs_client_area_error($message, array $params) {
	return array(
		'templatefile' => 'clientarea',
		'vars' => array(
			'params' => $params,
			'pvewhmcs_error' => $message,
			'lang' => pvewhmcs_load_client_lang($params),
		),
	);
}

// OUTPUT: Module output to the Client Area
function pvewhmcs_ClientArea($params) {
	pvewhmcs_ensure_schema();
	$lang = pvewhmcs_load_client_lang($params);
	$unavailable = $lang['hypervisor_unavailable'] ?? 'Error: Unable to gather data from Hypervisor. Please contact Tech Support!';

	// Retrieve virtual machine info from table mod_pvewhmcs_vms
	$guest = Capsule::table('mod_pvewhmcs_vms')->where('id', '=', $params['serviceid'])->first();
	if ($guest === null) {
		return pvewhmcs_client_area_error($unavailable, $params);
	}
	if (($provisioningError = pvewhmcs_guest_provisioning_error($guest)) !== null) {
		return pvewhmcs_client_area_error($provisioningError, $params);
	}
	$conflict = pvewhmcs_vmid_conflict_error($guest, $params);
	if ($conflict !== null) {
		return pvewhmcs_client_area_error($conflict, $params);
	}

	list($proxmox, $serverpassword) = pvewhmcs_service_server_api($params);
	if (!$proxmox->login()) {
		return pvewhmcs_client_area_error($unavailable, $params);
	}

	// One /cluster/resources read gives both the node and the live status.
	$cluster_resources = $proxmox->get('/cluster/resources');
	$vm_status = pvewhmcs_find_guest_resource($proxmox, $guest, $cluster_resources);
	if ($vm_status === null) {
		// e.g. a cancelled service whose retained guest an admin has since deleted.
		return pvewhmcs_client_area_error($unavailable, $params);
	}
	$guest_node = $vm_status['node'];

	# Get and set VM variables
	$vm_config = $proxmox->get(pvewhmcs_guest_api_path($guest_node, $guest) . '/config');
	// DEBUG - Log the /cluster/resources and /config for the VM/CT, if enabled
	if (pvewhmcs_debug_enabled()) {
		pvewhmcs_log_module_call(
			__FUNCTION__,
			'CLUSTER INFO: ' . json_encode($cluster_resources),
			'GUEST CONFIG (Service #' . $params['serviceid'] . ' / PVE ID #' . $guest->vmid . ' / Client #' . ($params['clientsdetails']['userid'] ?? '') . '): ' . json_encode($vm_config),
			$params,
			'',
			array($serverpassword)
		);
	}

	# Retrieve & set usage data appropriately
	$vm_status['uptime'] = time2format($vm_status['uptime']);
	$vm_status['cpu'] = round($vm_status['cpu'] * 100, 2);

	$vm_status['diskusepercent'] = !empty($vm_status['maxdisk']) ? intval(($vm_status['disk'] ?? 0) * 100 / $vm_status['maxdisk']) : 0;
	$vm_status['memusepercent'] = !empty($vm_status['maxmem']) ? intval(($vm_status['mem'] ?? 0) * 100 / $vm_status['maxmem']) : 0;

	if ($guest->vtype == 'lxc') {
		// Check on swap before setting graph value
		$ct_specific = $proxmox->get('/nodes/' . $guest_node . '/lxc/' . $guest->vmid . '/status/current');
		$vm_status['swapusepercent'] = !empty($ct_specific['maxswap']) ? intval(($ct_specific['swap'] ?? 0) * 100 / $ct_specific['maxswap']) : 0;
	} else {
		// Fall back to 0% usage to satisfy chart requirement
		$vm_status['swapusepercent'] = 0;
	}

	// ----------------------------------------------------------------
	// RRD statistics graphs (16 sequential requests) are rendered only by
	// the Statistics view (custom function vmStat), so fetch them only there.
	// pvewhmcs_fetch_rrd_stat() tolerates missing RRD data.
	// ----------------------------------------------------------------
	$vm_statistics = array();
	$show_statistics = (($_REQUEST['a'] ?? '') === 'vmStat');
	if ($show_statistics) {
		$rrd_sources = array('cpu' => 'cpu', 'mem' => 'mem', 'netinout' => 'netin,netout', 'diskrw' => 'diskread,diskwrite');
		foreach ($rrd_sources as $key => $ds) {
			foreach (array('year', 'month', 'week', 'day') as $timeframe) {
				$vm_statistics[$key][$timeframe] = pvewhmcs_fetch_rrd_stat($proxmox, $guest_node, $guest->vtype, $guest->vmid, $timeframe, $ds);
			}
		}
	}

	$vm_config['vtype'] = $guest->vtype ;
	$vm_config['ipv4'] = $guest->ipaddress ;
	$vm_config['netmask4'] = $guest->subnetmask ;
	$vm_config['gateway4'] = $guest->gateway ;
	$vm_config['created'] = $guest->created ;
	$vm_config['v6prefix'] = $guest->v6prefix ;

	return array(
		'templatefile' => 'clientarea',
		'vars' => array(
			'params' => $params,
			'vm_config' => $vm_config,
			'vm_status' => $vm_status,
			'vm_statistics' => $vm_statistics,
			'vm_show_statistics' => $show_statistics,
			'lang' => $lang,
		),
	);
}

// OUTPUT: VM Statistics/Graphs render to Client Area
function pvewhmcs_vmStat($params) {
	return true;
}

// VNC: Console access to VM/CT via noVNC
function pvewhmcs_prepare_noVNC($params) {
	pvewhmcs_ensure_schema();
	global $CONFIG;

	if (strlen(Capsule::table('mod_pvewhmcs')->where('id', '1')->value('vnc_secret')) < 15) {
		throw new Exception("PVEWHMCS Error: VNC Secret in Module Config either not set or not long enough. Recommend 20+ characters for security.");
	}

	$guest = Capsule::table('mod_pvewhmcs_vms')->where('id', '=', $params['serviceid'])->first();
	if ($guest === null) {
		throw new Exception("Error performing action. Unable to find guest linked to Service ID ({$params['serviceid']})");
	}
	if (($provisioningError = pvewhmcs_guest_provisioning_error($guest)) !== null) {
		throw new Exception($provisioningError);
	}
	$conflict = pvewhmcs_vmid_conflict_error($guest, $params);
	if ($conflict !== null) {
		throw new Exception($conflict);
	}

	$serverip = pvewhmcs_connection_host($params['serverhostname'] ?? '', $params['serverip'] ?? '');
	$serverport = pvewhmcs_connection_port($params['serverport'] ?? '');
	$proxmox_server = new PVE2_API($serverip, $params["serverusername"], "pam", $params["serverpassword"], $serverport, pvewhmcs_verify_tls($params));
	if (!$proxmox_server->login()) {
		throw new Exception('Failed to prepare noVNC. Unable to connect to server.');
	}

	$guest_node = pvewhmcs_find_guest_node($proxmox_server, $guest);
	if (empty($guest_node)) {
		throw new Exception('Failed to prepare noVNC. Unable to determine node.');
	}

	$vncpassword = Capsule::table('mod_pvewhmcs')->where('id', '1')->value('vnc_secret');
	$proxmox = new PVE2_API($serverip, 'vnc', 'pve', $vncpassword, $serverport, pvewhmcs_verify_tls($params));
	if (!$proxmox->login()) {
		throw new Exception('Failed to prepare noVNC. Please contact Technical Support.');
	}

	// `websocket=1` is the only extra parameter valid for both QEMU and LXC; with
	// it Proxmox generates and returns the one-time VNC password.
	$vm_vncproxy = $proxmox->post(
		'/nodes/' . $guest_node . '/' . $guest->vtype . '/' . $guest->vmid . '/vncproxy',
		array('websocket' => 1)
	);
	if (empty($vm_vncproxy['ticket']) || empty($vm_vncproxy['port']) || empty($vm_vncproxy['password'])) {
		throw new Exception('Failed to prepare noVNC. Proxmox did not return a complete VNC proxy session.');
	}

	$pveticket = $proxmox->getTicket();
	$vncticket = $vm_vncproxy['ticket'];
	$vncstream_password = $vm_vncproxy['password'];
	$path = 'api2/json/nodes/' . $guest_node . '/' . $guest->vtype . '/' . $guest->vmid . '/vncwebsocket?port=' . $vm_vncproxy['port'] . '&vncticket=' . urlencode($vncticket);
	$token = pvewhmcs_build_console_token(array(
		'host' => $serverip,
		'port' => (int) $serverport,
		'path' => $path,
		'cookie' => $pveticket,
		'verify' => pvewhmcs_verify_tls($params),
	), 120);

	$whmcs_base = rtrim($CONFIG['SystemURL'], '/');
	list($relay_host, $relay_port) = pvewhmcs_relay_public_endpoint($CONFIG['SystemURL']);
	$relay_path = PVEWHMCS_CONSOLE_RELAY_PATH . '/' . $token;
	pvewhmcs_console_relay_preconnect($relay_host, $relay_port, $relay_path);

	$url = $whmcs_base . '/modules/servers/pvewhmcs/novnc/vnc.html'
		. '?host=' . urlencode($relay_host)
		. '&port=' . urlencode((string) $relay_port)
		. '&path=' . urlencode($relay_path)
		. '&password=' . urlencode($vncstream_password)
		. '&encrypt=true&autoconnect=true';

	return array(
		'url' => $url,
	);
}

// VNC: Console access to VM/CT via noVNC
function pvewhmcs_noVNC($params) {
	pvewhmcs_ensure_schema();
	$lang = pvewhmcs_load_client_lang($params);

	if (pvewhmcs_client_service_inactive($params)) {
		return $lang['service_not_active'] ?? 'Service is not active.';
	}

	try {
		$prepared = pvewhmcs_prepare_noVNC($params);
	} catch (\Throwable $e) {
		// Details (hosts, Proxmox replies, config hints) go to the module log only.
		pvewhmcs_log_module_call(
			__FUNCTION__,
			array('serviceid' => $params['serviceid'] ?? null),
			$e->getMessage(),
			$params,
			pvewhmcs_compact_trace($e)
		);
		if (!empty($_SESSION['adminid'])) {
			return ($lang['novnc_prepare_failed'] ?? 'Failed to prepare noVNC.') . ' ' . $e->getMessage();
		}

		return $lang['novnc_unavailable'] ?? 'The console is unavailable right now. Please try again in a few minutes or contact support.';
	}

	$escaped_url = htmlspecialchars($prepared['url'], ENT_QUOTES, 'UTF-8');
	$script_url = json_encode($prepared['url'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

	// vncproxy + relay preconnect already ran synchronously above; this
	// just redirects the current page to the real noVNC URL.
	return '<style>
		@keyframes pvewhmcs-novnc-spin { to { transform: rotate(360deg); } }
		.pvewhmcs-novnc-loading { text-align: center; padding: 32px; }
		.pvewhmcs-novnc-spinner { display: inline-block; width: 28px; height: 28px; border: 4px solid #ddd; border-top-color: #337ab7; border-radius: 50%; animation: pvewhmcs-novnc-spin .8s linear infinite; vertical-align: middle; margin-right: 10px; }
		.pvewhmcs-novnc-loading a { color: #337ab7; font-weight: bold; }
	</style>
	<div class="pvewhmcs-novnc-loading">
		<span class="pvewhmcs-novnc-spinner" aria-hidden="true"></span>
		<strong>' . htmlspecialchars($lang['novnc_opening'] ?? 'Opening the noVNC console...', ENT_QUOTES, 'UTF-8') . '</strong>
		<br><small>' . htmlspecialchars($lang['novnc_manual_prefix'] ?? "If it doesn't open automatically,", ENT_QUOTES, 'UTF-8') . ' <a href="' . $escaped_url . '">' . htmlspecialchars($lang['novnc_manual_link'] ?? 'click here to open the console', ENT_QUOTES, 'UTF-8') . '</a>.</small>
	</div>
	<script>
		window.location.replace(' . $script_url . ');
	</script>';
}

/**
 * Shared prelude of the client/admin power buttons: client status gate (F10),
 * guest row, duplicate-VMID guard, login with the service's WHMCS server
 * credentials and node lookup. Returns array($proxmox, $guestPath, $serverPassword)
 * or an error string for WHMCS.
 */
function pvewhmcs_power_target(array $params) {
	if (pvewhmcs_client_service_inactive($params)) {
		$lang = pvewhmcs_load_client_lang($params);

		return $lang['service_not_active'] ?? 'Service is not active.';
	}

	$guest = Capsule::table('mod_pvewhmcs_vms')->where('id', '=', $params['serviceid'])->first();
	if ($guest === null) {
		return "Error performing action. Unable to find guest linked to Service ID ({$params['serviceid']})";
	}
	if (($provisioningError = pvewhmcs_guest_provisioning_error($guest)) !== null) {
		return $provisioningError;
	}
	$conflict = pvewhmcs_vmid_conflict_error($guest, $params);
	if ($conflict !== null) {
		return $conflict;
	}

	list($proxmox, $serverpassword) = pvewhmcs_service_server_api($params);
	if (!$proxmox->login()) {
		return "Error performing action. Couldn't login to PVE.";
	}
	$guest_node = pvewhmcs_find_guest_node($proxmox, $guest);
	if (empty($guest_node)) {
		return "Error performing action. Unable to determine node for VMID {$guest->vmid}.";
	}

	return array($proxmox, pvewhmcs_guest_api_path($guest_node, $guest), $serverpassword);
}

function pvewhmcs_power_debug_log($action, $logrequest, $response, array $params, $serverpassword) {
	if (pvewhmcs_debug_enabled()) {
		pvewhmcs_log_module_call($action, $logrequest, json_encode($response), $params, '', array($serverpassword));
	}
}

/**
 * POSTs a client/admin power action and waits for its task. Only the exact
 * string "success" satisfies WHMCS; a missing UPID or a failed/timed-out task
 * is reported as an error (a thrown PVE2_Exception propagates to the tracked
 * action wrapper, which records and re-throws it).
 */
function pvewhmcs_guest_power_action(PVE2_API $proxmox, $guestPath, $command, $action, array $params, $serverpassword) {
	$logrequest = $guestPath . '/status/' . $command;
	$response = $proxmox->post($logrequest, array());
	pvewhmcs_power_debug_log($action, $logrequest, $response, $params, $serverpassword);
	if (!pvewhmcs_is_upid($response)) {
		return "Error: Proxmox did not return a task ID for the {$command} of Service #{$params['serviceid']}; success cannot be confirmed.";
	}
	pvewhmcs_wait_task($proxmox, (string) $response, 60);

	return "success";
}

// PVE API FUNCTION, CLIENT/ADMIN: Start the VM/CT
function pvewhmcs_vmStart_impl($params) {
	$target = pvewhmcs_power_target($params);
	if (is_string($target)) {
		return $target;
	}
	list($proxmox, $guestPath, $serverpassword) = $target;

	return pvewhmcs_guest_power_action($proxmox, $guestPath, 'start', __FUNCTION__, $params, $serverpassword);
}

// PVE API FUNCTION, CLIENT/ADMIN: Reboot the VM/CT (starts it when stopped)
function pvewhmcs_vmReboot_impl($params) {
	$target = pvewhmcs_power_target($params);
	if (is_string($target)) {
		return $target;
	}
	list($proxmox, $guestPath, $serverpassword) = $target;

	$guest_specific = $proxmox->get($guestPath . '/status/current');
	$command = (($guest_specific['status'] ?? null) == 'stopped') ? 'start' : 'reboot';

	return pvewhmcs_guest_power_action($proxmox, $guestPath, $command, __FUNCTION__, $params, $serverpassword);
}

// PVE API FUNCTION, CLIENT/ADMIN: Shutdown the VM/CT
function pvewhmcs_vmShutdown_impl($params) {
	$target = pvewhmcs_power_target($params);
	if (is_string($target)) {
		return $target;
	}
	list($proxmox, $guestPath, $serverpassword) = $target;

	return pvewhmcs_guest_power_action($proxmox, $guestPath, 'shutdown', __FUNCTION__, $params, $serverpassword);
}

// PVE API FUNCTION, CLIENT/ADMIN: Stop the VM/CT
function pvewhmcs_vmStop_impl($params) {
	$target = pvewhmcs_power_target($params);
	if (is_string($target)) {
		return $target;
	}
	list($proxmox, $guestPath, $serverpassword) = $target;

	return pvewhmcs_guest_power_action($proxmox, $guestPath, 'stop', __FUNCTION__, $params, $serverpassword);
}

/**
 * Find which node a specific VMID resides on using cluster resources.
 *
 * @param PVE2_API $proxmox
 * @param int $vmid
 * @return string Node name
 * @throws Exception if VMID not found in cluster
 */
function pvewhmcs_find_node_by_vmid($proxmox, $vmid) {
	// targeted search for vm
	$resources = $proxmox->get('/cluster/resources?type=vm');
	foreach ($resources as $res) {
		if (isset($res['vmid']) && (int)$res['vmid'] == (int)$vmid) {
			return $res['node'];
		}
	}
	throw new Exception("PVEWHMCS Auto-Discovery: Template/VM ID {$vmid} not found in the cluster.");
}

/**
 * The /cluster/resources entry of a VM/CT, matched by VMID and guest type only.
 * Pass $cluster_resources to reuse a result already fetched.
 *
 * @param PVE2_API   $proxmox
 * @param object     $guest   Row from mod_pvewhmcs_vms (expects ->vmid, ->vtype)
 * @param array|null $cluster_resources
 * @return array|null
 */
function pvewhmcs_find_guest_resource(PVE2_API $proxmox, $guest, $cluster_resources = null) {
	if ($cluster_resources === null) {
		$cluster_resources = $proxmox->get('/cluster/resources');
	}
	if (!is_array($cluster_resources)) {
		return null;
	}

	foreach ($cluster_resources as $res) {
		if (!isset($res['type'], $res['vmid'], $res['node'])) {
			continue;
		}
		if ($res['vmid'] == $guest->vmid && $res['type'] === $guest->vtype) {
			return $res;
		}
	}

	return null;
}

/**
 * Locate the Proxmox node that hosts a given VM/CT.
 *
 * @return string|null
 */
function pvewhmcs_find_guest_node(PVE2_API $proxmox, $guest) {
	$resource = pvewhmcs_find_guest_resource($proxmox, $guest);

	return $resource['node'] ?? null;
}

// CLIENT AREA: REFRESH TO CHECK STATUS ON-CLICK
function pvewhmcs_vmCheck($params) {
	return "success";
}

// NETWORKING FUNCTION: Convert subnet mask to CIDR
function mask2cidr($mask){
	$long = ip2long($mask);
	$base = ip2long('255.255.255.255');
	return 32-log(($long ^ $base)+1,2);
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
