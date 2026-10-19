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
   `DOLIBARR_API_KEY` (the "Key for the website" on the setup page). If that public address
   is not Dolibarr's own (for example a tunnel hostname that Bot Fight Mode leaves alone),
   also enter it as "This Dolibarr's public address" on the setup page, so the hint there
   and the Givebutter webhook use it.

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
`deadlines 20` runs the deadline reminders as if it were 20 days from now.

```bash
docker compose down -v
```

throws the sandbox away.

## Tests

`.github/workflows/test.yml` lints the PHP and runs `sandbox/e2e.mjs` against the sandbox on
every push: signup, drawn signatures, payment, duplicate webhooks, a missed webhook caught by
the sync, cancellation, reminders, lapse, rejoining, cleanup of abandoned signups, the
Connect Givebutter button, the legacy import, and the deadline reminders (weekly reminders,
escalation, the mark-complete link, next year's copy). `sandbox/e2e-training.mjs` walks training bookings: a tool and trainer, new, non-member, member and under-18 trainees, signing, holds, payments matched after Givebutter's fee, the trainer's and trainee's email buttons, refunds, no-shows, unconfirmed sessions, closed days, free trainings, a group class, the masked lookup and a new waiver version. `sandbox/e2e-vote.mjs` runs a member vote from the officers' email links: notice, running for seats, floor nominations, opening at the meeting, the 30-day and full-member rules, the secret ballot, resending a ballot, counting with quorum, two-thirds and a tiebreak, GitHub tagging and posting (on a fake GitHub), a practice vote and the reminder schedule. It also checks that the staff pages render
and runs a signup through the real website.

## Training

Tools that need training before solo use, who trains them and when, the people booked,
and every training recorded. People book and pay on the website's **/training/book/**
page (ColumbiaGadgetWorks/website); everything is kept here.

### Tools, trainers and times

Members, Onboarding, **Training tools**, **New tool**: name, id (e.g. `3d-printers`, used
in the website's links), zone, fee, member discount (50% by default), trainer's share
(empty: the setup page's), seats per class, length, the machines it covers, the wiki page
with the training documents, and the website page. Then **add trainers**: a current member
trained on the tool, with a weekly time (Thursday 17:00). Each time is one session people
can book; holding one gives the member the tag **Trainer: <tool>**. Enabling the module
creates 3D printers as a sample ($10, $5 for members, Digital Fab); add its trainer there.

**Closed days** on the same page: nobody can book them. The website adds the days its
calendar shows "Open Hack Night cancelled". Closing a day with bookings asks those people
to pick a new date.

Every change is numbered and sent to the website (setting **Website**), which keeps its own
copy so its pages work while Dolibarr is down, and fetches it every 15 minutes in case a
push was lost. The page shows the version the website last reported; **Send to the website
now** sends the catalog and the lookup copy again. A website an hour behind emails the
alerts address.

### Booking, step by step

1. On the website the trainee types their email (Turnstile). Dolibarr answers member (a
   validated member card: member price; lapsed members are terminated by the dues job), non-member, or
   new, and what is left to sign. Nothing else about the person leaves Dolibarr.
2. Someone new gives a name and becomes a **non-member**: a contact plus a draft member
   card, marked "training only" on the onboarding list, never chased for dues.
3. Paperwork: everyone needs the **waiver**; members also the **membership agreement**.
   Missing ones are signed online from a link emailed to that address. The waiver asks for
   a date of birth; under 18 a parent or guardian signs (name, relationship, optional
   email, recorded on the PDF); under the minimum age (12) nobody can sign online. Members
   imported without signature dates sign the first time they book, or staff enter the date
   of a paper copy on their card.
4. They pick the tool, trainer and time (from 48 hours to 6 weeks ahead, not full, not a
   closed day, not a time the trainer already gives on another tool), tick "I agree" to
   the cancel and refund rules, and the seat is held for 30 minutes while they pay.
5. Givebutter opens with the price, name and email filled in. **The training campaign
   has no checkout questions**: the booking (`TR-0042`) carries the tool, zone, trainer
   and time, and the payment is matched to it by email and amount. The Givebutter id is
   saved on the booking. A payment that arrives after the hold still matches within a day
   if the seat is free; otherwise the trainee is asked to pick a new date.
6. The trainee gets a confirmation with **Need to cancel training? Click here** (works
   until 2 hours before: a new date, or a full refund). The trainer gets **I'll be there /
   I can't make it**, a reminder a day later, and if they have not confirmed 24 hours
   before, the session is cancelled. Whenever the trainer or the shop cancels, the trainee
   chooses **Reschedule** (free), **Refund** or **Other** (a comment to the secretary).
7. Day of: reminders to both. At the end of the session the trainer is asked, again an
   hour and a day later: **showed up, and I performed the training** / **did not show up,
   do not mark them as trained** / **Other** (a comment to the secretary). Two days on with
   no answer, the trainee is asked and can say **My trainer didn't show up**.
8. Trained: the training is recorded, the trainee is tagged **Trained: <tool>** and
   emailed, and what arrived **after Givebutter's fee** is split: the trainer's share to
   their credit, the rest to the zone. A first no-show gets one free new date within 60
   days; a second keeps the fee (split as usual) and records no training.

Every email link is signed for one booking and one person, and opening it changes
nothing until a button is pressed. The links open on the website
(`https://columbiagadgetworks.org/training/link/?…`), which passes the click to
`public/booking.php` with the website key and shows the answer inside the site; set
"Email buttons on the website" to 0 to link to Dolibarr directly. Both addresses keep
working, so links already sent never break.

Payments are matched within seconds: Givebutter's webhook brings them, and while a trainee
waits on the booking page Dolibarr also asks Givebutter for the last hour's payments (at
most every 20 seconds), as does the 15-minute job whenever a booking waits for payment. The
setup page shows when the last webhook arrived and the last one refused; when payments keep
being found by asking instead, the alerts address is emailed with what to check. Refunds are made by the treasurer in Givebutter (its API
cannot refund), then **Mark refunded** on the booking. Every time above is a setting
(Training bookings on the setup page), and every email's wording can be replaced with
`ONBOARDING_MAIL_BK_<NAME>_SUBJECT` / `_BODY` (names in `class/booking.class.php`, `mails()`).

Members, Onboarding, **Trainings** lists bookings that need someone (comments, refunds to
make, people still to pick a date, sessions the trainer has not answered), upcoming
sessions, payments that matched no booking (link them to the booking), and every training
recorded with how (paid, no fee, imported, group class).

### Free trainings and group classes

Dolibarr only, for the board (permission "Record free trainings and create group
classes"); the secretary manages them and gets every request.

- **Mark trained (no fee)** on the Trainings page: email (and a name, for someone new, who
  becomes a non-member), tool, trainer, date, reason. No money moves.
- **Import a list** of people already trained: one email per line, or email, first name,
  last name. Check first, then Import.
- **Group classes** (Members, Onboarding, Group classes): tool, trainer, date, seats. The
  class gets a code (`PRINT-7K2Q`), a sign-in link and a QR code to show or print. The
  code works from 30 minutes before to 3 hours after the class. Attendees sign in on their
  phones at /training/class/; new people become non-members and sign the waiver there (a
  guardian for a minor). After the class the trainer gets the list and confirms who was
  trained with one button (or the class marks people trained at sign-in, if chosen). No
  phone or no internet: staff add people from the class page, ticking "paper waiver
  signed" for a paper copy.

### Records and the lookup

Each member and contact card has a **Training** tab: trained on, may train, trainings
given and credit, bookings. Member cards also get **Trained on** and **Trainings given**
fields, which can be shown and filtered on the member list (and the Trained / Trainer tags).

The website's **/training/** page answers "What am I trained on?" with the email and name
masked (`a******4@gmail.com`, `A*** B***`) and the tools; an unknown email and one with no
trainings get the same answer. Dolibarr also sends the website the masked answer whenever a
training is recorded, so the lookup works while Dolibarr is down.

### Training fees and credit

Zone budgets and trainer credit are entries in the module's own ledger, not Dolibarr bank
accounts. The money itself arrives in one place (the Givebutter payout); a bank account per
zone or per trainer would not match any real statement. Each balance is the sum of its
entries, all listed on Training accounts. When a trainer's credit reaches $50 (setting), a
**dues credit** opens under Members, Onboarding, **Training accounts** and the membership
team is emailed. Approving takes $50 off their credit and names the Givebutter dues payment
to refund; refund it in the Givebutter dashboard, then press **Mark refunded**; the trainer
is emailed. **Void** on a training (refunded, or entered by mistake) reverses its shares and
removes the tag if nothing else covers that tool.

Payments for the old pay-after-training registrations (`action=trainingstart`) are still
recorded straight away, so a website not yet updated keeps working.

### Zone budgets and purchases

The zones are the setup page's **Zones** plus any in the training catalog. On
Training accounts, **Add budget** puts money into one zone or every zone at once,
noting which account it came from (a Dolibarr bank account when the Banks module
is on). Each zone row records a **Purchase** (or an adjustment) with what was
bought. Only people with the permission "Add zone budgets and record purchases"
see these forms, so purchasing can stay with one person; every entry shows who
entered it.

### Purchase requests

Zone leads, the president and the treasurer ask to spend from a zone's budget, or from the
**General fund**, on the website's **/budget** page. The form makes them pick the zone. The
president and treasurer (whoever holds those role tags, see below) can file for any zone or
the General fund; a zone boss
can file only for their own zone and is told which one it is if they try another; every
other member is turned away. The General fund has no budget to check, so a request for it
always needs the treasurer and one other director, and paying it spends no zone's budget.
Two kinds of request, and only two:

- **Reimburse me**: already bought. At least one receipt (PDF, JPG, PNG, WEBP or HEIC, up
  to 5 files of 5 MB) is required, the purchase must be within 60 days (setting), and the
  member says how to be paid (Venmo or check).
- **Request for purchase**: the treasurer buys it. It needs a web link and the total price.

The member proves who they are with the email on their member card and the two digits of
their `member_code`. `CGW-05`, `05` and `140405` all mean 05. Only validated members who are
paid up (or waived through today) get in, and every wrong way in gets the same answer, so the
form cannot be used to find out who is a member. Two digits is a weak secret, so failures are
counted: five wrong tries lock an email for 24 hours, even for the right code; 20 from one
address in an hour, or 100 across the whole form, shut it for an hour. A member whose card has
no `member_code` cannot use the form.

Who decides, from a signed link in their email (no Dolibarr login; opening the link only
shows the request, a button decides):

- The **zone lead**, for requests up to **$100** while the zone still has the money.
- Anything bigger, over the zone's budget, from a zone with no lead, or from the lead
  themselves: the **treasurer and one other director** (if the treasurer is the one asking,
  any two other directors). Nobody ever approves their own request.

**Roles are tags on member cards**, so nobody is hard-coded. Open a member, go to the
Tags/Categories tab and tick the role: `president`, `vice-president`, `secretary`, `treasurer`
or `director` for the board, and `<zone>-boss` for a zone, in lower case with hyphens
(`woodworking-boss`, `digital-fab-boss`, `crafting-boss`). The module reads who holds each tag
every time, taking their email and member code from their card, so when a job changes hands you
move the tag and everything follows: the new person gets the approval emails and may file, the
old one stops. Several people may hold a role. The setup page lists every role tag with who
holds it right now, flags a holder with no member code, and has a **Create the role tags**
button (enabling the module also creates them). Only validated members count. A zone with no
boss sends its requests to the board; if nobody holds `treasurer`, any two directors approve.
The board is everyone tagged with a board role. Approved requests wait under Members, Onboarding, **Purchase requests**,
where the treasurer presses **Mark paid** and chooses the account it was paid from. That puts
the money out of that Dolibarr bank account (a bank line linked to the member) and out of the
zone's budget, both naming the request. Until then an approved or waiting request counts as
**committed**, so the page shows `available = added - spent - committed`.

The website asks for `budget` (totals per zone: no names, no transactions), then files a
request in three calls so receipts travel one at a time: `purchase` (checks the member,
returns a draft), `purchasefile` for each receipt, `purchasefinish`. Turnstile and a per-IP
limit are the website's. Unfinished drafts are removed after two days.

## Waiver and agreement versions

The wording people sign lives in GitHub, in the website repository
(`content/legal/waiver.md`, `content/legal/agreement.md`, shown at /legal/). Set
**Liability waiver from** and **Membership agreement from** on the setup page to their raw
addresses (`https://raw.githubusercontent.com/ColumbiaGadgetWorks/website/main/content/legal/waiver.md`).
**Copy your current wording into those files first**: once the address is set, the file's
wording is what people sign.

Dolibarr reads them nightly and on **Check GitHub now**, and keeps every version (a hash of
the text) with its date and source. People sign the newest one; each signature records its
version, and the words of every version stay in Dolibarr, so later changes in GitHub never
change what someone signed. The website gets the wording from Dolibarr, never straight from
GitHub, so what a person reads is exactly the version recorded. Someone who signed an older
version can still book a training. Without the addresses, the wording on the setup page is
used, as before.

## Alerts

Problems between Dolibarr, the website and Givebutter are emailed to **Alerts go to** on the
setup page (empty: whoever holds the `secretary` tag), at most once an hour for the same
problem and once more when it clears: the website not checking in for 30 minutes (it fetches
the catalog every 15, so silence means it cannot reach Dolibarr), the website not accepting
updates, the hourly Givebutter sync failing, the training booking job failing, the waiver or
agreement unreadable in GitHub. When the website reaches Dolibarr again after an outage it
reports how long it lasted, and Dolibarr emails that too. The website sends no email itself.

### Updating to 0.7

1. Update the module and disable and enable **Member onboarding** once (new tables, fields,
   permissions, and the 15-minute job "Onboarding: training bookings").
2. Give the Board user group "Record free trainings and create group classes", the secretary
   the training permissions and "Edit training tools", and check someone holds `secretary`.
3. On the setup page set **Website** (`https://columbiagadgetworks.org`) and **Alerts go to**.
4. Training tools: add Adam to 3D printers, Thursdays 17:00.
5. In Givebutter, remove the zone, trainer and tool questions from the training campaign.
6. Deploy the website branch with the booking page, then press **Send to the website now**.
7. When the waiver and agreement files hold your wording, set their GitHub addresses.

## Member votes

Bylaw changes and the board election at the annual meeting (bylaws section 4; the steps are in
`policies/how-we-decide.md` in the bylaws repository). Nobody logs in: the Secretary and the
President work the vote from buttons in their emails, and every voter gets a personal ballot
link. Their links open `public/vote.php` inside the website at `/voting/link/`, and each works
only while its holder still has the `secretary` or `president` tag.

| When | What happens |
|---|---|
| 60 days before the meeting | The vote for the second Thursday of April is made, with one question per board seat (and, the first time, the question to ratify the bylaws). The officers get its panel link to add bylaw questions and the pull request members discuss them on |
| 21, 16 and 14 days before | Emails to the officers: **Send notice**. The notice goes to every full member who will have been a member for 30 days by the meeting, with a **Run for a board seat** button. Dolibarr refuses it less than 14 or more than 60 days before |
| 7 days before | **Send reminder**: the candidates so far and the discussion link |
| The meeting day, 5 pm | **Open voting**, pressed during the meeting after floor nominations. Dolibarr tags the pull request's latest commit `ballot/<date>`, takes the roll, and emails each voter their ballot |
| 6 days after | **Remind non-voters** |
| 8 days after | Voting closes by itself. The result (eligible voters, ballots, quorum, totals, outcome) is emailed to the officers and posted on the pull request, or as an issue in the governance repository |

- **Who votes**: validated members of the full member types (Standard and Supporter unless set
  otherwise) who joined at least 30 days before voting opens. Joining is their first subscription,
  else the date they were validated.
- **Candidates**: full members, 18 or older (from the birth date on the card, else they tick a box),
  one seat each. A seat with one candidate is Yes, No or Abstain; with none it is reported vacant.
- **Counting**: quorum is 10% of the roll, at least 5 ballots; abstentions count toward quorum but
  not as votes cast. Bylaw questions need two-thirds (or a majority, if set). A tie goes to the
  President as buttons in an email; to the Secretary when the President is tied; by lot when both are.
  At the first annual meeting the election counts only if the bylaws are ratified.
- **Secret ballot**: there is no table of ballots. Casting marks the member voted, with a date only,
  and adds one to each chosen answer in the same transaction. The totals stay hidden until voting closes.
- **Lost ballot email**: the website's `/voting/` page asks for the email and the last two digits of
  the member number (from `member_code`, else the member id) and emails the link again, at most every
  10 minutes, with the same answer whether or not the email can vote.
- **Practice vote**: Members, Onboarding, Member votes, **Start a practice vote**. The board members
  are the voters, the date rules are skipped, and nothing goes to GitHub.
- **GitHub**: set a fine-grained token on the setup page (Member votes), for the governance repository,
  with Contents, Issues and Pull requests set to Read and write. Dolibarr warns a month before it expires.

### Updating to 0.8

1. Update the module and disable and enable **Member onboarding** once (new tables and the
   Member votes permission; the votes run in the existing 15-minute job).
2. Give the board user group "See member votes". Check someone holds `secretary` and `president`.
3. On the setup page, under Member votes, paste the GitHub token. Check the full member types and seats.
4. Deploy the website branch with the `/voting/` pages.
5. In January, start a practice vote with the board.

## Deadline reminders

Any Dolibarr project task can nag the people assigned to it until it is done. Use it for board
deadlines: the 990-N, the Missouri annual registration, the insurance renewal.

Projects, **Deadline reminders** lists every task with a reminder set, when the next reminder
and the escalation go out, and who is assigned. **Send due reminders now** does today's work
straight away; otherwise the daily scheduled job does it.

To set one up, create a project (for example "Board deadlines") and in it a task:

- **Start date**: the first reminder goes out on this day. **End date**: the due date.
- **Assigned to**: the people who get the reminders. Their user card needs an email address.
- **Remind assignees every (days)**: 7 for weekly. Reminders keep going, past the due date
  too, until the task is at 100%.
- **Escalate this many days before due** and **Escalation group**: if the task is still open
  that close to the due date, everyone in the group (for example a "Board" user group) gets
  one email. People assigned to the task are left out; they already get the reminders.
- **Repeat every year**: when the task is done, a copy for next year is made with the same
  people and settings, dates one year later.

Every reminder has a **mark complete** link. It works without logging in to Dolibarr, is
signed for that one task and person, and asks for a button press before changing anything,
so a mail scanner opening the link does nothing. Setting the task to 100% in Dolibarr works
too.

Example: taxes due 1 January, can start 1 December. Start 1 Dec, end 1 Jan, assigned to the
treasurer, remind every 7 days, escalate 14 days before due to the Board group, repeat every
year. The treasurer hears on 1, 8, 15, 22 and 29 December until it is done; the rest of the
board hears once, on 18 December, if it is not.

Updating from 0.4: the module now needs the **Projects** module (it is switched on with this
one). Disable and enable **Member onboarding** once so the task fields, the table and the
scheduled job are created.

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
| `trained_on`, `trainings_given` | text, number | the module, after each training. Read-only; only this module uses them. |

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
