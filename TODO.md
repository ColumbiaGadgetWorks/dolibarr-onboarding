# Long-term ideas

Not started. Each needs a decision before it is built.

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
