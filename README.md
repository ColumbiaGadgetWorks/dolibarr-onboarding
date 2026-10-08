# dolibarr-onboarding

A Dolibarr module for Columbia Gadget Works. It is the record behind the website's
membership signup: who has signed the waiver and the agreement, who has uploaded an ID,
who is paying dues through Givebutter, and which badge each member holds.

The website half lives in
[ColumbiaGadgetWorks/website](https://github.com/ColumbiaGadgetWorks/website) (`src/join.js`
and the page at `/membership/join/`).

## What it does

1. **Signup.** The website collects name, email, Discord username and two notification
   choices. The module creates a Dolibarr **contact** and emails a link to come back to.
2. **Paperwork.** The applicant draws a signature under the waiver and the agreement and
   uploads a photo of their ID. Each signed document is stored as a PDF with the drawn
   signature, the date, the IP address and the exact version of the text shown. Ticks appear
   on the contact card. When all three are done the contact also becomes a **non-member**:
   a draft member in Dolibarr, waiting to pay.
3. **Dues.** The applicant pays through Givebutter. When Givebutter reports the payment the
   non-member is validated into a **member** and a subscription is recorded for the period
   paid. Standard or Supporter is decided by the amount.
4. **Missed dues.** If a plan is cancelled or fails, or dues simply run out, reminder emails go
   out on a schedule you set. After a grace period the member is terminated in Dolibarr
   (a non-member again) and the membership team is told to turn the badge off.
5. **Badges.** The member card carries the member code (badge label), the credential, whether
   door access is on, how dues are paid, and a dues-waived-until date. The module does not
   talk to the door system.

Members, Onboarding in the left menu lists everyone with their stage, with filters such as
"Paying, no active badge" and "Not paying, badge still active", and an "Email link" button
that sends someone a link to finish their paperwork online. Members, Onboarding, Unmatched
payments lists dues payments made with an email nobody signed up with.

## Email updates

The website's "Get updates by email" box sends each address here. It becomes a Dolibarr
**contact** tagged **Email updates** (the tag name is on the setup page), with both notify
fields ticked; an existing contact with that address just gets the tag. Someone who ticks
either notify box during the membership signup gets the tag too, and stays on the list even
if they never finish joining. Signing up on the website again takes the address off
Dolibarr's unsubscribe list, since it is a fresh request.

To send an update: Tools, EMailing, New emailing. Write it, then under Recipients add
**Contacts** with the contact tag filter set to **Email updates**. Put `__UNSUBSCRIBE__` in
the message so people can opt out; Dolibarr skips unsubscribed addresses on every later
emailing. Dolibarr needs an outgoing mail server (Setup, Emails) to send.

**Import email list** on the setup page takes an old list: the website's subscriber CSV, or
a subscriber export from an old site. Paste it, press Check to preview, then Import. Only an
`email` column is needed; a name and a signup date are kept when present. Addresses that
unsubscribed are skipped, and running it twice adds nobody twice.

This needs the **Tags/Categories** module, which is now enabled along with this one. On an
existing install, enable Tags/Categories by hand (and EMailing, to send).

## Install

This is a Dolibarr module, not a container. It lives inside the Dolibarr you already run.

1. Put this repository at `htdocs/custom/onboarding` (the folder must be named `onboarding`).
   With the official Docker image, from the Docker host:

   ```bash
   docker exec dolibarr sh -c 'cd /var/www/html/custom && curl -sL https://github.com/ColumbiaGadgetWorks/dolibarr-onboarding/archive/refs/heads/main.tar.gz | tar xz && rm -rf onboarding && mv dolibarr-onboarding-main onboarding && chown -R www-data:www-data onboarding'
   ```

   Replace the first `dolibarr` with your container's name. The same command updates the
   module later. `/var/www/html/custom` must be a mapped volume, or the module disappears
   when the container is updated.
2. In Dolibarr: Home, Setup, Modules, enable **Member onboarding**. Also enable **Scheduled
   jobs** and make sure Dolibarr's cron runs, or reminders are never sent.
3. Open the module's setup page (the gear icon):
   - Fill in the join page address, membership team email, Givebutter API key, campaign code
     and dues payment page, then Save.
   - Press **Connect Givebutter**. That creates the webhook in Givebutter and points it at
     this Dolibarr, so payments and cancelled plans are reported within seconds. Dolibarr must
     be reachable from the internet for this. Without it the hourly sync still works.
   - The waiver and agreement start with placeholder wording. Replace them when the real
     text is ready; people who signed the old wording keep a copy of what they signed.
4. **Import existing members** (button on the setup page): paste the member roster, press
   Check to preview, then Import. A person whose member record already exists (for example
   one created by the CGWManagement import scripts) is linked, not duplicated, and whatever
   is already on their card is kept.
