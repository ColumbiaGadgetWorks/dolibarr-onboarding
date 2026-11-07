# Session summary

Written 2026-11-07, on branch `claude/clever-albattani-azcra9`.

## What this chat covered

This chat made no code changes to the module. It covered one question: **would
[agb253/gemini-work-helper](https://github.com/agb253/gemini-work-helper) cut Claude token
usage?**

### What the tool is

A Chrome extension plus a local Python server (`127.0.0.1:8765`). Task cards (Markdown files
in `workqueue/todo/`) are bundled 1–3 at a time and pasted into the Gemini web app. A person
clicks Send. The reply is read off the page, a JSON block is pulled out for each task and
written to disk, and an optional `check:` command marks each task done or failed. It has no
connection to Claude.

### Verdict: little saving for this project

- **Gemini sees only the card's text**: not the repo, the files or the Claude
  conversation. Any context has to be written into the card by Claude, and Claude's output
  tokens cost more than its input tokens. That often costs about as much as doing the task.
- **Claude still reads the results back** to check them and use them.
- **It returns JSON only.** It can't edit code, so Claude still applies any changes.
- **Every batch goes into the same Gemini chat**, so earlier batches stay in Gemini's
  context and answers can drift or mix between tasks.
- **A person clicks for every batch.** Claude can't hand off and wait for the answer within
  one task.
- **Risks:** scripting the consumer Gemini site may break Google's terms (risk to the
  account). On a personal account, chats may be used for training, so member, donor and bank
  data must not go through it. It finds page elements by Google's page structure, so it breaks
  when Gemini's page changes.
- **Smaller bugs:** one card with no front matter crashes `/status`. A `#` in a `check:`
  command is treated as a comment and cuts the command short.
- **Where it helps:** short prompt, long output, checked by a script, for example
  generating seed or test data, classifying pasted data, background research lists.

### Better ways to save Claude tokens

1. Subagents on cheaper models (Haiku or Sonnet). They read files themselves and return
   only a summary, so the main context stays small.
2. If Gemini's quota is wanted: Google's Gemini CLI, called from the shell. It reads files
   itself and runs without clicks. Check its current sign-in and free-tier terms first.
3. Compact long sessions, read only the parts of files you need, and keep the README
   sections Claude has to read short (the README is about 64 KB).

**Decision: not adopted.** Nothing from it was added to this repository.

## Where the project stands (from the repository, for context)

The module work itself was done in earlier sessions (pull requests up to #47, mostly from the
`claude/magical-sagan-5wdbcz` branch). This branch starts at `d7097c6`, the merge of #47.

- **Module version 0.22.3** (`core/modules/modOnboarding.class.php`), needs Dolibarr 17+.
  Production runs Dolibarr 24.0.2 (php 8.2) with MariaDB 11.4 on Skynet.
- **Latest changes (5–6 November):** signup links that keep working with held duplicates merged
  (0.22.3); the setup page keeps many-line settings on Save (0.22.2); free classes made in
  Dolibarr, put on the Google Calendar through an Apps Script, and signed up for on the
  website.
- **Late October:** purchase requests (line items, approval rules, reimbursed/ordered,
  rejection reasons, training fees per zone); signed papers and ID photo copied onto the
  member card; on-the-spot trainings (0.18); yearly filings (990-N, Missouri); yearly access
  review (0.17); security policy automation (0.16); Plaid bank feeds; reconciling from
  statements, PDFs and PayPal; CGW home page and menu; email approval (hold outgoing email);
  PayPal dues sync; Needs attention page.
- **Website half:** [ColumbiaGadgetWorks/website](https://github.com/ColumbiaGadgetWorks/website)
  (`src/join.js`, `/membership/join/`).

## Notes for continuing

- **Tests:** `sandbox/` has a Docker stack (`docker-compose.yml`, `setup.php`, a mock
  Givebutter server) and Playwright end-to-end scripts (`e2e*.mjs`), plus
  `smoke-pages.sh`. CI runs `.github/workflows/test.yml`. A monthly Trivy scan runs
  `security-scan.yml` on the images in `.github/scan-images.txt`.
- **Version bump:** releases bump `$this->version` in the module descriptor and put the
  version in the commit title, e.g. `(0.22.3)`.
- **Roadmap:** `TODO.md`. Highest priority: the **Plaid security attestations, due
  2027-04-28**. Several of them wait on the information security policy being merged in
  cgwmanagement, the first monthly scan, the first access review, and officers' logins being
  linked to member cards.
- **Other dates in TODO.md:** PayPal members to move to Givebutter by January 2027; the City
  of Columbia grant (12 classes, November 2026 to October 2027); the lease ends 2027-04-30
  (start renewal about 90 days before).
- **Next ideas waiting on a decision:** door access emails answered from the email, then door
  lock integration (needs the lock system's brand and API); the numbered list in `TODO.md`.
- **Data care:** the module holds IDs, signatures, bank data and dues. Don't send any of it
  to outside AI tools without checking the organization's AI and privacy policy.
