#!/usr/bin/env bash
set -euo pipefail

REPO="${1:-$(pwd)}"
REMOTE="${2:-https://github.com/thystra/gnucash-invoice-batch-creator.git}"
BRANCH="${3:-main}"
REPO="$(cd "$REPO" && pwd)"

if [[ -d "$REPO/.git" ]]; then
  echo "Git metadata already exists at: $REPO/.git"
  git -C "$REPO" status --short || true
  exit 0
fi

if ! command -v git >/dev/null 2>&1; then
  echo "git is not installed. Install it first: sudo apt install git" >&2
  exit 1
fi

if [[ ! -f "$REPO/app/bootstrap.php" || ! -f "$REPO/public/index.php" ]]; then
  echo "This does not look like a gnucash-invoice-batch-creator working tree: $REPO" >&2
  exit 1
fi

TMPDIR="$(mktemp -d)"
cleanup() { rm -rf "$TMPDIR"; }
trap cleanup EXIT

echo "Cloning Git metadata from: $REMOTE"
git clone --no-checkout --branch "$BRANCH" "$REMOTE" "$TMPDIR/repo"
cp -a "$TMPDIR/repo/.git" "$REPO/.git"

# Keep the expected origin URL explicit.
git -C "$REPO" remote set-url origin "$REMOTE" || true

echo "Restored Git metadata to: $REPO/.git"
echo "Current status follows. Review before committing; this script does not reset or overwrite your working tree."
git -C "$REPO" status --short
