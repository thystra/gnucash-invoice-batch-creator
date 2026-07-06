<?php
/**
 * GnuCash Invoice Batch Creator local configuration example.
 *
 * Copy to config/config.php and edit through the web Configuration page.
 */
return [
    'gnucash_book_path' => '',
    'python_bin' => '/usr/bin/python3',
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
    'csv_date_format' => 'Y-m-d',
    'timezone' => 'America/New_York',
];
