#!/usr/bin/env python3
"""
GnuCash Invoice Batch Creator
Copyright (C) 2026 Alan Johnson / contributors
SPDX-License-Identifier: GPL-3.0-or-later

Read a GnuCash SQLite/XML book for customer and invoice IDs, scan uploaded
customer lists, scan selectable customers from the book, and generate GnuCash invoice import CSV rows.
"""
from __future__ import annotations

import argparse
import csv
import gzip
import json
import os
import re
import shutil
import sqlite3
import subprocess
import sys
import zipfile
import xml.etree.ElementTree as ET
from dataclasses import dataclass
from datetime import date, datetime
from decimal import Decimal, InvalidOperation
from pathlib import Path
from typing import Any, Iterable

# Match the PHP application runtime policy: files created by this helper should
# be group-readable/writable in setgid profile directories. Ownership still
# follows the PHP-FPM user that launched this script.
os.umask(0o007)


def apply_runtime_permissions(path: Path) -> None:
    try:
        if path.is_dir():
            path.chmod(0o2770)
        elif path.exists():
            path.chmod(0o660)
    except OSError:
        pass


def ensure_runtime_dir(path: Path) -> None:
    path.mkdir(parents=True, exist_ok=True)
    apply_runtime_permissions(path)


FIELDNAMES = [
    "id",
    "date_opened",
    "owner_id",
    "billingid",
    "notes",
    "date",
    "desc",
    "action",
    "account",
    "quantity",
    "price",
    "disc_type",
    "disc_how",
    "discount",
    "taxable",
    "taxincluded",
    "tax_table",
    "date_posted",
    "due_date",
    "account_posted",
    "memo_posted",
    "accu_splits",
]

CUSTOMER_HEADER_NAMES = {
    "customer_id",
    "customer id",
    "customer",
    "gnucash_customer_id",
    "gnucash customer id",
    "owner_id",
    "owner id",
    "id",
}


@dataclass
class Customer:
    id: str
    name: str = ""
    active: str = ""
    guid: str = ""


def print_json(data: Any) -> None:
    json.dump(data, sys.stdout, indent=2, ensure_ascii=False)
    sys.stdout.write("\n")


def err(message: str, code: int = 1) -> None:
    print_json({"ok": False, "error": message})
    raise SystemExit(code)


def text_value(value: Any) -> str:
    return "" if value is None else str(value)


def local_name(tag: str) -> str:
    if "}" in tag:
        return tag.rsplit("}", 1)[1]
    if ":" in tag:
        return tag.rsplit(":", 1)[1]
    return tag


def child_text(element: ET.Element, wanted: str) -> str:
    for child in list(element):
        if local_name(child.tag).lower() == wanted.lower():
            return (child.text or "").strip()
    return ""


def try_sqlite_book(book_path: Path) -> tuple[list[Customer], list[str]] | None:
    try:
        con = sqlite3.connect(f"file:{book_path}?mode=ro", uri=True)
    except sqlite3.Error:
        return None
    try:
        cur = con.cursor()
        tables = {row[0] for row in cur.execute("SELECT name FROM sqlite_master WHERE type='table'")}
        if "customers" not in tables and "invoices" not in tables:
            return None

        customers: list[Customer] = []
        if "customers" in tables:
            columns = {row[1] for row in cur.execute("PRAGMA table_info(customers)")}
            id_col = "id" if "id" in columns else None
            if id_col:
                name_col = "name" if "name" in columns else "''"
                active_col = "active" if "active" in columns else "''"
                guid_col = "guid" if "guid" in columns else "''"
                query = f"SELECT {id_col}, {name_col}, {active_col}, {guid_col} FROM customers ORDER BY {id_col}"
                for row in cur.execute(query):
                    cid = str(row[0] or "").strip()
                    if cid:
                        customers.append(Customer(cid, text_value(row[1]), text_value(row[2]), text_value(row[3])))

        invoice_ids: list[str] = []
        if "invoices" in tables:
            columns = {row[1] for row in cur.execute("PRAGMA table_info(invoices)")}
            if "id" in columns:
                for row in cur.execute("SELECT id FROM invoices WHERE id IS NOT NULL"):
                    iid = str(row[0] or "").strip()
                    if iid:
                        invoice_ids.append(iid)
        return customers, invoice_ids
    except sqlite3.Error as exc:
        raise RuntimeError(f"SQLite book scan failed: {exc}") from exc
    finally:
        con.close()




def try_sqlite_accounts(book_path: Path) -> list[dict[str, Any]] | None:
    """Return GnuCash account metadata from a SQLite book.

    The invoice importer expects account names/full account paths, not GUIDs,
    so the web UI uses these rows to offer valid account selections.
    """
    try:
        con = sqlite3.connect(f"file:{book_path}?mode=ro", uri=True)
        con.row_factory = sqlite3.Row
    except sqlite3.Error:
        return None
    try:
        cur = con.cursor()
        tables = {row[0] for row in cur.execute("SELECT name FROM sqlite_master WHERE type='table'")}
        if "accounts" not in tables:
            return None
        rows = {str(row["guid"]): dict(row) for row in cur.execute("SELECT guid, name, account_type, parent_guid FROM accounts")}

        def full_name(guid: str, seen: set[str] | None = None) -> str:
            seen = seen or set()
            row = rows.get(guid)
            if not row:
                return guid
            name = str(row.get("name") or "")
            parent = str(row.get("parent_guid") or "")
            if not parent or parent in seen or parent not in rows:
                return name
            parent_name = full_name(parent, seen | {guid})
            if parent_name in {"Root Account", "Root", ""}:
                return name
            return parent_name + ":" + name

        accounts = []
        for guid, row in rows.items():
            account_type = str(row.get("account_type") or "")
            name = str(row.get("name") or "")
            if not name:
                continue
            accounts.append({
                "guid": guid,
                "name": name,
                "full_name": full_name(guid),
                "account_type": account_type,
            })
        accounts.sort(key=lambda a: natural_key(str(a.get("full_name") or a.get("name") or "")))
        return accounts
    finally:
        con.close()


