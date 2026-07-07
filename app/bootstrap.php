<?php
/**
 * GnuCash Invoice Batch Creator
 * Copyright (C) 2026 Alan Johnson / contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

const APP_NAME = 'GnuCash Invoice Batch Creator';
const APP_VERSION = '0.1.11';
define('BASE_PATH', dirname(__DIR__));
define('CONFIG_PATH', BASE_PATH . '/config/config.php');
define('CONFIG_EXAMPLE_PATH', BASE_PATH . '/config/config.example.php');

$defaultConfig = require CONFIG_EXAMPLE_PATH;
$userConfig = is_file(CONFIG_PATH) ? require CONFIG_PATH : [];
$config = array_replace($defaultConfig, is_array($userConfig) ? $userConfig : []);

date_default_timezone_set((string)($config['timezone'] ?? 'America/New_York'));
// Keep generated runtime files group-readable/writable when the application is
// deployed with a shared project group such as publicweb. This does not change
// file ownership; use a dedicated PHP-FPM pool running as your user when you
// want generated CSV/PDF files to be owned by that user.
umask(0007);

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

function write_config(array $newConfig): void
{
    $php = "<?php\nreturn " . var_export($newConfig, true) . ";\n";
    if (file_put_contents(CONFIG_PATH, $php, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write config/config.php. Check permissions.');
    }
    apply_runtime_permissions(CONFIG_PATH);
}

function update_config(array $changes): void
{
    $current = app_config();
    write_config(array_replace($current, $changes));
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function path_join(string ...$parts): string
{
    return preg_replace('#/+#', '/', implode('/', $parts));
}

function runtime_identity(): array
{
    $scriptOwner = @fileowner(BASE_PATH);
    $scriptGroup = @filegroup(BASE_PATH);
    $effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
    $effectiveGid = function_exists('posix_getegid') ? posix_getegid() : null;
    $userInfo = is_int($effectiveUid) && function_exists('posix_getpwuid') ? posix_getpwuid($effectiveUid) : false;
    $groupInfo = is_int($effectiveGid) && function_exists('posix_getgrgid') ? posix_getgrgid($effectiveGid) : false;
    $ownerInfo = is_int($scriptOwner) && function_exists('posix_getpwuid') ? posix_getpwuid($scriptOwner) : false;
    $ownerGroupInfo = is_int($scriptGroup) && function_exists('posix_getgrgid') ? posix_getgrgid($scriptGroup) : false;
    return [
        'effective_uid' => $effectiveUid,
        'effective_user' => is_array($userInfo) ? (string)$userInfo['name'] : (string)($effectiveUid ?? 'unknown'),
        'effective_gid' => $effectiveGid,
        'effective_group' => is_array($groupInfo) ? (string)$groupInfo['name'] : (string)($effectiveGid ?? 'unknown'),
        'app_owner_uid' => $scriptOwner,
        'app_owner' => is_array($ownerInfo) ? (string)$ownerInfo['name'] : (string)($scriptOwner ?: 'unknown'),
        'app_group_gid' => $scriptGroup,
        'app_group' => is_array($ownerGroupInfo) ? (string)$ownerGroupInfo['name'] : (string)($scriptGroup ?: 'unknown'),
    ];
}


function path_within_open_basedir(string $path, string $openBasedir): bool
{
    if (trim($openBasedir) === '') {
        return true;
    }
    $realPath = realpath($path) ?: $path;
    foreach (explode(PATH_SEPARATOR, $openBasedir) as $allowed) {
        $allowed = trim($allowed);
        if ($allowed === '') {
            continue;
        }
        $realAllowed = realpath($allowed) ?: $allowed;
        $realAllowed = rtrim($realAllowed, DIRECTORY_SEPARATOR);
        if ($realPath === $realAllowed || str_starts_with($realPath, $realAllowed . DIRECTORY_SEPARATOR)) {
            return true;
        }
    }
    return false;
}

function open_basedir_status(): array
{
    $openBasedir = (string)ini_get('open_basedir');
    return [
        'value' => $openBasedir,
        'restricted' => trim($openBasedir) !== '',
        'base_path_allowed' => path_within_open_basedir(BASE_PATH, $openBasedir),
        'config_allowed' => path_within_open_basedir(CONFIG_PATH, $openBasedir),
        'var_allowed' => path_within_open_basedir(BASE_PATH . '/var', $openBasedir),
        'snap_bin_allowed' => trim($openBasedir) === '' || path_within_open_basedir('/snap/bin', $openBasedir),
    ];
}

function chromium_candidates(?string $configured = null): array
{
    $candidates = [];
    $add = static function (?string $value) use (&$candidates): void {
        $value = trim((string)$value);
        if ($value !== '' && !in_array($value, $candidates, true)) {
            $candidates[] = $value;
        }
    };

    $add($configured);
    // Ubuntu's chromium deb is commonly a transitional wrapper to the snap;
    // in local installs the real executable is usually here.
    $add('/snap/bin/chromium');
    $add('/usr/bin/chromium');
    $add('/usr/bin/chromium-browser');
    $add('/usr/bin/google-chrome');
    $add('/usr/bin/google-chrome-stable');
    $add('/opt/google/chrome/chrome');

    foreach (['chromium', 'chromium-browser', 'google-chrome', 'google-chrome-stable'] as $cmd) {
        $found = trim((string)@shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null'));
        if ($found !== '') {
            $add($found);
        }
    }

    return $candidates;
}

function detect_chromium_binary(?string $configured = null): array
{
    $candidates = chromium_candidates($configured);
    foreach ($candidates as $candidate) {
        if (@is_file($candidate) && @is_executable($candidate)) {
            return [
                'ok' => true,
                'path' => $candidate,
                'configured' => (string)($configured ?? ''),
                'candidates' => $candidates,
            ];
        }
    }
    return [
        'ok' => false,
        'path' => '',
        'configured' => (string)($configured ?? ''),
        'candidates' => $candidates,
    ];
}

function apply_runtime_permissions(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    clearstatcache(true, $path);
    if (is_dir($path)) {
        @chmod($path, 02770);
    } elseif (is_file($path)) {
        @chmod($path, 0660);
    }

    $parent = is_dir($path) ? dirname($path) : dirname($path);
    $gid = @filegroup($parent);
    if ($gid !== false && function_exists('chgrp')) {
        @chgrp($path, (int)$gid);
    }
}

function ensure_runtime_dirs(): void
{
    foreach (['var/uploads', 'var/generated', 'var/groups', 'var/templates', 'var/profiles', 'var/cache', 'var/log', 'config'] as $dir) {
        $path = BASE_PATH . '/' . $dir;
        if (!is_dir($path)) {
            mkdir($path, 0770, true);
        }
        apply_runtime_permissions($path);
    }
}


function upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE => 'Uploaded file is larger than PHP upload_max_filesize.',
        UPLOAD_ERR_FORM_SIZE => 'Uploaded file is larger than the form limit.',
        UPLOAD_ERR_PARTIAL => 'Uploaded file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'PHP upload temporary directory is missing.',
        UPLOAD_ERR_CANT_WRITE => 'PHP could not write the uploaded file to disk.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
        default => 'Unknown upload error code: ' . $code,
    };
}

function runtime_writable_checks(): array
{
    ensure_runtime_dirs();
    $checks = [];
    $paths = [
        'Application root' => BASE_PATH,
        'config/' => BASE_PATH . '/config',
        'config/config.php' => CONFIG_PATH,
        'var/' => BASE_PATH . '/var',
        'var/profiles/' => profiles_root(),
        'var/uploads/' => BASE_PATH . '/var/uploads',
        'var/generated/' => BASE_PATH . '/var/generated',
    ];
    $profile = active_profile();
    if ($profile) {
        $slug = (string)$profile['slug'];
        foreach (['books', 'uploads', 'generated', 'groups', 'templates', 'report-assets', 'reports'] as $dir) {
            $paths['active profile ' . $dir . '/'] = profile_dir($slug) . '/' . $dir;
        }
    }
    foreach ($paths as $label => $path) {
        $exists = file_exists($path);
        $target = $path;
        if (!$exists && basename($path) === 'config.php') {
            $target = dirname($path);
        }
        $checks[] = [
            'label' => $label,
            'path' => $path,
            'exists' => $exists,
            'writable' => is_writable($target),
            'readable' => is_readable($target),
            'is_dir' => is_dir($path),
        ];
    }
    return $checks;
}

function runtime_has_warnings(): bool
{
    foreach (runtime_writable_checks() as $check) {
        if (empty($check['readable']) || empty($check['writable'])) {
            return true;
        }
    }
    return false;
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
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    $tmp = $path . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write temporary JSON file: ' . $tmp);
    }
    apply_runtime_permissions($tmp);
    rename($tmp, $path);
    apply_runtime_permissions($path);
}

function list_named_json(string $dir): array
{
    $base = str_starts_with($dir, '/') ? $dir : BASE_PATH . '/' . $dir;
    $items = [];
    foreach (glob($base . '/*.json') ?: [] as $path) {
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

function profiles_root(): string
{
    return BASE_PATH . '/var/profiles';
}

function profile_dir(string $slug): string
{
    return profiles_root() . '/' . slugify($slug);
}

function profile_json_path(string $slug): string
{
    return profile_dir($slug) . '/profile.json';
}

function ensure_profile_dirs(string $slug): void
{
    foreach (['books', 'uploads', 'generated', 'groups', 'templates', 'report-assets', 'report-templates', 'reports', 'cache', 'log'] as $dir) {
        $path = profile_dir($slug) . '/' . $dir;
        if (!is_dir($path)) {
            mkdir($path, 0770, true);
        }
        apply_runtime_permissions($path);
    }
    apply_runtime_permissions(profile_dir($slug));
}

function profile_data_dir(string $kind, ?string $slug = null): string
{
    $slug = $slug ?: (active_profile_slug() ?? '');
    if ($slug === '') {
        return BASE_PATH . '/var/' . $kind;
    }
    ensure_profile_dirs($slug);
    return profile_dir($slug) . '/' . $kind;
}

function normalize_profile(array $profile, string $slug): array
{
    $profile['slug'] = $profile['slug'] ?? $slug;
    $profile['name'] = trim((string)($profile['name'] ?? $slug)) ?: $slug;
    $profile['created_at'] = $profile['created_at'] ?? date(DATE_ATOM);
    $profile['updated_at'] = $profile['updated_at'] ?? $profile['created_at'];
    $profile['books'] = is_array($profile['books'] ?? null) ? $profile['books'] : [];
    $profile['active_book'] = (string)($profile['active_book'] ?? '');
    $profile['report_settings'] = is_array($profile['report_settings'] ?? null) ? $profile['report_settings'] : [];
    return $profile;
}

function save_profile(array $profile): void
{
    $slug = slugify((string)($profile['slug'] ?? $profile['name'] ?? 'profile'));
    ensure_profile_dirs($slug);
    $profile['slug'] = $slug;
    $profile['updated_at'] = date(DATE_ATOM);
    json_write_file(profile_json_path($slug), $profile);
}

function get_profile(string $slug): ?array
{
    $slug = slugify($slug);
    $path = profile_json_path($slug);
    if (!is_file($path)) {
        return null;
    }
    $profile = json_read_file($path, null);
    return is_array($profile) ? normalize_profile($profile, $slug) : null;
}

function list_profiles(): array
{
    ensure_runtime_dirs();
    $items = [];
    foreach (glob(profiles_root() . '/*/profile.json') ?: [] as $path) {
        $slug = basename(dirname($path));
        $profile = json_read_file($path, []);
        if (is_array($profile)) {
            $items[] = normalize_profile($profile, $slug);
        }
    }
    usort($items, fn($a, $b) => strnatcasecmp((string)$a['name'], (string)$b['name']));
    return $items;
}

