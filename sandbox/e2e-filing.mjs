// Yearly filings: the treasurer is asked for the statements, uploads them from
// the email link, presses All uploaded, gets everything needed to file, marks
// both filings done; reminders, the board's escalation and the questions box.
//
//   node e2e-filing.mjs   (after e2e.mjs; pretends it is June of next year)
import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';

const DOLI = process.env.DOLI_URL || 'http://localhost:8080';
const MAIL = process.env.MAIL_URL || 'http://localhost:8025';
const stamp = Date.now();
const TREASURER = `treasurer-${stamp}@example.test`;
const PRESIDENT = `president-${stamp}@example.test`;
const SECRETARY = `secretary-${stamp}@example.test`;
const ACCOUNT = `Checking ${stamp}`;

const tick = (...args) => execFileSync('docker', ['compose', 'exec', '-T', '-u', 'www-data', 'dolibarr', 'php', '/var/www/html/custom/onboarding/sandbox/tick.php', ...args], { encoding: 'utf8' }).trim();
const last = (out) => out.split('\n').pop();
const sql = (q) => execFileSync('docker', ['compose', 'exec', '-T', 'db', 'mariadb', '-N', '-udolidbuser', '-pdolidbpass', 'dolidb', '-e', q], { encoding: 'utf8' }).trim();
const setConst = (k, v) => tick('purchase', 'const', k, v);
const step = (name) => console.log(`\n== ${name}`);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function mails(addr) {
  const r = await (await fetch(`${MAIL}/api/v1/search?query=${encodeURIComponent(`to:${addr}`)}`)).json();
  return r.messages || [];
}
async function mail(addr, subject) {
  for (let i = 0; i < 20; i++) {
    const m = (await mails(addr)).find((x) => x.Subject.includes(subject));
    if (m) return { ...m, text: (await (await fetch(`${MAIL}/api/v1/message/${m.ID}`)).json()).Text };
    await sleep(250);
  }
  throw new Error(`no "${subject}" to ${addr}; has ${JSON.stringify((await mails(addr)).map((m) => m.Subject))}`);
}
const count = async (addr, subject) => (await mails(addr)).filter((m) => m.Subject.includes(subject)).length;

// The page link from an email, pointed at the sandbox.
function pageOf(text) {
  const href = (text.match(/https?:\/\/\S*filing\.php\?\S+/) || [])[0];
  assert.ok(href, 'a link to the filing page');
  const u = new URL(href);
  return `${DOLI}/custom/onboarding/public/filing.php?${u.searchParams.toString()}`;
}
const fields = (url) => Object.fromEntries(new URL(url).searchParams);
async function post(url, data, files = []) {
  const fd = new FormData();
  for (const [k, v] of Object.entries({ ...fields(url), ...data })) fd.append(k, v);
  for (const [name, filename, body, type] of files) fd.append(name, new Blob([body], { type }), filename);
  const r = await fetch(url.split('?')[0], { method: 'POST', body: fd, redirect: 'manual' });
  assert.equal(r.status, 303, 'answers with a redirect');
  const to = r.headers.get('location');
  return { location: to, page: await (await fetch(`${DOLI}/custom/onboarding/public/filing.php?${new URL(to).searchParams.toString()}`)).text() };
}
const show = (fy) => JSON.parse(last(tick('filing', 'show', String(fy))));
const pdf = (s) => `%PDF-1.4\n% ${s}\n${'x'.repeat(200)}\n%%EOF\n`;

// The fiscal year that ends in the coming May, so its months include today.
const now = new Date();
const FY = now.getUTCMonth() >= 5 ? now.getUTCFullYear() + 1 : now.getUTCFullYear();
const months = Array.from({ length: 12 }, (_, i) => { const d = new Date(Date.UTC(FY - 1, 5 + i, 1)); return d.toISOString().slice(0, 7); });
const monName = (ym) => new Date(`${ym}-15T12:00:00Z`).toLocaleString('en-US', { month: 'long', timeZone: 'UTC' });
const IRS_DUE = `${FY}-10-15`;
const TODAY = now.toISOString().slice(0, 10);
const addDays = (d, n) => new Date(Date.parse(`${d}T12:00:00Z`) + n * 86400e3).toISOString().slice(0, 10);