def scan_xml_accounts(book_path: Path) -> list[dict[str, Any]]:
    try:
        root = ET.fromstring(open_xml_bytes(book_path))
    except Exception:
        return []
    rows: dict[str, dict[str, str]] = {}
    for elem in root.iter():
        if local_name(elem.tag) == "GncAccount":
            guid = child_text(elem, "id") or child_text(elem, "guid")
            name = child_text(elem, "name")
            account_type = child_text(elem, "type")
            parent = child_text(elem, "parent")
            if guid and name:
                rows[guid] = {"guid": guid, "name": name, "account_type": account_type, "parent_guid": parent}

    def full_name(guid: str, seen: set[str] | None = None) -> str:
        seen = seen or set()
        row = rows.get(guid)
        if not row:
            return guid
        name = row.get("name", "")
        parent = row.get("parent_guid", "")
        if not parent or parent in seen or parent not in rows:
            return name
        parent_name = full_name(parent, seen | {guid})
        if parent_name in {"Root Account", "Root", ""}:
            return name
        return parent_name + ":" + name

    accounts = [{"guid": guid, "name": row["name"], "full_name": full_name(guid), "account_type": row.get("account_type", "")} for guid, row in rows.items()]
    accounts.sort(key=lambda a: natural_key(str(a.get("full_name") or a.get("name") or "")))
    return accounts


def scan_accounts(book_path: str | Path) -> list[dict[str, Any]]:
    path = Path(book_path).expanduser()
    sqlite_accounts = try_sqlite_accounts(path)
    if sqlite_accounts is not None:
        return sqlite_accounts
    return scan_xml_accounts(path)


def is_income_account(account: dict[str, Any]) -> bool:
    return str(account.get("account_type") or "").upper() == "INCOME"


def is_receivable_account(account: dict[str, Any]) -> bool:
    return str(account.get("account_type") or "").upper() in {"RECEIVABLE", "A/RECEIVABLE"}

def open_xml_bytes(book_path: Path) -> bytes:
    raw = book_path.read_bytes()
    if raw[:2] == b"\x1f\x8b":
        return gzip.decompress(raw)
    return raw


def scan_xml_book(book_path: Path) -> tuple[list[Customer], list[str]]:
    try:
        root = ET.fromstring(open_xml_bytes(book_path))
    except Exception as exc:  # noqa: BLE001
        raise RuntimeError(f"Unable to parse book as SQLite or XML/gzipped XML: {exc}") from exc

    customers: list[Customer] = []
    invoice_ids: list[str] = []

    for elem in root.iter():
        lname = local_name(elem.tag)
        if lname == "GncCustomer":
            cid = child_text(elem, "id")
            if cid:
                customers.append(
                    Customer(
                        id=cid,
                        name=child_text(elem, "name"),
                        active=child_text(elem, "active"),
                        guid=child_text(elem, "guid"),
                    )
                )
        elif lname == "GncInvoice":
            iid = child_text(elem, "id")
            if iid:
                invoice_ids.append(iid)

    customers.sort(key=lambda c: natural_key(c.id))
    return customers, invoice_ids


def scan_book(book_path: str | Path) -> tuple[list[Customer], list[str]]:
    path = Path(book_path).expanduser()
    if not path.is_file():
        raise FileNotFoundError(f"GnuCash book not found: {path}")
    sqlite_result = try_sqlite_book(path)
    if sqlite_result is not None:
        return sqlite_result
    return scan_xml_book(path)


def natural_key(value: str) -> list[Any]:
    parts = re.split(r"(\d+)", value)
    return [int(p) if p.isdigit() else p.lower() for p in parts]


def suggest_next_invoice_id(invoice_ids: Iterable[str], prefix: str = "", padding: int = 0) -> str:
    numbers: list[tuple[int, int]] = []
    if prefix:
        pattern = re.compile(r"^" + re.escape(prefix) + r"(\d+)$")
    else:
        pattern = re.compile(r"^(\d+)$")

    for invoice_id in invoice_ids:
        match = pattern.match(str(invoice_id).strip())
        if match:
            digits = match.group(1)
            numbers.append((int(digits), len(digits)))

    if not numbers:
        width = padding if padding > 0 else 0
        return prefix + (str(1).zfill(width) if width else "1")

    max_num = max(num for num, _width in numbers)
    detected_width = max(width for _num, width in numbers)
    width = padding if padding > 0 else detected_width
    return prefix + str(max_num + 1).zfill(width)


def increment_invoice_id(start: str, offset: int) -> str:
    match = re.match(r"^(.*?)(\d+)$", start)
    if not match:
        if offset == 0:
            return start
        return f"{start}-{offset + 1}"
    prefix, digits = match.groups()
    return f"{prefix}{str(int(digits) + offset).zfill(len(digits))}"


def customer_is_active(value: Any) -> bool:
    """Return a practical active/inactive flag for GnuCash customer rows.

    SQLite books normally use customers.active as 1 or 0. XML exports may use
    text values. Missing/blank active values are treated as active so older or
    unusual books do not hide customers unexpectedly.
    """
    raw = str(value or "").strip().lower()
    if raw in {"0", "false", "no", "n", "inactive"}:
        return False
    return True


def customers_to_json(customers: list[Customer]) -> list[dict[str, Any]]:
    return [
        {
            "id": c.id,
            "name": c.name,
            "active": c.active,
            "active_bool": customer_is_active(c.active),
            "guid": c.guid,
        }
        for c in customers
    ]


