<?php

/**
 * GitHub push webhook for module-only deployments.
 *
 * Deploy this file to modules/addons/pvewhmcs/ and create the ignored sibling
 * github-webhook.local.php with the webhook secret before exposing this URL.
 */

declare(strict_types=1);

const PVEWHMCS_WEBHOOK_REPOSITORY = 'junglivre/Proxmox-VE-for-WHMCS';
const PVEWHMCS_WEBHOOK_BRANCH = 'master';
const PVEWHMCS_WEBHOOK_MAX_PAYLOAD_BYTES = 1048576;
const PVEWHMCS_WEBHOOK_PRESERVED_FILES = array(
    'github-webhook.php',
    'github-webhook.local.php',
    'github-webhook.lock',
);

function pvewhmcs_webhook_response(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n";
}

function pvewhmcs_webhook_fail(int $status, string $message): void
{
    error_log('PVEWHMCS GitHub webhook: ' . $message);
    pvewhmcs_webhook_response($status, array('ok' => false, 'message' => $message));
    exit;
}

function pvewhmcs_webhook_config(): array
{
    $path = __DIR__ . '/github-webhook.local.php';
    if (!is_file($path)) {
        pvewhmcs_webhook_fail(500, 'Missing local webhook configuration.');
    }

    $config = require $path;
    if (!is_array($config) || !isset($config['secret']) || !is_string($config['secret']) || $config['secret'] === '') {
        pvewhmcs_webhook_fail(500, 'Webhook configuration has no secret.');
    }

    if (isset($config['github_token']) && !is_string($config['github_token'])) {
        pvewhmcs_webhook_fail(500, 'Webhook configuration has an invalid GitHub token.');
    }

    return $config;
}

function pvewhmcs_webhook_signature_is_valid(string $payload, string $secret, string $signature): bool
{
    if (strpos($signature, 'sha256=') !== 0) {
        return false;
    }

    $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
    return hash_equals($expected, $signature);
}

function pvewhmcs_webhook_archive_url(string $commit): string
{
    return 'https://api.github.com/repos/' . PVEWHMCS_WEBHOOK_REPOSITORY . '/zipball/' . $commit;
}

function pvewhmcs_webhook_request(string $url, array $headers): array
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Could not initialise cURL.');
    }

    $redirect = null;
    curl_setopt_array($curl, array(
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => function ($handle, $header) use (&$redirect) {
            if (stripos($header, 'Location:') === 0) {
                $redirect = trim(substr($header, strlen('Location:')));
            }

            return strlen($header);
        },
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ));

    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $redirect = $redirect ?: curl_getinfo($curl, CURLINFO_REDIRECT_URL);
    $error = curl_error($curl);
    curl_close($curl);

    return array($body, $status, $redirect, $error);
}

function pvewhmcs_webhook_download(string $url, ?string $github_token): string
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required.');
    }

    $headers = array(
        'Accept: application/vnd.github+json',
        'User-Agent: pvewhmcs-module-webhook',
    );
    if ($github_token !== null && $github_token !== '') {
        $headers[] = 'Authorization: Bearer ' . $github_token;
    }

    list($archive, $status, $redirect, $error) = pvewhmcs_webhook_request($url, $headers);
    if ($status >= 300 && $status < 400 && is_string($redirect) && $redirect !== '') {
        $redirect_parts = parse_url($redirect);
        if (($redirect_parts['scheme'] ?? '') !== 'https' || ($redirect_parts['host'] ?? '') !== 'codeload.github.com') {
            throw new RuntimeException('GitHub returned an unexpected archive download host.');
        }

        // The API redirect carries GitHub's short-lived download authorisation.
        // Do not forward a repository token to the codeload host.
        list($archive, $status, $unused_redirect, $error) = pvewhmcs_webhook_request($redirect, array(
            'User-Agent: pvewhmcs-module-webhook',
        ));
    }

    if (!is_string($archive) || $status < 200 || $status >= 300) {
        throw new RuntimeException('GitHub archive download failed: HTTP ' . $status . ($error === '' ? '' : ' (' . $error . ')'));
    }

    return $archive;
}

