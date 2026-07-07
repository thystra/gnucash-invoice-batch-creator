<?php
/**
 * GnuCash Invoice Batch Creator
 * Copyright (C) 2026 Alan Johnson / contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$action = $_GET['action'] ?? 'home';

try {
    match ($action) {
        'home' => page_home(),
        'help' => page_help(),
        'setup_profile' => page_setup_profile(),
        'create_profile' => action_create_profile(),
        'config' => page_config(),
        'save_config' => action_save_config(),
        'upload_book' => action_upload_book(),
        'set_active_book' => action_set_active_book(),
        'delete_book' => action_delete_book(),
        'delete_profile' => action_delete_profile(),
        'wizard' => page_wizard(),
        'scan_upload' => action_scan_upload(),
        'select_book_customers' => action_select_book_customers(),
        'generate' => action_generate(),
        'groups' => page_groups(),
        'save_group' => action_save_group(),
        'templates' => page_templates(),
        'reports' => page_reports(),
        'save_report_settings' => action_save_report_settings(),
        'generate_reports' => action_generate_reports(),
        'download_report_zip' => action_download_report_zip(),
        'delete_report_batch' => action_delete_report_batch(),
        'clean_reports' => action_clean_reports(),
        'delete_group' => action_delete_named(profile_data_dir('groups'), 'groups'),
        'delete_template' => action_delete_named(profile_data_dir('templates'), 'templates'),
        'download' => action_download(),
        default => page_not_found(),
    };
} catch (Throwable $e) {
    render_header('Error');
    echo '<div class="flash error">' . h($e->getMessage()) . '</div>';
    echo '<pre>' . h($e->getTraceAsString()) . '</pre>';
    render_footer();
}

function page_help(): void
{
    render_header('Help / Instructions');
    echo '<section class="card"><h2>Batch invoice workflow</h2>';
    echo '<ol>';
    echo '<li>Create or select the correct entity/profile.</li>';
    echo '<li>Upload a copy of that entity\'s GnuCash book.</li>';
    echo '<li>Open Batch Wizard and select customers from the scanned customer list, or load a saved group.</li>';
    echo '<li>Choose invoice values. Account fields should be selected from the accounts scanned from the active book.</li>';
    echo '<li>Generate and download the CSV.</li>';
    echo '<li>Import the CSV into GnuCash, save the revised book, then upload the revised copy if you want batch customer reports.</li>';
    echo '</ol></section>';

    echo '<section class="card"><h2>GnuCash import steps</h2>';
    echo '<ol>';
    echo '<li>In GnuCash, open <strong>File → Import → Import Bills &amp; Invoices…</strong>.</li>';
    echo '<li>Choose the generated CSV file.</li>';
    echo '<li>Set import type to <strong>Invoice</strong>.</li>';
    echo '<li>For CSV format, choose <strong>Comma separated with quotes</strong> when this tool is configured for comma output. Use the semicolon option only if Settings uses semicolon output.</li>';
    echo '<li>Set the date format in this tool to match your GnuCash Preferences. Common values are <code>m/d/Y</code> for MM/DD/YYYY and <code>d/m/Y</code> for DD/MM/YYYY.</li>';
    echo '<li>Prices and discounts are exported as fixed two-decimal values such as <code>15.00</code>, which avoids GnuCash interpreting whole-dollar values as cents under some decimal settings.</li>';
    echo '<li>In the Afterwards step, choose <strong>Do not open imported documents in tabs</strong>. Opening a large batch of invoice tabs can make GnuCash very slow or unstable.</li>';
    echo '<li>Review the preview carefully. If GnuCash reports an invalid date or invalid account, fix the tool settings/template and regenerate the CSV before importing again.</li>';
    echo '</ol></section>';

    echo '<section class="card"><h2>CSV column reference</h2>';
    echo '<p>The generated file uses GnuCash\'s invoice import column order:</p>';
    echo '<pre>id, date_opened, owner_id, billingid, notes, date, desc, action, account, quantity, price, disc_type, disc_how, discount, taxable, taxincluded, tax_table, date_posted, due_date, account_posted, memo_posted, accu_splits</pre>';
    echo '<p class="help"><code>owner_id</code> is the customer ID from the GnuCash customers table. <code>account</code> must be the income account for the invoice line. <code>account_posted</code> must be an Accounts Receivable account when posting customer invoices.</p>';
    echo '</section>';
    render_footer();
}

function page_home(): void
{
    if (!list_profiles()) {
        page_setup_profile();
        return;
    }

    render_header('Home');
    $profile = active_profile();
    $book = current_book_path();
    echo '<div class="grid">';
    echo '<section class="card"><h2>Create a batch</h2><p>Select customers directly from the active GnuCash book, choose invoice parameters, and generate a GnuCash invoice import CSV for the active entity.</p><p><a class="button" href="?action=wizard">Open batch wizard</a></p></section>';
    echo '<section class="card"><h2>Active entity</h2><p><strong>' . h($profile['name'] ?? '(none)') . '</strong></p><p><strong>Book copy:</strong><br>' . h($book ?: '(none selected)') . '</p><p><a class="button secondary" href="?action=config">Manage entities/books</a></p></section>';
    echo '<section class="card"><h2>Saved groups</h2><p>Reuse a customer group for monthly dues or repeated billing runs. Groups are stored per entity.</p><p><a class="button secondary" href="?action=groups">Manage groups</a></p></section>';
    echo '<section class="card"><h2>Templates</h2><p>Reuse descriptions, accounts, dates, posting settings, taxes, and price levels. Templates are stored per entity.</p><p><a class="button secondary" href="?action=templates">Manage templates</a></p></section>';
    echo '<section class="card"><h2>Customer reports</h2><p>Generate customer statement PDFs from a revised uploaded GnuCash book, using saved groups and Chromium PDF rendering.</p><p><a class="button secondary" href="?action=reports">Batch reports</a></p></section>';
    echo '<section class="card"><h2>Book scan</h2>';
    if ($book !== '' && is_file($book)) {
        $result = run_python(['scan-book', '--book', $book, '--prefix', (string)app_config('id_prefix', ''), '--padding', (string)app_config('id_padding', 0)]);
        if ($result['ok'] && is_array($result['json'])) {
            echo '<p><span class="badge">Customers: ' . h($result['json']['customer_count'] ?? 0) . '</span> <span class="badge">Next invoice: ' . h($result['json']['next_invoice_id'] ?? '') . '</span></p>';
        } else {
            echo '<div class="flash error">Unable to scan configured book. ' . h($result['stderr'] ?: $result['stdout']) . '</div>';
        }
    } else {
        echo '<p>No active book copy has been selected for this entity yet.</p>';
    }
    echo '</section>';
    echo '</div>';
    render_footer();
}

function page_setup_profile(): void
{
    render_header('First Entity Setup');
    echo '<section class="card"><h2>Set up your first entity/profile</h2>';
    echo '<p>Create an entity such as ORG A, ORG B, or ENTITY A LLC. Each entity keeps its own uploaded GnuCash book copies, customer groups, generated CSV files, invoice templates, and report output.</p>';
    render_runtime_checks_compact();
    echo '<form method="post" enctype="multipart/form-data" action="?action=create_profile">' . csrf_field();
    echo '<input type="hidden" name="after_create" value="wizard">';
    echo '<div class="grid">';
    field_text('profile_name', 'Entity/profile name', '', 'Example: ORG A, ORG B, ENTITY A LLC');
    echo '<div><label for="book_file">Initial GnuCash book copy</label><input type="file" id="book_file" name="book_file" accept=".gnucash,.sqlite,.sqlite3,.db,.db3,.xml,.xac,.gz" required><div class="help">Upload a copy of the book. Runtime uploads are ignored by git.</div></div>';
    echo '</div>';
    echo '<div class="actions"><button type="submit">Create entity and continue</button><a class="button secondary" href="?action=config">Open settings</a></div>';
    echo '</form></section>';
    render_footer();
}

function page_config(): void
{
    render_header('Settings');
    $cfg = app_config();
    $profiles = list_profiles();
    $active = active_profile();

    render_runtime_checks_full();

    echo '<section class="card"><h2>Global defaults</h2>';
    echo '<form method="post" action="?action=save_config">' . csrf_field();
    echo '<div class="grid">';
    if ($profiles) {
        echo '<div><label for="active_profile">Active entity/profile</label><select id="active_profile" name="active_profile">';
        foreach ($profiles as $profile) {
            $selected = (($active['slug'] ?? '') === ($profile['slug'] ?? '')) ? ' selected' : '';
            echo '<option value="' . h($profile['slug']) . '"' . $selected . '>' . h($profile['name']) . '</option>';
        }
        echo '</select><div class="help">The wizard, groups, templates, uploads, and generated CSV files use the active entity.</div></div>';
    }
    field_text('python_bin', 'Python binary', (string)$cfg['python_bin'], 'Usually /usr/bin/python3.');
    render_chromium_field((string)($cfg['chromium_bin'] ?? '/snap/bin/chromium'));
    render_default_account_fields($cfg);
    field_text('default_action', 'Default invoice action', (string)$cfg['default_action'], 'Common values: ea, hour, day, material.');
    field_text('default_tax_table', 'Default tax table', (string)$cfg['default_tax_table'], 'Leave blank for no tax table.');
    field_text('id_prefix', 'Invoice ID prefix', (string)$cfg['id_prefix'], 'Optional. Example: DUES-');
    field_number('id_padding', 'Invoice ID numeric padding', (int)$cfg['id_padding'], '0 preserves detected width or uses no padding.');
    field_text('csv_delimiter', 'CSV delimiter', (string)$cfg['csv_delimiter'], 'Use comma or semicolon.');
    field_text('csv_date_format', 'GnuCash import date format', (string)$cfg['csv_date_format'], 'Must match GnuCash Preferences. Common values: m/d/Y for MM/DD/YYYY, d/m/Y for DD/MM/YYYY, Y-m-d for YYYY-MM-DD.');
    field_text('timezone', 'Timezone', (string)$cfg['timezone'], 'Example: America/New_York');
    echo '</div>';
    echo '<label class="check"><input type="checkbox" name="default_posted" value="1" ' . ((bool)$cfg['default_posted'] ? 'checked' : '') . '> Post invoices by default</label>';
    echo '<label class="check"><input type="checkbox" name="default_taxable" value="1" ' . ((bool)$cfg['default_taxable'] ? 'checked' : '') . '> Taxable by default</label>';
    echo '<label class="check"><input type="checkbox" name="default_taxincluded" value="1" ' . ((bool)$cfg['default_taxincluded'] ? 'checked' : '') . '> Tax included by default</label>';
    echo '<div class="actions"><button type="submit">Save settings</button>';
    if (current_book_path() !== '') {
        echo '<a class="button secondary" href="?action=config&scan=1">Scan active book now</a>';
    }
    echo '</div></form></section>';

    echo '<section class="card"><h2>Create another entity/profile</h2>';
    echo '<form method="post" enctype="multipart/form-data" action="?action=create_profile">' . csrf_field();
    echo '<input type="hidden" name="after_create" value="config">';
    echo '<div class="grid">';
    field_text('profile_name', 'Entity/profile name', '', 'Example: ORG B or ENTITY A LLC');
    echo '<div><label for="book_file_new">Initial GnuCash book copy</label><input type="file" id="book_file_new" name="book_file" accept=".gnucash,.sqlite,.sqlite3,.db,.db3,.xml,.xac,.gz" required><div class="help">Upload a copy of this entity\'s book.</div></div>';
    echo '</div><div class="actions"><button type="submit">Create entity</button></div></form></section>';

    if ($profiles) {
        render_profiles_manager($profiles);
    }

    if (isset($_GET['scan'])) {
        render_book_scan_result();
    }

    render_footer();
}

function render_profiles_manager(array $profiles): void
{
    $active = active_profile();
    echo '<section class="card"><h2>Entities / profiles</h2>';
    echo '<table><tr><th>Name</th><th>Books</th><th>Active book</th><th>Actions</th></tr>';
    foreach ($profiles as $profile) {
        $bookCount = count($profile['books'] ?? []);
        $isActive = (($active['slug'] ?? '') === ($profile['slug'] ?? ''));
        $activeBook = (string)($profile['active_book'] ?? '');
        echo '<tr><td><strong>' . h($profile['name']) . '</strong>' . ($isActive ? ' <span class="badge">active</span>' : '') . '</td><td>' . h($bookCount) . '</td><td>' . h($activeBook ?: '(none)') . '</td><td>';
        echo '<form class="inline" method="post" action="?action=delete_profile" onsubmit="return confirm(\'Delete this profile and all of its uploaded books, groups, templates, uploads, and generated CSVs?\')">' . csrf_field() . '<input type="hidden" name="slug" value="' . h($profile['slug']) . '"><button class="danger" type="submit">Delete profile</button></form>';
            echo '</td></tr>';
        }
        echo '</table>';
    echo '</section>';

    if ($active) {
        echo '<section class="card"><h2>Books for active entity: ' . h($active['name']) . '</h2>';
        echo '<form method="post" enctype="multipart/form-data" action="?action=upload_book">' . csrf_field();
        echo '<div class="grid"><div><label for="book_file_more">Upload another GnuCash book copy</label><input type="file" id="book_file_more" name="book_file" accept=".gnucash,.sqlite,.sqlite3,.db,.db3,.xml,.xac,.gz" required><div class="help">Use this when you have a refreshed copy of the book. The newest upload becomes active automatically.</div></div></div>';
        echo '<div class="actions"><button type="submit">Upload book copy</button></div></form>';

        $books = $active['books'] ?? [];
        if (!$books) {
            echo '<p>No uploaded book copies for this entity.</p>';
        } else {
            echo '<table><tr><th>Original file</th><th>Stored file</th><th>Uploaded</th><th>Size</th><th>Actions</th></tr>';
            foreach ($books as $book) {
                $file = (string)($book['file'] ?? '');
                $isActiveBook = $file !== '' && $file === (string)($active['active_book'] ?? '');
                echo '<tr><td>' . h($book['original_name'] ?? $file) . ($isActiveBook ? ' <span class="badge">active</span>' : '') . '</td><td><code>' . h($file) . '</code></td><td>' . h(substr((string)($book['uploaded_at'] ?? ''), 0, 19)) . '</td><td>' . h(number_format((int)($book['size_bytes'] ?? 0))) . ' bytes</td><td>';
                if (!$isActiveBook && $file !== '') {
                    echo '<form class="inline" method="post" action="?action=set_active_book">' . csrf_field() . '<input type="hidden" name="file" value="' . h($file) . '"><button class="secondary" type="submit">Make active</button></form> ';
                }
                if ($file !== '') {
                    echo '<form class="inline" method="post" action="?action=delete_book" onsubmit="return confirm(\'Delete this stored book copy?\')">' . csrf_field() . '<input type="hidden" name="file" value="' . h($file) . '"><button class="danger" type="submit">Delete book</button></form>';
                }
                echo '</td></tr>';
            }
            echo '</table>';
        }
        echo '</section>';
    }
}

function render_book_scan_result(): void
{
    echo '<section class="card"><h2>Book scan result</h2>';
    $book = current_book_path();
    if ($book === '') {
        echo '<div class="flash error">No active book is configured.</div></section>';
        return;
    }
    $result = run_python(['scan-book', '--book', $book, '--prefix', (string)app_config('id_prefix', ''), '--padding', (string)app_config('id_padding', 0)]);
    if ($result['ok'] && is_array($result['json'])) {
        echo '<p><strong>Active book:</strong> ' . h($book) . '</p>';
        echo '<p><strong>Customers:</strong> ' . h($result['json']['customer_count'] ?? 0) . ' total; ' . h($result['json']['active_customer_count'] ?? 0) . ' active; ' . h($result['json']['inactive_customer_count'] ?? 0) . ' inactive</p>';
        echo '<p><strong>Existing invoice IDs:</strong> ' . h($result['json']['invoice_count'] ?? 0) . '</p>';
        echo '<p><strong>Suggested next invoice ID:</strong> ' . h($result['json']['next_invoice_id'] ?? '') . '</p>';
        echo '<details><summary>First 25 customers</summary><table><tr><th>ID</th><th>Name</th><th>Active</th></tr>';
        foreach (array_slice($result['json']['customers'] ?? [], 0, 25) as $customer) {
            echo '<tr><td>' . h($customer['id'] ?? '') . '</td><td>' . h($customer['name'] ?? '') . '</td><td>' . (php_customer_is_active($customer) ? 'active' : 'inactive') . '</td></tr>';
        }
        echo '</table></details>';
    } else {
        echo '<div class="flash error">Scan failed.</div><pre>' . h(($result['stderr'] ?? '') . "\n" . ($result['stdout'] ?? '')) . '</pre>';
    }
    echo '</section>';
}

function posted_text_value(string $key, string $fallback): string
{
    if (!array_key_exists($key, $_POST)) {
        return $fallback;
    }
    $value = trim((string)$_POST[$key]);
    return $value !== '' ? $value : $fallback;
}

function action_save_config(): never
{
    check_csrf();
    ensure_runtime_dirs();
    $current = app_config();
    $activeProfile = posted_text_value('active_profile', (string)($current['active_profile'] ?? ''));
    $postedChromium = posted_text_value('chromium_bin', (string)($current['chromium_bin'] ?? '/snap/bin/chromium'));
    $chromiumDetection = detect_chromium_binary($postedChromium);
    $chromiumToStore = !empty($chromiumDetection['ok']) ? (string)$chromiumDetection['path'] : $postedChromium;

    // Preserve existing keys that may have been added by newer versions or local
    // customization. Only replace fields that are actually controlled by this form.
    // For account fields, never fall back to hard-coded defaults when the browser
    // submits an empty value; keep the current saved value instead.
    $changes = [
        'active_profile' => $activeProfile,
        'gnucash_book_path' => (string)($current['gnucash_book_path'] ?? ''),
        'python_bin' => posted_text_value('python_bin', (string)($current['python_bin'] ?? '/usr/bin/python3')),
        'chromium_bin' => $chromiumToStore,
        'default_income_account' => posted_text_value('default_income_account', (string)($current['default_income_account'] ?? 'Income:Dues')),
        'default_ar_account' => posted_text_value('default_ar_account', (string)($current['default_ar_account'] ?? 'Assets:Accounts Receivable')),
        'default_posted' => isset($_POST['default_posted']),
        'default_action' => posted_text_value('default_action', (string)($current['default_action'] ?? 'ea')),
        'default_taxable' => isset($_POST['default_taxable']),
        'default_taxincluded' => isset($_POST['default_taxincluded']),
        'default_tax_table' => trim((string)($_POST['default_tax_table'] ?? (string)($current['default_tax_table'] ?? ''))),
        'id_prefix' => trim((string)($_POST['id_prefix'] ?? (string)($current['id_prefix'] ?? ''))),
        'id_padding' => max(0, (int)($_POST['id_padding'] ?? (int)($current['id_padding'] ?? 0))),
        'csv_delimiter' => in_array(($_POST['csv_delimiter'] ?? ($current['csv_delimiter'] ?? ',')), [',', ';'], true) ? (string)($_POST['csv_delimiter'] ?? $current['csv_delimiter'] ?? ',') : ',',
        'csv_date_format' => posted_text_value('csv_date_format', (string)($current['csv_date_format'] ?? 'm/d/Y')),
        'timezone' => posted_text_value('timezone', (string)($current['timezone'] ?? 'America/New_York')),
    ];
    $new = array_replace($current, $changes);
    write_config($new);

    $accountMessage = ' Default income account: ' . $changes['default_income_account'] . '; default A/R account: ' . $changes['default_ar_account'] . '.';
    if (!empty($chromiumDetection['ok']) && $postedChromium !== $chromiumToStore) {
        flash('warn', 'Settings saved, but Chromium was not executable at the configured path. Saved detected Chromium path: ' . $chromiumToStore . '.' . $accountMessage);
    } elseif (empty($chromiumDetection['ok'])) {
        flash('warn', 'Settings saved, but Chromium was not found. Customer Report PDFs will fail until Chromium is installed or the correct path is set.' . $accountMessage);
    } else {
        flash('ok', 'Settings saved.' . $accountMessage);
    }
    redirect_to('config');
}

function action_create_profile(): never
{
    check_csrf();
    ensure_runtime_dirs();
    $name = trim((string)($_POST['profile_name'] ?? ''));
    if ($name === '') {
        flash('error', 'Enter an entity/profile name.');
        redirect_to(list_profiles() ? 'config' : 'setup_profile');
    }
    $baseSlug = slugify($name);
    $slug = $baseSlug;
    $suffix = 2;
    while (get_profile($slug) !== null) {
        $slug = $baseSlug . '-' . $suffix;
        $suffix++;
    }
    $profile = normalize_profile([
        'slug' => $slug,
        'name' => $name,
        'created_at' => date(DATE_ATOM),
        'books' => [],
        'active_book' => '',
    ], $slug);
    ensure_profile_dirs($slug);
    try {
        $book = store_uploaded_book('book_file', $profile);
    } catch (Throwable $e) {
        recursive_delete(profile_dir($slug));
        flash('error', $e->getMessage());
        redirect_to(list_profiles() ? 'config' : 'setup_profile');
    }
    $profile['books'][] = $book;
    $profile['active_book'] = $book['file'];
    save_profile($profile);
    update_config(['active_profile' => $slug]);
    unset($_SESSION['batch_scan'], $_SESSION['last_generate']);
    flash('ok', 'Created entity/profile: ' . $name . '. The wizard will scan the uploaded book and list active customers.');
    $after = (string)($_POST['after_create'] ?? 'config');
    redirect_to($after === 'wizard' ? 'wizard' : 'config');
}

function action_upload_book(): never
{
    check_csrf();
    $profile = require_profile_configured();
    $book = store_uploaded_book('book_file', $profile);
    $profile['books'][] = $book;
    $profile['active_book'] = $book['file'];
    save_profile($profile);
    unset($_SESSION['batch_scan']);
    flash('ok', 'Uploaded and activated GnuCash book copy: ' . ($book['original_name'] ?? $book['file']) . '. Open the Batch Wizard to select customers from the book.');
    redirect_to('config');
}

function action_set_active_book(): never
{
    check_csrf();
    $profile = require_profile_configured();
    $file = basename((string)($_POST['file'] ?? ''));
    $found = false;
    foreach (($profile['books'] ?? []) as $book) {
        if (($book['file'] ?? '') === $file) {
            $found = true;
            break;
        }
    }
    if (!$found || !is_file(book_entry_path($profile, $file))) {
        flash('error', 'Book copy not found for this entity.');
        redirect_to('config');
    }
    $profile['active_book'] = $file;
    save_profile($profile);
    unset($_SESSION['batch_scan']);
    flash('ok', 'Active book copy changed.');
    redirect_to('config');
}

function action_delete_book(): never
{
    check_csrf();
    $profile = require_profile_configured();
    $file = basename((string)($_POST['file'] ?? ''));
    $books = [];
    foreach (($profile['books'] ?? []) as $book) {
        if (($book['file'] ?? '') === $file) {
            $path = book_entry_path($profile, $file);
            if (is_file($path)) {
                unlink($path);
            }
            continue;
        }
        $books[] = $book;
    }
    $profile['books'] = $books;
    if (($profile['active_book'] ?? '') === $file) {
        $profile['active_book'] = (string)($books[0]['file'] ?? '');
    }
    save_profile($profile);
    unset($_SESSION['batch_scan']);
    flash('ok', 'Deleted stored book copy.');
    redirect_to('config');
}

function action_delete_profile(): never
{
    check_csrf();
    $slug = slugify((string)($_POST['slug'] ?? ''));
    if ($slug === '' || get_profile($slug) === null) {
        flash('error', 'Profile not found.');
        redirect_to('config');
    }
    recursive_delete(profile_dir($slug));
    if ((string)app_config('active_profile', '') === $slug) {
        $remaining = list_profiles();
        update_config(['active_profile' => (string)($remaining[0]['slug'] ?? '')]);
    }
    unset($_SESSION['batch_scan'], $_SESSION['last_generate']);
    flash('ok', 'Deleted entity/profile and its stored data.');
    redirect_to(list_profiles() ? 'config' : 'setup_profile');
}

function php_customer_is_active(array $customer): bool
{
    if (array_key_exists('active_bool', $customer)) {
        $flag = filter_var($customer['active_bool'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($flag !== null) {
            return $flag;
        }
        if (is_bool($customer['active_bool'])) {
            return $customer['active_bool'];
        }
    }
    $raw = strtolower(trim((string)($customer['active'] ?? '')));
    return !in_array($raw, ['0', 'false', 'no', 'n', 'inactive'], true);
}

function active_status_label(array $customer): string
{
    return php_customer_is_active($customer) ? 'active' : 'inactive';
}

function scan_book_for_wizard(string $book): array
{
    return run_python(['scan-book', '--book', $book, '--prefix', (string)app_config('id_prefix', ''), '--padding', (string)app_config('id_padding', 0)]);
}


function account_options_from_scan(array $scanJson, string $key): array
{
    $items = is_array($scanJson[$key] ?? null) ? $scanJson[$key] : [];
    usort($items, fn($a, $b) => strnatcasecmp((string)($a['full_name'] ?? $a['name'] ?? ''), (string)($b['full_name'] ?? $b['name'] ?? '')));
    return $items;
}

function render_account_select(string $name, string $label, string $value, array $accounts, string $help = ''): void
{
    $listId = $name . '_account_suggestions';
    $known = [];
    foreach ($accounts as $account) {
        $full = (string)($account['full_name'] ?? $account['name'] ?? '');
        if ($full !== '') {
            $known[$full] = (string)($account['account_type'] ?? '');
        }
    }

    echo '<div><label for="' . h($name) . '">' . h($label) . '</label>';
    echo '<input type="text" id="' . h($name) . '" name="' . h($name) . '" value="' . h($value) . '" list="' . h($listId) . '" autocomplete="off">';
    echo '<datalist id="' . h($listId) . '">';
    foreach ($known as $full => $type) {
        $labelText = $type !== '' ? $full . ' [' . $type . ']' : $full;
        echo '<option value="' . h($full) . '" label="' . h($labelText) . '"></option>';
    }
    echo '</datalist>';

    $extra = '';
    if (!$accounts) {
        $extra = ' No matching accounts were detected in the active book; enter the full GnuCash account path manually.';
    } elseif ($value !== '' && !isset($known[$value])) {
        $extra = ' Current configured value was not detected in the active book; it will still be submitted unless changed.';
    } else {
        $extra = ' You may select a scanned account or type the exact GnuCash account path.';
    }
    if ($help !== '' || $extra !== '') {
        echo '<div class="help">' . h(trim($help . ' ' . $extra)) . '</div>';
    }
    echo '</div>';
}

function render_default_account_fields(array $cfg): void
{
    $book = current_book_path();
    $scanJson = [];
    if ($book !== '' && is_file($book)) {
        $scan = scan_book_for_wizard($book);
        if ($scan['ok'] && is_array($scan['json'])) {
            $scanJson = $scan['json'];
        }
    }
    render_account_select('default_income_account', 'Default income account', (string)$cfg['default_income_account'], account_options_from_scan($scanJson, 'income_accounts'), 'Used for invoice line items. Suggestions are scanned from the active book.');
    render_account_select('default_ar_account', 'Default A/R account', (string)$cfg['default_ar_account'], account_options_from_scan($scanJson, 'receivable_accounts'), 'Used as account_posted for posted invoices. Suggestions are scanned from the active book.');
}

function render_saved_groups_input(array $groups, bool $open): void
{
    echo '<section class="card"><details' . ($open ? ' open' : '') . '><summary><strong>Saved groups and alternate input</strong></summary>';
    echo '<p class="help">Saved groups are the fastest path for repeat monthly runs. Uploading a separate customer-ID file remains available as a fallback for external rosters, but is no longer required.</p>';
    echo '<form method="post" enctype="multipart/form-data" action="?action=scan_upload">' . csrf_field();
    if ($groups) {
        echo '<label>Load saved group for this entity</label><select name="saved_group"><option value="">-- do not load a group --</option>';
        foreach ($groups as $group) {
            echo '<option value="' . h($group['slug']) . '">' . h($group['name']) . ' (' . h(count($group['data']['customer_ids'] ?? [])) . ' customers)</option>';
        }
        echo '</select>';
    } else {
        echo '<p>No saved groups exist for this entity yet. Select customers from the active book, then save the group in the invoice parameters step.</p>';
    }
    echo '<details><summary>Optional: upload a customer ID file instead</summary>';
    echo '<label>Upload customer ID file</label><input type="file" name="customer_file" accept=".csv,.txt,.tsv,.xlsx">';
    echo '<div class="help">CSV/text/XLSX. A recognized customer ID column is best, but exact ID matching is also attempted.</div>';
    echo '</details>';
    echo '<div class="actions"><button type="submit">Load saved group / scan uploaded file</button></div></form></details></section>';
}

function page_wizard(): void
{
    $book = require_book_configured();
    $profile = active_profile();
    render_header('Batch Wizard');
    echo '<p class="muted">Active entity: <strong>' . h($profile['name'] ?? '') . '</strong>. Active book copy: <code>' . h(basename($book)) . '</code>.</p>';

    $groups = list_named_json(profile_data_dir('groups'));
    $hasSelection = !empty($_SESSION['batch_scan']);
    $showInactive = (string)($_GET['show_inactive'] ?? '') === '1';
    $sortMode = in_array(($_GET['sort'] ?? 'id'), ['id', 'name'], true) ? (string)$_GET['sort'] : 'id';
    $bookScan = scan_book_for_wizard($book);

    if ($groups) {
        render_saved_groups_input($groups, true);
    }

    echo '<section class="card"><details' . (!$hasSelection ? ' open' : '') . '><summary><strong>1. Select customers from the active GnuCash book</strong></summary>';
    echo '<p class="help">The wizard reads the <code>customers</code> table from the uploaded GnuCash book copy. Active customers are shown by default. Inactive customers are hidden unless you choose to display them.</p>';

    if (!$bookScan['ok'] || !is_array($bookScan['json'])) {
        echo '<div class="flash error">Unable to scan customers from the active book.<pre>' . h(($bookScan['stderr'] ?? '') . "\n" . ($bookScan['stdout'] ?? '')) . '</pre></div>';
    } else {
        $customers = is_array($bookScan['json']['customers'] ?? null) ? $bookScan['json']['customers'] : [];
        $activeCount = (int)($bookScan['json']['active_customer_count'] ?? count(array_filter($customers, 'php_customer_is_active')));
        $inactiveCount = (int)($bookScan['json']['inactive_customer_count'] ?? max(0, count($customers) - $activeCount));
        $visible = array_values(array_filter($customers, fn($c) => $showInactive || php_customer_is_active($c)));
        usort($visible, function ($a, $b) use ($sortMode) {
            if ($sortMode === 'name') {
                $cmp = strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
                return $cmp !== 0 ? $cmp : strnatcasecmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
            }
            return strnatcasecmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
        });
        echo '<p><span class="badge">Total customers: ' . h(count($customers)) . '</span> <span class="badge">Active: ' . h($activeCount) . '</span> <span class="badge">Inactive: ' . h($inactiveCount) . '</span> <span class="badge">Next invoice: ' . h($bookScan['json']['next_invoice_id'] ?? '') . '</span> <span class="badge">Sort: ' . h($sortMode) . '</span></p>';
        $inactiveParam = $showInactive ? '&show_inactive=1' : '';
        echo '<div class="actions">';
        echo '<a class="button secondary" href="?action=wizard&sort=id' . h($inactiveParam) . '">Sort by ID</a>';
        echo '<a class="button secondary" href="?action=wizard&sort=name' . h($inactiveParam) . '">Sort by name</a>';
        if ($showInactive) {
            echo '<a class="button secondary" href="?action=wizard&sort=' . h($sortMode) . '">Hide inactive customers</a>';
        } else {
            echo '<a class="button secondary" href="?action=wizard&sort=' . h($sortMode) . '&show_inactive=1">Show inactive customers</a>';
        }
        echo '</div>';

        if (!$visible) {
            echo '<div class="flash warn">No customers are visible with the current filter.</div>';
        } else {
            echo '<form method="post" action="?action=select_book_customers">' . csrf_field();
            echo '<div class="table-tools"><button class="secondary" type="button" onclick="document.querySelectorAll(\'.customer-pick\').forEach(cb => cb.checked = true)">Select all visible</button> <button class="secondary" type="button" onclick="document.querySelectorAll(\'.customer-pick\').forEach(cb => cb.checked = false)">Clear visible</button></div>';
            echo '<table class="customer-picker"><tr><th>Select</th><th>ID</th><th>Name</th><th>Status</th></tr>';
            foreach ($visible as $customer) {
                $isActive = php_customer_is_active($customer);
                $checked = $isActive ? ' checked' : '';
                $rowClass = $isActive ? '' : ' class="inactive-row"';
                echo '<tr' . $rowClass . '><td><input class="customer-pick" type="checkbox" name="customer_ids[]" value="' . h($customer['id'] ?? '') . '"' . $checked . '></td><td><code>' . h($customer['id'] ?? '') . '</code></td><td>' . h($customer['name'] ?? '') . '</td><td>' . ($isActive ? '<span class="badge">active</span>' : '<span class="badge bad">inactive</span>') . '</td></tr>';
            }
            echo '</table>';
            echo '<div class="actions"><button type="submit">Continue with selected customers</button></div></form>';
        }
    }
    echo '</details></section>';

    if (!$groups) {
        render_saved_groups_input($groups, false);
    }

    if (!empty($_SESSION['batch_scan'])) {
        render_params_form($_SESSION['batch_scan']);
    }
    render_footer();
}

function action_select_book_customers(): never
{
    check_csrf();
    $book = require_book_configured();
    $ids = $_POST['customer_ids'] ?? [];
    if (!is_array($ids)) {
        $ids = [];
    }
    $ids = array_values(array_filter(array_map('strval', $ids), fn($id) => trim($id) !== ''));
    if (!$ids) {
        flash('error', 'Select at least one customer from the GnuCash book.');
        redirect_to('wizard');
    }
    $result = run_python(
        ['scan-ids', '--book', $book, '--prefix', (string)app_config('id_prefix', ''), '--padding', (string)app_config('id_padding', 0)],
        ['customer_ids' => $ids]
    );
    if (!$result['ok'] || !is_array($result['json'])) {
        flash('error', 'Customer selection failed: ' . trim(($result['stderr'] ?? '') . ' ' . ($result['stdout'] ?? '')));
        redirect_to('wizard');
    }
    $_SESSION['batch_scan'] = $result['json'];
    flash('ok', 'Selected ' . count($result['json']['matched'] ?? []) . ' customers from the GnuCash book.');
    redirect_to('wizard');
}

function action_scan_upload(): never
{
    check_csrf();
    $book = require_book_configured();
    $savedGroup = trim((string)($_POST['saved_group'] ?? ''));

    if ($savedGroup !== '') {
        $path = profile_data_dir('groups') . '/' . basename($savedGroup) . '.json';
        $group = json_read_file($path, null);
        if (!is_array($group) || empty($group['customer_ids'])) {
            flash('error', 'Saved group could not be loaded.');
            redirect_to('wizard');
        }
        $result = run_python(
            ['scan-ids', '--book', $book, '--prefix', (string)app_config('id_prefix', ''), '--padding', (string)app_config('id_padding', 0)],
            ['customer_ids' => $group['customer_ids']]
        );
    } else {
        if (empty($_FILES['customer_file']) || ($_FILES['customer_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('error', 'Choose a saved group, select customers from the GnuCash book above, or upload a customer ID file.');
            redirect_to('wizard');
        }
        $original = basename((string)$_FILES['customer_file']['name']);
        $target = profile_data_dir('uploads') . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '-' . slugify($original);
        if (!move_uploaded_file((string)$_FILES['customer_file']['tmp_name'], $target)) {
            flash('error', 'Unable to store uploaded file. Check runtime directory write permissions.');
            redirect_to('wizard');
        }
        $result = run_python(['scan-upload', '--book', $book, '--input', $target, '--prefix', (string)app_config('id_prefix', ''), '--padding', (string)app_config('id_padding', 0)]);
    }

    if (!$result['ok'] || !is_array($result['json'])) {
        flash('error', 'Customer scan failed: ' . trim(($result['stderr'] ?? '') . ' ' . ($result['stdout'] ?? '')));
        redirect_to('wizard');
    }
    $_SESSION['batch_scan'] = $result['json'];
    flash('ok', 'Customer scan complete. Matched ' . count($result['json']['matched'] ?? []) . ' customers.');
    redirect_to('wizard');
}

function render_params_form(array $scan): void
{
    $cfg = app_config();
    $book = current_book_path();
    $matched = $scan['matched'] ?? [];
    $unmatched = $scan['unmatched'] ?? [];
    $bookScan = run_python(['scan-book', '--book', $book, '--prefix', (string)app_config('id_prefix', ''), '--padding', (string)app_config('id_padding', 0)]);
    $scanJson = ($bookScan['ok'] && is_array($bookScan['json'])) ? $bookScan['json'] : [];
    $suggested = $scanJson['next_invoice_id'] ?? '';
    $templates = list_named_json(profile_data_dir('templates'));
    $incomeAccounts = account_options_from_scan($scanJson, 'income_accounts');
    $receivableAccounts = account_options_from_scan($scanJson, 'receivable_accounts');

    echo '<section class="card"><h2>2. Review selected customers</h2>';
    echo '<p><span class="badge">Matched: ' . h(count($matched)) . '</span> <span class="badge">Unmatched: ' . h(count($unmatched)) . '</span></p>';
    echo '<details><summary>Selected customers</summary><table><tr><th>ID</th><th>Name</th><th>Status</th></tr>';
    foreach ($matched as $customer) {
        $isActive = php_customer_is_active($customer);
        echo '<tr><td>' . h($customer['id'] ?? '') . '</td><td>' . h($customer['name'] ?? '') . '</td><td>' . ($isActive ? '<span class="badge">active</span>' : '<span class="badge bad">inactive</span>') . '</td></tr>';
    }
    echo '</table></details>';
    if ($unmatched) {
        echo '<details><summary>Unmatched uploaded IDs/tokens</summary><pre>' . h(implode("\n", $unmatched)) . '</pre></details>';
    }
    echo '</section>';

    $loadedTemplate = [];
    $loadSlug = isset($_GET['template']) ? basename((string)$_GET['template']) : '';
    if ($loadSlug !== '') {
        $loadedTemplate = json_read_file(profile_data_dir('templates') . '/' . $loadSlug . '.json', []);
    }
    $params = $loadedTemplate['params'] ?? [];
    $today = date('Y-m-d');

    echo '<section class="card"><h2>3. Invoice parameters</h2>';
    echo '<p class="help">Date fields use browser dates here, then the CSV generator formats them as <code>' . h((string)app_config('csv_date_format', 'm/d/Y')) . '</code> for GnuCash import.</p>';
    if ($templates) {
        echo '<form method="get" class="actions"><input type="hidden" name="action" value="wizard"><label style="margin:0">Load template <select name="template"><option value="">-- select --</option>';
        foreach ($templates as $template) {
            echo '<option value="' . h($template['slug']) . '">' . h($template['name']) . '</option>';
        }
        echo '</select></label><button class="secondary" type="submit">Load</button></form>';
    }

    echo '<form method="post" action="?action=generate">' . csrf_field();
    echo '<div class="grid">';
    field_text('start_invoice_id', 'Starting invoice ID', (string)($params['start_invoice_id'] ?? $suggested), 'Override if needed. Existing invoice IDs are not overwritten by this tool, but GnuCash may ask about duplicates on import.');
    field_date('date_opened', 'Invoice opened date', (string)($params['date_opened'] ?? $today));
    field_date('entry_date', 'Line item date', (string)($params['entry_date'] ?? $today));
    field_date('date_posted', 'Posted date', (string)($params['date_posted'] ?? $today));
    field_date('due_date', 'Due date', (string)($params['due_date'] ?? date('Y-m-d', strtotime('+30 days'))));
    field_text('billing_id', 'Billing ID', (string)($params['billing_id'] ?? 'Monthly dues ' . date('Y-m')), 'Optional batch billing ID.');
    field_text('description', 'Line item description', (string)($params['description'] ?? 'Monthly dues'), 'Shown as the invoice line description.');
    field_text('action_name', 'Action', (string)($params['action'] ?? $cfg['default_action']), 'Example: ea');
    render_account_select('income_account', 'Income account', (string)($params['income_account'] ?? $cfg['default_income_account']), $incomeAccounts, 'Must match an existing GnuCash income account. Suggestions are scanned from the active book.');
    render_account_select('ar_account', 'A/R account', (string)($params['ar_account'] ?? $cfg['default_ar_account']), $receivableAccounts, 'Used as account_posted when posting invoices. Suggestions are scanned from the active book.');
    field_number('quantity', 'Quantity', (float)($params['quantity'] ?? 1), 'Usually 1. Exported as an explicit decimal quantity, for example 1.0.');
    field_number('price', 'Unit price', (float)($params['price'] ?? 0), 'Amount per customer. Exported with two decimals, for example 15.00.');
    field_number('discount', 'Discount', (float)($params['discount'] ?? 0), 'Normally 0.');
    field_text('tax_table', 'Tax table', (string)($params['tax_table'] ?? $cfg['default_tax_table']), 'Leave blank for no tax table.');
    field_text('memo_posted', 'Posted memo', (string)($params['memo_posted'] ?? 'Posted by batch invoice creator'), 'Used only when posted.');
    echo '</div>';
    echo '<label>Invoice notes</label><textarea name="notes">' . h((string)($params['notes'] ?? '')) . '</textarea>';
    echo '<label class="check"><input type="checkbox" name="posted" value="1" ' . ((bool)($params['posted'] ?? $cfg['default_posted']) ? 'checked' : '') . '> Generate posted invoices</label>';
    echo '<label class="check"><input type="checkbox" name="taxable" value="1" ' . ((bool)($params['taxable'] ?? $cfg['default_taxable']) ? 'checked' : '') . '> Taxable</label>';
    echo '<label class="check"><input type="checkbox" name="taxincluded" value="1" ' . ((bool)($params['taxincluded'] ?? $cfg['default_taxincluded']) ? 'checked' : '') . '> Tax included</label>';
    echo '<label class="check"><input type="checkbox" name="accu_splits" value="1" ' . ((bool)($params['accu_splits'] ?? false) ? 'checked' : '') . '> Accumulate splits when posting</label>';
    echo '<label class="check"><input type="checkbox" name="save_as_defaults" value="1"> Save these invoice accounts and common values as global defaults</label>';
    echo '<div class="grid">';
    field_text('save_group_name', 'Save/update customer group name', '', 'Optional. Saves the matched customers for reuse under the active entity.');
    field_text('save_template_name', 'Save/update template name', '', 'Optional. Saves these invoice settings under the active entity.');
    echo '</div>';
    echo '<div class="actions"><button type="submit">Generate GnuCash CSV</button></div>';
    echo '</form></section>';
}

function action_generate(): never
{
    check_csrf();
    $book = require_book_configured();
    $scan = $_SESSION['batch_scan'] ?? null;
    if (!is_array($scan) || empty($scan['matched'])) {
        flash('error', 'No matched customer scan is available.');
        redirect_to('wizard');
    }
    $customerIds = array_values(array_map(fn($c) => (string)$c['id'], $scan['matched']));
    $params = [
        'start_invoice_id' => trim((string)($_POST['start_invoice_id'] ?? '')),
        'date_opened' => trim((string)($_POST['date_opened'] ?? date('Y-m-d'))),
        'entry_date' => trim((string)($_POST['entry_date'] ?? date('Y-m-d'))),
        'date_posted' => trim((string)($_POST['date_posted'] ?? date('Y-m-d'))),
        'due_date' => trim((string)($_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days')))),
        'billing_id' => trim((string)($_POST['billing_id'] ?? '')),
        'notes' => trim((string)($_POST['notes'] ?? '')),
        'description' => trim((string)($_POST['description'] ?? 'Monthly dues')),
        'action' => trim((string)($_POST['action_name'] ?? 'ea')),
        'income_account' => trim((string)($_POST['income_account'] ?? app_config('default_income_account'))),
        'ar_account' => trim((string)($_POST['ar_account'] ?? app_config('default_ar_account'))),
        'quantity' => (string)($_POST['quantity'] ?? '1'),
        'price' => (string)($_POST['price'] ?? '0'),
        'discount' => (string)($_POST['discount'] ?? '0'),
        'tax_table' => trim((string)($_POST['tax_table'] ?? '')),
        'memo_posted' => trim((string)($_POST['memo_posted'] ?? 'Posted by batch invoice creator')),
        'posted' => isset($_POST['posted']),
        'taxable' => isset($_POST['taxable']),
        'taxincluded' => isset($_POST['taxincluded']),
        'accu_splits' => isset($_POST['accu_splits']),
        'csv_delimiter' => (string)app_config('csv_delimiter', ','),
        'csv_date_format' => (string)app_config('csv_date_format', 'm/d/Y'),
    ];

    if (isset($_POST['save_as_defaults'])) {
        update_config([
            'default_income_account' => $params['income_account'],
            'default_ar_account' => $params['ar_account'],
            'default_action' => $params['action'],
            'default_tax_table' => $params['tax_table'],
            'default_posted' => (bool)$params['posted'],
            'default_taxable' => (bool)$params['taxable'],
            'default_taxincluded' => (bool)$params['taxincluded'],
        ]);
        flash('ok', 'Updated global invoice defaults from this batch.');
    }

    $saveGroup = trim((string)($_POST['save_group_name'] ?? ''));
    if ($saveGroup !== '') {
        json_write_file(profile_data_dir('groups') . '/' . slugify($saveGroup) . '.json', [
            'name' => $saveGroup,
            'created_at' => date(DATE_ATOM),
            'profile' => active_profile_slug(),
            'customer_ids' => $customerIds,
        ]);
    }

    $saveTemplate = trim((string)($_POST['save_template_name'] ?? ''));
    if ($saveTemplate !== '') {
        $templateParams = $params;
        unset($templateParams['start_invoice_id']);
        json_write_file(profile_data_dir('templates') . '/' . slugify($saveTemplate) . '.json', [
            'name' => $saveTemplate,
            'created_at' => date(DATE_ATOM),
            'profile' => active_profile_slug(),
            'params' => $templateParams,
        ]);
    }

    $filename = 'gnucash-invoices-' . date('Ymd-His') . '.csv';
    $out = profile_data_dir('generated') . '/' . $filename;
    $payload = ['customer_ids' => $customerIds, 'params' => $params];
    $result = run_python(['generate', '--book', $book, '--out', $out, '--prefix', (string)app_config('id_prefix', ''), '--padding', (string)app_config('id_padding', 0)], $payload);
    if (!$result['ok'] || !is_array($result['json'])) {
        flash('error', 'CSV generation failed: ' . trim(($result['stderr'] ?? '') . ' ' . ($result['stdout'] ?? '')));
        redirect_to('wizard');
    }
    $_SESSION['last_generate'] = $result['json'];
    flash('ok', 'Generated ' . ($result['json']['invoice_count'] ?? 0) . ' invoices.');
    redirect_to('groups', ['generated' => $filename]);
}

function render_customer_filter_controls(string $action, string $sortMode, bool $showInactive, array $extraParams = []): void
{
    $baseParams = array_merge(['action' => $action], $extraParams);
    $idParams = array_merge($baseParams, ['sort' => 'id']);
    $nameParams = array_merge($baseParams, ['sort' => 'name']);
    if ($showInactive) {
        $idParams['show_inactive'] = '1';
        $nameParams['show_inactive'] = '1';
    }
    $toggleParams = array_merge($baseParams, ['sort' => $sortMode]);
    if (!$showInactive) {
        $toggleParams['show_inactive'] = '1';
    }
    echo '<div class="actions">';
    echo '<a class="button secondary" href="?' . h(http_build_query($idParams)) . '">Sort by ID</a>';
    echo '<a class="button secondary" href="?' . h(http_build_query($nameParams)) . '">Sort by name</a>';
    echo '<a class="button secondary" href="?' . h(http_build_query($toggleParams)) . '">' . ($showInactive ? 'Hide inactive customers' : 'Show inactive customers') . '</a>';
    echo '</div>';
}

function sorted_visible_customers(array $customers, bool $showInactive, string $sortMode): array
{
    $visible = array_values(array_filter($customers, fn($c) => $showInactive || php_customer_is_active($c)));
    usort($visible, function ($a, $b) use ($sortMode) {
        if ($sortMode === 'name') {
            $cmp = strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
            return $cmp !== 0 ? $cmp : strnatcasecmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
        }
        return strnatcasecmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
    });
    return $visible;
}

function render_customer_group_creator(): void
{
    $book = current_book_path();
    echo '<section class="card"><h2>Create or update a customer group</h2>';
    echo '<p class="help">Create a reusable customer group directly from the active GnuCash book. This does not generate invoices, so it is useful for statement/report batches on a clean install.</p>';
    if ($book === '' || !is_file($book)) {
        echo '<div class="flash warn">Upload and select a readable GnuCash book copy before creating groups.</div>';
        echo '</section>';
        return;
    }

    $groups = list_named_json(profile_data_dir('groups'));
    $editSlug = trim((string)($_GET['edit_group'] ?? ''));
    $editing = null;
    if ($editSlug !== '') {
        foreach ($groups as $group) {
            if (hash_equals((string)$group['slug'], $editSlug)) {
                $editing = $group;
                break;
            }
        }
        if ($editing === null) {
            echo '<div class="flash warn">The requested group was not found for this entity. Creating a new group instead.</div>';
            $editSlug = '';
        }
    }

    $draft = $_SESSION['group_form_draft'] ?? null;
    if (is_array($draft) && (($draft['profile'] ?? '') !== active_profile_slug())) {
        $draft = null;
    }
    unset($_SESSION['group_form_draft']);

    $showInactive = (string)($_GET['show_inactive'] ?? ($draft['show_inactive'] ?? '')) === '1';
    $requestedSort = (string)($_GET['sort'] ?? ($draft['sort'] ?? 'name'));
    $sortMode = in_array($requestedSort, ['id', 'name'], true) ? $requestedSort : 'name';
    $bookScan = scan_book_for_wizard($book);
    if (!$bookScan['ok'] || !is_array($bookScan['json'])) {
        echo '<div class="flash error">Unable to scan customers from the active book.<pre>' . h(($bookScan['stderr'] ?? '') . "\n" . ($bookScan['stdout'] ?? '')) . '</pre></div>';
        echo '</section>';
        return;
    }

    $formName = (string)($draft['group_name'] ?? '');
    $formNote = (string)($draft['group_note'] ?? '');
    $selectedIds = [];
    $hasExplicitSelection = false;
    if (is_array($draft)) {
        $selectedIds = is_array($draft['customer_ids'] ?? null) ? array_values(array_map('strval', $draft['customer_ids'])) : [];
        $hasExplicitSelection = true;
        $editSlug = (string)($draft['previous_group_slug'] ?? $editSlug);
    } elseif ($editing !== null) {
        $formName = (string)($editing['data']['name'] ?? $editing['name'] ?? '');
        $formNote = (string)($editing['data']['note'] ?? '');
        $selectedIds = is_array($editing['data']['customer_ids'] ?? null) ? array_values(array_map('strval', $editing['data']['customer_ids'])) : [];
        $hasExplicitSelection = true;
    }
    $selectedLookup = array_fill_keys($selectedIds, true);

    $customers = is_array($bookScan['json']['customers'] ?? null) ? $bookScan['json']['customers'] : [];
    $activeCount = (int)($bookScan['json']['active_customer_count'] ?? count(array_filter($customers, 'php_customer_is_active')));
    $inactiveCount = (int)($bookScan['json']['inactive_customer_count'] ?? max(0, count($customers) - $activeCount));
    $visible = sorted_visible_customers($customers, $showInactive, $sortMode);
    echo '<p><span class="badge">Total customers: ' . h(count($customers)) . '</span> <span class="badge">Active: ' . h($activeCount) . '</span> <span class="badge">Inactive: ' . h($inactiveCount) . '</span> <span class="badge">Sort: ' . h($sortMode) . '</span></p>';
    $filterExtra = $editSlug !== '' ? ['edit_group' => $editSlug] : [];
    render_customer_filter_controls('groups', $sortMode, $showInactive, $filterExtra);

    if ($editing !== null || $editSlug !== '') {
        echo '<div class="flash ok"><strong>Editing group:</strong> ' . h($formName !== '' ? $formName : $editSlug) . '. Change the name to rename it, then save.</div>';
    }

    if (!$visible) {
        echo '<div class="flash warn">No customers are visible with the current filter.</div>';
        echo '</section>';
        return;
    }

    $visibleIds = array_values(array_filter(array_map(static fn($c) => (string)($c['id'] ?? ''), $visible), static fn($id) => $id !== ''));
    $hiddenSelectedIds = array_values(array_diff($selectedIds, $visibleIds));

    echo '<form method="post" action="?action=save_group">' . csrf_field();
    echo '<input type="hidden" name="previous_group_slug" value="' . h($editSlug) . '">';
    echo '<input type="hidden" name="sort_mode" value="' . h($sortMode) . '">';
    echo '<input type="hidden" name="show_inactive" value="' . ($showInactive ? '1' : '0') . '">';
    foreach ($hiddenSelectedIds as $hiddenId) {
        echo '<input type="hidden" name="customer_ids[]" value="' . h($hiddenId) . '">';
    }
    echo '<div class="grid">';
    field_text('group_name', 'Group name', $formName, 'Required. If this name already exists, the group is updated. Example: July 2026 Dues or All Active Youth.');
    echo '<div><label for="group_note">Group note</label><input type="text" id="group_note" name="group_note" value="' . h($formNote) . '"><div class="help">Optional note to remind you why this group was created.</div></div>';
    echo '</div>';
    if ($hiddenSelectedIds) {
        echo '<p class="help">' . h(count($hiddenSelectedIds)) . ' selected customer(s) are preserved but hidden by the current filter. Choose “Show inactive customers” to review or remove them.</p>';
    }
    echo '<div class="table-tools"><button class="secondary" type="button" onclick="document.querySelectorAll(\'.group-customer-pick\').forEach(cb => cb.checked = true)">Select all visible</button> <button class="secondary" type="button" onclick="document.querySelectorAll(\'.group-customer-pick\').forEach(cb => cb.checked = false)">Clear visible</button></div>';
    echo '<table class="customer-picker"><tr><th>Select</th><th>ID</th><th>Name</th><th>Status</th></tr>';
    foreach ($visible as $customer) {
        $id = (string)($customer['id'] ?? '');
        $isActive = php_customer_is_active($customer);
        $checked = $hasExplicitSelection ? (isset($selectedLookup[$id]) ? ' checked' : '') : ($isActive ? ' checked' : '');
        $rowClass = $isActive ? '' : ' class="inactive-row"';
        echo '<tr' . $rowClass . '><td><input class="group-customer-pick" type="checkbox" name="customer_ids[]" value="' . h($id) . '"' . $checked . '></td><td><code>' . h($id) . '</code></td><td>' . h($customer['name'] ?? '') . '</td><td>' . ($isActive ? '<span class="badge">active</span>' : '<span class="badge bad">inactive</span>') . '</td></tr>';
    }
    echo '</table>';
    echo '<div class="actions"><button type="submit">Save customer group</button>';
    if ($editing !== null || $editSlug !== '') {
        echo '<a class="button secondary" href="?action=groups">Cancel edit</a>';
    }
    echo '</div>';
    echo '</form></section>';
}

function page_groups(): void
{
    $profile = require_profile_configured();
    render_header('Groups');
    echo '<p class="muted">Showing groups for active entity: <strong>' . h($profile['name']) . '</strong>.</p>';
    if (!empty($_GET['generated']) && !empty($_SESSION['last_generate'])) {
        $gen = $_SESSION['last_generate'];
        echo '<section class="card"><h2>Generated CSV</h2>';
        echo '<p><strong>Invoices:</strong> ' . h($gen['invoice_count'] ?? 0) . '</p>';
        echo '<p><strong>Rows:</strong> ' . h($gen['row_count'] ?? 0) . '</p>';
        echo '<p><strong>Invoice range:</strong> ' . h(($gen['first_invoice_id'] ?? '') . ' - ' . ($gen['last_invoice_id'] ?? '')) . '</p>';
        echo '<p><a class="button" href="?action=download&file=' . h((string)$_GET['generated']) . '">Download CSV</a></p>';
        if (!empty($gen['warnings'])) {
            echo '<details><summary>Warnings</summary><pre>' . h(implode("\n", $gen['warnings'])) . '</pre></details>';
        }
        echo '</section>';
    }

    render_customer_group_creator();

    echo '<section class="card"><h2>Saved customer groups</h2>';
    $groups = list_named_json(profile_data_dir('groups'));
    if (!$groups) {
        echo '<p>No saved groups yet for this entity. Use the creator above to save a group from the active GnuCash book.</p>';
    } else {
        echo '<table><tr><th>Name</th><th>Customers</th><th>Note</th><th>Modified</th><th>Actions</th></tr>';
        foreach ($groups as $group) {
            $note = (string)($group['data']['note'] ?? '');
            echo '<tr><td>' . h($group['name']) . '</td><td>' . h(count($group['data']['customer_ids'] ?? [])) . '</td><td>' . h($note) . '</td><td>' . h(date('Y-m-d H:i', $group['modified'])) . '</td><td><a href="?action=groups&edit_group=' . h($group['slug']) . '">Edit</a> | <a href="?action=wizard">Use in wizard</a> | <a href="?action=reports">Use in reports</a> | <a href="?action=delete_group&name=' . h($group['slug']) . '" onclick="return confirm(\'Delete this group?\')">Delete</a></td></tr>';
        }
        echo '</table>';
    }
    echo '</section>';
    render_footer();
}

function action_save_group(): never
{
    check_csrf();
    require_profile_configured();
    require_book_configured();
    $name = trim((string)($_POST['group_name'] ?? ''));
    $note = trim((string)($_POST['group_note'] ?? ''));
    $previousSlug = trim((string)($_POST['previous_group_slug'] ?? ''));
    $sortMode = in_array(($_POST['sort_mode'] ?? 'name'), ['id', 'name'], true) ? (string)$_POST['sort_mode'] : 'name';
    $showInactive = (string)($_POST['show_inactive'] ?? '0') === '1';
    $ids = $_POST['customer_ids'] ?? [];
    if (!is_array($ids)) {
        $ids = [];
    }
    $ids = array_values(array_unique(array_filter(array_map(static fn($id) => trim((string)$id), $ids), static fn($id) => $id !== '')));

    $storeDraftAndReturn = function (string $message) use ($name, $note, $previousSlug, $sortMode, $showInactive, $ids): never {
        $_SESSION['group_form_draft'] = [
            'profile' => active_profile_slug(),
            'group_name' => $name,
            'group_note' => $note,
            'previous_group_slug' => $previousSlug,
            'sort' => $sortMode,
            'show_inactive' => $showInactive ? '1' : '0',
            'customer_ids' => $ids,
        ];
        flash('error', $message);
        $params = ['sort' => $sortMode];
        if ($showInactive) {
            $params['show_inactive'] = '1';
        }
        if ($previousSlug !== '') {
            $params['edit_group'] = $previousSlug;
        }
        redirect_to('groups', $params);
    };

    if ($name === '') {
        $storeDraftAndReturn('Enter a customer group name. Your customer selections were preserved.');
    }
    if (!$ids) {
        $storeDraftAndReturn('Select at least one customer for the group. Your form values were preserved.');
    }

    $groupsDir = profile_data_dir('groups');
    $newSlug = slugify($name);
    $target = $groupsDir . '/' . $newSlug . '.json';
    $existing = is_file($target) ? json_read_file($target, []) : [];
    $previousPath = $previousSlug !== '' ? $groupsDir . '/' . basename($previousSlug) . '.json' : '';
    if ($existing === [] && $previousPath !== '' && is_file($previousPath)) {
        $existing = json_read_file($previousPath, []);
    }

    json_write_file($target, [
        'name' => $name,
        'created_at' => is_array($existing) ? (string)($existing['created_at'] ?? date(DATE_ATOM)) : date(DATE_ATOM),
        'updated_at' => date(DATE_ATOM),
        'profile' => active_profile_slug(),
        'note' => $note,
        'customer_ids' => $ids,
    ]);
    if ($previousPath !== '' && basename($previousSlug) !== $newSlug && is_file($previousPath)) {
        unlink($previousPath);
    }
    unset($_SESSION['group_form_draft']);
    flash('ok', 'Saved customer group "' . $name . '" with ' . count($ids) . ' customers.');
    redirect_to('groups');
}

function page_templates(): void
{
    $profile = require_profile_configured();
    render_header('Templates');
    echo '<p class="muted">Showing templates for active entity: <strong>' . h($profile['name']) . '</strong>.</p>';
    echo '<section class="card"><h2>Saved invoice templates</h2>';
    $templates = list_named_json(profile_data_dir('templates'));
    if (!$templates) {
        echo '<p>No saved templates yet for this entity.</p>';
    } else {
        echo '<table><tr><th>Name</th><th>Description</th><th>Price</th><th>Posted</th><th>Modified</th><th>Actions</th></tr>';
        foreach ($templates as $template) {
            $p = $template['data']['params'] ?? [];
            echo '<tr><td>' . h($template['name']) . '</td><td>' . h($p['description'] ?? '') . '</td><td>' . h($p['price'] ?? '') . '</td><td>' . h(!empty($p['posted']) ? 'yes' : 'no') . '</td><td>' . h(date('Y-m-d H:i', $template['modified'])) . '</td><td><a href="?action=wizard&template=' . h($template['slug']) . '">Use</a> | <a href="?action=delete_template&name=' . h($template['slug']) . '" onclick="return confirm(\'Delete this template?\')">Delete</a></td></tr>';
        }
        echo '</table>';
    }
    echo '</section>';
    render_footer();
}

function action_delete_named(string $dir, string $returnAction): never
{
    $name = basename((string)($_GET['name'] ?? ''));
    if ($name !== '') {
        $path = $dir . '/' . $name . '.json';
        if (is_file($path)) {
            unlink($path);
            flash('ok', 'Deleted.');
        }
    }
    redirect_to($returnAction);
}

function action_download(): never
{
    require_profile_configured();
    $file = basename((string)($_GET['file'] ?? ''));
    $path = profile_data_dir('generated') . '/' . $file;
    if ($file === '' || !is_file($path)) {
        http_response_code(404);
        exit('File not found.');
    }
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}


function report_filename_preview_php(array $settings, array $customer, string $dateFrom = '', string $dateTo = '', string $group = ''): string
{
    $template = (string)($settings['filename_template'] ?? '{customer} - {date_to} - {text}');
    $source = (string)($settings['filename_customer_source'] ?? 'billing_name');
    $dateFormat = (string)($settings['filename_date_format'] ?? 'Y-m-d');
    $text = (string)($settings['filename_text'] ?? 'statement');
    $dateFrom = $dateFrom !== '' ? report_format_filename_date_php($dateFrom, $dateFormat) : report_format_filename_date_php(date('Y-01-01'), $dateFormat);
    $dateTo = $dateTo !== '' ? report_format_filename_date_php($dateTo, $dateFormat) : report_format_filename_date_php(date('Y-m-d'), $dateFormat);
    $selectedCustomer = match ($source) {
        'customer_number', 'customer_id' => (string)($customer['id'] ?? ''),
        'company_name' => (string)($customer['name'] ?? $customer['id'] ?? ''),
        'billing_name' => (string)($customer['billing_name'] ?? $customer['name'] ?? $customer['id'] ?? ''),
        default => (string)($customer['billing_name'] ?? $customer['name'] ?? $customer['id'] ?? ''),
    };
    $values = [
        'customer' => $selectedCustomer,
        'customer_id' => (string)($customer['id'] ?? ''),
        'customer_number' => (string)($customer['id'] ?? ''),
        'company_name' => (string)($customer['name'] ?? ''),
        'name' => (string)($customer['name'] ?? ''),
        'billing_name' => (string)($customer['billing_name'] ?? ''),
        'date' => $dateTo,
        'date_to' => $dateTo,
        'date_from' => $dateFrom,
        'group' => $group,
        'text' => $text,
    ];
    $rendered = preg_replace_callback('/\{([A-Za-z0-9_ -]+)\}/', static function (array $m) use ($values): string {
        $key = strtolower(trim((string)$m[1]));
        return (string)($values[$key] ?? '');
    }, $template) ?? $template;
    return report_safe_filename_php($rendered) . '.pdf';
}

function report_format_filename_date_php(string $value, string $format): string
{
    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }
    $format = trim($format) !== '' ? $format : 'Y-m-d';
    $aliases = [
        'yyyy-mm-dd' => 'Y-m-d',
        'mm-dd-yyyy' => 'm-d-Y',
        'dd-mm-yyyy' => 'd-m-Y',
        'mm/dd/yyyy' => 'm/d/Y',
        'dd/mm/yyyy' => 'd/m/Y',
    ];
    $format = $aliases[strtolower($format)] ?? $format;
    return date($format, $ts);
}

function report_safe_filename_php(string $value): string
{
    $clean = trim((string)$value);
    $clean = preg_replace('/[<>:"\\\/|?*\x00-\x1F]+/', '-', $clean) ?? $clean;
    $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
    $clean = trim($clean, " .-_");
    return $clean !== '' ? substr($clean, 0, 180) : 'customer';
}

function remove_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $path = $item->getPathname();
        if ($item->isDir()) {
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}


function page_reports(): void
{
    $profile = require_profile_configured();
    $book = require_book_configured();
    render_header('Batch Customer Reports');
    echo '<p class="muted">Active entity: <strong>' . h($profile['name']) . '</strong>. Active book copy: <code>' . h(basename($book)) . '</code>.</p>';
    $readyBatch = basename((string)($_GET['batch'] ?? ''));
    if ($readyBatch !== '' && is_dir(reports_batch_dir($readyBatch, $profile))) {
        $zipReady = is_file(reports_batch_dir($readyBatch, $profile) . '/customer-reports.zip');
        echo '<div class="flash ok report-ready"><strong>Report batch ready:</strong> <code>' . h($readyBatch) . '</code>. ';
        echo '<a class="button secondary inline" href="#report-downloads">Jump to download area</a>';
        if ($zipReady) {
            echo ' <a class="button inline" href="?action=download_report_zip&amp;batch=' . h($readyBatch) . '">Download ZIP now</a>';
        }
        echo '</div>';
    }

    $settings = profile_report_settings($profile);
    $groups = list_named_json(profile_data_dir('groups'));
    $metadata = run_python(['report-metadata', '--book', $book]);
    $arAccounts = [];
    if ($metadata['ok'] && is_array($metadata['json'])) {
        $arAccounts = $metadata['json']['ar_accounts'] ?? [];
    }
    $scan = run_python(['scan-book', '--book', $book]);
    $previewCustomers = [];
    if ($scan['ok'] && is_array($scan['json'])) {
        $previewCustomers = is_array($scan['json']['customers'] ?? null) ? $scan['json']['customers'] : [];
        usort($previewCustomers, fn($a, $b) => strcasecmp((string)($a['name'] ?? $a['id'] ?? ''), (string)($b['name'] ?? $b['id'] ?? '')));
    }
    $previewCustomer = $previewCustomers[0] ?? ['id' => '000001', 'name' => 'Example Customer', 'billing_name' => 'Example Billing Name'];

    echo '<section class="card"><h2>Report appearance</h2>';
    echo '<p class="help">The tool uses a GnuCash-like customer report layout and renders PDFs with Chromium. To keep the same look and feel as your manual reports, export one GnuCash Customer Report as HTML and upload it here as a style reference. CSS found in that HTML will be layered into the tool-owned report template.</p>';
    echo '<form method="post" enctype="multipart/form-data" action="?action=save_report_settings">' . csrf_field();
    echo '<div class="grid">';
    field_text('organization_name', 'Header organization/entity name', (string)$settings['organization_name'], 'Shown in the report header. Example: Troop 20.');
    field_text('footer_text', 'Footer text', (string)$settings['footer_text'], 'Optional.');
    echo '<div><label for="page_size">Page size</label><select id="page_size" name="page_size">';
    foreach (['Letter', 'A4'] as $size) {
        echo '<option value="' . h($size) . '"' . ((string)$settings['page_size'] === $size ? ' selected' : '') . '>' . h($size) . '</option>';
    }
    echo '</select></div>';
    echo '<div><label for="logo_file">Logo / banner image</label><input type="file" id="logo_file" name="logo_file" accept=".png,.jpg,.jpeg,.gif,.webp,.svg"><div class="help">Optional per-entity logo. Existing: ' . h((string)($settings['logo_file'] ?: '(none)')) . '</div></div>';
    echo '<div><label for="style_reference_file">GnuCash exported HTML style reference</label><input type="file" id="style_reference_file" name="style_reference_file" accept=".html,.htm"><div class="help">Optional. Export one manually formatted Customer Report from GnuCash as HTML and upload it here. Existing: ' . h((string)($settings['style_reference_file'] ?: '(none)')) . '</div></div>';
    echo '</div>';
    echo '<label for="custom_css">Custom CSS</label><textarea id="custom_css" name="custom_css" rows="8">' . h((string)$settings['custom_css']) . '</textarea>';

    echo '<details open><summary><strong>Report file naming</strong></summary>';
    echo '<p class="help">Choose how individual report PDF files are named. Use the template dropdowns or enter a custom pattern. Variables: <code>{customer}</code>, <code>{customer_id}</code>, <code>{customer_number}</code>, <code>{company_name}</code>, <code>{billing_name}</code>, <code>{date}</code>, <code>{date_to}</code>, <code>{date_from}</code>, <code>{group}</code>, <code>{text}</code>.</p>';
    echo '<div class="grid">';
    echo '<div><label for="filename_customer_source">Customer value for {customer}</label><select id="filename_customer_source" name="filename_customer_source">';
    foreach (["billing_name" => "Billing Address Name", "company_name" => "Company Name", "customer_number" => "Customer Number"] as $val => $label) {
        echo '<option value="' . h($val) . '"' . ((string)$settings['filename_customer_source'] === $val ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    echo '</select></div>';
    field_text('filename_template', 'Filename template', (string)$settings['filename_template'], 'Recommended: {customer} - {date_to} - {text}');
    field_text('filename_text', 'Filename text value', (string)$settings['filename_text'], 'Example: statement, dues, invoice-summary. Used by {text}.');
    field_text('filename_date_format', 'Filename date format', (string)$settings['filename_date_format'], 'Examples: Y-m-d, m-d-Y, m/d/Y. Used by {date}, {date_to}, and {date_from}.');
    echo '<div><label for="filename_preview_customer">Preview against customer</label><select id="filename_preview_customer">';
    foreach ($previewCustomers as $cust) {
        $label = trim((string)($cust['id'] ?? '') . ' - ' . (string)($cust['name'] ?? ''));
        echo '<option value="' . h((string)($cust['id'] ?? '')) . '" data-customer-id="' . h((string)($cust['id'] ?? '')) . '" data-company-name="' . h((string)($cust['name'] ?? '')) . '" data-billing-name="' . h((string)($cust['billing_name'] ?? '')) . '">' . h($label) . '</option>';
    }
    if (!$previewCustomers) {
        echo '<option value="000001" data-customer-id="000001" data-company-name="Example Customer" data-billing-name="Example Billing Name">000001 - Example Customer</option>';
    }
    echo '</select><div class="help">This preview does not save the selected customer; it only tests the naming pattern.</div></div>';
    echo '<div><label>Filename preview</label><div id="filename_preview" class="filename-preview"><code>' . h(report_filename_preview_php($settings, $previewCustomer)) . '</code></div></div>';
    echo '</div></details>';

    echo '<label class="check"><input type="checkbox" name="include_zero_balance" value="1" ' . (!empty($settings['include_zero_balance']) ? 'checked' : '') . '> Include zero-balance accounts/customers</label>';
    echo '<label class="check"><input type="checkbox" name="show_internal_offsets" value="1" ' . (!empty($settings['show_internal_offsets']) ? 'checked' : '') . '> Show internal A/R offset/allocation rows <span class="help-inline">Diagnostic option. Leave unchecked for cleaner GnuCash-like customer statements that show billed items and actual payments only.</span></label>';
    echo '<script>
(function(){
  const template = document.getElementById("filename_template");
  const source = document.getElementById("filename_customer_source");
  const text = document.getElementById("filename_text");
  const fmt = document.getElementById("filename_date_format");
  const cust = document.getElementById("filename_preview_customer");
  const out = document.getElementById("filename_preview");
  function formatDate(fmtValue){
    const d = new Date();
    const y = String(d.getFullYear());
    const m = String(d.getMonth()+1).padStart(2,"0");
    const day = String(d.getDate()).padStart(2,"0");
    return (fmtValue || "Y-m-d").replace(/Y/g,y).replace(/m/g,m).replace(/d/g,day);
  }
  function safeName(v){
    v = String(v || "").replace(/[<>:"\\/|?*\x00-\x1F]+/g,"-").replace(/\s+/g," ").trim().replace(/^[ ._-]+|[ ._-]+$/g,"");
    return v || "customer";
  }
  function update(){
    if(!out || !cust) return;
    const opt = cust.selectedOptions[0];
    const customerId = opt ? (opt.dataset.customerId || opt.value || "") : "";
    const companyName = opt ? (opt.dataset.companyName || "") : "";
    const billingName = opt ? (opt.dataset.billingName || "") : "";
    let customer = billingName || companyName || customerId;
    if(source && source.value === "company_name") customer = companyName || customerId;
    if(source && source.value === "customer_number") customer = customerId;
    const values = {
      customer: customer,
      customer_id: customerId,
      customer_number: customerId,
      company_name: companyName,
      name: companyName,
      billing_name: billingName,
      date: formatDate(fmt ? fmt.value : "Y-m-d"),
      date_to: formatDate(fmt ? fmt.value : "Y-m-d"),
      date_from: formatDate(fmt ? fmt.value : "Y-m-d"),
      group: "customer-group",
      text: text ? text.value : "statement"
    };
    let v = template ? template.value : "{customer} - {date_to} - {text}";
    v = v.replace(/\{([A-Za-z0-9_ -]+)\}/g, function(_, key){ return values[String(key).trim().toLowerCase()] || ""; });
    out.innerHTML = "<code>" + safeName(v).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;") + ".pdf</code>";
  }
  [template, source, text, fmt, cust].forEach(el => { if(el) el.addEventListener("input", update); if(el) el.addEventListener("change", update); });
  update();
})();
</script>';
    echo '<div class="actions"><button type="submit">Save report appearance</button></div></form></section>';

    echo '<section class="card"><h2>Generate customer report PDFs</h2>';
    if (!$groups) {
        echo '<div class="flash warn">No saved customer groups exist for this entity yet. Open Groups and create a group from the active GnuCash book, then return here.</div>';
    }
    if (!$metadata['ok'] || !is_array($metadata['json'])) {
        echo '<div class="flash error">Unable to scan report metadata. Report generation currently requires a readable GnuCash SQLite book copy.<pre>' . h(($metadata['stderr'] ?? '') . "\n" . ($metadata['stdout'] ?? '')) . '</pre></div>';
    }
    echo '<form method="post" action="?action=generate_reports">' . csrf_field();
    echo '<div class="grid">';
    echo '<div><label for="group_slug">Customer group</label><select id="group_slug" name="group_slug" required><option value="">-- select a saved group --</option>';
    foreach ($groups as $group) {
        echo '<option value="' . h($group['slug']) . '">' . h($group['name']) . ' (' . h(count($group['data']['customer_ids'] ?? [])) . ' customers)</option>';
    }
    echo '</select></div>';
    field_date('date_from', 'Date range start', date('Y-01-01'));
    field_date('date_to', 'Date range end', date('Y-m-d'));
    field_text('batch_name', 'Batch/report folder name', 'customer-reports-' . date('Y-m-d'), 'Used for the generated ZIP and report folder.');
    echo '</div>';

    echo '<h3>A/R accounts</h3>';
    if ($arAccounts) {
        echo '<p class="help">Select one or more receivable accounts. Leaving all selected mirrors the multi-account manual report workflow.</p>';
        echo '<div class="checks">';
        foreach ($arAccounts as $acct) {
            $guid = (string)($acct['guid'] ?? '');
            $name = (string)($acct['full_name'] ?? $acct['name'] ?? $guid);
            echo '<label class="check"><input type="checkbox" name="ar_accounts[]" value="' . h($guid) . '" checked> ' . h($name) . '</label>';
        }
        echo '</div>';
    } else {
        echo '<div class="flash warn">No Accounts Receivable accounts were detected. The report generator will attempt to use posted invoice accounts.</div>';
    }
    echo '<label class="check"><input type="checkbox" name="include_zero_balance" value="1" ' . (!empty($settings['include_zero_balance']) ? 'checked' : '') . '> Include zero-balance account sections and customers</label>';
    echo '<div class="actions"><button type="submit"' . (!$groups ? ' disabled' : '') . '>Generate PDF batch</button></div></form></section>';

    render_recent_report_batches();
    render_footer();
}

function action_save_report_settings(): never
{
    check_csrf();
    $profile = require_profile_configured();
    ensure_profile_dirs((string)$profile['slug']);
    $settings = profile_report_settings($profile);
    $settings['organization_name'] = trim((string)($_POST['organization_name'] ?? $profile['name'] ?? ''));
    $settings['footer_text'] = trim((string)($_POST['footer_text'] ?? ''));
    $settings['page_size'] = in_array(($_POST['page_size'] ?? 'Letter'), ['Letter', 'A4'], true) ? (string)$_POST['page_size'] : 'Letter';
    $settings['include_zero_balance'] = isset($_POST['include_zero_balance']);
    $settings['show_internal_offsets'] = isset($_POST['show_internal_offsets']);
    $settings['custom_css'] = (string)($_POST['custom_css'] ?? '');
    $source = (string)($_POST['filename_customer_source'] ?? 'billing_name');
    $settings['filename_customer_source'] = in_array($source, ['billing_name', 'company_name', 'customer_number'], true) ? $source : 'billing_name';
    $settings['filename_template'] = trim((string)($_POST['filename_template'] ?? '{customer} - {date_to} - {text}')) ?: '{customer} - {date_to} - {text}';
    $settings['filename_text'] = trim((string)($_POST['filename_text'] ?? 'statement')) ?: 'statement';
    $settings['filename_date_format'] = trim((string)($_POST['filename_date_format'] ?? 'Y-m-d')) ?: 'Y-m-d';

    $assetDir = profile_dir((string)$profile['slug']) . '/report-assets';
    if (!is_dir($assetDir)) {
        mkdir($assetDir, 0770, true);
    }
    if (!is_writable($assetDir)) {
        flash('error', 'Report asset directory is not writable: ' . $assetDir);
        redirect_to('reports');
    }

    if (!empty($_FILES['logo_file']) && (int)($_FILES['logo_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $err = (int)$_FILES['logo_file']['error'];
        if ($err !== UPLOAD_ERR_OK) {
            flash('error', 'Logo upload failed: ' . upload_error_message($err));
            redirect_to('reports');
        }
        $original = basename((string)$_FILES['logo_file']['name']);
        if (!preg_match('/\.(png|jpe?g|gif|webp|svg)$/i', $original)) {
            flash('error', 'Logo must be PNG, JPG, GIF, WEBP, or SVG.');
            redirect_to('reports');
        }
        $stored = 'logo-' . date('Ymd-His') . '-' . slugify($original);
        if (!move_uploaded_file((string)$_FILES['logo_file']['tmp_name'], $assetDir . '/' . $stored)) {
            flash('error', 'Unable to store logo upload.');
            redirect_to('reports');
        }
        chmod($assetDir . '/' . $stored, 0660);
        $settings['logo_file'] = $stored;
    }

    if (!empty($_FILES['style_reference_file']) && (int)($_FILES['style_reference_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $err = (int)$_FILES['style_reference_file']['error'];
        if ($err !== UPLOAD_ERR_OK) {
            flash('error', 'Style reference upload failed: ' . upload_error_message($err));
            redirect_to('reports');
        }
        $original = basename((string)$_FILES['style_reference_file']['name']);
        if (!preg_match('/\.(html?|xhtml)$/i', $original)) {
            flash('error', 'Style reference must be an exported HTML file.');
            redirect_to('reports');
        }
        $stored = 'gnucash-style-reference-' . date('Ymd-His') . '.html';
        if (!move_uploaded_file((string)$_FILES['style_reference_file']['tmp_name'], $assetDir . '/' . $stored)) {
            flash('error', 'Unable to store style reference upload.');
            redirect_to('reports');
        }
        chmod($assetDir . '/' . $stored, 0660);
        $settings['style_reference_file'] = $stored;
    }

    $profile['report_settings'] = $settings;
    save_profile($profile);
    flash('ok', 'Report appearance settings saved.');
    redirect_to('reports');
}

function action_generate_reports(): never
{
    check_csrf();
    $profile = require_profile_configured();
    $book = require_book_configured();
    $groupSlug = basename((string)($_POST['group_slug'] ?? ''));
    $group = json_read_file(profile_data_dir('groups') . '/' . $groupSlug . '.json', null);
    if (!is_array($group) || empty($group['customer_ids'])) {
        flash('error', 'Select a valid saved customer group.');
        redirect_to('reports');
    }
    $batch = slugify((string)($_POST['batch_name'] ?? 'customer-reports-' . date('Y-m-d')));
    if ($batch === '') {
        $batch = 'customer-reports-' . date('Ymd-His');
    }
    $outDir = reports_batch_dir($batch, $profile);
    $settings = profile_report_settings($profile);
    $arAccounts = array_values(array_filter(array_map('strval', $_POST['ar_accounts'] ?? [])));
    $logoPath = !empty($settings['logo_file']) ? report_asset_path((string)$settings['logo_file'], $profile) : '';
    $stylePath = !empty($settings['style_reference_file']) ? report_asset_path((string)$settings['style_reference_file'], $profile) : '';
    $payload = [
        'customer_ids' => $group['customer_ids'],
        'group_name' => (string)($group['name'] ?? $groupSlug),
        'organization_name' => (string)($settings['organization_name'] ?? $profile['name'] ?? ''),
        'footer_text' => (string)($settings['footer_text'] ?? ''),
        'page_size' => (string)($settings['page_size'] ?? 'Letter'),
        'include_zero_balance' => isset($_POST['include_zero_balance']),
        'show_internal_offsets' => !empty($settings['show_internal_offsets']),
        'date_from' => (string)($_POST['date_from'] ?? date('Y-01-01')),
        'date_to' => (string)($_POST['date_to'] ?? date('Y-m-d')),
        'ar_accounts' => $arAccounts,
        'logo_path' => is_file($logoPath) ? $logoPath : '',
        'style_reference_path' => is_file($stylePath) ? $stylePath : '',
        'custom_css' => (string)($settings['custom_css'] ?? ''),
        'filename_template' => (string)($settings['filename_template'] ?? '{customer} - {date_to} - {text}'),
        'filename_customer_source' => (string)($settings['filename_customer_source'] ?? 'billing_name'),
        'filename_text' => (string)($settings['filename_text'] ?? 'statement'),
        'filename_date_format' => (string)($settings['filename_date_format'] ?? 'Y-m-d'),
        'chromium_bin' => (string)app_config('chromium_bin', '/snap/bin/chromium'),
    ];
    $result = run_python(['customer-reports', '--book', $book, '--out-dir', $outDir], $payload);
    if (!$result['ok'] || !is_array($result['json'])) {
        flash('error', 'Customer report generation failed: ' . trim(($result['stderr'] ?? '') . ' ' . ($result['stdout'] ?? '')));
        redirect_to('reports');
    }
    flash('ok', 'Generated ' . h((string)($result['json']['pdf_count'] ?? 0)) . ' customer report PDFs.');
    redirect_to('reports', ['batch' => $batch]);
}

function action_download_report_zip(): never
{
    require_profile_configured();
    $batch = basename((string)($_GET['batch'] ?? ''));
    $zip = reports_batch_dir($batch) . '/customer-reports.zip';
    if ($batch === '' || !is_file($zip)) {
        http_response_code(404);
        exit('Report ZIP not found.');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $batch . '.zip"');
    header('Content-Length: ' . filesize($zip));
    readfile($zip);
    exit;
}

function action_delete_report_batch(): never
{
    check_csrf();
    require_profile_configured();
    $batch = basename((string)($_POST['batch'] ?? ''));
    if ($batch === '') {
        flash('error', 'No report batch selected.');
        redirect_to('reports');
    }
    $dir = reports_batch_dir($batch);
    $base = realpath(reports_batch_dir()) ?: reports_batch_dir();
    $real = realpath($dir);
    if ($real === false || !str_starts_with($real, rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        flash('error', 'Invalid report batch path.');
        redirect_to('reports');
    }
    remove_tree($real);
    flash('ok', 'Deleted report batch: ' . $batch);
    redirect_to('reports');
}

function action_clean_reports(): never
{
    check_csrf();
    $profile = require_profile_configured();
    $confirm = (string)($_POST['confirm_clean_reports'] ?? '');
    if ($confirm !== 'DELETE') {
        flash('error', 'Type DELETE to clean all report output for this entity.');
        redirect_to('reports');
    }
    $base = reports_batch_dir('', $profile);
    if (is_dir($base)) {
        $dirs = glob($base . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($dirs as $dir) {
            remove_tree($dir);
        }
    }
    ensure_runtime_dirs();
    ensure_profile_dirs((string)$profile['slug']);
    flash('ok', 'Cleaned all customer report output for this entity.');
    redirect_to('reports');
}


function render_recent_report_batches(): void
{
    echo '<section class="card" id="report-downloads"><h2>Recent report batches</h2>';
    $base = reports_batch_dir();
    $dirs = glob($base . '/*', GLOB_ONLYDIR) ?: [];
    usort($dirs, fn($a, $b) => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
    if (!$dirs) {
        echo '<p>No report batches generated yet for this entity.</p>';
    } else {
        echo '<table><tr><th>Batch</th><th>Generated</th><th>PDFs</th><th>Actions</th></tr>';
        foreach (array_slice($dirs, 0, 10) as $dir) {
        $batch = basename($dir);
        $manifest = json_read_file($dir . '/batch-manifest.json', []);
        $pdfCount = is_array($manifest) ? (int)($manifest['pdf_count'] ?? 0) : 0;
        $generated = is_array($manifest) ? (string)($manifest['generated_at'] ?? '') : '';
        echo '<tr><td><code>' . h($batch) . '</code></td><td>' . h(substr($generated, 0, 19)) . '</td><td>' . h($pdfCount) . '</td><td>';
        if (is_file($dir . '/customer-reports.zip')) {
            echo '<a class="button secondary inline" href="?action=download_report_zip&batch=' . h($batch) . '">Download ZIP</a>';
        }
        echo '<form class="inline" method="post" action="?action=delete_report_batch" onsubmit="return confirm(&quot;Delete this report batch?&quot;)">' . csrf_field() . '<input type="hidden" name="batch" value="' . h($batch) . '"><button class="danger" type="submit">Delete</button></form>';
            echo '</td></tr>';
        }
        echo '</table>';
    }
    echo '<details><summary><strong>Clean all report output for this entity</strong></summary>';
    echo '<p class="help">This deletes generated report HTML, PDFs, ZIP files, and manifests under this entity&rsquo;s <code>reports/customer-reports/</code> directory. It does not delete books, groups, templates, or report appearance settings.</p>';
    echo '<form method="post" action="?action=clean_reports" onsubmit="return confirm(&quot;Delete ALL customer report output for this entity?&quot;)">' . csrf_field();
    echo '<label for="confirm_clean_reports">Type DELETE to confirm</label><input type="text" id="confirm_clean_reports" name="confirm_clean_reports" autocomplete="off">';
    echo '<div class="actions"><button class="danger" type="submit">Clean reports directory</button></div></form></details>';
    echo '</section>';
}

function render_runtime_checks_compact(): void
{
    $checks = runtime_writable_checks();
    $bad = array_values(array_filter($checks, fn($c) => empty($c['readable']) || empty($c['writable'])));
    if (!$bad) {
        echo '<div class="flash ok">Runtime directory check passed: config and var storage appear writable by PHP.</div>';
        return;
    }
    echo '<div class="flash warn"><strong>Writable directory check has warnings.</strong> Uploads will fail until PHP can write to config/ and var/. Open Settings after setup for details, or run bin/setup-local-permissions.sh from the repo root.</div>';
}


function render_chromium_field(string $configured): void
{
    $detected = detect_chromium_binary($configured);
    $value = !empty($detected['ok']) ? (string)$detected['path'] : $configured;
    field_text('chromium_bin', 'Chromium binary', $value, 'Used to render customer report PDFs. Ubuntu desktop installs often use /snap/bin/chromium. The generator also tries common paths automatically if this setting is stale.');

    if (!empty($detected['ok'])) {
        $message = 'Chromium detected at ' . (string)$detected['path'] . '.';
        if ($configured !== '' && $configured !== (string)$detected['path']) {
            $message .= ' The configured path was stale or indirect; save Settings to store the detected path.';
        }
        echo '<div class="flash ok"><strong>Chromium check:</strong> ' . h($message) . '</div>';
    } else {
        echo '<div class="flash warn"><strong>Chromium check:</strong> Chromium was not found. Install Chromium or enter the full path. Tried: <code>' . h(implode(', ', $detected['candidates'] ?? [])) . '</code></div>';
    }
}

function render_runtime_checks_full(): void
{
    echo '<section class="card"><h2>Runtime checks</h2>';
    $identity = runtime_identity();
    echo '<p class="help">Uploads and generated reports require the PHP-FPM user to write to <code>config/</code> and <code>var/</code>. The directory should usually be group-owned by <code>publicweb</code> with setgid/default ACLs. If PHP cannot open <code>index.php</code>, check the PHP-FPM <code>open_basedir</code> path for this pool.</p>';
    echo '<table><tr><th>Runtime identity</th><th>User</th><th>Group</th></tr>';
    echo '<tr><td>PHP effective process</td><td><code>' . h($identity['effective_user']) . '</code></td><td><code>' . h($identity['effective_group']) . '</code></td></tr>';
    echo '<tr><td>Application directory owner</td><td><code>' . h($identity['app_owner']) . '</code></td><td><code>' . h($identity['app_group']) . '</code></td></tr>';
    echo '</table>';
    $basedir = open_basedir_status();
    echo '<table class="runtime-table"><tr><th>PHP open_basedir</th><th>Status</th></tr>';
    echo '<tr><td><code>' . h($basedir['value'] !== '' ? $basedir['value'] : '(not restricted)') . '</code></td><td class="status-cell">' . (!empty($basedir['base_path_allowed']) ? '<span class="badge">app root allowed</span>' : '<span class="badge bad">app root blocked</span>') . '</td></tr>';
    echo '<tr><td><code>' . h(BASE_PATH) . '</code></td><td class="status-cell">' . (!empty($basedir['base_path_allowed']) ? '<span class="badge">allowed</span>' : '<span class="badge bad">blocked</span>') . '</td></tr>';
    echo '<tr><td><code>' . h(BASE_PATH . '/var') . '</code></td><td class="status-cell">' . (!empty($basedir['var_allowed']) ? '<span class="badge">allowed</span>' : '<span class="badge bad">blocked</span>') . '</td></tr>';
    echo '<tr><td><code>/snap/bin</code></td><td class="status-cell">' . (!empty($basedir['snap_bin_allowed']) ? '<span class="badge">allowed or unrestricted</span>' : '<span class="badge bad">not allowed</span><div class="help">Chromium snap detection may fail.</div>') . '</td></tr>';
    echo '</table>';
    if (($identity['effective_user'] ?? '') !== ($identity['app_owner'] ?? '')) {
        echo '<div class="flash warn"><strong>Ownership note:</strong> Generated CSV/PDF files are created by the PHP-FPM process user, currently <code>' . h($identity['effective_user']) . '</code>. ACLs can make them writable by your shell user, but they cannot make new files owned by your shell user. For files to be owned by <code>' . h($identity['app_owner']) . '</code>, run this app in a dedicated local PHP-FPM pool as that user.</div>';
    }
    echo '<table><tr><th>Path</th><th>Exists</th><th>Readable</th><th>Writable</th></tr>';
    foreach (runtime_writable_checks() as $check) {
        $ok = !empty($check['readable']) && !empty($check['writable']);
        echo '<tr><td><code>' . h($check['path']) . '</code><br><span class="muted">' . h($check['label']) . '</span></td><td>' . h(!empty($check['exists']) ? 'yes' : 'no') . '</td><td>' . h(!empty($check['readable']) ? 'yes' : 'no') . '</td><td>' . ($ok ? '<span class="badge">yes</span>' : '<span class="badge bad">no</span>') . '</td></tr>';
    }
    echo '</table>';
    echo '<details open><summary>Suggested local repair commands</summary><pre>cd /path/to/this/clone
bash bin/setup-local-permissions.sh</pre><p class="help">This reclaims existing <code>www-data</code>-owned runtime files for your user, restores setgid/default ACLs, and keeps <code>publicweb</code> group write access.</p></details>';
    echo '<details><summary>Make generated files owned by your shell user</summary><pre>cd /path/to/this/clone
sudo bash bin/install-local-fpm-pool.sh alan publicweb 8.5 "$(pwd)"</pre><p class="help">This writes the PHP-FPM <code>open_basedir</code> for the current clone path. Then update the nginx location for this app to use <code>/run/php/gnucash-invoice-batch-creator.sock</code>. See <code>config/nginx-local-example.conf</code>.</p></details>';
    echo '</section>';
}

function page_not_found(): void
{
    http_response_code(404);
    render_header('Not found');
    echo '<p>Page not found.</p>';
    render_footer();
}

function field_text(string $name, string $label, string $value, string $help = ''): void
{
    echo '<div><label for="' . h($name) . '">' . h($label) . '</label><input type="text" id="' . h($name) . '" name="' . h($name) . '" value="' . h($value) . '">';
    if ($help !== '') echo '<div class="help">' . h($help) . '</div>';
    echo '</div>';
}

function field_number(string $name, string $label, int|float $value, string $help = ''): void
{
    echo '<div><label for="' . h($name) . '">' . h($label) . '</label><input type="number" step="0.01" id="' . h($name) . '" name="' . h($name) . '" value="' . h((string)$value) . '">';
    if ($help !== '') echo '<div class="help">' . h($help) . '</div>';
    echo '</div>';
}

function field_date(string $name, string $label, string $value): void
{
    echo '<div><label for="' . h($name) . '">' . h($label) . '</label><input type="date" id="' . h($name) . '" name="' . h($name) . '" value="' . h($value) . '"></div>';
}