5. Give the membership team the three permissions under Users and Groups.
6. On the website Worker, set the secrets `DOLIBARR_URL` (Dolibarr's public address) and
   `DOLIBARR_API_KEY` (the "Key for the website" on the setup page).

The website's Worker runs on Cloudflare's network, so it reaches Dolibarr at its public
address. The key it uses can drive a signup and nothing else; it cannot read the member list.

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
to the branch name. Scheduled jobs do not run by themselves in the sandbox; use the command
below or the buttons on the module's setup page.

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
every push: signup, drawn signatures, payment, duplicate webhooks, a missed webhook caught by
the sync, cancellation, reminders, lapse, rejoining, cleanup of abandoned signups, the
Connect Givebutter button, and the legacy import. It also checks that the staff pages render
and runs a signup through the real website.

## Training fees

Trainings are paid on their own Givebutter campaign (not the dues campaign, and
with Funds hidden on it). Its checkout asks three questions, found by their
titles: one with "zone" in it (e.g. "Training Zone?", answers like
"Machining (Mill, Lathe)"), one with "trainer" ("Trainer's name"), and one with
"tool" or "equipment" ("Tool or equipment"). The amount is the fee the zone boss
set (5, 10, 15, 20).

For each paid training the module:

1. Records the training (Members, Onboarding, **Trainings**) and tags the payer's
   member and contact cards **Trained: <tool>**, under a parent tag **Trained**.
   The payer gets an email saying so.
2. Splits the fee: the trainer's share (50% by default) to the trainer's credit,
   the rest to the zone's budget ("Machining", the answer up to the bracket).
3. Matches the typed trainer name to a member: their full name, Discord handle,
   badge code or email. A name that matches nobody (or two people) waits on the
   Trainings page; pick the member once and that spelling is remembered.
4. When a trainer's credit reaches $50 (setting), opens a **dues credit** under
   Members, Onboarding, **Training accounts** and emails the membership team.
   Approving takes $50 off their credit and names the Givebutter dues payment to
   refund. Givebutter's API cannot refund, so refund it in the Givebutter
   dashboard, then press **Mark refunded**; the trainer is emailed.

Zone budgets and trainer credit are entries in the module's own ledger, not
Dolibarr bank accounts. The money itself arrives in one place (the Givebutter
payout); a bank account per zone or per trainer would not match any real
statement and would need one account per member. Each balance is the sum of its
entries, all listed on Training accounts, where zone spending and corrections
are entered too. **Void** on a training (refunded, or entered by mistake)
reverses its shares and removes the tag if nothing else covers that tool.

The website's **/training/** page looks up what an email address is trained on
(tool, zone, date only), through the same website key, behind Turnstile and an
hourly limit.

Setup: create the Givebutter campaign with the three questions, hide Funds on it,
then on the setup page fill in **Training campaign code** and **Training lookup
page**. Give staff the two new permissions (see trainings; match trainers,
record spending and approve credits). An existing install needs the module
disabled and enabled once to create the three new tables.

## Fields on the member card

The module and the CGWManagement scripts (`dolibarr/scripts/bootstrap.py`) share one set of
extra fields on members. The module creates the ones it uses when it is enabled; the scripts
create those plus a few of their own (home zone, orientation date, storage bin). The names
must stay identical in both places.

| Field | Type | Set by |
|---|---|---|
| `member_code` | text, unique | import, staff. The badge label, e.g. CGW-13. |
| `credential_id` | text | import, staff. A fob serial, keypad slot, or an old physical key. |
| `access_enabled` | yes/no | import, staff. Mirrors the door system; the module only reports it. |
| `waiver_date`, `agreement_date` | date | the module, when the applicant signs online; staff for a paper copy. |
| `id_verified` | yes/no | the module on upload, or the import (`license` column). |
| `discord_handle` | text | the module from the signup form. |
| `payment_channel` | Givebutter / PayPal / Check / Cash / Venmo / None | the module (Givebutter) or staff. Only Givebutter members are chased for dues. |
| `payment_state` | none / active / past_due / cancelled / lapsed | the module. |
| `dues_waived_until` | date | staff. Scholarships and board-approved pauses. |
| `notify_events`, `notify_news` | yes/no | the module from the signup form. |

`discord_handle`, `waiver_date`, `agreement_date`, `id_verified`, `payment_state` and the two
notify flags are also mirrored onto the contact the module creates at signup.

Installs made before October 2026 had these as `onb_*` fields. Enabling the module (or
re-enabling it) moves the data across and removes the old fields.

## How Givebutter is matched

Givebutter documents prefilling the checkout with an amount and a frequency only. The join
page also passes the name and email in case the form picks them up, but a payment is matched
to an applicant by the email actually typed at checkout. The join page tells the
applicant which address to use. Once a payment has matched, the Givebutter contact and plan
ids are remembered and used from then on. Only payments to the configured campaign count,
and a recurring plan only affects membership after it has paid dues at least once, so a
member's separate monthly donation cannot cancel their membership.

## License

GPL-3.0-or-later, the same as Dolibarr.
