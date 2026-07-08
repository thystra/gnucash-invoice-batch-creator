## v0.1.20 notes

- The Batch Wizard customer picker now includes a **Select non-zero visible** button when the active uploaded book is a SQLite GnuCash book with transaction tables.
- Customer rows now show scanned A/R balance values in the Batch Wizard customer picker.
- The Groups page also includes the same balance-aware selection shortcut, so users can create statement/report groups for customers with non-zero balances without generating invoices first.
- Balance scanning reuses the customer-report A/R logic and falls back gracefully when transaction-level balance data is unavailable, such as XML-only book scans.

## v0.1.19 notes

- Customer report PDF pagination now uses Chromium DevTools Protocol footer templates instead of CSS page counters. This fixes the `Page 0 of 0` footer issue seen with Chromium command-line PDF rendering.
- Customer report HTML no longer injects the CSS `counter(page)` footer. The PDF renderer supplies `Page X of Y` only at PDF render time when page numbers are enabled.
- The PDF renderer still suppresses Chromium local file-path headers/footers; the generated footer contains only the tool-owned page numbering text.


## v0.1.18 notes

- Active book paths on the Home page now wrap inside their card instead of overflowing long profile/book paths.
- The Home page and footer now include project repository, issue reporting, and support/tips links.
- Customer report PDF generation now passes Chromium flags to suppress Chromium's built-in PDF headers and footers so local `file://` paths are not printed at the bottom of reports.
- Report settings include an optional **Add page number footer** control, enabled by default, that adds a tool-owned page-number footer to generated customer report PDFs.


## v0.1.17 notes

- Customer report payment rows now suppress internal GnuCash offset/allocation splits by default. The report generator only shows invoice/credit-note rows and payment rows that have a non-receivable payment counterparty such as Cash, Bank, Asset, Liability, or Credit accounts.
- Multiple A/R lot splits from the same payment transaction are collapsed into one payment/adjustment line per customer/account/transaction.
- Report settings include an optional diagnostic checkbox to show internal offset/allocation rows if you need to audit lot-balancing behavior.

## v0.1.16 notes

- After customer report generation, the Reports page now shows a **Jump to download area** link near the top of the page when returning from the batch job.
- The Recent report batches section now has a stable `#report-downloads` anchor, and the post-generation notice also includes a direct ZIP download button when the ZIP exists.

## v0.1.15 notes

- Patch scripts now explicitly preserve `.git/` and do not use archive-permission sync that can remove repository metadata.
- Added `bin/repair-git-metadata.sh` for recovery if a prior patch script removed `.git/`.

### Recovering if `.git/` was removed by an older patch

From the project directory:

```bash
cd ~/public_html/gnucash-invoice-batch-creator
bash bin/repair-git-metadata.sh . https://github.com/thystra/gnucash-invoice-batch-creator.git main
git status
```

This restores Git metadata from GitHub without resetting your working tree. Do not run `git reset --hard` unless you intentionally want to discard local patch files.

## v0.1.14 notes

- Account fields now use editable scan-backed suggestions rather than a closed select box. This allows a user to select scanned income/A/R accounts or type the exact GnuCash account path, and the submitted value is preserved explicitly.
- Saving Settings now preserves existing configuration keys and uses the current saved account values as fallbacks instead of reverting to hard-coded defaults such as `Income:Dues`.
- Settings success messages now echo the saved default income and A/R accounts so it is obvious which values were written to `config/config.php`.
- The invoice wizard now includes an optional **Save these invoice accounts and common values as global defaults** checkbox for updating global defaults from a successful batch run.

## v0.1.13 notes

- Reports can now be deleted one batch at a time from the Recent report batches table.
- The Reports page now has a **Clean reports directory** control that deletes generated customer-report HTML, PDFs, ZIP files, and manifests for the active entity/profile without deleting books, groups, templates, or report appearance settings.
- Report PDF filenames are configurable per entity/profile. The default convention is:

```text
{customer} - {date_to} - {text}
```

- Filename variables include `{customer}`, `{customer_id}`, `{customer_number}`, `{company_name}`, `{billing_name}`, `{date}`, `{date_to}`, `{date_from}`, `{group}`, and `{text}`.
- `{customer}` can be configured to use Billing Address Name, Company Name, or Customer Number. SQLite GnuCash books are scanned for `customers.id`, `customers.name`, and `customers.addr_name` when available.
- The Report Appearance form includes a filename preview tester against a selected customer from the active uploaded book.

## v0.1.12 notes

- Customer group validation errors now preserve the selected checkboxes, group name, note, sort mode, and inactive-customer filter.
- Saved customer groups can now be loaded for editing from the Groups page and saved again, including renaming a group.
- The PHP `open_basedir` runtime check table now uses fixed-width status cells, wrapped path text, and non-wrapping status badges so the badges do not break awkwardly on long basedir values.

## v0.1.11 notes

- Root/subdirectory access through `/gnucashtools/invoices/` now loads assets correctly through the root `index.php` shim. Direct `/gnucashtools/invoices/public/index.php` still works, but should not be required for local testing.
- The Groups page now includes an explicit customer-group creator that scans the active uploaded book and saves selected customers without generating invoice CSVs.
- Reports now direct clean installs to create a group from the Groups page rather than requiring an invoice batch first.

