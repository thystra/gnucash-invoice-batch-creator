<?php
/**
 * GnuCash Invoice Batch Creator
 * Copyright (C) 2026 Alan Johnson / contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

const APP_NAME = 'GnuCash Invoice Batch Creator';
const APP_VERSION = '0.1.0';
define('BASE_PATH', dirname(__DIR__));
define('CONFIG_PATH', BASE_PATH . '/config/config.php');
define('CONFIG_EXAMPLE_PATH', BASE_PATH . '/config/config.example.php');

$defaultConfig = require CONFIG_EXAMPLE_PATH;
$userConfig = is_file(CONFIG_PATH) ? require CONFIG_PATH : [];
$config = array_replace($defaultConfig, is_array($userConfig) ? $userConfig : []);

date_default_timezone_set((string)($config['timezone'] ?? 'America/New_York'));

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('gnc_batch_invoice_creator');
    session_start();
}

function app_config(?string $key = null, mixed $default = null): mixed
{
    global $config;
    if ($key === null) {
        return $config;
    }
    return $config[$key] ?? $default;
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function path_join(string ...$parts): string
{
    return preg_replace('#/+#', '/', implode('/', $parts));
}

function ensure_runtime_dirs(): void
{
    foreach (['var/uploads', 'var/generated', 'var/groups', 'var/templates', 'var/cache', 'var/log', 'config'] as $dir) {
        $path = BASE_PATH . '/' . $dir;
        if (!is_dir($path)) {
            mkdir($path, 0770, true);
        }
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function check_csrf(): void
{
    $posted = $_POST['csrf_token'] ?? '';
    if (!is_string($posted) || !hash_equals(csrf_token(), $posted)) {
        http_response_code(400);
        exit('Invalid CSRF token.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function pop_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function redirect_to(string $action, array $params = []): never
{
    $params = array_merge(['action' => $action], $params);
    header('Location: ?' . http_build_query($params));
    exit;
}

function slugify(string $value): string
{
    $value = trim(strtolower($value));
    $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?: 'item';
    return trim($value, '-_.') ?: 'item';
}

function json_read_file(string $path, mixed $default = null): mixed
{
    if (!is_file($path)) {
        return $default;
    }
    $json = file_get_contents($path);
    if ($json === false) {
        return $default;
    }
    $data = json_decode($json, true);
    return json_last_error() === JSON_ERROR_NONE ? $data : $default;
}

function json_write_file(string $path, mixed $data): void
{
    $tmp = $path . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write temporary JSON file: ' . $tmp);
    }
    rename($tmp, $path);
}

function list_named_json(string $dir): array
{
    $items = [];
    foreach (glob(BASE_PATH . '/' . $dir . '/*.json') ?: [] as $path) {
        $data = json_read_file($path, []);
        $items[] = [
            'slug' => basename($path, '.json'),
            'path' => $path,
            'name' => $data['name'] ?? basename($path, '.json'),
            'data' => $data,
            'modified' => filemtime($path) ?: 0,
        ];
    }
    usort($items, fn($a, $b) => strnatcasecmp((string)$a['name'], (string)$b['name']));
    return $items;
}

function run_python(array $args, ?array $stdinJson = null): array
{
    $python = (string)app_config('python_bin', '/usr/bin/python3');
    $script = BASE_PATH . '/bin/gnc_batch_invoice.py';
    $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($script);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg((string)$arg);
    }

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($cmd, $descriptorSpec, $pipes, BASE_PATH);
    if (!is_resource($process)) {
        return ['ok' => false, 'exit_code' => -1, 'stdout' => '', 'stderr' => 'Unable to start Python process.', 'json' => null];
    }

    if ($stdinJson !== null) {
        fwrite($pipes[0], json_encode($stdinJson, JSON_UNESCAPED_SLASHES));
    }
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $decoded = json_decode($stdout ?: '', true);

    return [
        'ok' => $exitCode === 0,
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'json' => json_last_error() === JSON_ERROR_NONE ? $decoded : null,
    ];
}

function render_header(string $title): void
{
    $configured = app_config('gnucash_book_path') && is_file((string)app_config('gnucash_book_path'));
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . ' - ' . h(APP_NAME) . '</title>';
    echo '<link rel="stylesheet" href="assets/app.css">';
    echo '</head><body><header class="top"><div><a class="brand" href="?">' . h(APP_NAME) . '</a><span class="version">v' . h(APP_VERSION) . '</span></div>';
    echo '<nav><a href="?">Home</a><a href="?action=wizard">Batch Wizard</a><a href="?action=groups">Groups</a><a href="?action=templates">Templates</a><a href="?action=config">Configuration</a></nav></header>';
    echo '<main class="wrap">';
    foreach (pop_flashes() as $message) {
        echo '<div class="flash ' . h($message['type']) . '">' . h($message['message']) . '</div>';
    }
    if (!$configured) {
        echo '<div class="flash warn">Configure a readable GnuCash book path before generating invoices.</div>';
    }
    echo '<h1>' . h($title) . '</h1>';
}

function render_footer(): void
{
    echo '</main><footer class="foot">GPL-3.0-or-later. Use on localhost or a trusted internal network only.</footer></body></html>';
}

function require_book_configured(): void
{
    $book = (string)app_config('gnucash_book_path', '');
    if ($book === '' || !is_file($book)) {
        flash('error', 'Please configure a readable GnuCash book path first.');
        redirect_to('config');
    }
}

ensure_runtime_dirs();