def scan_book_json(args: argparse.Namespace) -> None:
    customers, invoice_ids = scan_book(args.book)
    accounts = scan_accounts(args.book)
    active_count = sum(1 for c in customers if customer_is_active(c.active))
    inactive_count = len(customers) - active_count
    print_json(
        {
            "ok": True,
            "book": str(Path(args.book).expanduser()),
            "customer_count": len(customers),
            "active_customer_count": active_count,
            "inactive_customer_count": inactive_count,
            "invoice_count": len(invoice_ids),
            "next_invoice_id": suggest_next_invoice_id(invoice_ids, args.prefix or "", int(args.padding or 0)),
            "customers": customers_to_json(customers),
            "account_count": len(accounts),
            "accounts": accounts,
            "income_accounts": [a for a in accounts if is_income_account(a)],
            "receivable_accounts": [a for a in accounts if is_receivable_account(a)],
        }
    )


def read_csv_rows(path: Path) -> list[list[str]]:
    data = path.read_text(errors="replace")
    sample = data[:4096]
    try:
        dialect = csv.Sniffer().sniff(sample, delimiters=",;\t|")
    except csv.Error:
        dialect = csv.excel
    return [[cell.strip() for cell in row] for row in csv.reader(data.splitlines(), dialect)]


def read_xlsx_rows(path: Path) -> list[list[str]]:
    try:
        from openpyxl import load_workbook  # type: ignore
    except Exception as exc:  # noqa: BLE001
        raise RuntimeError("XLSX upload requires python3-openpyxl to be installed.") from exc
    wb = load_workbook(path, read_only=True, data_only=True)
    ws = wb.active
    rows: list[list[str]] = []
    for row in ws.iter_rows(values_only=True):
        rows.append(["" if cell is None else str(cell).strip() for cell in row])
    return rows


def extract_ids_from_rows(rows: list[list[str]], known_ids: set[str]) -> list[str]:
    if not rows:
        return []
    # Header-driven mode.
    header = [cell.strip().lower() for cell in rows[0]]
    id_indexes = [idx for idx, name in enumerate(header) if name in CUSTOMER_HEADER_NAMES]
    found: list[str] = []
    if id_indexes:
        for row in rows[1:]:
            for idx in id_indexes:
                if idx < len(row):
                    value = row[idx].strip()
                    if value:
                        found.append(value)
        return dedupe(found)

    # Exact-match mode against known GnuCash customer IDs.
    for row in rows:
        for cell in row:
            cell = cell.strip()
            if cell in known_ids:
                found.append(cell)
    if found:
        return dedupe(found)

    # Fallback: first non-empty cell from each row.
    for row in rows:
        for cell in row:
            if cell.strip():
                found.append(cell.strip())
                break
    return dedupe(found)


def extract_ids_from_file(input_path: str | Path, known_ids: set[str]) -> list[str]:
    path = Path(input_path).expanduser()
    if not path.is_file():
        raise FileNotFoundError(f"Input file not found: {path}")
    suffix = path.suffix.lower()
    if suffix == ".xlsx":
        rows = read_xlsx_rows(path)
    elif suffix in {".csv", ".tsv"}:
        rows = read_csv_rows(path)
    else:
        text = path.read_text(errors="replace")
        rows = [[line.strip()] for line in text.splitlines() if line.strip()]
    return extract_ids_from_rows(rows, known_ids)


def dedupe(values: Iterable[str]) -> list[str]:
    out: list[str] = []
    seen: set[str] = set()
    for value in values:
        clean = str(value).strip().strip('"').strip("'")
        if not clean or clean in seen:
            continue
        out.append(clean)
        seen.add(clean)
    return out


def match_customer_ids(ids: Iterable[str], customers: list[Customer]) -> dict[str, Any]:
    by_id = {c.id: c for c in customers}
    matched: list[dict[str, str]] = []
    unmatched: list[str] = []
    for cid in dedupe(ids):
        customer = by_id.get(cid)
        if customer is None:
            unmatched.append(cid)
        else:
            matched.append({"id": customer.id, "name": customer.name, "active": customer.active, "active_bool": customer_is_active(customer.active), "guid": customer.guid})
    return {"matched": matched, "unmatched": unmatched}


def scan_upload_json(args: argparse.Namespace) -> None:
    customers, invoice_ids = scan_book(args.book)
    known_ids = {c.id for c in customers}
    ids = extract_ids_from_file(args.input, known_ids)
    result = match_customer_ids(ids, customers)
    result.update(
        {
            "ok": True,
            "input_count": len(ids),
            "customer_count": len(customers),
            "invoice_count": len(invoice_ids),
            "next_invoice_id": suggest_next_invoice_id(invoice_ids, args.prefix or "", int(args.padding or 0)),
        }
    )
    print_json(result)


def scan_ids_json(args: argparse.Namespace) -> None:
    payload = json.load(sys.stdin)
    customers, invoice_ids = scan_book(args.book)
    ids = payload.get("customer_ids", [])
    result = match_customer_ids(ids, customers)
    result.update(
        {
            "ok": True,
            "input_count": len(ids),
            "customer_count": len(customers),
            "invoice_count": len(invoice_ids),
            "next_invoice_id": suggest_next_invoice_id(invoice_ids, args.prefix or "", int(args.padding or 0)),
        }
    )
    print_json(result)


def decimal_string(value: Any, default: str = "0") -> str:
    try:
        dec = Decimal(str(value).strip() or default)
    except (InvalidOperation, ValueError):
        dec = Decimal(default)
    # Use fixed-point plain string, removing unnecessary trailing zeroes.
    s = format(dec, "f")
    if "." in s:
        s = s.rstrip("0").rstrip(".")
    return s or "0"


def money_string(value: Any, default: str = "0") -> str:
    """Return a money-style decimal string with exactly two fractional digits.

    GnuCash invoice import is sensitive to locale/decimal settings. Exporting
    prices as explicit fixed-point values, e.g. 15.00 instead of 15, avoids
    cases where whole-dollar prices are interpreted as cents.
    """
    try:
        dec = Decimal(str(value).strip() or default)
    except (InvalidOperation, ValueError):
        dec = Decimal(default)
    return format(dec.quantize(Decimal("0.01")), "f")


def yn(value: bool) -> str:
    return "Y" if bool(value) else "N"




