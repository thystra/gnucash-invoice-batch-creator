<?php
/**
 * Root-directory front controller for subdirectory/local public_html installs.
 *
 * Preferred production deployment still points nginx directly at public/, but
 * this shim lets a simple clone under ~/public_html/invoices work at
 * http://localhost/invoices/index.php when nginx maps PHP files from the
 * public_html document root.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

define('APP_ASSET_BASE', 'public');
require __DIR__ . '/public/index.php';
