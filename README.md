# dolibarr-onboarding

A Dolibarr module for Columbia Gadget Works. It is the record behind the website's
membership signup: who has signed the waiver and the agreement, who has uploaded an ID,
who is paying dues through Givebutter, and which badge each member holds.

The website half lives in
[ColumbiaGadgetWorks/website](https://github.com/ColumbiaGadgetWorks/website) (`src/join.js`
and the page at `/membership/join/`).

## What it does

1. **Signup.** The website collects name, email, Discord username and two notification
   choices. The module creates a Dolibarr **contact** for the applicant and emails them a
   link to come back to.
2. **Paperwork.** The applicant signs the waiver and the agreement by typing their name, and
   uploads a photo of their ID. Each signature is stored as a PDF and a text file with the
   date, IP address and the exact version of the text that was shown. Ticks appear on the
   contact card.
3. **Dues.** The applicant pays through Givebutter. When Givebutter reports the payment, the
   module creates a **member** from the contact, validates it, and records a subscription for
   the period paid. Standard or Supporter is decided by the amount.
4. **Missed dues.** If a plan is cancelled or fails, or dues simply run out, reminder emails go
   out on a schedule you set. After a grace period the member is terminated in Dolibarr
   ("non-paying") and the membership team is told to turn the badge off.
5. **Badges.** The member card gains Badge ID, Badge access active, and Dues waived until.
   The module does not talk to the door system.

Members, Onboarding in the left menu lists everyone with their stage, with filters such as
"Paying, no active badge" and "Not paying, badge still active". Members, Onboarding,
Unmatched payments lists dues payments made with an email nobody signed up with, so a
person can assign them.

## Install

1. Copy this repository to `htdocs/custom/onboarding` on the Dolibarr server (the folder must
   be named `onboarding`).
2. In Dolibarr: Home, Setup, Modules, enable **Member onboarding**. Members and Third
   parties are enabled with it. Enable **Scheduled jobs** too, and make sure Dolibarr's cron
   is running, or reminders will never be sent.
3. Open the module's setup page (the gear icon) and fill in:
   - Join page address, membership team email
   - Givebutter API key, membership campaign code, dues payment page
   - Reminder days and grace period
   - The real waiver and agreement text. **The defaults are placeholders.**
4. Give the membership team the three permissions under Users and Groups: see applicants,
   assign payments, view ID photos.
5. On the website Worker, set `DOLIBARR_URL`, `DOLIBARR_API_KEY` (shown on the setup page)
   and `GIVEBUTTER_WEBHOOK_SECRET`. The website README has the details.

The website's Worker runs on Cloudflare's network, not yours. It must be able to reach
`https://<dolibarr>/custom/onboarding/public/api.php`, so Dolibarr needs a public hostname
or a Cloudflare Tunnel. Only that one path has to be exposed. Its key can drive a signup and
deliver Givebutter events and nothing else; it cannot read the member list.

## Sandbox

`sandbox/` is a complete throwaway copy of the system: Dolibarr with this module, a fake
Givebutter, a mail catcher, and the real website. No real money, members or email.

```bash
cd sandbox && docker compose up -d
```

The first start takes a few minutes. Then:

| Address | What |
|---|---|
| http://localhost:8787/membership/join/ | The website's join page |
| http://localhost:8080 | Dolibarr, login `admin` / `admin` |
| http://localhost:8090 | Fake Givebutter: charge again, fail, cancel or resume a plan |
| http://localhost:8025 | Every email the system sent |

On another machine such as Unraid, set `SANDBOX_HOST` to that machine's address first so
links in emails and the pay button point at it. To test a website branch, set `WEBSITE_REF`
to the branch name.

To watch reminders without waiting days, pretend time has passed:

```bash
docker compose exec -u www-data dolibarr php /var/www/html/custom/onboarding/sandbox/tick.php daily 8
```

`daily 8` runs the reminder job as if it were 8 days from now. `sync` pulls from the fake
Givebutter. `dump someone@example.test` prints what Dolibarr holds for a person.

```bash
docker compose down -v
```

throws the sandbox away.

## Tests

`.github/workflows/test.yml` lints the PHP and runs `sandbox/e2e.mjs` against the sandbox on
every push: signup, paperwork, payment, duplicate webhooks, a missed webhook caught by the
sync, cancellation, reminders, lapse, rejoining, and cleanup of abandoned signups.

## How Givebutter is matched

Givebutter's checkout can be prefilled with an amount and a frequency but not an email, so a
payment is matched to an applicant by the email typed at checkout. The join page tells the
applicant which address to use. Once a payment has matched, the Givebutter contact and plan
ids are remembered and used from then on. Only payments to the configured campaign count,
and a recurring plan only affects membership after it has paid dues at least once, so a
member's separate monthly donation cannot cancel their membership.

## License

GPL-3.0-or-later, the same as Dolibarr.