def normalize_date_format(fmt: str) -> str:
    raw = str(fmt or "").strip()
    compact = raw.lower().replace(" ", "")
    mapping = {
        "m/d/y": "%m/%d/%Y",
        "mm/dd/yyyy": "%m/%d/%Y",
        "m/d/yyyy": "%m/%d/%Y",
        "%m/%d/%y": "%m/%d/%Y",
        "%m/%d/%Y".lower(): "%m/%d/%Y",
        "d/m/y": "%d/%m/%Y",
        "dd/mm/yyyy": "%d/%m/%Y",
        "d/m/yyyy": "%d/%m/%Y",
        "%d/%m/%y": "%d/%m/%Y",
        "%d/%m/%Y".lower(): "%d/%m/%Y",
        "y-m-d": "%Y-%m-%d",
        "yyyy-mm-dd": "%Y-%m-%d",
        "%Y-%m-%d".lower(): "%Y-%m-%d",
        "y/m/d": "%Y/%m/%d",
        "yyyy/mm/dd": "%Y/%m/%d",
        "d.m.y": "%d.%m.%Y",
        "dd.mm.yyyy": "%d.%m.%Y",
        "m-d-y": "%m-%d-%Y",
        "mm-dd-yyyy": "%m-%d-%Y",
        "d-m-y": "%d-%m-%Y",
        "dd-mm-yyyy": "%d-%m-%Y",
    }
    if compact in mapping:
        return mapping[compact]
    # Translate common PHP date tokens to strftime tokens.
    py = raw.replace("Y", "%Y").replace("y", "%y").replace("m", "%m").replace("n", "%m").replace("d", "%d").replace("j", "%d")
    if "%" in py:
        return py
    return "%m/%d/%Y"


def parse_form_date(value: Any) -> date | None:
    raw = str(value or "").strip()
    if not raw:
        return None
    for fmt in ("%Y-%m-%d", "%m/%d/%Y", "%d/%m/%Y", "%m-%d-%Y", "%d-%m-%Y", "%Y/%m/%d", "%d.%m.%Y"):
        try:
            return datetime.strptime(raw[:10] if fmt == "%Y-%m-%d" else raw, fmt).date()
        except ValueError:
            continue
    return None


def import_date(value: Any, fmt: str) -> str:
    dt = parse_form_date(value)
    if dt is None:
        return str(value or "")
    return dt.strftime(normalize_date_format(fmt))

def generate_json(args: argparse.Namespace) -> None:
    payload = json.load(sys.stdin)
    customers, invoice_ids = scan_book(args.book)
    known_ids = {c.id for c in customers}
    customer_ids = [cid for cid in dedupe(payload.get("customer_ids", [])) if cid in known_ids]
    params = payload.get("params", {}) or {}
    if not customer_ids:
        err("No valid customer IDs were supplied for generation.")

    start_id = str(params.get("start_invoice_id") or "").strip()
    if not start_id:
        start_id = suggest_next_invoice_id(invoice_ids, args.prefix or "", int(args.padding or 0))

    delimiter = str(params.get("csv_delimiter") or ",")
    if delimiter not in {",", ";"}:
        delimiter = ","

    posted = bool(params.get("posted", True))
    taxable = bool(params.get("taxable", False))
    taxincluded = bool(params.get("taxincluded", False))
    accu_splits = bool(params.get("accu_splits", False))
    csv_date_format = str(params.get("csv_date_format") or "m/d/Y")

    rows: list[list[str]] = []
    warnings: list[str] = []
    generated_ids: list[str] = []
    existing = set(invoice_ids)

    for idx, customer_id in enumerate(customer_ids):
        invoice_id = increment_invoice_id(start_id, idx)
        generated_ids.append(invoice_id)
        if invoice_id in existing:
            warnings.append(f"Generated invoice ID already exists in book: {invoice_id}")

        row = [
            invoice_id,
            import_date(params.get("date_opened") or "", csv_date_format),
            customer_id,
            str(params.get("billing_id") or ""),
            str(params.get("notes") or ""),
            import_date(params.get("entry_date") or params.get("date_opened") or "", csv_date_format),
            str(params.get("description") or ""),
            str(params.get("action") or "ea"),
            str(params.get("income_account") or ""),
            decimal_string(params.get("quantity", "1"), "1"),
            money_string(params.get("price", "0"), "0"),
            "%" if decimal_string(params.get("discount", "0"), "0") != "0" else "",
            "=" if decimal_string(params.get("discount", "0"), "0") != "0" else "",
            money_string(params.get("discount", "0"), "0"),
            yn(taxable),
            yn(taxincluded),
            str(params.get("tax_table") or ""),
            import_date(params.get("date_posted") or "", csv_date_format) if posted else "",
            import_date(params.get("due_date") or "", csv_date_format) if posted else "",
            str(params.get("ar_account") or "") if posted else "",
            str(params.get("memo_posted") or "") if posted else "",
            yn(accu_splits) if posted and accu_splits else "",
        ]
        rows.append(row)

    out = Path(args.out).expanduser()
    ensure_runtime_dir(out.parent)
    with out.open("w", newline="", encoding="utf-8") as fh:
        writer = csv.writer(fh, delimiter=delimiter, quoting=csv.QUOTE_MINIMAL)
        writer.writerows(rows)
    apply_runtime_permissions(out)

    print_json(
        {
            "ok": True,
            "output": str(out),
            "invoice_count": len(customer_ids),
            "row_count": len(rows),
            "first_invoice_id": generated_ids[0] if generated_ids else "",
            "last_invoice_id": generated_ids[-1] if generated_ids else "",
            "warnings": warnings,
            "fields": FIELDNAMES,
        }
    )



# ---------------------------------------------------------------------------
# Customer report generation
# ---------------------------------------------------------------------------