function active_profile_slug(): ?string
{
    $profiles = list_profiles();
    if (!$profiles) {
        return null;
    }
    $configured = slugify((string)app_config('active_profile', ''));
    if ($configured !== '') {
        foreach ($profiles as $profile) {
            if (($profile['slug'] ?? '') === $configured) {
                return $configured;
            }
        }
    }
    return (string)$profiles[0]['slug'];
}

function active_profile(): ?array
{
    $slug = active_profile_slug();
    return $slug ? get_profile($slug) : null;
}

function book_entry_path(array $profile, string $file): string
{
    return profile_dir((string)$profile['slug']) . '/books/' . basename($file);
}

function active_book_path(?array $profile = null): string
{
    $profile = $profile ?: active_profile();
    if (is_array($profile)) {
        $active = (string)($profile['active_book'] ?? '');
        if ($active !== '') {
            $path = book_entry_path($profile, $active);
            if (is_file($path)) {
                return $path;
            }
        }
        foreach (($profile['books'] ?? []) as $book) {
            $file = (string)($book['file'] ?? '');
            if ($file !== '') {
                $path = book_entry_path($profile, $file);
                if (is_file($path)) {
                    return $path;
                }
            }
        }
    }
    $legacy = (string)app_config('gnucash_book_path', '');
    return is_file($legacy) ? $legacy : '';
}

