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
check 'payments.php' 'Unmatched PayPal payments'
check 'admin/setup.php' 'PayPal Client ID'
check 'admin/setup.php' 'name="section" value="3"'
check 'admin/setup.php' 'Sync PayPal now'
check 'admin/setup.php' 'Key for the website'
check 'admin/setup.php' 'Training campaign code'
check 'trainings.php' 'Sewing machine'
check 'trainings.php?search=lathe' 'voided'
check 'training-accounts.php' 'Zone budgets'
check 'training-accounts.php' 'Dues refunded'
check 'purchases.php' 'Purchase requests'
check 'attention.php' 'Unmatched dues payments'
check 'attention.php' 'Scheduled jobs failing'
check '../../index.php?mainmenu=home' 'All checks'
check 'mailgate.php' 'Mixed recipients'
check 'mailgate.php?view=1' 'Kind'
check 'admin/setup.php' 'Never held for'
check 'purchases.php' 'PR-0'
check 'purchases.php' 'Paid'
check 'admin/setup.php' 'Purchase request roles'
check 'admin/setup.php' 'Create the role tags'
check 'training-tools.php' '3D printers'
check 'training-tools.php' 'Closed days'
TOOL=$(docker compose exec -T db mariadb -N -udolidbuser -pdolidbpass dolidb -e "SELECT fk_tool FROM llx_onboarding_trainer_slot ORDER BY rowid DESC LIMIT 1")
check "training-tools.php?action=edit&id=$TOOL" 'Trainers and their times'
check 'trainings.php' 'Upcoming'
check 'trainings.php' 'Mark trained (no fee)'
check 'trainings.php' 'Bookings that need someone'
BK=$(docker compose exec -T db mariadb -N -udolidbuser -pdolidbpass dolidb -e "SELECT rowid FROM llx_onboarding_booking ORDER BY rowid DESC LIMIT 1")
check "trainings.php?booking=$BK" 'TR-'
CL=$(docker compose exec -T db mariadb -N -udolidbuser -pdolidbpass dolidb -e "SELECT rowid FROM llx_onboarding_class ORDER BY rowid DESC LIMIT 1")
check 'class.php' 'Create the class'
check "class.php?id=$CL" 'Sign-in code'
check "class.php?id=$CL" '<svg'
check 'votes.php' 'Start a practice vote'
check 'admin/setup.php' 'Full member types'
MEM=$(docker compose exec -T db mariadb -N -udolidbuser -pdolidbpass dolidb -e "SELECT fk_adherent FROM llx_onboarding_training WHERE fk_adherent IS NOT NULL AND source = 'class' ORDER BY rowid DESC LIMIT 1")
check "member-training.php?id=$MEM" 'Trained on'
CT=$(docker compose exec -T db mariadb -N -udolidbuser -pdolidbpass dolidb -e "SELECT fk_socpeople FROM llx_onboarding_booking WHERE fk_socpeople IS NOT NULL ORDER BY rowid DESC LIMIT 1")
check "member-training.php?contact=$CT" 'Bookings'
check 'applicants.php?filter=training' 'Training only'
check 'admin/setup.php' 'Waiver and agreement versions'
check 'admin/setup.php' 'Training bookings'
# Zone budgets: a starting budget for every zone, then a purchase against one.
before=$(docker compose exec -T -u www-data dolibarr php /var/www/html/custom/onboarding/sandbox/tick.php training balance zone Metalworking)
T4=$(curl -s -b "$JAR" "$BASE/custom/onboarding/training-accounts.php" | grep -o 'name="token" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//')
curl -s -b "$JAR" -o /dev/null --data-urlencode "token=$T4" -d 'action=zoneentry&kind=allocation&zone=*&amount=300' --data-urlencode 'from=General fund' "$BASE/custom/onboarding/training-accounts.php"
T5=$(curl -s -b "$JAR" "$BASE/custom/onboarding/training-accounts.php" | grep -o 'name="token" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//')
curl -s -b "$JAR" -o /dev/null --data-urlencode "token=$T5" -d 'action=zoneentry&kind=spend&zone=Crafting&amount=50' --data-urlencode 'note=Workbench' "$BASE/custom/onboarding/training-accounts.php"
out=$(curl -s -b "$JAR" "$BASE/custom/onboarding/training-accounts.php")
if grep -q 'Budget added (from General fund)' <<<"$out" && grep -q 'Workbench' <<<"$out" && grep -q '>Metalworking<' <<<"$out"; then echo "ok   zone budgets and a purchase"; else echo "FAIL zone budgets and a purchase"; fail=1; fi
bal=$(docker compose exec -T -u www-data dolibarr php /var/www/html/custom/onboarding/sandbox/tick.php training balance zone Metalworking)
if [ "$bal" = "$((before + 300))" ]; then echo "ok   every zone got 300"; else echo "FAIL Metalworking went from $before to $bal"; fail=1; fi
check 'deadlines.php' 'File taxes'
check 'deadlines.php?done=1' 'next year'
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
