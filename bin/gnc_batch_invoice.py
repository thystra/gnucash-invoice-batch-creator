#!/usr/bin/env python3
"""
GnuCash Invoice Batch Creator
Copyright (C) 2026 Alan Johnson / contributors
SPDX-License-Identifier: GPL-3.0-or-later

Read a GnuCash SQLite/XML book for customer and invoice IDs, scan uploaded
customer lists, and generate GnuCash invoice import CSV rows.
"""
from __future__ import annotations

import argparse
import csv
import gzip
import json
import os
import re
import sqlite3
import sys
import xml.etree.ElementTree as ET
from dataclasses import dataclass
from decimal import Decimal, InvalidOperation
from pathlib import Path
from typing import Any, Iterable

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
                        customers.append(Customer(cid, str(row[1] or ""), str(row[2] or ""), str(row[3] or "")))

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


def customers_to_json(customers: list[Customer]) -> list[dict[str, str]]:
    return [{"id": c.id, "name": c.name, "active": c.active, "guid": c.guid} for c in customers]


def scan_book_json(args: argparse.Namespace) -> None:
    customers, invoice_ids = scan_book(args.book)
    print_json(
        {
            "ok": True,
            "book": str(Path(args.book).expanduser()),
            "customer_count": len(customers),
            "invoice_count": len(invoice_ids),
            "next_invoice_id": suggest_next_invoice_id(invoice_ids, args.prefix or "", int(args.padding or 0)),
            "customers": customers_to_json(customers),
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
            matched.append({"id": customer.id, "name": customer.name, "active": customer.active, "guid": customer.guid})
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


def yn(value: bool) -> str:
    return "Y" if bool(value) else "N"


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
            str(params.get("date_opened") or ""),
            customer_id,
            str(params.get("billing_id") or ""),
            str(params.get("notes") or ""),
            str(params.get("entry_date") or params.get("date_opened") or ""),
            str(params.get("description") or ""),
            str(params.get("action") or "ea"),
            str(params.get("income_account") or ""),
            decimal_string(params.get("quantity", "1"), "1"),
            decimal_string(params.get("price", "0"), "0"),
            "%" if decimal_string(params.get("discount", "0"), "0") != "0" else "",
            "=" if decimal_string(params.get("discount", "0"), "0") != "0" else "",
            decimal_string(params.get("discount", "0"), "0"),
            yn(taxable),
            yn(taxincluded),
            str(params.get("tax_table") or ""),
            str(params.get("date_posted") or "") if posted else "",
            str(params.get("due_date") or "") if posted else "",
            str(params.get("ar_account") or "") if posted else "",
            str(params.get("memo_posted") or "") if posted else "",
            yn(accu_splits) if posted and accu_splits else "",
        ]
        rows.append(row)

    out = Path(args.out).expanduser()
    out.parent.mkdir(parents=True, exist_ok=True)
    with out.open("w", newline="", encoding="utf-8") as fh:
        writer = csv.writer(fh, delimiter=delimiter, quoting=csv.QUOTE_MINIMAL)
        writer.writerows(rows)

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