function current_book_path(): string
{
    return active_book_path(active_profile());
}


function profile_report_settings(?array $profile = null): array
{
    $profile = $profile ?: active_profile();
    $defaultName = is_array($profile) ? (string)($profile['name'] ?? '') : '';
    $settings = is_array($profile) ? (array)($profile['report_settings'] ?? []) : [];
    return array_replace([
        'organization_name' => $defaultName,
        'footer_text' => '',
        'page_size' => 'Letter',
        'include_zero_balance' => true,
        'style_reference_file' => '',
        'logo_file' => '',
        'custom_css' => '',
    ], $settings);
}

function report_asset_path(string $file, ?array $profile = null): string
{
    $profile = $profile ?: active_profile();
    if (!is_array($profile)) {
        return '';
    }
    $file = basename($file);
    if ($file === '') {
        return '';
    }
    return profile_dir((string)$profile['slug']) . '/report-assets/' . $file;
}

function reports_batch_dir(?string $batch = null, ?array $profile = null): string
{
    $profile = $profile ?: active_profile();
    if (!is_array($profile)) {
        return BASE_PATH . '/var/generated';
    }
    $base = profile_dir((string)$profile['slug']) . '/reports/customer-reports';
    return $batch === null || $batch === '' ? $base : $base . '/' . basename($batch);
}

