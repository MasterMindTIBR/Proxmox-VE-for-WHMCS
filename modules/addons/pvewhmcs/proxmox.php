<?php

/*

Proxmox VE APIv2 (PVE2) Client - PHP Class
https://github.com/CpuID/pve2-api-php-client/

Copyright (c) Nathan Sullivan

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
the Software, and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

*/

use Illuminate\Database\Capsule\Manager as Capsule;

if (!defined('WHMCS')) {
	die('This file cannot be accessed directly');
}

class PVE2_Exception extends RuntimeException {}

// Returns the Proxmox host as stored in WHMCS, without URL brackets: a
// bracketed IPv6 literal ("[2001:db8::1]") becomes "2001:db8::1" so it passes
// IP validation. Use pvewhmcs_url_host() to bracket it again inside a URL.
function pvewhmcs_connection_host($hostname, $ipaddress) {
	$hostname = trim(trim((string) $hostname), '[]');
	if ($hostname !== '') {
		return $hostname;
	}

	return trim(trim((string) $ipaddress), '[]');
}

// Host part for composing a URL: IPv6 literals need brackets.
function pvewhmcs_url_host($host): string {
	$host = (string) $host;
	if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
		return '[' . $host . ']';
	}

	return $host;
}

function pvewhmcs_connection_port($port) {
	$port = trim((string) $port);

	return $port === '' ? 8006 : $port;
}

// Proxmox returns RRD PNGs as JSON strings whose characters are the image's
// bytes (U+0000..U+00FF); map them back to raw bytes (utf8_decode() is
// deprecated since PHP 8.2 and only used when mbstring is missing).
function pvewhmcs_rrd_image_bytes($image) {
	$image = (string) $image;

	return function_exists('mb_convert_encoding')
		? mb_convert_encoding($image, 'ISO-8859-1', 'UTF-8')
		: utf8_decode($image);
}

const PVEWHMCS_SCHEMA_VERSION = '1.3.7';

/**
 * Returns one column from a module table, or null when it is absent.
 * Table and column names are internal constants below, never user input.
 */
function pvewhmcs_schema_column($table, $column) {
	$columns = Capsule::select(
		'SELECT COLUMN_TYPE AS `Type`, IS_NULLABLE AS `Null`, COLUMN_DEFAULT AS `Default`'
		. ' FROM information_schema.COLUMNS'
		. ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
		. ' LIMIT 1',
		array($table, $column)
	);

	return $columns[0] ?? null;
}

function pvewhmcs_schema_index_exists($table, $index) {
	foreach (Capsule::select("SHOW INDEX FROM `{$table}`") as $row) {
		if (($row->Key_name ?? null) === $index) {
			return true;
		}
	}

	return false;
}