REPORT_CSS = """
@page { size: __PAGE_SIZE__; margin: 0.45in; }
* { box-sizing: border-box; }
body { font-family: Arial, Helvetica, sans-serif; color: #222; font-size: 11pt; }
a { color: #1a3db7; text-decoration: underline; }
.report-header { display: grid; grid-template-columns: 1fr auto; gap: 1rem; align-items: start; margin-bottom: 1rem; }
.report-title { font-size: 18pt; font-weight: 700; margin: 0 0 .8rem 0; }
.report-meta { text-align: right; line-height: 1.8; }
.logo { max-height: 72px; max-width: 240px; display: block; margin-left: auto; margin-bottom: .4rem; }
.customer-name { margin: .2rem 0 .7rem 0; font-size: 12pt; }
.account-section { margin: 1.1rem 0 1.5rem 0; break-inside: auto; }
.account-title { font-size: 16pt; margin: 1rem 0 .6rem 0; break-after: avoid; }
table { border-collapse: collapse; width: 100%; }
thead { display: table-header-group; }
tr { break-inside: avoid; }
th, td { border: 1px solid #8a8a8a; padding: 4px 5px; vertical-align: top; }
th { font-weight: 700; text-align: center; background: #f4f4f4; }
td.num, th.num { text-align: right; white-space: nowrap; }
td.date, td.ref, td.type { white-space: nowrap; }
.period-total td { font-weight: 700; }
.balance-negative { color: #d00000; }
.money-link { color: #1a3db7; text-decoration: underline; font-style: italic; }
.total-row td { font-weight: 700; }
.aging-table { width: auto; margin: .55rem 0 1rem 0; break-inside: avoid; }
.aging-table th, .aging-table td { min-width: 4.8rem; }
.footer { margin-top: 1rem; color: #555; font-size: 9pt; }
.page-break { break-after: page; }
"""


def sqlite_connect_ro(book_path: Path) -> sqlite3.Connection:
    con = sqlite3.connect(f"file:{book_path}?mode=ro", uri=True)
    con.row_factory = sqlite3.Row
    return con


def table_columns(cur: sqlite3.Cursor, table: str) -> set[str]:
    return {row[1] for row in cur.execute(f"PRAGMA table_info({table})")}


def table_exists(cur: sqlite3.Cursor, table: str) -> bool:
    return cur.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", (table,)).fetchone() is not None


def dec_from_num_denom(num: Any, denom: Any) -> Decimal:
    try:
        denom_d = Decimal(str(denom or 1))
        if denom_d == 0:
            denom_d = Decimal(1)
        return Decimal(str(num or 0)) / denom_d
    except Exception:
        return Decimal("0")


def money(value: Decimal | int | float | str) -> str:
    d = Decimal(str(value)).quantize(Decimal("0.01"))
    prefix = "-$" if d < 0 else "$"
    return f"{prefix}{abs(d):,.2f}"


def money_cell(value: Decimal) -> str:
    cls = "balance-negative" if value < 0 else ""
    return f'<span class="{cls}">{html_escape(money(value))}</span>'


def html_escape(value: Any) -> str:
    import html
    return html.escape(str(value or ""), quote=True)


def parse_iso_date(value: str, fallback: date | None = None) -> date:
    value = str(value or "").strip()[:10]
    try:
        return date.fromisoformat(value)
    except ValueError:
        return fallback or date.today()


def display_date(value: str) -> str:
    value = str(value or "").strip()[:10]
    try:
        return datetime.strptime(value, "%Y-%m-%d").strftime("%m/%d/%Y")
    except ValueError:
        return value


def account_full_names(con: sqlite3.Connection) -> dict[str, str]:
    cur = con.cursor()
    if not table_exists(cur, "accounts"):
        return {}
    rows = {row["guid"]: dict(row) for row in cur.execute("SELECT guid, name, parent_guid FROM accounts")}

    def full(guid: str, seen: set[str] | None = None) -> str:
        seen = seen or set()
        row = rows.get(guid)
        if not row:
            return guid
        name = str(row.get("name") or "")
        parent = str(row.get("parent_guid") or "")
        if not parent or parent in seen or parent not in rows:
            return name
        parent_name = full(parent, seen | {guid})
        if parent_name in {"Root Account", "Root", ""}:
            return name
        return parent_name + ":" + name

    return {guid: full(guid) for guid in rows}


def report_metadata_json(args: argparse.Namespace) -> None:
    path = Path(args.book).expanduser()
    try:
        con = sqlite_connect_ro(path)
    except sqlite3.Error as exc:
        raise RuntimeError(f"Report metadata requires a readable SQLite GnuCash book: {exc}") from exc
    try:
        cur = con.cursor()
        if not table_exists(cur, "accounts"):
            raise RuntimeError("No accounts table found. Report generation currently requires a SQLite GnuCash book.")
        accounts = scan_accounts(path)
        ar_accounts = [a for a in accounts if is_receivable_account(a)]
        income_accounts = [a for a in accounts if is_income_account(a)]
        print_json({"ok": True, "accounts": accounts, "ar_accounts": ar_accounts, "income_accounts": income_accounts})
    finally:
        con.close()