function require_profile_configured(): array
{
    $profile = active_profile();
    if (!$profile) {
        flash('warn', 'Create your first entity/profile before creating invoices.');
        redirect_to('setup_profile');
    }
    return $profile;
}

function require_book_configured(): string
{
    require_profile_configured();
    $book = current_book_path();
    if ($book === '' || !is_file($book)) {
        flash('error', 'Upload or select a readable GnuCash book copy for this entity first.');
        redirect_to('config');
    }
    return $book;
}

function allowed_book_extension(string $name): bool
{
    $lower = strtolower($name);
    foreach (['.gnucash', '.sqlite', '.sqlite3', '.db', '.db3', '.xml', '.xac', '.gz'] as $suffix) {
        if (str_ends_with($lower, $suffix)) {
            return true;
        }
    }
    return false;
}

function store_uploaded_book(string $field, array $profile): array
{
    $err = (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE);
    if (empty($_FILES[$field]) || $err !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload a GnuCash book copy. ' . upload_error_message($err));
    }
    $original = basename((string)$_FILES[$field]['name']);
    if (!allowed_book_extension($original)) {
        throw new RuntimeException('Unsupported GnuCash book upload extension. Use .gnucash, .sqlite, .sqlite3, .db, .db3, .xml, .xac, or .gz.');
    }
    ensure_profile_dirs((string)$profile['slug']);
    $booksDir = profile_dir((string)$profile['slug']) . '/books';
    if (!is_dir($booksDir) || !is_writable($booksDir)) {
        throw new RuntimeException('Unable to store uploaded GnuCash book: profile books directory is not writable: ' . $booksDir);
    }
    $stored = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '-' . slugify($original);
    $target = $booksDir . '/' . $stored;
    $tmp = (string)$_FILES[$field]['tmp_name'];
    if (!is_uploaded_file($tmp)) {
        throw new RuntimeException('Unable to store uploaded GnuCash book: PHP did not provide a valid uploaded temporary file.');
    }
    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('Unable to store uploaded GnuCash book. Check PHP-FPM/nginx user write access to: ' . $booksDir);
    }
    apply_runtime_permissions($target);
    return [
        'file' => $stored,
        'original_name' => $original,
        'title' => preg_replace('/\.(gnucash|sqlite3?|db3?|xml|xac|gz)$/i', '', $original) ?: $original,
        'uploaded_at' => date(DATE_ATOM),
        'size_bytes' => filesize($target) ?: 0,
    ];
}

