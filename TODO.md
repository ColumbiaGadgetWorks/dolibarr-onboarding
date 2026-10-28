# Long-term ideas

Not started unless marked. Each needs a decision before it is built. The numbered list at
the end came from the bylaws, the website, the wiki, InfoSheets, the kiosk, label printer
and management repositories: things the makerspace tracks by hand today.

## High priority: records, privacy and access policy

Needed for Plaid's production review. The policy is
`governance/policies/information-security.md` in cgwmanagement (adopted by merging its pull
request); the privacy page says the same on the website. The four officers stay Dolibarr
administrators (decided 2026-10-28). Built in 0.16: logins disabled when a role ends, bank data
deleted after 7 years, Access review page, monthly security scan.

### Plaid security attestations, due 2027-04-28

| Attestation | How | Status |
|---|---|---|
| Documented access control policy | `PERMISSIONS.md` and the policy, section 2 | Attest once the policy is merged |
| Zero trust access architecture | Dolibarr only through Cloudflare (HTTPS) and VoidAuth sign-in; every page needs a session or a signed link. Cloudflare Access in front is not needed for now | Attest once VoidAuth requires a second step |
| Centralized identity and access management | VoidAuth (OIDC) for Dolibarr; people's Dolibarr passwords random and unknown; one emergency admin | Same |
| MFA where Plaid Link is deployed | VoidAuth requires an authenticator app or passkey for every account | Same |
| Information Security Policy | The policy | Attest once merged |
| Data deletion and retention policy | The policy, section 8; the daily job deletes bank data after 7 years and ID photos after 90 days | Attest once merged and ID photo days set to 90 |
| Patches vulnerabilities within a defined SLA | The policy, section 4: critical and high within 14 days, others 60; the monthly security check issue | Attest after the first monthly check |
| Vulnerability scanning | Monthly Trivy scan workflow (images in `.github/scan-images.txt`) and Dependabot alerts on the repositories | Attest after the first scan |
| Automated de-provisioning | Module 0.16: logins of people whose membership or officer tag ended are disabled, with a checklist to the security owner | Attest once officers' logins are linked to member cards |
| Periodic access reviews and audits | Access review page; a yearly "Access review" deadline task to the Vice President and Secretary each April | Attest after the first review |

## Door access emails

What happens today: when someone's first dues payment arrives, the membership team address
(setup page, Membership team email) gets "New paying member: ... needs a badge". When a
Givebutter member's membership ends, it gets "Membership ended: ..." saying whether their
badge still has access and should be turned off. Members, Onboarding has the filters
"Paying, no active badge" and "Not paying, badge still active", and both are counted on
Needs attention.

The idea: make these into requests that are answered from the email.

- New member: email the door admin asking which keycard they were given, with a form (or
  buttons) to enter the card and confirm access is on. That fills in the member's badge and
  ticks access on their card.
- Membership lapses (any channel, PayPal and check included, not only Givebutter): email
  asking for access to be removed, with a button to confirm it is off.
- Remind until answered, like the deadline reminders, and show open requests on Needs
  attention.

## Door lock system integration

The door lock system is networked. Manage it from Dolibarr: add, enable and disable
credentials directly when a member is added, pays or lapses, instead of by hand. Needs:
which system it is, its API, where it runs, and whether Dolibarr can reach it from Skynet.
Would replace most of the emails above, which could stay as a fallback.

## More ideas, from the repositories

1. **Yearly tax and state filings.** In progress (Yearly filings, 0.14). Later: 1099-NEC
   for anyone paid $600 or more, and year-end donor acknowledgment letters.
2. **Automated door access.** See the two sections above. Needs the lock system's brand
   and API first.