def load_report_data(con: sqlite3.Connection, customer_ids: list[str], date_from: date, date_to: date, selected_ar: set[str]) -> dict[str, Any]:
    cur = con.cursor()
    needed = ["customers", "invoices", "accounts", "splits", "transactions"]
    missing = [t for t in needed if not table_exists(cur, t)]
    if missing:
        raise RuntimeError("Customer reports require a SQLite GnuCash book with these missing tables: " + ", ".join(missing))

    full_names = account_full_names(con)
    customers_by_id: dict[str, dict[str, Any]] = {}
    customers_by_guid: dict[str, dict[str, Any]] = {}
    customer_cols = table_columns(cur, "customers")
    name_expr = "name" if "name" in customer_cols else "id"
    for row in cur.execute(f"SELECT guid, id, {name_expr} AS name FROM customers"):
        item = {"guid": row["guid"], "id": str(row["id"]), "name": str(row["name"] or row["id"])}
        customers_by_id[item["id"]] = item
        customers_by_guid[item["guid"]] = item

    # Pull posted invoice/credit note A/R splits for selected customers.
    customer_guids = [customers_by_id[cid]["guid"] for cid in customer_ids if cid in customers_by_id]
    if not customer_guids:
        return {"customers": [], "accounts": []}

    qmarks = ",".join("?" for _ in customer_guids)
    invoice_rows = list(cur.execute(
        f"""
        SELECT i.guid, i.id, i.owner_guid, i.date_opened, i.date_posted, i.notes, i.billing_id,
               i.post_txn, i.post_lot, i.post_acc,
               t.post_date AS txn_post_date, t.description AS txn_description,
               s.value_num, s.value_denom, s.memo AS split_memo, s.account_guid
        FROM invoices i
        LEFT JOIN transactions t ON t.guid = i.post_txn
        LEFT JOIN splits s ON s.tx_guid = i.post_txn AND (s.account_guid = i.post_acc OR i.post_acc IS NULL OR i.post_acc = '')
        WHERE i.owner_guid IN ({qmarks})
          AND i.post_txn IS NOT NULL AND i.post_txn != ''
        """,
        customer_guids,
    ))

    by_customer: dict[str, dict[str, Any]] = {}
    invoice_txns: set[str] = set()
    lots_by_customer_account: dict[tuple[str, str], set[str]] = {}
    invoice_amounts: dict[str, Decimal] = {}

    for row in invoice_rows:
        cust = customers_by_guid.get(row["owner_guid"])
        if not cust:
            continue
        acct = str(row["account_guid"] or row["post_acc"] or "")
        if selected_ar and acct not in selected_ar:
            continue
        tx_date_raw = str(row["txn_post_date"] or row["date_posted"] or row["date_opened"] or "")[:10]
        tx_date = parse_iso_date(tx_date_raw)
        if tx_date < date_from or tx_date > date_to:
            continue
        amount = dec_from_num_denom(row["value_num"], row["value_denom"])
        inv_guid = str(row["guid"])
        if inv_guid in invoice_amounts:
            # Avoid duplicate A/R splits in odd books; keep first matching A/R row.
            continue
        invoice_amounts[inv_guid] = amount
        invoice_txns.add(str(row["post_txn"] or ""))
        lot = str(row["post_lot"] or "")
        if lot:
            lots_by_customer_account.setdefault((cust["id"], acct), set()).add(lot)
        desc = first_invoice_description(cur, inv_guid) or row["notes"] or row["billing_id"] or row["txn_description"] or "Invoice"
        row_item = {
            "date": tx_date_raw,
            "due_date": tx_date_raw,
            "reference": str(row["id"] or ""),
            "type": "Invoice" if amount >= 0 else "Credit Note",
            "description": str(desc),
            "debit": amount if amount > 0 else Decimal("0"),
            "credit": -amount if amount < 0 else Decimal("0"),
            "amount": amount,
            "sort": (tx_date_raw, 1, str(row["id"] or "")),
        }
        add_report_row(by_customer, cust, acct, full_names.get(acct, acct), row_item)

    # Payment/credit splits against lots belonging to each customer's posted invoices.
    for (customer_id, acct), lots in lots_by_customer_account.items():
        if not lots:
            continue
        placeholders = ",".join("?" for _ in lots)
        params = [acct, *lots]
        for row in cur.execute(
            f"""
            SELECT s.guid, s.tx_guid, s.memo, s.value_num, s.value_denom, s.lot_guid,
                   t.post_date, t.num, t.description
            FROM splits s
            JOIN transactions t ON t.guid = s.tx_guid
            WHERE s.account_guid = ?
              AND s.lot_guid IN ({placeholders})
            ORDER BY t.post_date, t.num, s.guid
            """,
            params,
        ):
            if str(row["tx_guid"] or "") in invoice_txns:
                continue
            tx_date_raw = str(row["post_date"] or "")[:10]
            tx_date = parse_iso_date(tx_date_raw)
            if tx_date < date_from or tx_date > date_to:
                continue
            amount = dec_from_num_denom(row["value_num"], row["value_denom"])
            if amount == 0:
                continue
            cust = customers_by_id[customer_id]
            desc = row["description"] or row["memo"] or "Payment"
            row_item = {
                "date": tx_date_raw,
                "due_date": "",
                "reference": str(row["num"] or ""),
                "type": "Payment" if amount < 0 else "Adjustment",
                "description": str(desc),
                "debit": amount if amount > 0 else Decimal("0"),
                "credit": -amount if amount < 0 else Decimal("0"),
                "amount": amount,
                "sort": (tx_date_raw, 2, str(row["num"] or "")),
            }
            add_report_row(by_customer, cust, acct, full_names.get(acct, acct), row_item)

    return {"customers": list(by_customer.values())}


def first_invoice_description(cur: sqlite3.Cursor, invoice_guid: str) -> str:
    if not table_exists(cur, "entries"):
        return ""
    cols = table_columns(cur, "entries")
    if "invoice" not in cols:
        return ""
    desc_col = "description" if "description" in cols else "notes" if "notes" in cols else "''"
    row = cur.execute(f"SELECT {desc_col} AS description FROM entries WHERE invoice=? ORDER BY date LIMIT 1", (invoice_guid,)).fetchone()
    return str(row["description"] or "") if row else ""


def add_report_row(by_customer: dict[str, dict[str, Any]], cust: dict[str, Any], acct: str, acct_name: str, row_item: dict[str, Any]) -> None:
    c = by_customer.setdefault(cust["id"], {"id": cust["id"], "name": cust["name"], "accounts": {}})
    a = c["accounts"].setdefault(acct, {"guid": acct, "name": acct_name, "rows": []})
    a["rows"].append(row_item)