function recursive_delete(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    $items = scandir($path);
    if ($items === false) {
        throw new RuntimeException('Unable to read directory for deletion: ' . $path);
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        recursive_delete($path . '/' . $item);
    }
    rmdir($path);
}

function app_asset(string $path): string
{
    $path = ltrim($path, '/');
    if (defined('APP_ASSET_BASE')) {
        $base = rtrim((string)APP_ASSET_BASE, '/');
        return $base === '' ? $path : $base . '/' . $path;
    }
    return $path;
}

function render_header(string $title): void
{
    $profiles = list_profiles();
    $profile = active_profile();
    $book = current_book_path();
    $configured = $book !== '' && is_file($book);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . ' - ' . h(APP_NAME) . '</title>';
    echo '<link rel="stylesheet" href="' . h(app_asset('assets/app.css')) . '">';
    echo '</head><body><header class="top"><div><a class="brand" href="?">' . h(APP_NAME) . '</a><span class="version">v' . h(APP_VERSION) . '</span>';
    if ($profile) {
        echo '<span class="entity">' . h($profile['name']) . '</span>';
    }
    echo '</div><nav><a href="?">Home</a><a href="?action=wizard">Batch Wizard</a><a href="?action=groups">Groups</a><a href="?action=templates">Templates</a><a href="?action=reports">Reports</a><a href="?action=config">Settings</a></nav></header>';
    echo '<main class="wrap">';
    foreach (pop_flashes() as $message) {
        echo '<div class="flash ' . h($message['type']) . '">' . h($message['message']) . '</div>';
    }
    if (runtime_has_warnings()) {
        echo '<div class="flash warn">Runtime writable checks have warnings. Open Settings → Runtime checks if uploads or generation fail.</div>';
    }
    if (!$profiles) {
        echo '<div class="flash warn">No entity/profile has been configured yet. Create the first entity to continue.</div>';
    } elseif (!$configured) {
        echo '<div class="flash warn">Upload or select a readable GnuCash book copy for the active entity before generating invoices.</div>';
    }
    echo '<h1>' . h($title) . '</h1>';
}

function render_footer(): void
{
    echo '</main><footer class="foot">GPL-3.0-or-later. Use on localhost or a trusted internal network only.</footer></body></html>';
}

ensure_runtime_dirs();
