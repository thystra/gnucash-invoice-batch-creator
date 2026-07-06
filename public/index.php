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
        'config' => page_config(),
        'save_config' => action_save_config(),
        'wizard' => page_wizard(),
        'scan_upload' => action_scan_upload(),
        'generate' => action_generate(),
        'groups' => page_groups(),
        'templates' => page_templates(),
        'delete_group' => action_delete_named('var/groups', 'groups'),
        'delete_template' => action_delete_named('var/templates', 'templates'),
        'download' => action_download(),
        default => page_not_found(),
    };
} catch (Throwable $e) {
    render_header('Error');
    echo '<div class="flash error">' . h($e->getMessage()) . '</div>';
    echo '<pre>' . h($e->getTraceAsString()) . '</pre>';
    render_footer();
}

function page_home(): void
{
    render_header('Home');
    echo '<div class="grid">';
    echo '<section class="card"><h2>Create a batch</h2><p>Upload a customer ID file, choose invoice parameters, and generate a GnuCash invoice import CSV.</p><p><a class="button" href="?action=wizard">Start batch wizard</a></p></section>';
    echo '<section class="card"><h2>Saved groups</h2><p>Reuse a customer group for monthly dues or repeated billing runs.</p><p><a class="button secondary" href="?action=groups">Manage groups</a></p></section>';
    echo '<section class="card"><h2>Templates</h2><p>Reuse descriptions, accounts, dates, posting settings, taxes, and price levels.</p><p><a class="button secondary" href="?action=templates">Manage templates</a></p></section>';
    echo '<section class="card"><h2>Book scan</h2>';
    echo '<p><strong>Configured book:</strong><br>' . h(app_config('gnucash_book_path') ?: '(not configured)') . '</p>';
    if (app_config('gnucash_book_path') && is_file((string)app_config('gnucash_book_path'))) {
        $result = run_python(['scan-book', '--book', (string)app_config('gnucash_book_path'), '--prefix', (string)app_config('id_prefix', ''), '--padding', (string)app_config('id_padding', 0)]);
        if ($result['ok'] && is_array($result['json'])) {
            echo '<p><span class="badge">Customers: ' . h($result['json']['customer_count'] ?? 0) . '</span> <span class="badge">Next invoice: ' . h($result['json']['next_invoice_id'] ?? '') . '</span></p>';
        } else {
            echo '<div class="flash error">Unable to scan configured book. ' . h($result['stderr'] ?: $result['stdout']) . '</div>';
        }
    }
    echo '</section>';
    echo '</div>';
    render_footer();
}