function pvewhmcs_webhook_safe_relative_path(string $path): ?string
{
    $path = str_replace('\\', '/', $path);
    $parts = explode('/', $path);
    if ($path === '' || in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) {
        return null;
    }

    return $path;
}

function pvewhmcs_webhook_extract_module(ZipArchive $archive, string $module_path, string $destination): int
{
    $marker = '/' . trim($module_path, '/') . '/';
    $files = 0;

    for ($index = 0; $index < $archive->numFiles; $index++) {
        $name = $archive->getNameIndex($index);
        if (!is_string($name)) {
            continue;
        }

        $position = strpos($name, $marker);
        if ($position === false) {
            continue;
        }

        $relative = pvewhmcs_webhook_safe_relative_path(substr($name, $position + strlen($marker)));
        if ($relative === null) {
            if (substr($name, -1) !== '/') {
                throw new RuntimeException('Archive contains an unsafe module path.');
            }
            continue;
        }

        $target = $destination . '/' . $relative;
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create staging directory.');
        }

        $source = $archive->getStream($name);
        if ($source === false) {
            throw new RuntimeException('Could not read an archive member.');
        }

        $output = fopen($target, 'wb');
        if ($output === false) {
            fclose($source);
            throw new RuntimeException('Could not write a staged module file.');
        }

        $copied = stream_copy_to_stream($source, $output);
        fclose($source);
        fclose($output);
        if ($copied === false) {
            throw new RuntimeException('Could not extract a module file.');
        }

        chmod($target, 0644);
        $files++;
    }

    return $files;
}

function pvewhmcs_webhook_remove_tree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }

    if (!is_dir($path) || is_link($path)) {
        if (!unlink($path)) {
            throw new RuntimeException('Could not remove a stale module file.');
        }
        return;
    }

    $entries = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
    foreach ($entries as $entry) {
        pvewhmcs_webhook_remove_tree($entry->getPathname());
    }

    if (!rmdir($path)) {
        throw new RuntimeException('Could not remove a stale module directory.');
    }
}

function pvewhmcs_webhook_sync_module(string $source, string $destination, array $preserved = array()): void
{
    $source_iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    $expected = array();

    foreach ($source_iterator as $entry) {
        if (!$entry->isFile() || $entry->isLink()) {
            throw new RuntimeException('The staged module contains an unsupported filesystem entry.');
        }

        $relative = pvewhmcs_webhook_safe_relative_path(substr($entry->getPathname(), strlen($source) + 1));
        if ($relative === null) {
            throw new RuntimeException('The staged module contains an unsafe filesystem path.');
        }
        $expected[$relative] = true;

        $target = $destination . '/' . $relative;
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create a module directory.');
        }

        $temporary = $target . '.deploy-' . bin2hex(random_bytes(8));
        if (!copy($entry->getPathname(), $temporary) || !rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('Could not replace a module file.');
        }
        chmod($target, 0644);
    }

    if (!is_dir($destination)) {
        return;
    }

    $destination_iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($destination, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($destination_iterator as $entry) {
        $relative = substr($entry->getPathname(), strlen($destination) + 1);
        if (isset($expected[$relative]) || in_array($relative, $preserved, true)) {
            continue;
        }

        if ($entry->isDir() && !$entry->isLink()) {
            @rmdir($entry->getPathname());
        } elseif (!unlink($entry->getPathname())) {
            throw new RuntimeException('Could not remove a stale module file.');
        }
    }
}

function pvewhmcs_webhook_cleanup(string $directory): void
{
    try {
        pvewhmcs_webhook_remove_tree($directory);
    } catch (Throwable $exception) {
        error_log('PVEWHMCS GitHub webhook cleanup: ' . $exception->getMessage());
    }
}