3. **Equipment register and repairs.** Replace the InfoSheets Netlify repair log: one card
   per machine with its zone, specs (feeding the website's tool pages), training needed,
   and an out-of-service status that blocks training bookings until it is fixed. Repair
   requests by email button, like purchase requests.
4. **Discipline and suspension cases.** Track the bylaws' steps: written notice 15 days
   before a hearing, the member's 5 days to answer, the board's 2/3 vote, and the 14-day
   limit on an emergency suspension. Reminders before each limit runs out; badge off
   while suspended.
5. **Incident and near-miss reports.** A form (kiosk and website) that must be filed
   within 24 hours, emails the zone boss and the safety officer, and can take a tool out
   of service (item 3).
6. **Board meetings.** Quarterly at least, 48 hours' notice to members, minutes filed by
   the secretary, and a flag for a director who misses three meetings in a row.
7. **Roles register.** Who holds each role (the role tags already do this), terms ending
   30 April, the rule of no more than two roles per person, an alert when fewer than three
   directors are left, and each zone boss's duties as yearly tasks.
8. **Spending rules.** Over $500 needs two directors including the President or
   Treasurer, nobody approves their own, and receipts within 30 days. Purchase requests do
   most of this; make the limits match the bylaws and add the receipt chase.
9. **Hardship waivers.** Granted for 12 months at most, capped at 20% of members or 4
   people, with a reminder before each one ends. The Legacy $40 tier: offer the switch.
10. **Conflict of interest.** A yearly disclosure from every director (a task with an
    email button), and a tracker for the 45-day member petition.
11. **Accounts register.** Every online account (bank, PayPal, Givebutter, domain,
    Google, GitHub, Cloudflare), at least two people with access to each, and a yearly
    review that asks each owner to confirm.
12. **Bank statement upload and monthly close.** Central Bank has no free feed
    (SimpleFIN does not cover business checking). Bank feeds through Plaid are built (0.15);
    they go live once Plaid's production review passes. Until then upload the statement each
    month. Still to build: the treasurer signs off the month by email
    button. Yearly filings would then find every month already there.
13. **Recurring bills.** Rent ($875 a month), the lathe payments, insurance: expected each
    month, matched to bank lines, flagged when missing. The lease ends 2027-04-30: start
    the renewal about 90 days before.
14. **Insurance record.** Policy, insurer, limits, renewal date, certificate for the
    landlord, with a renewal reminder.
15. **Donations.** In-kind intake (what, value, donor), acknowledgment letters, and
    splitting Supporter dues into dues plus a donation.
16. **Grant projects.** The City of Columbia grant (12 classes, November 2026 to October
    2027) and OCA: what was promised, classes held against it, spending, report dates.
17. **PayPal to Givebutter move.** Every PayPal member moved to Givebutter by January
    2027: who is left, an email asking each to switch, and the PayPal plan cancelled.
18. **Monthly board packet.** One email or page each month: members joined and lost, dues
    in, spending by zone, open purchase requests, deadlines, incidents.
19. **Kiosk visitor and guest sign-in.** Guests sign the waiver on the kiosk, with the
    under-18 guardian rules, and their visits are counted.
20. **Lockers and bins.** Who has which, paid until when, and the 30-day clear-out notice
    when someone leaves.
21. **Label printer buttons.** Print a member's bin label, a tool's out-of-service tag or a
    donation label from Dolibarr through the Zebra printer's API.
22. **Consumables.** Stock levels for filament, glue, sandpaper and the like, with a
    reorder point that opens a purchase request.
23. **One events calendar.** Trainings, classes, board meetings and open houses in one
    calendar the website reads.
24. **Kiosk shop fund campaigns.** The kiosk's donate screens feed Donations (item 15).
25. **Contact form leads.** Website contact form messages become Dolibarr contacts with a
    follow-up task, so nobody is forgotten.
26. **Volunteer hours and the Teacher Fund.** Hours logged per person; the Teacher Fund
    pays up to $500 a person a year at 50%, settled quarterly.
27. **Orientation and the first-month check-in.** Orientation booked like a training, and
    an email a month after joining asking how it is going.
28. **Website numbers.** Member count, tools, trainings held and similar figures pushed
    from Dolibarr to the website instead of typed by hand.
29. **Role dashboards.** Buttons on the setup page, next to "Make it everyone's home page":
    "Set dashboard for secretary", "for treasurer", "for president". Each gives the people
    holding that role tag their own CGW home page layout (a YAML per role, kept per user):
    the treasurer's with bank balances, reconciling, purchase requests to pay and the tax
    filing; the secretary's with signups, votes, board meetings and member counts; the
    president's with Needs attention, deadlines and the board packet numbers.
30. **Access and change alerts.** Who can see and change what (Dolibarr user rights, kept
    to roles), reviewed yearly. An email, through email approval's approver, when
    something that should rarely change does: a bank entry edited or deleted after it was
    reconciled, a bank account's details, user rights or a new admin, module settings
    (API keys, the email approver), a member's dues dates set by hand, a large refund, or
    many records deleted at once. Uses Dolibarr's triggers and the audit log (Security
    events); a daily summary of anything unusual, at once for the serious ones.
