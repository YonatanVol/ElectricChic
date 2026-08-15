#!/usr/bin/env bash
#
# Package the local demo into something that can be uploaded to a real host.
#
#   ./scripts/export-site.sh
#
# WHY THIS EXISTS
#
# The demo lives in a Local site on one Mac. Getting it onto a server means
# moving three separate things — the database, the uploaded images, and the
# code — and forgetting any one of them produces a site that looks broken in a
# way that is annoying to diagnose remotely.
#
# The code is already in git and deploys from there. This handles the two
# things git deliberately does not track.
#
# WHAT THIS DOES NOT DO
#
# It does not upload anything. Choosing a host, opening the account and paying
# for it are decisions with money attached, and they belong to a person.
#
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

readonly OUT_DIR="build/export"
readonly STAMP="$(date +%Y%m%d-%H%M)"

echo "ElectricChic — packaging the demo for upload"
echo "============================================"
echo

mkdir -p "${OUT_DIR}"

# ── 1. Database ──────────────────────────────────────────────────────────────
#
# --add-drop-table    so importing over an existing database replaces it
#                     cleanly instead of colliding on every row
# --single-transaction  a consistent snapshot; without it mysqldump warns that
#                     rows changing mid-export can produce an inconsistent file
# --set-gtid-purged=OFF  otherwise the dump carries replication IDs from this
#                     machine, which a different server should not inherit
#
# mysqldump on 8.4 also prints an error about `column_masking_policy`, an
# enterprise feature this database user has no rights to read. It is noise: the
# dump still completes, and the verification step below is what actually
# decides whether the file is usable. A backup nobody trusts is a backup nobody
# restores, so the check is run rather than assumed.
echo "1/3  Exporting the database…"

./scripts/wp db export "${OUT_DIR}/database-${STAMP}.sql" \
	--add-drop-table \
	--single-transaction \
	--set-gtid-purged=OFF \
	--default-character-set=utf8mb4 \
	2> >(grep -v 'masking polic' >&2) \
	>/dev/null

# Verify rather than assume. mysqldump can exit after writing a partial file,
# and a truncated dump looks exactly like a good one until the day it is needed.
if ! tail -5 "${OUT_DIR}/database-${STAMP}.sql" | grep -q 'Dump completed'; then
	echo "     ✗ the dump did not finish — refusing to hand over a partial backup" >&2
	exit 1
fi

for table in posts postmeta options terms; do
	if ! grep -q "CREATE TABLE \`wp_${table}\`" "${OUT_DIR}/database-${STAMP}.sql"; then
		echo "     ✗ wp_${table} is missing from the dump" >&2
		exit 1
	fi
done

db_size="$(du -h "${OUT_DIR}/database-${STAMP}.sql" | cut -f1)"
echo "     ✓ database-${STAMP}.sql (${db_size}) — verified complete"

# ── 2. Uploads ───────────────────────────────────────────────────────────────
#
# Product images and any media. Not in git on purpose — binaries bloat a
# repository permanently and cannot be reviewed in a diff.
echo "2/3  Packaging uploaded images…"

uploads_dir="$(./scripts/wp eval 'echo wp_upload_dir()["basedir"];' 2>/dev/null || true)"

if [[ -n "${uploads_dir}" && -d "${uploads_dir}" ]]; then
	tar -czf "${OUT_DIR}/uploads-${STAMP}.tar.gz" -C "$(dirname "${uploads_dir}")" "$(basename "${uploads_dir}")"
	up_size="$(du -h "${OUT_DIR}/uploads-${STAMP}.tar.gz" | cut -f1)"
	echo "     ✓ uploads-${STAMP}.tar.gz (${up_size})"
else
	echo "     ! uploads directory not found — skipping"
fi

# ── 3. What the new server has to be told ────────────────────────────────────
#
# The database carries the old URL in dozens of places, including inside
# serialised PHP arrays where a plain find-and-replace corrupts the data. The
# note below hands over the correct command rather than leaving it to be
# rediscovered.
echo "3/3  Writing the import instructions…"

cat > "${OUT_DIR}/IMPORT-README.md" <<'NOTE'
# העלאת האתר לשרת

שלושה קבצים, שלושה שלבים. אם משהו נתקע — עצור ושאל, אל תנחש.

## מה יש כאן

| קובץ | מה זה |
|---|---|
| `database-*.sql` | כל התוכן: מוצרים, מחירים, זמינות, דפים, הגדרות |
| `uploads-*.tar.gz` | התמונות |

הקוד עצמו (התוסף והתבנית) **לא נמצא כאן** — הוא מגיע מ-git.

## שלב 1 — להעלות את הקוד

```bash
git clone https://github.com/YonatanVol/ElectricChic.git
```

ואז לקשר את שתי התיקיות למקום שוורדפרס מצפה להן:

```bash
ln -s /path/to/ElectricChic/wp-content/plugins/electricchic-core  wp-content/plugins/electricchic-core
ln -s /path/to/ElectricChic/wp-content/themes/electricchic-child  wp-content/themes/electricchic-child
```

## שלב 2 — לייבא את מסד הנתונים

```bash
wp db import database-*.sql
```

## שלב 3 — להחליף את הכתובת ⚠️ החלק שקל לפשל בו

הכתובת הישנה (`http://localhost:8080`) מופיעה במסד הנתונים בעשרות
מקומות, וחלק מהם נמצאים **בתוך מערכים מסודרים של PHP**. חיפוש־והחלפה
רגיל שובר אותם ואז חלקים מהאתר פשוט נעלמים.

לכן משתמשים בפקודה הזו ולא בכלי החלפה רגיל — היא יודעת לפרק ולהרכיב
מחדש את המערכים האלה:

```bash
wp search-replace 'http://localhost:8080' 'https://הכתובת-החדשה' --all-tables --precise
```

הרץ קודם עם `--dry-run` כדי לראות כמה שינויים יתבצעו, בלי לשנות כלום.

## שלב 4 — להעלות את התמונות

```bash
tar -xzf uploads-*.tar.gz -C wp-content/
```

## שלב 5 — לוודא שזה אתר הדגמה

⚠️ **הכי חשוב בקובץ הזה.**

באתר יש מנגנון שמסמן אותו כהדגמה: באנר צהוב בראש כל עמוד, חסימה של
ביצוע הזמנות, וחסימה מגוגל. זה **דלוק כברירת מחדל** — כלומר אם לא
נוגעים בכלום, הכל מוגן.

אל תוסיף את השורה הבאה ל-`wp-config.php` עד שהאתר באמת עולה לאוויר
עם מחירים מאושרים:

```php
define( 'EC_DEMO_MODE', false );
```

## בדיקה שהכל עלה נכון

```bash
wp option get home
wp post list --post_type=product --format=count      # אמור להיות 24
wp plugin list --status=active                        # electricchic-core פעיל?
```
NOTE

echo "     ✓ IMPORT-README.md"
echo
echo "============================================"
echo "מוכן: ${OUT_DIR}/"
ls -1sh "${OUT_DIR}" | tail -n +2 | sed 's/^/     /'
echo
echo "השלב הבא הוא לבחור אחסון ולפתוח חשבון — זה דורש כרטיס אשראי,"
echo "ולכן זה עליך. אחרי שיש גישה, אפשר להריץ את ההוראות שב-README."