function pvewhmcs_webhook_deploy(string $commit, ?string $github_token): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP ZipArchive extension is required.');
    }

    $temporary = sys_get_temp_dir() . '/pvewhmcs-webhook-' . bin2hex(random_bytes(12));
    $archive_path = $temporary . '/source.zip';
    $addon_stage = $temporary . '/addon';
    $server_stage = $temporary . '/server';

    if (!mkdir($temporary, 0700, true)) {
        throw new RuntimeException('Could not create a deployment staging directory.');
    }

    try {
        $archive = pvewhmcs_webhook_download(pvewhmcs_webhook_archive_url($commit), $github_token);
        if (file_put_contents($archive_path, $archive, LOCK_EX) === false) {
            throw new RuntimeException('Could not write the GitHub archive.');
        }
        unset($archive);

        $zip = new ZipArchive();
        if ($zip->open($archive_path) !== true) {
            throw new RuntimeException('GitHub returned an invalid ZIP archive.');
        }

        $addon_files = pvewhmcs_webhook_extract_module($zip, 'modules/addons/pvewhmcs', $addon_stage);
        $server_files = pvewhmcs_webhook_extract_module($zip, 'modules/servers/pvewhmcs', $server_stage);
        $zip->close();

        if ($addon_files === 0 || $server_files === 0) {
            throw new RuntimeException('GitHub archive did not contain both module directories.');
        }

        $modules_directory = dirname(__DIR__, 2);
        pvewhmcs_webhook_sync_module($addon_stage, __DIR__, PVEWHMCS_WEBHOOK_PRESERVED_FILES);
        pvewhmcs_webhook_sync_module($server_stage, $modules_directory . '/servers/pvewhmcs');

        return array('addon_files' => $addon_files, 'server_files' => $server_files);
    } finally {
        pvewhmcs_webhook_cleanup($temporary);
    }
}

function pvewhmcs_webhook_handle(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        pvewhmcs_webhook_response(405, array('ok' => false, 'message' => 'POST required.'));
        return;
    }

    $content_length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($content_length <= 0 || $content_length > PVEWHMCS_WEBHOOK_MAX_PAYLOAD_BYTES) {
        pvewhmcs_webhook_fail(413, 'Invalid webhook payload size.');
    }

    $config = pvewhmcs_webhook_config();
    $payload = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    if (!is_string($payload) || !is_string($signature) || !pvewhmcs_webhook_signature_is_valid($payload, $config['secret'], $signature)) {
        pvewhmcs_webhook_fail(401, 'Invalid webhook signature.');
    }

    $event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
    if ($event === 'ping') {
        pvewhmcs_webhook_response(200, array('ok' => true, 'message' => 'Webhook verified.'));
        return;
    }
    if ($event !== 'push') {
        pvewhmcs_webhook_response(202, array('ok' => true, 'message' => 'Ignored event.'));
        return;
    }

    $data = json_decode($payload, true);
    if (!is_array($data) || ($data['repository']['full_name'] ?? '') !== PVEWHMCS_WEBHOOK_REPOSITORY) {
        pvewhmcs_webhook_fail(400, 'Unexpected repository payload.');
    }
    if (($data['ref'] ?? '') !== 'refs/heads/' . PVEWHMCS_WEBHOOK_BRANCH || !empty($data['deleted'])) {
        pvewhmcs_webhook_response(202, array('ok' => true, 'message' => 'Ignored branch update.'));
        return;
    }

    $commit = $data['after'] ?? '';
    if (!is_string($commit) || !preg_match('/^[a-f0-9]{40}$/', $commit)) {
        pvewhmcs_webhook_fail(400, 'Push payload has no valid commit SHA.');
    }

    $lock = fopen(__DIR__ . '/github-webhook.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        pvewhmcs_webhook_response(409, array('ok' => false, 'message' => 'A deployment is already running.'));
        return;
    }

    try {
        $result = pvewhmcs_webhook_deploy($commit, $config['github_token'] ?? null);
        pvewhmcs_webhook_response(200, array(
            'ok' => true,
            'commit' => $commit,
            'addon_files' => $result['addon_files'],
            'server_files' => $result['server_files'],
        ));
    } catch (Throwable $exception) {
        pvewhmcs_webhook_fail(500, 'Deployment failed: ' . $exception->getMessage());
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

if (!defined('PVEWHMCS_WEBHOOK_LIBRARY')) {
    pvewhmcs_webhook_handle();
}