function page_config(): void
{
    render_header('Configuration');
    $cfg = app_config();
    echo '<form method="post" action="?action=save_config" class="card">' . csrf_field();
    echo '<div class="grid">';
    field_text('gnucash_book_path', 'GnuCash book path', (string)$cfg['gnucash_book_path'], 'Use a test copy first. SQLite, XML, and gzipped XML are supported for scanning.');
    field_text('python_bin', 'Python binary', (string)$cfg['python_bin'], 'Usually /usr/bin/python3.');
    field_text('default_income_account', 'Default income account', (string)$cfg['default_income_account'], 'Example: Income:Dues');
    field_text('default_ar_account', 'Default A/R account', (string)$cfg['default_ar_account'], 'Example: Assets:Accounts Receivable');
    field_text('default_action', 'Default invoice action', (string)$cfg['default_action'], 'Common values: ea, hour, day, material.');
    field_text('default_tax_table', 'Default tax table', (string)$cfg['default_tax_table'], 'Leave blank for no tax table.');
    field_text('id_prefix', 'Invoice ID prefix', (string)$cfg['id_prefix'], 'Optional. Example: DUES-');
    field_number('id_padding', 'Invoice ID numeric padding', (int)$cfg['id_padding'], '0 preserves detected width or uses no padding.');
    field_text('csv_delimiter', 'CSV delimiter', (string)$cfg['csv_delimiter'], 'Use comma or semicolon.');
    field_text('csv_date_format', 'Display date format', (string)$cfg['csv_date_format'], 'PHP date format used by the web form defaults. Generated CSV dates use ISO yyyy-mm-dd unless changed in future code.');
    field_text('timezone', 'Timezone', (string)$cfg['timezone'], 'Example: America/New_York');
    echo '</div>';
    echo '<label class="check"><input type="checkbox" name="default_posted" value="1" ' . ((bool)$cfg['default_posted'] ? 'checked' : '') . '> Post invoices by default</label>';
    echo '<label class="check"><input type="checkbox" name="default_taxable" value="1" ' . ((bool)$cfg['default_taxable'] ? 'checked' : '') . '> Taxable by default</label>';
    echo '<label class="check"><input type="checkbox" name="default_taxincluded" value="1" ' . ((bool)$cfg['default_taxincluded'] ? 'checked' : '') . '> Tax included by default</label>';
    echo '<div class="actions"><button type="submit">Save configuration</button><a class="button secondary" href="?action=config&scan=1">Scan book now</a></div>';
    echo '</form>';

    if (isset($_GET['scan'])) {
        echo '<section class="card"><h2>Book scan result</h2>';
        $book = (string)app_config('gnucash_book_path', '');
        $result = run_python(['scan-book', '--book', $book, '--prefix', (string)app_config('id_prefix', ''), '--padding', (string)app_config('id_padding', 0)]);
        if ($result['ok'] && is_array($result['json'])) {
            echo '<p><strong>Customers:</strong> ' . h($result['json']['customer_count'] ?? 0) . '</p>';
            echo '<p><strong>Existing invoice IDs:</strong> ' . h($result['json']['invoice_count'] ?? 0) . '</p>';
            echo '<p><strong>Suggested next invoice ID:</strong> ' . h($result['json']['next_invoice_id'] ?? '') . '</p>';
            echo '<details><summary>First 25 customers</summary><table><tr><th>ID</th><th>Name</th><th>Active</th></tr>';
            foreach (array_slice($result['json']['customers'] ?? [], 0, 25) as $customer) {
                echo '<tr><td>' . h($customer['id'] ?? '') . '</td><td>' . h($customer['name'] ?? '') . '</td><td>' . h($customer['active'] ?? '') . '</td></tr>';
            }
            echo '</table></details>';
        } else {
            echo '<div class="flash error">Scan failed.</div><pre>' . h(($result['stderr'] ?? '') . "\n" . ($result['stdout'] ?? '')) . '</pre>';
        }
        echo '</section>';
    }
    render_footer();
}