try {
  step('setup: a June fiscal year, the company ids, an account with a year of lines, the officers');
  tick('filing', 'reset');
  // Only this test's officers hold the board tags.
  sql("DELETE cm FROM llx_categorie_member cm JOIN llx_categorie c ON c.rowid = cm.fk_categorie WHERE LOWER(c.label) IN ('president','secretary','vice-president','treasurer','director')");
  setConst('SOCIETE_FISCAL_MONTH_START', '6');
  setConst('MAIN_INFO_SIREN', '27-3926809');
  setConst('MAIN_INFO_SIRET', 'N000706594');
  setConst('ONBOARDING_FILING_ACCOUNTS', ACCOUNT);
  setConst('ONBOARDING_FILING_YEARLY', 'Givebutter: the transactions report for the year\n1099-K: any 1099-K received');
  const acct = last(tick('purchase', 'bank', ACCOUNT));
  assert.ok(Number(acct) > 0, 'account created');
  tick('filing', 'line', acct, `${months[0]}-10`, '1234', 'Deposit dues');
  tick('filing', 'line', acct, `${months[1]}-12`, '100', 'Deposit');
  tick('filing', 'line', acct, `${months[2]}-03`, '500', 'GIVEBUTTER PAYOUT');
  tick('filing', 'line', acct, `${months[2]}-20`, '-200', 'Rent');
  tick('filing', 'reconcile', acct, months[1]);
  let n = 1;
  for (const [email, first, role] of [[TREASURER, 'Tara', 'treasurer'], [PRESIDENT, 'Pat', 'president'], [SECRETARY, 'Sam', 'secretary']]) {
    last(tick('purchase', 'member', email, `CGW-F${String(stamp).slice(-6)}${n++}`, first, 'Filer'));
    tick('purchase', 'tag', email, role);
  }

  step('nothing happens before the start day; on it the treasurer is asked');
  assert.match(tick('filing', 'run', `${FY}-06-14`), /nothing to do/);
  assert.equal(show(FY), null);
  assert.match(tick('filing', 'run', `${FY}-06-15`), new RegExp(`FY${FY} filing opened`));
  const ask = await mail(TREASURER, `FY${FY} taxes: please upload the year's statements`);
  assert.match(ask.text, /Hi Tara/);
  assert.match(ask.text, new RegExp(`${ACCOUNT}: statements for`));
  assert.ok(!ask.text.includes(`${monName(months[1]).slice(0, 3)} ${months[1].slice(0, 4)},`), 'the reconciled month is not asked for');
  assert.match(ask.text, /Givebutter: the transactions report/);
  assert.match(ask.text, new RegExp(`due 15 Oct ${FY}`));
  const page = pageOf(ask.text);

  step('the same day again sends nothing; a week later a reminder');
  tick('filing', 'run', `${FY}-06-15`);
  assert.equal(await count(TREASURER, 'taxes'), 1);
  assert.match(tick('filing', 'run', `${FY}-06-22`), /reminded/);
  await mail(TREASURER, `Reminder: FY${FY} taxes: please upload`);

  step('the link: a wrong key is refused; opening it changes nothing');
  assert.equal((await fetch(page.replace(/k=[0-9a-f]+/, 'k=0000'))).status, 403);
  const before = await (await fetch(page)).text();
  assert.match(before, /Upload the year&#039;s statements|Upload the year's statements/);
  assert.match(before, /reconciled in Dolibarr/);
  assert.equal(show(FY).status, 'docs');

  step('uploads: months from file names, one to sort by hand, a report, a none mark');
  const key = `acct:${acct}`;
  let r = await post(page, { do: 'save' }, [
    [`up[${key}][]`, `${months[0]}.pdf`, pdf('june'), 'application/pdf'],
    [`up[${key}][]`, `Statement ${monName(months[2])} ${months[2].slice(0, 4)}.pdf`, pdf('aug'), 'application/pdf'],
    [`up[${key}][]`, 'scan.pdf', pdf('scan'), 'application/pdf'],
    [`up[${key}][]`, 'virus.exe', 'MZ'.padEnd(300, 'x'), 'application/octet-stream'],
    ['up[y:givebutter][]', 'givebutter.csv', 'date,amount\n'.padEnd(200, '1'), 'text/csv'],
  ]);
  assert.match(decodeURIComponent(r.location), /4 files saved/);
  assert.match(decodeURIComponent(r.location), /virus\.exe: not a statement file/);
  assert.match(r.page, /which month\?/);
  // 12 months less the reconciled one and two uploads, plus the 1099-K
  assert.equal(show(FY).missing, 10);
  const scanId = (r.page.match(/name="assign\[(\d+)\]"/) || [])[1];
  r = await post(page, { do: 'save', [`assign[${scanId}]`]: months[3], 'none[y:1099-k]': '1' });
  assert.equal(show(FY).missing, 8);

  step('a file can be downloaded through the link');
  const fileId = (r.page.match(/file=(\d+)"/) || [])[1];
  const dl = await fetch(`${page}&file=${fileId}`);
  assert.equal(dl.status, 200);
  assert.match(dl.headers.get('content-type'), /pdf|csv/);

  step('All uploaded with months still missing needs a reason');
  r = await post(page, { do: 'docsdone' });
  assert.match(decodeURIComponent(r.location), /still missing/);
  assert.equal(show(FY).status, 'docs');
  r = await post(page, { do: 'docsdone', note: 'The bank is mailing the rest' });
  assert.equal(show(FY).status, 'file');

  step('the ready-to-file email has the 990-N values, the numbers and the state report');
  const ready = await mail(TREASURER, `FY${FY} taxes: ready to file the IRS Form 990-N and the Missouri report`);
  assert.match(ready.text, /EIN: 27-3926809/);
  assert.match(ready.text, new RegExp(`Tax year: ${FY - 1}, beginning 06/01/${FY - 1} and ending 05/31/${FY}`));
  assert.match(ready.text, /Principal officer: Pat Filer, President/);
  assert.match(ready.text, /Gross receipts normally \$50,000 or less: Yes/);
  assert.match(ready.text, new RegExp(`${ACCOUNT}: money in: \\$1,334\\.00`), 'deposits counted, the rent and (with Givebutter counted) the payout left out');
  assert.match(ready.text, /charter number N000706594/);
  assert.match(ready.text, /Treasurer: Tara Filer/);
  assert.match(r.page, /IRS return filed/);

  step('the board hears 30 days before the IRS due date, once');
  tick('filing', 'run', addDays(IRS_DUE, -30));
  const esc = await mail(SECRETARY, `FY${FY} taxes: the IRS Form 990-N is due in 30 days`);
  assert.match(esc.text, /Ready to file since/);
  assert.match(esc.text, /reinstated once before/);
  await mail(PRESIDENT, 'is due in 30 days');
  assert.equal(await count(TREASURER, 'is due in 30 days'), 0, 'the treasurer gets reminders, not the escalation');
  tick('filing', 'run', addDays(IRS_DUE, -29));
  assert.equal(await count(SECRETARY, 'taxes: the IRS'), 1);

  step('a question goes to the president');
  await post(page, { do: 'help', q: 'Which Venmo account do we use?' });
  const q = await mail(PRESIDENT, `FY${FY} taxes: a question from Tara Filer (${TREASURER})`);
  assert.match(q.text, /Which Venmo account/);

  step('IRS filed, then the state report: the year is done and everyone hears');
  // The page runs on the real clock, so the days are real ones.
  r = await post(page, { do: 'irs', day: addDays(TODAY, -2), form: '990-N', receipts: '$2,034.00' }, [['proof', 'accepted.pdf', pdf('irs ok'), 'application/pdf']]);
  let f = show(FY);
  assert.equal(f.irs_filed_at, addDays(TODAY, -2));
  assert.equal(Number(f.receipts), 2034);
  assert.equal(f.status, 'file');
  r = await post(page, { do: 'irs', day: TODAY });
  assert.match(decodeURIComponent(r.location), /already marked filed/);
  r = await post(page, { do: 'state', day: addDays(TODAY, -1) });
  f = show(FY);
  assert.equal(f.status, 'done');
  assert.match(r.page, /This year is done/);
  assert.match(r.page, /accepted\.pdf/);
  const done = await mail(PRESIDENT, `FY${FY} tax filings are done`);
  assert.match(done.text, /gross receipts \$2,034\.00/);
  assert.match(done.text, /by Tara Filer/);
  await mail(TREASURER, `FY${FY} tax filings are done`);

  step('a finished year sends nothing more; next year three-year average uses the reported figure');
  const sent = await count(TREASURER, 'taxes');
  tick('filing', 'run', addDays(IRS_DUE, 7));
  assert.equal(await count(TREASURER, 'taxes'), sent);
  assert.equal(f.form.years[2].reported, true);
  console.log('\nAll yearly filing checks passed');
} finally {
  for (const [email, role] of [[TREASURER, 'treasurer'], [PRESIDENT, 'president'], [SECRETARY, 'secretary']]) {
    try { tick('purchase', 'untag', email, role); } catch { /* not created */ }
  }
  setConst('ONBOARDING_FILING_ACCOUNTS', '');
  setConst('ONBOARDING_FILING_YEARLY', '');
}
