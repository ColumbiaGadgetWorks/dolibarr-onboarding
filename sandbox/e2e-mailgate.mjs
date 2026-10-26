// Email approval: with an approver set, emails wait for a button press.
//
//   node e2e-mailgate.mjs   (after e2e.mjs; it turns email approval off again at the end)
import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';

const DOLI = process.env.DOLI_URL || 'http://localhost:8080';
const MAIL = process.env.MAIL_URL || 'http://localhost:8025';
const KEY = process.env.ONBOARDING_API_KEY || 'sandbox-key-sandbox-key-sandbox-key';
const stamp = Date.now();
const APPROVER = `approver-${stamp}@example.test`;
const EXEMPT = `board-${stamp}@example.test`;

const tick = (...args) => execFileSync('docker', ['compose', 'exec', '-T', '-u', 'www-data', 'dolibarr', 'php', '/var/www/html/custom/onboarding/sandbox/tick.php', ...args], { encoding: 'utf8' }).trim();
const setConst = (k, v) => tick('purchase', 'const', k, v);
const step = (name) => console.log(`\n== ${name}`);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function api(action, body = {}) {
  const r = await fetch(`${DOLI}/custom/onboarding/public/api.php?action=${action}`, {
    method: 'POST', headers: { 'content-type': 'application/json', 'X-Onboarding-Key': KEY }, body: JSON.stringify(body),
  });
  return { status: r.status, ...(await r.json()) };
}
async function mails(addr) {
  const r = await (await fetch(`${MAIL}/api/v1/search?query=${encodeURIComponent(`to:${addr}`)}`)).json();
  return r.messages || [];
}
const subjects = async (addr) => (await mails(addr)).map((m) => m.Subject);
async function requestFor(who) {
  for (let i = 0; i < 10; i++) {
    const m = (await mails(APPROVER)).find((x) => x.Subject.startsWith(`Approve email to ${who}:`));
    if (m) {
      const html = (await (await fetch(`${MAIL}/api/v1/message/${m.ID}`)).json()).HTML.replace(/\s+/g, ' ');
      const href = (html.match(/href="([^"]*mail\.php\?h=[^"]*do=approve[^"]*)"/) || [])[1];
      assert.ok(href, 'approve button in the request');
      const u = new URL(href.replace(/&amp;/g, '&'));
      return { m, h: u.searchParams.get('h'), t: u.searchParams.get('t'), html };
    }
    await sleep(300);
  }
  throw new Error(`no approval request for ${who}; approver has ${JSON.stringify(await subjects(APPROVER))}`);
}
const page = (h, t, extra = '') => fetch(`${DOLI}/custom/onboarding/public/mail.php?h=${h}&t=${t}${extra}`);
const press = async (h, t, what) => (await fetch(`${DOLI}/custom/onboarding/public/mail.php`, {
  method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ h, t, do: what }),
})).text();

try {
  tick('mailgate', 'reset');
  setConst('ONBOARDING_MAILGATE_APPROVER', APPROVER);
  setConst('ONBOARDING_MAILGATE_EXEMPT', EXEMPT);

  step('a signup email waits for approval; the approver gets a copy with buttons');
  const ann = `ann-${stamp}@example.test`;
  assert.equal((await api('start', { firstname: 'Ann', lastname: 'Held', email: ann })).ok, true);
  const r1 = await requestFor(ann);
  assert.match(r1.html, /Approve, and always allow this kind/);
  assert.match(r1.html, /Hi Ann,\s+Thanks/, 'the email itself is in the request, line breaks kept');
  assert.deepEqual(await subjects(ann), [], 'nothing reaches the member before approval');

  step('the link only shows the email; a wrong token is refused');
  assert.equal((await page(r1.h, 'nope')).status, 403);
  const shown = await (await page(r1.h, r1.t, '&do=approve')).text();
  assert.match(shown, /Send this email\?/);
  assert.deepEqual(await subjects(ann), [], 'opening the link sends nothing');

  step('Approve sends it, once');
  assert.match(await press(r1.h, r1.t, 'approve'), /Approved and sent/);
  assert.equal((await subjects(ann)).length, 1);
  assert.match(await press(r1.h, r1.t, 'approve'), /Already sent/);
  assert.equal((await subjects(ann)).length, 1);

  step('Approve, and always allow this kind: later ones of that kind go straight out');
  const bob = `bob-${stamp}@example.test`;
  await api('start', { firstname: 'Bob', lastname: 'Held', email: bob });
  const r2 = await requestFor(bob);
  assert.match(await press(r2.h, r2.t, 'always'), /now sent without asking/);
  assert.equal((await subjects(bob)).length, 1);
  const cal = `cal-${stamp}@example.test`;
  await api('start', { firstname: 'Cal', lastname: 'Free', email: cal });
  assert.equal((await subjects(cal)).length, 1, 'an allowed kind is not held');
  assert.ok(!(await subjects(APPROVER)).some((s) => s.includes(cal)), 'and the approver is not asked');

  step('Dolibarr\'s own emails are held too; Reject never sends them');
  const dee = `dee-${stamp}@example.test`;
  assert.equal(tick('mailgate', 'send', dee, 'Your password reset'), 'true', 'the sender is told it went');
  const r3 = await requestFor(dee);
  assert.equal(r3.m.Attachments, 1, 'the attachment is copied into the request');
  assert.match(await press(r3.h, r3.t, 'reject'), /Rejected/);
  assert.deepEqual(await subjects(dee), []);
  assert.match(await press(r3.h, r3.t, 'approve'), /Already rejected/);
  assert.deepEqual(await subjects(dee), []);

  step('exempt addresses and the approver are never held');
  tick('mailgate', 'send', EXEMPT, 'Board note');
  assert.deepEqual(await subjects(EXEMPT), ['Board note']);
  tick('mailgate', 'send', `"Adam" <${APPROVER}>`, 'For the approver');
  assert.ok((await subjects(APPROVER)).includes('For the approver'));

  step('one exempt and one other recipient: held');
  const eve = `eve-${stamp}@example.test`;
  tick('mailgate', 'send', `${EXEMPT}, ${eve}`, 'Mixed recipients');
  const r4 = await requestFor(EXEMPT);
  assert.deepEqual(await subjects(eve), []);
  assert.match(await press(r4.h, r4.t, 'approve'), /Approved and sent/);
  const got = (await mails(eve))[0];
  assert.equal(got && got.Attachments, 1, 'the approved email keeps its attachment though the original file is gone');

  step('the daily job reminds the approver of nothing new yet');
  assert.match(tick('mailgate', 'daily'), /0 waiting over a day/);
} finally {
  setConst('ONBOARDING_MAILGATE_APPROVER', '');
  setConst('ONBOARDING_MAILGATE_EXEMPT', '');
}

step('approval off: emails go straight out again');
const fay = `fay-${Date.now()}@example.test`;
tick('mailgate', 'send', fay, 'Straight out');
assert.deepEqual(await subjects(fay), ['Straight out']);

console.log('\nEMAIL APPROVAL PASSED');