function action_save_config(): never
{
    check_csrf();
    ensure_runtime_dirs();
    $new = [
        'gnucash_book_path' => trim((string)($_POST['gnucash_book_path'] ?? '')),
        'python_bin' => trim((string)($_POST['python_bin'] ?? '/usr/bin/python3')),
        'default_income_account' => trim((string)($_POST['default_income_account'] ?? 'Income:Dues')),
        'default_ar_account' => trim((string)($_POST['default_ar_account'] ?? 'Assets:Accounts Receivable')),
        'default_posted' => isset($_POST['default_posted']),
        'default_action' => trim((string)($_POST['default_action'] ?? 'ea')),
        'default_taxable' => isset($_POST['default_taxable']),
        'default_taxincluded' => isset($_POST['default_taxincluded']),
        'default_tax_table' => trim((string)($_POST['default_tax_table'] ?? '')),
        'id_prefix' => trim((string)($_POST['id_prefix'] ?? '')),
        'id_padding' => max(0, (int)($_POST['id_padding'] ?? 0)),
        'csv_delimiter' => in_array(($_POST['csv_delimiter'] ?? ','), [',', ';'], true) ? (string)$_POST['csv_delimiter'] : ',',
        'csv_date_format' => trim((string)($_POST['csv_date_format'] ?? 'Y-m-d')),
        'timezone' => trim((string)($_POST['timezone'] ?? 'America/New_York')),
    ];
    $php = "<?php\nreturn " . var_export($new, true) . ";\n";
    if (file_put_contents(CONFIG_PATH, $php, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write config/config.php. Check permissions.');
    }
    flash('ok', 'Configuration saved.');
    redirect_to('config');
}

function page_wizard(): void
{
    require_book_configured();
    render_header('Batch Wizard');
    $groups = list_named_json('var/groups');
    echo '<section class="card"><h2>1. Choose customer IDs</h2>';
    echo '<form method="post" enctype="multipart/form-data" action="?action=scan_upload">' . csrf_field();
    echo '<label>Upload customer ID file</label><input type="file" name="customer_file" accept=".csv,.txt,.tsv,.xlsx">';
    echo '<div class="help">CSV/text/XLSX. A recognized customer ID column is best, but exact ID matching is also attempted.</div>';
    if ($groups) {
        echo '<label>Or load saved group</label><select name="saved_group"><option value="">-- do not load a group --</option>';
        foreach ($groups as $group) {
            echo '<option value="' . h($group['slug']) . '">' . h($group['name']) . ' (' . h(count($group['data']['customer_ids'] ?? [])) . ' customers)</option>';
        }
        echo '</select>';
    }
    echo '<div class="actions"><button type="submit">Scan / Load Customers</button></div></form></section>';

    if (!empty($_SESSION['batch_scan'])) {
        render_params_form($_SESSION['batch_scan']);
    }
    render_footer();
}

function action_scan_upload(): never
{
    check_csrf();
    require_book_configured();
    $savedGroup = trim((string)($_POST['saved_group'] ?? ''));

    if ($savedGroup !== '') {
        $path = BASE_PATH . '/var/groups/' . basename($savedGroup) . '.json';
        $group = json_read_file($path, null);
        if (!is_array($group) || empty($group['customer_ids'])) {
            flash('error', 'Saved group could not be loaded.');
            redirect_to('wizard');
        }
        $result = run_python(['scan-ids', '--book', (string)app_config('gnucash_book_path')], ['customer_ids' => $group['customer_ids']]);
    } else {
        if (empty($_FILES['customer_file']) || ($_FILES['customer_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('error', 'Upload a customer ID file or choose a saved group.');
            redirect_to('wizard');
        }
        $original = basename((string)$_FILES['customer_file']['name']);
        $target = BASE_PATH . '/var/uploads/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '-' . slugify($original);
        if (!move_uploaded_file((string)$_FILES['customer_file']['tmp_name'], $target)) {
            flash('error', 'Unable to store uploaded file.');
            redirect_to('wizard');
        }
        $result = run_python(['scan-upload', '--book', (string)app_config('gnucash_book_path'), '--input', $target]);
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
    $matched = $scan['matched'] ?? [];
    $unmatched = $scan['unmatched'] ?? [];
    $bookScan = run_python(['scan-book', '--book', (string)app_config('gnucash_book_path'), '--prefix', (string)app_config('id_prefix', ''), '--padding', (string)app_config('id_padding', 0)]);
    $suggested = $bookScan['json']['next_invoice_id'] ?? '';
    $templates = list_named_json('var/templates');

    echo '<section class="card"><h2>2. Review matched customers</h2>';
    echo '<p><span class="badge">Matched: ' . h(count($matched)) . '</span> <span class="badge">Unmatched: ' . h(count($unmatched)) . '</span></p>';
    echo '<details open><summary>Matched customers</summary><table><tr><th>ID</th><th>Name</th></tr>';
    foreach ($matched as $customer) {
        echo '<tr><td>' . h($customer['id'] ?? '') . '</td><td>' . h($customer['name'] ?? '') . '</td></tr>';
    }
    echo '</table></details>';
    if ($unmatched) {
        echo '<details><summary>Unmatched uploaded IDs/tokens</summary><pre>' . h(implode("\n", $unmatched)) . '</pre></details>';
    }
    echo '</section>';

    $loadedTemplate = [];
    $loadSlug = isset($_GET['template']) ? basename((string)$_GET['template']) : '';
    if ($loadSlug !== '') {
        $loadedTemplate = json_read_file(BASE_PATH . '/var/templates/' . $loadSlug . '.json', []);
    }
    $params = $loadedTemplate['params'] ?? [];
    $today = date('Y-m-d');

    echo '<section class="card"><h2>3. Invoice parameters</h2>';
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
    field_text('income_account', 'Income account', (string)($params['income_account'] ?? $cfg['default_income_account']), 'Must match a GnuCash income account name.');
    field_text('ar_account', 'A/R account', (string)($params['ar_account'] ?? $cfg['default_ar_account']), 'Used when posting invoices.');
    field_number('quantity', 'Quantity', (float)($params['quantity'] ?? 1), 'Usually 1.');
    field_number('price', 'Unit price', (float)($params['price'] ?? 0), 'Amount per customer.');
    field_number('discount', 'Discount', (float)($params['discount'] ?? 0), 'Normally 0.');
    field_text('tax_table', 'Tax table', (string)($params['tax_table'] ?? $cfg['default_tax_table']), 'Leave blank for no tax table.');
    field_text('memo_posted', 'Posted memo', (string)($params['memo_posted'] ?? 'Posted by batch invoice creator'), 'Used only when posted.');
    echo '</div>';
    echo '<label>Invoice notes</label><textarea name="notes">' . h((string)($params['notes'] ?? '')) . '</textarea>';
    echo '<label class="check"><input type="checkbox" name="posted" value="1" ' . ((bool)($params['posted'] ?? $cfg['default_posted']) ? 'checked' : '') . '> Generate posted invoices</label>';
    echo '<label class="check"><input type="checkbox" name="taxable" value="1" ' . ((bool)($params['taxable'] ?? $cfg['default_taxable']) ? 'checked' : '') . '> Taxable</label>';
    echo '<label class="check"><input type="checkbox" name="taxincluded" value="1" ' . ((bool)($params['taxincluded'] ?? $cfg['default_taxincluded']) ? 'checked' : '') . '> Tax included</label>';
    echo '<label class="check"><input type="checkbox" name="accu_splits" value="1" ' . ((bool)($params['accu_splits'] ?? false) ? 'checked' : '') . '> Accumulate splits when posting</label>';
    echo '<div class="grid">';
    field_text('save_group_name', 'Save/update customer group name', '', 'Optional. Saves the matched customers for reuse.');
    field_text('save_template_name', 'Save/update template name', '', 'Optional. Saves these invoice settings for reuse.');
    echo '</div>';
    echo '<div class="actions"><button type="submit">Generate GnuCash CSV</button></div>';
    echo '</form></section>';
}

function action_generate(): never
{
    check_csrf();
    require_book_configured();
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
    ];

    $saveGroup = trim((string)($_POST['save_group_name'] ?? ''));
    if ($saveGroup !== '') {
        json_write_file(BASE_PATH . '/var/groups/' . slugify($saveGroup) . '.json', [
            'name' => $saveGroup,
            'created_at' => date(DATE_ATOM),
            'customer_ids' => $customerIds,
        ]);
    }

    $saveTemplate = trim((string)($_POST['save_template_name'] ?? ''));
    if ($saveTemplate !== '') {
        $templateParams = $params;
        unset($templateParams['start_invoice_id']);
        json_write_file(BASE_PATH . '/var/templates/' . slugify($saveTemplate) . '.json', [
            'name' => $saveTemplate,
            'created_at' => date(DATE_ATOM),
            'params' => $templateParams,
        ]);
    }

    $filename = 'gnucash-invoices-' . date('Ymd-His') . '.csv';
    $out = BASE_PATH . '/var/generated/' . $filename;
    $payload = ['customer_ids' => $customerIds, 'params' => $params];
    $result = run_python(['generate', '--book', (string)app_config('gnucash_book_path'), '--out', $out, '--prefix', (string)app_config('id_prefix', ''), '--padding', (string)app_config('id_padding', 0)], $payload);
    if (!$result['ok'] || !is_array($result['json'])) {
        flash('error', 'CSV generation failed: ' . trim(($result['stderr'] ?? '') . ' ' . ($result['stdout'] ?? '')));
        redirect_to('wizard');
    }
    $_SESSION['last_generate'] = $result['json'];
    flash('ok', 'Generated ' . ($result['json']['invoice_count'] ?? 0) . ' invoices.');
    redirect_to('groups', ['generated' => $filename]);
}

function page_groups(): void
{
    render_header('Groups');
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
    echo '<section class="card"><h2>Saved customer groups</h2>';
    $groups = list_named_json('var/groups');
    if (!$groups) {
        echo '<p>No saved groups yet.</p>';
    } else {
        echo '<table><tr><th>Name</th><th>Customers</th><th>Modified</th><th>Actions</th></tr>';
        foreach ($groups as $group) {
            echo '<tr><td>' . h($group['name']) . '</td><td>' . h(count($group['data']['customer_ids'] ?? [])) . '</td><td>' . h(date('Y-m-d H:i', $group['modified'])) . '</td><td><a href="?action=wizard">Use</a> | <a href="?action=delete_group&name=' . h($group['slug']) . '" onclick="return confirm(\'Delete this group?\')">Delete</a></td></tr>';
        }
        echo '</table>';
    }
    echo '</section>';
    render_footer();
}

function page_templates(): void
{
    render_header('Templates');
    echo '<section class="card"><h2>Saved invoice templates</h2>';
    $templates = list_named_json('var/templates');
    if (!$templates) {
        echo '<p>No saved templates yet.</p>';
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
        $path = BASE_PATH . '/' . $dir . '/' . $name . '.json';
        if (is_file($path)) {
            unlink($path);
            flash('ok', 'Deleted.');
        }
    }
    redirect_to($returnAction);
}

function action_download(): never
{
    $file = basename((string)($_GET['file'] ?? ''));
    $path = BASE_PATH . '/var/generated/' . $file;
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