def calc_aging(rows: list[dict[str, Any]], as_of: date) -> dict[str, Decimal]:
    # Conservative first pass: place the ending balance into an age bucket based on the oldest
    # unpaid positive invoice date. Negative balances are shown in Total only.
    balance = sum((r["amount"] for r in rows), Decimal("0"))
    buckets = {"prepayment": Decimal("0"), "current": Decimal("0"), "0-30": Decimal("0"), "31-60": Decimal("0"), "61-90": Decimal("0"), "91+": Decimal("0"), "total": balance}
    if balance < 0:
        buckets["prepayment"] = balance
        return buckets
    if balance == 0:
        return buckets
    invoice_dates = [parse_iso_date(r.get("due_date") or r.get("date") or "") for r in rows if r.get("debit", Decimal("0")) > 0]
    age = (as_of - min(invoice_dates or [as_of])).days
    if age <= 0:
        key = "current"
    elif age <= 30:
        key = "0-30"
    elif age <= 60:
        key = "31-60"
    elif age <= 90:
        key = "61-90"
    else:
        key = "91+"
    buckets[key] = balance
    return buckets


def render_customer_html(customer: dict[str, Any], payload: dict[str, Any], style_css: str) -> str:
    as_of = parse_iso_date(payload.get("date_to", ""))
    page_size = str(payload.get("page_size") or "Letter")
    css = REPORT_CSS.replace("__PAGE_SIZE__", page_size) + "\n" + style_css + "\n" + str(payload.get("custom_css") or "")
    logo = ""
    logo_path = str(payload.get("logo_path") or "")
    if logo_path and Path(logo_path).is_file():
        logo_uri = Path(logo_path).resolve().as_uri()
        logo = f'<img class="logo" src="{html_escape(logo_uri)}" alt="Logo">'
    header = f"""
    <div class="report-header">
      <div>
        <h1 class="report-title">Customer Report: <a>{html_escape(customer['name'])}</a></h1>
        <div class="customer-name">{html_escape(customer['name'])}</div>
        <div>Date Range: {html_escape(display_date(payload.get('date_from','')))} - {html_escape(display_date(payload.get('date_to','')))}</div>
      </div>
      <div class="report-meta">{logo}<div>{html_escape(payload.get('organization_name',''))}</div><div>{html_escape(display_date(payload.get('date_to','')))}</div></div>
    </div>
    """
    sections = []
    for account in sorted(customer.get("accounts", {}).values(), key=lambda a: a.get("name", "")):
        rows = sorted(account["rows"], key=lambda r: r["sort"])
        if not rows:
            continue
        balance = Decimal("0")
        debit_total = Decimal("0")
        credit_total = Decimal("0")
        body_rows = []
        for r in rows:
            debit_total += r["debit"]
            credit_total += r["credit"]
            balance += r["amount"]
            body_rows.append(
                "<tr>"
                f"<td class=\"date\">{html_escape(display_date(r['date']))}</td>"
                f"<td class=\"date\">{html_escape(display_date(r.get('due_date','')))}</td>"
                f"<td class=\"ref\"><a>{html_escape(r.get('reference',''))}</a></td>"
                f"<td class=\"type\">{html_escape(r.get('type',''))}</td>"
                f"<td>{html_escape(r.get('description',''))}</td>"
                f"<td class=\"num\">{('<span class=\"money-link\">' + html_escape(money(r['debit'])) + '</span>') if r['debit'] else ''}</td>"
                f"<td class=\"num\">{('<span class=\"money-link\">' + html_escape(money(r['credit'])) + '</span>') if r['credit'] else ''}</td>"
                f"<td class=\"num\">{money_cell(balance)}</td>"
                "</tr>"
            )
        aging = calc_aging(rows, as_of)
        due_label = "Total Credit" if balance < 0 else "Total Due"
        sections.append(
            f"<section class=\"account-section\"><h2 class=\"account-title\">Account: {html_escape(account['name'])}</h2>"
            "<table><thead><tr><th>Date</th><th>Due Date</th><th>Reference</th><th>Type</th><th>Description</th><th class=\"num\">Debits</th><th class=\"num\">Credits</th><th class=\"num\">Balance</th></tr></thead><tbody>"
            + "\n".join(body_rows)
            + f"<tr class=\"period-total\"><td colspan=\"5\">Period<br>Totals</td><td class=\"num\">{html_escape(money(debit_total))}</td><td class=\"num\">{html_escape(money(credit_total))}</td><td class=\"num\">{money_cell(balance)}</td></tr>"
            + f"<tr class=\"total-row\"><td colspan=\"7\">{due_label}</td><td class=\"num\">{money_cell(balance)}</td></tr></tbody></table>"
            + "<table class=\"aging-table\"><thead><tr><th>Pre-Payment</th><th>Current</th><th>0-30 days</th><th>31-60 days</th><th>61-90 days</th><th>91+ days</th><th>Total</th></tr></thead><tbody><tr>"
            + f"<td>{html_escape(money(aging['prepayment']))}</td><td>{html_escape(money(aging['current']))}</td><td>{html_escape(money(aging['0-30']))}</td><td>{html_escape(money(aging['31-60']))}</td><td>{html_escape(money(aging['61-90']))}</td><td>{html_escape(money(aging['91+']))}</td><td>{html_escape(money(aging['total']))}</td>"
            + "</tr></tbody></table></section>"
        )
    footer = f"<div class=\"footer\">{html_escape(payload.get('footer_text',''))}</div>" if payload.get("footer_text") else ""
    return "<!doctype html><html><head><meta charset=\"utf-8\"><style>" + css + "</style></head><body>" + header + "\n".join(sections) + footer + "</body></html>"


def extract_style_reference(path: str) -> str:
    if not path:
        return ""
    p = Path(path)
    if not p.is_file():
        return ""
    text = p.read_text(errors="replace")
    styles = re.findall(r"<style[^>]*>(.*?)</style>", text, flags=re.I | re.S)
    return "\n".join(styles)


def safe_filename(value: str) -> str:
    clean = re.sub(r"[^A-Za-z0-9._-]+", "-", value.strip())
    return clean.strip("-._") or "customer"