function pvewhmcs_schema_add_missing_column($table, $column, $definition) {
	if (pvewhmcs_schema_column($table, $column) === null) {
		Capsule::statement("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
	}
}

function pvewhmcs_schema_assert_max_length($table, $column, $maxLength) {
	if (pvewhmcs_schema_column($table, $column) === null) {
		return;
	}
	$rows = Capsule::select("SELECT COUNT(*) AS invalid_count FROM `{$table}` WHERE CHAR_LENGTH(`{$column}`) > ?", array($maxLength));
	$row = $rows[0] ?? null;
	if ((int) ($row->invalid_count ?? 0) > 0) {
		throw new RuntimeException("PVEWHMCS schema repair cannot safely shorten {$table}.{$column}; existing data exceeds {$maxLength} characters.");
	}
}

function pvewhmcs_schema_default_matches($currentDefault, $expectedDefault) {
	if ($currentDefault === $expectedDefault) {
		return true;
	}
	if ($expectedDefault === null) {
		return $currentDefault === null || strtoupper((string) $currentDefault) === 'NULL';
	}
	if ($currentDefault === null) {
		return false;
	}

	// MariaDB reports string defaults from information_schema as SQL literals
	// (for example, "'ready'" and "''"), whereas MySQL can return plain text.
	return (string) $currentDefault === "'" . str_replace("'", "''", (string) $expectedDefault) . "'";
}


function pvewhmcs_schema_normalize_column($table, $column, $definition, $type, $nullable, $default, $backfill = null) {
	$current = pvewhmcs_schema_column($table, $column);
	if ($current === null) {
		Capsule::statement("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
		return;
	}

	if ($backfill !== null) {
		Capsule::statement("UPDATE `{$table}` SET `{$column}` = {$backfill} WHERE `{$column}` IS NULL");
	}

	$currentDefault = $current->Default;
	$matches = strtolower((string) $current->Type) === strtolower($type)
		&& (($current->Null ?? null) === ($nullable ? 'YES' : 'NO'))
		&& pvewhmcs_schema_default_matches($currentDefault, $default);
	if (!$matches) {
		Capsule::statement("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$definition}");
	}
}

/**
 * Brings every module table required by the current fresh-install contract to
 * the 1.3.7 shape. This is deliberately lock-protected and has no failure
 * cache: callers may proceed only after a complete successful repair.
 */
function pvewhmcs_ensure_schema() {
	static $ensured = false;
	if ($ensured) {
		return true;
	}

	$lockName = 'pvewhmcs:schema';
	$acquired = false;
	try {
		$result = Capsule::select('SELECT GET_LOCK(?, 30) AS acquired', array($lockName));
		if (empty($result) || (int) ($result[0]->acquired ?? 0) !== 1) {
			throw new RuntimeException('PVEWHMCS schema repair could not acquire its database lock.');
		}
		$acquired = true;

		// Inspect only after the advisory lock is held. Create each missing table
		// from the fresh-install source; do not issue no-op CREATE DDL on later calls.
		$schema = Capsule::schema();
		$sql = @file_get_contents(__DIR__ . '/db.sql');
		if ($sql === false) {
			throw new RuntimeException('PVEWHMCS schema repair cannot read db.sql.');
		}
		foreach (explode(';', $sql) as $statement) {
			if (!preg_match('/^\s*CREATE TABLE IF NOT EXISTS `([^`]+)`/i', $statement, $matches)) {
				continue;
			}
			if (!$schema->hasTable($matches[1])) {
				Capsule::statement($statement);
			}
		}

		foreach (array(
			'config' => 'varchar(255) DEFAULT NULL',
			'vnc_secret' => 'varchar(255) DEFAULT NULL',
			'start_vmid' => 'int(10) unsigned DEFAULT 100',
			'debug_mode' => 'tinyint(1) unsigned DEFAULT 0',
			'console_relay_secret' => 'varchar(255) DEFAULT NULL',
			'console_relay_host' => 'varchar(255) DEFAULT NULL',
			'console_relay_port' => 'int(5) unsigned DEFAULT NULL',
			'name_pattern' => 'varchar(255) DEFAULT NULL',
		) as $column => $definition) {
			pvewhmcs_schema_add_missing_column('mod_pvewhmcs', $column, $definition);
		}
		Capsule::statement("INSERT IGNORE INTO `mod_pvewhmcs` (`id`, `config`, `vnc_secret`, `debug_mode`) VALUES (1, NULL, NULL, 0)");
		pvewhmcs_schema_assert_max_length('mod_pvewhmcs', 'schema_version', 20);
		pvewhmcs_schema_normalize_column('mod_pvewhmcs', 'schema_version', "varchar(20) NOT NULL DEFAULT ''", 'varchar(20)', false, '', "''");

		foreach (array(
			'title' => 'varchar(255) NOT NULL', 'gateway' => 'varchar(100) DEFAULT NULL',
		) as $column => $definition) {
			pvewhmcs_schema_add_missing_column('mod_pvewhmcs_ip_pools', $column, $definition);
		}
		foreach (array(
			'pool_id' => 'int(11) NOT NULL DEFAULT 0', 'ipaddress' => "varchar(255) NOT NULL DEFAULT '0'", 'mask' => "varchar(255) NOT NULL DEFAULT '0'",
		) as $column => $definition) {
			pvewhmcs_schema_add_missing_column('mod_pvewhmcs_ip_addresses', $column, $definition);
		}
		if (!pvewhmcs_schema_index_exists('mod_pvewhmcs_ip_addresses', 'ipaddress')) {
			Capsule::statement('ALTER TABLE `mod_pvewhmcs_ip_addresses` ADD UNIQUE KEY `ipaddress` (`ipaddress`)');
		}

		foreach (array(
			'title' => 'varchar(255) NOT NULL', 'vmtype' => 'varchar(8) NOT NULL', 'ostype' => 'varchar(8) DEFAULT NULL',
			'cpus' => 'smallint(4) unsigned DEFAULT NULL', 'cpuemu' => 'varchar(30) DEFAULT NULL', 'cores' => 'smallint(4) unsigned DEFAULT NULL',
			'cpulimit' => 'smallint(5) unsigned DEFAULT NULL', 'cpuunits' => 'smallint(5) unsigned DEFAULT NULL', 'memory' => 'int(10) unsigned NOT NULL',
			'swap' => 'int(10) unsigned DEFAULT NULL', 'disk' => 'int(10) unsigned DEFAULT NULL', 'diskformat' => 'varchar(10) DEFAULT NULL',
			'diskcache' => 'varchar(20) DEFAULT NULL', 'disktype' => 'varchar(20) DEFAULT NULL', 'storage' => "varchar(20) DEFAULT 'local'",
			'diskio' => "varchar(20) DEFAULT '0'", 'netmode' => 'varchar(10) DEFAULT NULL', 'bridge' => "varchar(20) NOT NULL DEFAULT 'vmbr'",
			'netmodel' => 'varchar(10) DEFAULT NULL', 'netrate' => "int(10) DEFAULT '0'", 'firewall' => 'tinyint(1) unsigned NOT NULL DEFAULT 0',
			'bw' => 'int(10) unsigned DEFAULT 0', 'kvm' => 'tinyint(1) unsigned DEFAULT 0', 'onboot' => 'tinyint(1) unsigned DEFAULT 0',
			'vlanid' => 'int(10) DEFAULT NULL', 'ipv6' => "varchar(10) DEFAULT 'auto'", 'balloon' => "int(10) DEFAULT '0'",
			'unpriv' => 'tinyint(1) unsigned DEFAULT 0', 'ssh-keys' => "varchar(100) DEFAULT ''",
		) as $column => $definition) {
			pvewhmcs_schema_add_missing_column('mod_pvewhmcs_plans', $column, $definition);
		}
		pvewhmcs_schema_assert_max_length('mod_pvewhmcs_plans', 'vmbr', 64);
		pvewhmcs_schema_normalize_column('mod_pvewhmcs_plans', 'vmbr', 'varchar(64) NULL DEFAULT NULL', 'varchar(64)', true, null);

		foreach (array(
			'vmid' => 'int(10) unsigned DEFAULT NULL', 'node_id' => 'int(10) unsigned DEFAULT NULL', 'user_id' => 'int(10) unsigned NOT NULL',
			'vtype' => 'varchar(255) NOT NULL', 'ipaddress' => 'varchar(255) NOT NULL', 'subnetmask' => 'varchar(255) NOT NULL',
			'gateway' => 'varchar(255) NOT NULL', 'created' => 'datetime DEFAULT NULL', 'v6prefix' => 'varchar(128) DEFAULT NULL',
			'ha_suspended' => "tinyint(1) unsigned NOT NULL DEFAULT '0'",
		) as $column => $definition) {
			pvewhmcs_schema_add_missing_column('mod_pvewhmcs_vms', $column, $definition);
		}
		Capsule::statement('UPDATE `mod_pvewhmcs_vms` SET `vmid` = `id` WHERE `vmid` IS NULL OR `vmid` = 0');
		pvewhmcs_schema_assert_max_length('mod_pvewhmcs_vms', 'provisioning_state', 16);
		pvewhmcs_schema_assert_max_length('mod_pvewhmcs_vms', 'provisioning_upid', 255);
		pvewhmcs_schema_assert_max_length('mod_pvewhmcs_vms', 'provisioning_node', 255);
		pvewhmcs_schema_assert_max_length('mod_pvewhmcs_vms', 'provisioning_mode', 16);
		pvewhmcs_schema_normalize_column('mod_pvewhmcs_vms', 'provisioning_state', "varchar(16) NOT NULL DEFAULT 'ready'", 'varchar(16)', false, 'ready', "'ready'");
		pvewhmcs_schema_normalize_column('mod_pvewhmcs_vms', 'provisioning_upid', 'varchar(255) NULL DEFAULT NULL', 'varchar(255)', true, null);
		pvewhmcs_schema_normalize_column('mod_pvewhmcs_vms', 'provisioning_node', 'varchar(255) NULL DEFAULT NULL', 'varchar(255)', true, null);
		pvewhmcs_schema_normalize_column('mod_pvewhmcs_vms', 'provisioning_mode', 'varchar(16) NULL DEFAULT NULL', 'varchar(16)', true, null);
		pvewhmcs_schema_normalize_column('mod_pvewhmcs_vms', 'provisioning_error', 'text NULL', 'text', true, null);
		pvewhmcs_schema_normalize_column('mod_pvewhmcs_vms', 'provisioning_updated_at', 'datetime NULL', 'datetime', true, null);
		if (!pvewhmcs_schema_index_exists('mod_pvewhmcs_vms', 'provisioning_state')) {
			Capsule::statement('ALTER TABLE `mod_pvewhmcs_vms` ADD KEY `provisioning_state` (`provisioning_state`)');
		}

		foreach (array(
			'auth_id' => "int(11) NOT NULL DEFAULT '0'", 'user_id' => "int(11) NOT NULL DEFAULT '0'", 'service' => "int(11) NOT NULL DEFAULT '0'",
			'timestamp' => 'datetime NOT NULL', 'node_id' => "int(11) NOT NULL DEFAULT '0'", 'target_id' => "int(11) NOT NULL DEFAULT '0'",
			'level' => 'varchar(10) NOT NULL', 'type' => 'text NOT NULL', 'action' => 'text NOT NULL', 'request' => 'text NOT NULL',
			'response' => 'text NOT NULL', 'raw' => 'text NOT NULL',
		) as $column => $definition) {
			pvewhmcs_schema_add_missing_column('mod_pvewhmcs_logs', $column, $definition);
		}
		pvewhmcs_schema_normalize_column('mod_pvewhmcs_logs', 'server_id', "int(11) NOT NULL DEFAULT '0'", 'int(11)', false, '0', '0');
		if (!pvewhmcs_schema_index_exists('mod_pvewhmcs_logs', 'server_timestamp')) {
			Capsule::statement('ALTER TABLE `mod_pvewhmcs_logs` ADD KEY `server_timestamp` (`server_id`, `timestamp`)');
		}
		if (!pvewhmcs_schema_index_exists('mod_pvewhmcs_logs', 'level_timestamp')) {
			Capsule::statement('ALTER TABLE `mod_pvewhmcs_logs` ADD KEY `level_timestamp` (`level`, `timestamp`)');
		}

		// This is intentionally last: a version marker never attests to a partial repair.
		Capsule::table('mod_pvewhmcs')->where('id', 1)->update(array('schema_version' => PVEWHMCS_SCHEMA_VERSION));
		$ensured = true;
	} finally {
		if ($acquired) {
			Capsule::select('SELECT RELEASE_LOCK(?)', array($lockName));
		}
	}

	return true;
}

function pvewhmcs_log_action(array $fields) {
	pvewhmcs_ensure_schema();
	$row = array_merge(
		array(
			'auth_id' => 0,
			'user_id' => 0,
			'service' => 0,
			'node_id' => 0,
			'target_id' => 0,
			'level' => 'info',
			'type' => '',
			'action' => '',
			'request' => '',
			'response' => '',
			'raw' => '',
		),
		$fields
	);
	$row['timestamp'] = date('Y-m-d H:i:s');

	try {
		Capsule::table('mod_pvewhmcs_logs')->insert($row);
	} catch (\Throwable $e) {
		error_log('PVEWHMCS: Failed to write action log entry: ' . $e->getMessage());
	}
}

// Path prefix the WHMCS web server proxies to the console relay (Node.js
// WS-to-WS bridge). Must match the relay's own path prefix and the reverse
// proxy rule documented in modules/servers/pvewhmcs/console-relay/README.md.
const PVEWHMCS_CONSOLE_RELAY_PATH = 'pve-console-ws';

function pvewhmcs_console_relay_secret() {
	pvewhmcs_ensure_schema();
	return trim((string) (Capsule::table('mod_pvewhmcs')->where('id', '1')->value('console_relay_secret') ?? ''));
}

function pvewhmcs_base64url_encode($data) {
	return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Packs the Proxmox console connection details (host, port, path with the
 * port/vncticket query string, and the restricted vnc@pve PVEAuthCookie) into
 * a short-lived v2 token for the console relay: "v2." + base64url(iv ||
 * ciphertext || tag), AES-256-GCM with a key derived from the Console Relay
 * Secret. The payload is encrypted and authenticated, so the browser that
 * receives the token cannot read the host, port, path or cookie, nor forge or
 * alter it. Only the relay decrypts it and opens the real Proxmox connection.
 * The relay still accepts legacy v1 (HMAC-signed, unencrypted) tokens.
 */
function pvewhmcs_build_console_token(array $payload, $ttl_seconds = 60) {
	$secret = pvewhmcs_console_relay_secret();
	if (strlen($secret) < 32) {
		throw new Exception('PVEWHMCS Error: Console Relay Secret in Module Config is not set or not long enough. Recommend 32+ characters.');
	}

	$payload['exp'] = time() + max(1, (int) $ttl_seconds);
	$payload['sid'] = bin2hex(random_bytes(16));

	// Must match decodeTokenV2() in the console relay.
	$context = 'pvewhmcs-console-token-v2';
	$key = hash('sha256', $context . '|' . $secret, true);
	$iv = random_bytes(12);
	$tag = '';
	$ciphertext = openssl_encrypt(json_encode($payload), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $context, 16);
	if ($ciphertext === false || strlen($tag) !== 16) {
		throw new RuntimeException('PVEWHMCS Error: Unable to encrypt the console token.');
	}

	return 'v2.' . pvewhmcs_base64url_encode($iv . $ciphertext . $tag);
}

/**
 * Resolves the public host/port the browser should open its console
 * WebSocket against. Uses the admin-configured Console Relay Host/Port
 * (mod_pvewhmcs.console_relay_host/port) when set, so the relay can be
 * hosted on its own subdomain instead of sharing the WHMCS domain. Falls
 * back to WHMCS's own $CONFIG['SystemURL'] host/port otherwise.
 */
function pvewhmcs_relay_public_endpoint($system_url) {
	pvewhmcs_ensure_schema();
	$config = Capsule::table('mod_pvewhmcs')->where('id', '1')->first();
	$host = trim((string) ($config->console_relay_host ?? ''));
	$port = $config->console_relay_port ?? null;

	if ($host === '') {
		$host = parse_url($system_url, PHP_URL_HOST);
	}
	if (!$port) {
		$port = parse_url($system_url, PHP_URL_PORT);
		if (!$port) {
			$port = (parse_url($system_url, PHP_URL_SCHEME) === 'http') ? 80 : 443;
		}
	}

	return array($host, (int) $port);
}
function pvewhmcs_console_relay_preconnect($relay_host, $relay_port, $relay_path) {
	$relay_host = trim((string) $relay_host);
	$relay_port = (int) $relay_port;
	$relay_path = '/' . ltrim((string) $relay_path, '/');
	if ($relay_host === '' || $relay_port < 1 || $relay_port > 65535) {
		throw new InvalidArgumentException('Invalid Console Relay endpoint.');
	}

	$authority = $relay_host;
	if (strpos($relay_host, ':') !== false && $relay_host[0] !== '[') {
		$authority = '[' . $relay_host . ']';
	}
	$url = 'https://' . $authority . ($relay_port === 443 ? '' : ':' . $relay_port) . $relay_path . '/prepare';
	$curl = curl_init($url);
	if ($curl === false) {
		throw new RuntimeException('Unable to initialize the Console Relay preconnect.');
	}

	curl_setopt_array($curl, array(
		CURLOPT_CUSTOMREQUEST => 'POST',
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 3,
		CURLOPT_TIMEOUT => 8,
		CURLOPT_HTTPHEADER => array('Accept: application/json'),
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
	));
	$response = curl_exec($curl);
	$curl_error = curl_error($curl);
	$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
	curl_close($curl);

	if ($response === false || $status < 200 || $status >= 300) {
		$detail = $curl_error !== '' ? ' ' . $curl_error : '';
		throw new RuntimeException('Console Relay preconnect failed (HTTP ' . $status . ').' . $detail);
	}

	$decoded = json_decode((string) $response, true);
	if (!is_array($decoded) || empty($decoded['ready'])) {
		throw new RuntimeException('Console Relay did not acknowledge the preconnect.');
	}

	return true;
}


class PVE2_API {
	// Proxmox tickets live 2 hours; renew 5 minutes before that.
	const LOGIN_TICKET_LIFETIME = 7200;
	const LOGIN_TICKET_RENEW_MARGIN = 300;

	protected $hostname;
	protected $username;
	protected $realm;
	protected $password;
	protected $port;
	protected $verify_ssl;
	protected $last_error = null;
	protected $connect_timeout = 10;
	protected $total_timeout = 30;

	protected $login_ticket = null;
	protected $login_ticket_timestamp = null;
	protected $cluster_node_list = null;

	public function __construct ($hostname, $username, $realm, #[\SensitiveParameter] $password, $port = 8006, $verify_ssl = true) {
		if (empty($hostname) || empty($username) || empty($realm) || empty($password) || empty($port)) {
			throw new PVE2_Exception("PVE2 API: Hostname/Username/Realm/Password/Port required for PVE2_API object constructor.", 1);
		}
		// Check hostname resolves.
		if (gethostbyname($hostname) == $hostname && !filter_var($hostname, FILTER_VALIDATE_IP)) {
			// Fallback: check for IPv6 (AAAA) records if IPv4 lookup failed
			if (!checkdnsrr($hostname, 'AAAA')) {
				throw new PVE2_Exception("PVE2 API: Cannot resolve {$hostname}.", 2);
			}
		}
		// Check port is between 1 and 65535.
		if (!filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]])) {
			throw new PVE2_Exception("PVE2 API: Port must be an integer between 1 and 65535.", 6);
		}
		// Check that verify_ssl is boolean.
		if (!is_bool($verify_ssl)) {
			throw new PVE2_Exception("PVE2 API: verify_ssl must be boolean.", 7);
		}

		$this->hostname   = $hostname;
		$this->username   = $username;
		$this->realm      = $realm;
		$this->password   = $password;
		$this->port       = $port;
		$this->verify_ssl = $verify_ssl;
	}

	public function get_last_error () {
		return $this->last_error;
	}

	/*
	 * void set_timeouts (int connectSeconds, int totalSeconds)
	 * cURL connect and total timeouts for login() and every API request.
	 */
	public function set_timeouts(int $connectSeconds, int $totalSeconds): void {
		if ($connectSeconds < 1 || $totalSeconds < 1) {
			throw new PVE2_Exception("PVE2 API: Timeouts must be positive whole seconds.", 8);
		}

		$this->connect_timeout = $connectSeconds;
		$this->total_timeout = $totalSeconds;
	}

	/*
	 * Options shared by login() and action(): TLS policy, timeouts, and an
	 * empty Expect header so cURL never waits for "100 Continue" on large
	 * request bodies.
	 */
	private function init_curl ($action_path, array $headers) {
		$prox_ch = curl_init();
		$headers[] = 'Expect:';

		curl_setopt($prox_ch, CURLOPT_URL, 'https://' . pvewhmcs_url_host($this->hostname) . ":{$this->port}/api2/json{$action_path}");
		curl_setopt($prox_ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($prox_ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($prox_ch, CURLOPT_SSL_VERIFYPEER, $this->verify_ssl);
		curl_setopt($prox_ch, CURLOPT_SSL_VERIFYHOST, $this->verify_ssl ? 2 : 0);
		curl_setopt($prox_ch, CURLOPT_CONNECTTIMEOUT, $this->connect_timeout);
		curl_setopt($prox_ch, CURLOPT_TIMEOUT, $this->total_timeout);

		return $prox_ch;
	}

	/*
	 * bool login ()
	 * Performs login to PVE Server using JSON API, and obtains Access Ticket.
	 */
	public function login () {
		$this->last_error = null;

		$login_postfields_string = http_build_query(array(
			'username' => $this->username,
			'password' => $this->password,
			'realm' => $this->realm,
		));

		$prox_ch = $this->init_curl('/access/ticket', array());
		curl_setopt($prox_ch, CURLOPT_POST, true);
		curl_setopt($prox_ch, CURLOPT_POSTFIELDS, $login_postfields_string);
		unset($login_postfields_string);

		$login_ticket = curl_exec($prox_ch);
		$http_code = (int) curl_getinfo($prox_ch, CURLINFO_RESPONSE_CODE);
		$ssl_verify_result = (int) curl_getinfo($prox_ch, CURLINFO_SSL_VERIFYRESULT);
		$login_error_number = curl_errno($prox_ch);
		$login_error = curl_error($prox_ch);

		curl_close($prox_ch);
		unset($prox_ch);

		if ($login_ticket === false) {
			$this->login_ticket = null;
			$this->login_ticket_timestamp = null;
			if ($this->verify_ssl && in_array($login_error_number, array(51, 60), true)) {
				throw new PVE2_Exception("PVE2 API: TLS certificate verification failed for {$this->hostname}: {$login_error}", 4);
			}

			if ($login_error_number === 35) {
				$this->last_error = "Unable to establish TLS with Proxmox at {$this->hostname}:{$this->port}. Check the port (normally 8006) and TLS settings.";
			} else {
				$this->last_error = "Unable to reach Proxmox at {$this->hostname}:{$this->port}. Check host, port, firewall, and that the API is listening (wrong port?).";
				if ($login_error !== '') {
					$this->last_error .= " cURL: {$login_error}.";
				}
			}

			return false;
		}

		$login_ticket_data = json_decode($login_ticket, true);
		if ($http_code !== 200 || !is_array($login_ticket_data) || empty($login_ticket_data['data']['ticket'])) {
			$this->login_ticket = null;
			$this->login_ticket_timestamp = null;
			if ($this->verify_ssl && $ssl_verify_result !== 0) {
				throw new PVE2_Exception("PVE2 API: Invalid SSL cert on {$this->hostname} - check that the hostname is correct, and that it appears in the server certificate's SAN list. Alternatively disable certificate verification for this WHMCS server only if you understand the risk.", 4);
			}

			if ($http_code === 401 || $http_code === 403) {
				$this->last_error = 'Authentication failed. Check the Proxmox username, password, and authentication realm.';
			} elseif ($http_code >= 400) {
				$this->last_error = "Proxmox rejected the login request with HTTP {$http_code}.";
			} else {
				$this->last_error = 'Proxmox returned an invalid login response.';
			}

			return false;
		}

		// Login success. get_node_list() loads the node list on first use.
		$this->login_ticket = $login_ticket_data['data'];
		$this->login_ticket_timestamp = time();
		$this->cluster_node_list = null;
		return true;
	}

	# Gets the PVE Access Ticket
	public function getTicket() {
		if ($this->login_ticket['ticket']) {
			return $this->login_ticket['ticket'];
		} else {
			return false;
		}
	}

	/*
	 * bool check_login_ticket ()
	 * Checks if the login ticket is valid still, returns false if not.
	 * Method of checking is purely by age of ticket right now...
	 */
	protected function check_login_ticket () {
		if ($this->login_ticket == null) {
			// Just to be safe, set this to null again.
			$this->login_ticket_timestamp = null;
			return false;
		}
		if (time() - (int) $this->login_ticket_timestamp >= self::LOGIN_TICKET_LIFETIME - self::LOGIN_TICKET_RENEW_MARGIN) {
			// Reset login ticket object values.
			$this->login_ticket = null;
			$this->login_ticket_timestamp = null;
			return false;
		}

		return true;
	}

	/*
	 * mixed action (string action_path, string http_method[, array put_post_parameters])
	 * This method is responsible for the general cURL requests to the JSON API,
	 * and sits behind the abstraction layer methods get/put/post/delete etc.
	 * A 2xx reply returns true for PUT and the decoded "data" otherwise; any
	 * other reply or a transport failure throws PVE2_Exception.
	 */
	private function action ($action_path, $http_method, $put_post_parameters = null) {
		// Check if we have a prefixed / on the path, if not add one.
		if (substr($action_path, 0, 1) != "/") {
			$action_path = "/" . $action_path;
		}

		if (!in_array($http_method, array('GET', 'PUT', 'POST', 'DELETE'), true)) {
			throw new PVE2_Exception("PVE2 API: Error - Invalid HTTP Method specified.", 5);
		}

		if (!$this->check_login_ticket()) {
			throw new PVE2_Exception("PVE2 API: Not logged into Proxmox. No login Access Ticket found or Ticket expired.", 3);
		}

		$prox_ch = $this->init_curl($action_path, array("CSRFPreventionToken: {$this->login_ticket['CSRFPreventionToken']}"));
		curl_setopt($prox_ch, CURLOPT_HEADER, true);
		curl_setopt($prox_ch, CURLOPT_COOKIE, "PVEAuthCookie=" . $this->login_ticket['ticket']);

		switch ($http_method) {
			case "PUT":
				curl_setopt($prox_ch, CURLOPT_CUSTOMREQUEST, "PUT");
				curl_setopt($prox_ch, CURLOPT_POSTFIELDS, http_build_query((array) $put_post_parameters));
				break;
			case "POST":
				curl_setopt($prox_ch, CURLOPT_POST, true);
				curl_setopt($prox_ch, CURLOPT_POSTFIELDS, http_build_query((array) $put_post_parameters));
				break;
			case "DELETE":
				// The delete destination is specified in the URL.
				curl_setopt($prox_ch, CURLOPT_CUSTOMREQUEST, "DELETE");
				break;
		}

		$action_response = curl_exec($prox_ch);
		if ($action_response === false) {
			$curl_errno = curl_errno($prox_ch);
			$curl_error = curl_error($prox_ch);
			curl_close($prox_ch);
			throw new PVE2_Exception("PVE2 API: {$http_method} {$action_path} failed: cURL error {$curl_errno}: {$curl_error}");
		}

		$http_code = (int) curl_getinfo($prox_ch, CURLINFO_RESPONSE_CODE);
		$header_size = (int) curl_getinfo($prox_ch, CURLINFO_HEADER_SIZE);
		curl_close($prox_ch);
		unset($prox_ch);

		$header_response = (string) substr($action_response, 0, $header_size);
		$body_response = (string) substr($action_response, $header_size);
		unset($action_response);

		if ($http_code >= 200 && $http_code < 300) {
			$action_response_array = json_decode($body_response, true);
			$data = is_array($action_response_array) ? ($action_response_array['data'] ?? null) : null;
			// Most PUT endpoints are synchronous and callers historically receive
			// true. Some Proxmox versions return an asynchronous task UPID instead;
			// preserve that value so provisioning can wait for it before continuing.
			if ($http_method == "PUT") {
				return is_string($data) && strpos($data, 'UPID:') === 0 ? $data : true;
			}

			return $data;
		}

		// Proxmox puts most error details in the status line's reason phrase.
		// With several header blocks (interim 1xx, proxies) the last is final.
		$header_blocks = preg_split("/\r?\n\r?\n/", trim($header_response));
		$status_line = trim((string) strtok((string) end($header_blocks), "\r\n"));

		throw new PVE2_Exception("PVE2 API: {$http_method} {$action_path} failed.\n" .
			"HTTP CODE: {$http_code},\n" .
			"HTTP ERROR: {$status_line},\n" .
			"REPLY INFO: {$body_response}");
	}

	/*
	 * bool reload_node_list ()
	 * Caches the list of node names as provided by /api2/json/nodes.
	 * We need this for future get/post/put/delete calls.
	 * ie. $this->get("nodes/XXX/status"); where XXX is one of the values from this return array.
	 */
	public function reload_node_list () {
		$node_list = $this->get("/nodes");
		$nodes_array = array();
		if (is_array($node_list)) {
			foreach ($node_list as $node) {
				if (is_array($node) && isset($node['node']) && $node['node'] !== '') {
					$nodes_array[] = $node['node'];
				}
			}
		}

		if (count($nodes_array) === 0) {
			error_log("PVE2 API: Empty list of Nodes returned in this Cluster.");
			return false;
		}

		$this->cluster_node_list = $nodes_array;
		return true;
	}

	/*
	 * array|false get_node_list ()
	 * Node names, loaded from /nodes on first use and cached in the object.
	 */
	public function get_node_list () {
		if ($this->cluster_node_list == null) {
			if ($this->reload_node_list() === false) {
				return false;
			}
		}

		return $this->cluster_node_list;
	}

	/*
	 * bool|string get_version ()
	 * Return the version and minor revision of Proxmox Server
	 */
	public function get_version () {
		$version = $this->get("/version");
		if ($version == null) {
			return false;
		} else {
			return $version['version'];
		}
	}

	/*
	 * object/array? get (string action_path)
	 */
	public function get ($action_path) {
		return $this->action($action_path, "GET");
	}

	/*
	 * bool|string put (string action_path, array parameters)
	 * Synchronous PUTs return true; asynchronous PUTs return their task UPID.
	 */
	public function put ($action_path, $parameters) {
		return $this->action($action_path, "PUT", $parameters);
	}

	/*
	 * mixed post (string action_path, array parameters)
	 * Returns "data"; for async endpoints that is the task UPID string.
	 */
	public function post ($action_path, $parameters) {
		return $this->action($action_path, "POST", $parameters);
	}

	/*
	 * mixed delete (string action_path)
	 */
	public function delete ($action_path) {
		return $this->action($action_path, "DELETE");
	}

	// Logout not required, PVEAuthCookie tokens have a 2 hour lifetime.
}

?>
