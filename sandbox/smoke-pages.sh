#!/bin/bash
# Logs in to the sandbox Dolibarr as admin and checks that every staff page of
# the module renders. Run after e2e.mjs, which leaves data to display.
set -u
BASE=${DOLI_URL:-http://localhost:8080}
JAR=$(mktemp)
TOKEN=$(curl -s -c "$JAR" "$BASE/index.php" | grep -o 'name="token" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//')
curl -s -b "$JAR" -c "$JAR" -o /dev/null --data-urlencode "token=$TOKEN" -d 'actionlogin=login&loginfunction=loginfunction&username=admin&password=admin' "$BASE/index.php?mainmenu=home"
fail=0
check() { # path, text that must appear
  local body code
  body=$(curl -s -b "$JAR" -w '\n%{http_code}' "$BASE/custom/onboarding/$1")
  code=${body##*$'\n'}
  if [ "$code" != "200" ] || ! grep -q "$2" <<<"$body" || grep -qE 'Fatal error|Parse error|<b>Warning</b>|Deprecated</b>|name="password"' <<<"$body"; then
    echo "FAIL $1 (HTTP $code, wanted \"$2\")"; grep -oE '(Fatal error|Parse error|<b>Warning</b>|Deprecated</b>)[^<]{0,300}' <<<"$body" | head -5; fail=1
  else
    echo "ok   $1"
  fi
}
check 'applicants.php' 'e2e-'
check 'applicants.php?filter=nobadge' 'Paying, no active badge'
check 'applicants.php?filter=problem' 'Payment problem'
check 'payments.php' 'stranger-'
check 'admin/setup.php' 'Key for the website'
check 'admin/import.php' 'Import existing members'
# The import page's "Check" button, with a pasted tab-separated sheet.
T2=$(curl -s -b "$JAR" "$BASE/custom/onboarding/admin/import.php" | grep -o 'name="token" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//')
out=$(curl -s -b "$JAR" --data-urlencode "token=$T2" -d 'action=check' --data-urlencode "sheet=$(printf 'name\temail\tmember_type\tpayment_channel\taccess_code\nPaste Test\tpaste-test@example.test\tLegacy\tPayPal\tCGW-77')" "$BASE/custom/onboarding/admin/import.php")
if grep -q 'would add as member (Legacy, pays by PayPal), badge CGW-77' <<<"$out"; then echo "ok   import page check"; else echo "FAIL import page check"; grep -o 'Row 2[^<]*' <<<"$out" | head -3; fail=1; fi
check 'admin/import-emails.php' 'Import email list'
# The email list import's "Check" button, with a pasted CSV.
T3=$(curl -s -b "$JAR" "$BASE/custom/onboarding/admin/import-emails.php" | grep -o 'name="token" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//')
out=$(curl -s -b "$JAR" --data-urlencode "token=$T3" -d 'action=check' -d 'source=smoke' --data-urlencode "sheet=$(printf 'email,name\npaste-list-%s@example.test,Paste List' "$RANDOM$RANDOM")" "$BASE/custom/onboarding/admin/import-emails.php")
if grep -q '1 new contacts would be added' <<<"$out"; then echo "ok   email import page check"; else echo "FAIL email import page check"; grep -oE '(Fatal error|[0-9]+ new contacts)[^<]{0,200}' <<<"$out" | head -3; fail=1; fi
ID=$(docker compose exec -T db mariadb -N -udolidbuser -pdolidbpass dolidb -e "SELECT rowid FROM llx_onboarding_applicant WHERE id_file IS NOT NULL ORDER BY rowid LIMIT 1")
for f in waiver:application/pdf agreement:application/pdf id:image/png; do
  type=$(curl -s -b "$JAR" -o /dev/null -w '%{content_type}' "$BASE/custom/onboarding/document.php?id=$ID&file=${f%%:*}")
  if [ "$type" = "${f#*:}" ]; then echo "ok   document ${f%%:*}"; else echo "FAIL document ${f%%:*}: got $type"; fail=1; fi
done
# Not logged in: the ID photo must not be served.
type=$(curl -s -o /dev/null -w '%{content_type}' "$BASE/custom/onboarding/document.php?id=$ID&file=id")
case "$type" in image/*) echo "FAIL ID photo served without login"; fail=1;; *) echo "ok   ID photo needs login";; esac
exit $fail