def render_pdf(chromium_bin: str, html_path: Path, pdf_path: Path) -> None:
    binary = chromium_bin or shutil.which("chromium") or shutil.which("chromium-browser") or shutil.which("google-chrome") or ""
    if binary and not Path(binary).exists():
        found = shutil.which(binary)
        binary = found or binary
    if not binary:
        raise RuntimeError("Chromium binary not found. Install chromium or set chromium_bin in Settings.")
    cmd = [
        binary,
        "--headless=new",
        "--disable-gpu",
        "--no-sandbox",
        "--disable-dev-shm-usage",
        f"--print-to-pdf={pdf_path}",
        str(html_path.resolve().as_uri()),
    ]
    proc = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=120)
    if proc.returncode != 0 or not pdf_path.is_file():
        # Some distro Chromium builds do not support --headless=new.
        cmd[1] = "--headless"
        proc = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=120)
    if proc.returncode != 0 or not pdf_path.is_file():
        raise RuntimeError("Chromium PDF render failed: " + (proc.stderr or proc.stdout or "unknown error"))


def customer_reports_json(args: argparse.Namespace) -> None:
    payload = json.load(sys.stdin)
    book = Path(args.book).expanduser()
    out_dir = Path(args.out_dir).expanduser()
    html_dir = out_dir / "html"
    pdf_dir = out_dir / "pdf"
    ensure_runtime_dir(html_dir)
    ensure_runtime_dir(pdf_dir)
    customer_ids = dedupe(payload.get("customer_ids", []))
    date_from = parse_iso_date(str(payload.get("date_from") or date.today().replace(month=1, day=1)))
    date_to = parse_iso_date(str(payload.get("date_to") or date.today()))
    selected_ar = set(map(str, payload.get("ar_accounts") or []))
    style_css = extract_style_reference(str(payload.get("style_reference_path") or ""))

    try:
        con = sqlite_connect_ro(book)
    except sqlite3.Error as exc:
        raise RuntimeError(f"Customer report generation requires a readable SQLite GnuCash book: {exc}") from exc
    try:
        data = load_report_data(con, customer_ids, date_from, date_to, selected_ar)
    finally:
        con.close()

    include_zero = bool(payload.get("include_zero_balance", True))
    generated: list[dict[str, Any]] = []
    for customer in data["customers"]:
        # Skip customers with no account sections unless requested. At this stage no-row customers are not present.
        html = render_customer_html(customer, payload, style_css)
        base = safe_filename(f"{customer['id']}-{customer['name']}")
        html_path = html_dir / f"{base}.html"
        pdf_path = pdf_dir / f"{base}.pdf"
        html_path.write_text(html, encoding="utf-8")
        apply_runtime_permissions(html_path)
        if not include_zero:
            total = Decimal("0")
            for account in customer.get("accounts", {}).values():
                for row in account.get("rows", []):
                    total += row.get("amount", Decimal("0"))
            if total == 0:
                continue
        render_pdf(str(payload.get("chromium_bin") or ""), html_path, pdf_path)
        apply_runtime_permissions(pdf_path)
        generated.append({"customer_id": customer["id"], "customer_name": customer["name"], "html": str(html_path), "pdf": str(pdf_path), "status": "generated"})

    zip_path = out_dir / "customer-reports.zip"
    with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        for item in generated:
            zf.write(item["pdf"], arcname=Path(item["pdf"]).name)
    apply_runtime_permissions(zip_path)

    manifest = {
        "ok": True,
        "book": str(book),
        "group_name": payload.get("group_name", ""),
        "date_from": str(payload.get("date_from", "")),
        "date_to": str(payload.get("date_to", "")),
        "generated_at": datetime.now().isoformat(timespec="seconds"),
        "pdf_count": len(generated),
        "zip": str(zip_path),
        "customers": generated,
    }
    manifest_path = out_dir / "batch-manifest.json"
    manifest_path.write_text(json.dumps(manifest, indent=2), encoding="utf-8")
    apply_runtime_permissions(manifest_path)
    apply_runtime_permissions(out_dir)
    print_json(manifest)

def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="GnuCash batch invoice creation helper")
    sub = parser.add_subparsers(dest="command", required=True)

    p = sub.add_parser("scan-book", help="Scan a GnuCash book for customers and invoice IDs")
    p.add_argument("--book", required=True)
    p.add_argument("--prefix", default="")
    p.add_argument("--padding", default="0")
    p.set_defaults(func=scan_book_json)

    p = sub.add_parser("scan-upload", help="Scan an uploaded customer ID file")
    p.add_argument("--book", required=True)
    p.add_argument("--input", required=True)
    p.add_argument("--prefix", default="")
    p.add_argument("--padding", default="0")
    p.set_defaults(func=scan_upload_json)

    p = sub.add_parser("scan-ids", help="Validate customer IDs from JSON stdin")
    p.add_argument("--book", required=True)
    p.add_argument("--prefix", default="")
    p.add_argument("--padding", default="0")
    p.set_defaults(func=scan_ids_json)

    p = sub.add_parser("generate", help="Generate GnuCash invoice import CSV from JSON stdin")
    p.add_argument("--book", required=True)
    p.add_argument("--out", required=True)
    p.add_argument("--prefix", default="")
    p.add_argument("--padding", default="0")
    p.set_defaults(func=generate_json)

    p = sub.add_parser("report-metadata", help="Scan a SQLite GnuCash book for report metadata such as A/R accounts")
    p.add_argument("--book", required=True)
    p.set_defaults(func=report_metadata_json)

    p = sub.add_parser("customer-reports", help="Generate batch customer report HTML/PDF/ZIP from JSON stdin")
    p.add_argument("--book", required=True)
    p.add_argument("--out-dir", required=True)
    p.set_defaults(func=customer_reports_json)

    return parser


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    try:
        args.func(args)
    except Exception as exc:  # noqa: BLE001
        print_json({"ok": False, "error": str(exc)})
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
