<?php
/**
 * Local configuration defaults for GnuCash Invoice Batch Creator.
 * Copy to config/config.php or edit through the web UI.
 */
return [
    // Active entity/profile slug. Created and managed from Settings.
    'active_profile' => '',

    // Legacy fallback path used only when no entity book is active.
    // New installs should upload book copies under entity/profile storage.
    'gnucash_book_path' => '',

    'python_bin' => '/usr/bin/python3',
    'chromium_bin' => '/usr/bin/chromium',
    'default_income_account' => 'Income:Dues',
    'default_ar_account' => 'Assets:Accounts Receivable',
    'default_posted' => true,
    'default_action' => 'ea',
    'default_taxable' => false,
    'default_taxincluded' => false,
    'default_tax_table' => '',
    'id_prefix' => '',
    'id_padding' => 0,
    'csv_delimiter' => ',',
    'csv_date_format' => 'm/d/Y',
    'timezone' => 'America/New_York',
];
