// End-to-end test against the sandbox stack. Plays the part of the website
// Worker, walks one person from signup to paying member, then through a
// cancelled plan, reminders, lapse and rejoining.
//
//   docker compose up -d db dolibarr givebutter mail   (with MOCK_WEBHOOK_URL
//       pointing straight at Dolibarr, see .github/workflows/test.yml)
//   node e2e.mjs
import { execFileSync } from 'node:child_process';
import { deflateSync, crc32 } from 'node:zlib';
import assert from 'node:assert/strict';

const DOLI = process.env.DOLI_URL || 'http://localhost:8080';
const MOCK = process.env.MOCK_URL || 'http://localhost:8090';
const MAIL = process.env.MAIL_URL || 'http://localhost:8025';
const KEY = process.env.ONBOARDING_API_KEY || 'sandbox-key-sandbox-key-sandbox-key';
const email = `e2e-${Date.now()}@example.test`;

async function api(action, body = {}, key = KEY) {
  const r = await fetch(`${DOLI}/custom/onboarding/public/api.php?action=${action}`, {
    method: 'POST',
    headers: { 'content-type': 'application/json', 'X-Onboarding-Key': key },
    body: JSON.stringify(body),
  });
  const text = await r.text();
  let json;
  try { json = JSON.parse(text); } catch { throw new Error(`${action}: HTTP ${r.status}, not JSON: ${text.slice(0, 600)}`); }
  return { status: r.status, ...json };
}
const mock = async (path, body = {}) => (await fetch(`${MOCK}${path}`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(body) })).json();
const tickIn = (input, ...args) => execFileSync('docker', ['compose', 'exec', '-T', '-u', 'www-data', 'dolibarr', 'php', '/var/www/html/custom/onboarding/sandbox/tick.php', ...args], { encoding: 'utf8', input }).trim();
const tick = (...args) => execFileSync('docker', ['compose', 'exec', '-T', '-u', 'www-data', 'dolibarr', 'php', '/var/www/html/custom/onboarding/sandbox/tick.php', ...args], { encoding: 'utf8' }).trim();
const dumpOf = (who) => JSON.parse(tick('dump', who).split('\n').pop());
const dump = () => dumpOf(email);
async function mailTo(addr) {
  const r = await (await fetch(`${MAIL}/api/v1/search?query=${encodeURIComponent(`to:${addr}`)}`)).json();
  return (r.messages || []).map((m) => m.Subject);
}

function png(size = 32) {
  const chunk = (type, data) => {
    const body = Buffer.concat([Buffer.from(type), data]);
    const out = Buffer.alloc(body.length + 8);
    out.writeUInt32BE(data.length, 0);
    body.copy(out, 4);
    out.writeUInt32BE(crc32(body), body.length + 4);
    return out;
  };
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(size, 0); ihdr.writeUInt32BE(size, 4); ihdr[8] = 8; ihdr[9] = 2;
  const raw = Buffer.alloc((size * 3 + 1) * size);
  for (let i = 0; i < raw.length; i++) raw[i] = i % (size * 3 + 1) === 0 ? 0 : Math.floor(Math.random() * 256);
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}

const step = (name) => console.log(`\n== ${name}`);

step('wrong key is refused');
assert.equal((await api('docs', {}, 'nope')).status, 401);

step('documents');
const docs = (await api('docs')).docs;
assert.ok(docs.waiver.version && docs.agreement.version);

step('step 1: details');
assert.equal((await api('start', { firstname: 'Ada', lastname: '', email })).status, 400);
const started = await api('start', { firstname: 'Ada', lastname: 'Tester', email, discord: 'ada#1', notify_events: true, notify_news: false, ip: '203.0.113.9' });
assert.equal(started.ok, true, JSON.stringify(started));
assert.match(started.token, /^[a-f0-9]{48}$/);
assert.equal(started.stage, 'details');
const token = started.token;

step('same email again gets no token');
const again = await api('start', { firstname: 'Eve', lastname: 'Else', email });
assert.equal(again.check_email, true);
assert.equal(again.token, undefined);
// The emailed link replaced the token, as it would for the real owner.
assert.equal((await api('status', { token })).status, 404);
const fresh = dump();
assert.equal(fresh.applicant.firstname, 'Ada', 'a second signup must not overwrite the first');
assert.equal(fresh.contact_fields.options_discord_handle, 'ada#1');
assert.equal(String(fresh.contact_fields.options_notify_events), '1');

step('resume link arrives by email');
let subjects = await mailTo(email);
assert.ok(subjects.length >= 2, `expected signup emails, got ${JSON.stringify(subjects)}`);
const list = await (await fetch(`${MAIL}/api/v1/search?query=${encodeURIComponent(`to:${email}`)}`)).json();
const body = await (await fetch(`${MAIL}/api/v1/message/${list.messages[0].ID}`)).json();
const t2 = (body.Text.match(/#t=([a-f0-9]{48})/) || [])[1];
assert.ok(t2, 'resume link with token in the email');

step('step 2: paperwork');
const signature = png(48).toString('base64');
assert.equal((await api('sign', { token: t2, doc: 'waiver', name: 'Ada Tester', version: 'old', signature })).status, 409);
assert.equal((await api('sign', { token: t2, doc: 'waiver', name: 'Ada Tester', version: docs.waiver.version })).error, 'signature', 'a drawn signature is required');
assert.equal((await api('sign', { token: t2, doc: 'waiver', name: 'Ada Tester', version: docs.waiver.version, ip: '203.0.113.9', signature })).waiver, true);
assert.equal(dump().member, null, 'only a contact until the paperwork is done');
assert.equal((await api('sign', { token: t2, doc: 'agreement', name: 'Ada Tester', version: docs.agreement.version, ip: '203.0.113.9', signature })).agreement, true);
assert.equal((await api('id', { token: t2, data: Buffer.from('this is definitely not an image, but it is long enough to pass the size check for sure, yes').toString('base64') })).status, 400);
const up = await api('id', { token: t2, data: png().toString('base64') });
assert.equal(up.id, true, JSON.stringify(up));
assert.equal(up.stage, 'payment');
let d = dump();
assert.ok(d.files.includes('waiver.pdf'), `signed PDF missing: ${d.files}`);
assert.ok(d.files.includes('agreement.txt') && d.files.includes('id.png'), `files: ${d.files}`);
assert.ok(d.contact_fields.options_waiver_date, 'waiver date mirrored onto the contact');
assert.ok(d.files.includes('waiver-signature.png'), `drawn signature missing: ${d.files}`);
assert.equal(d.member.status, -1, 'ready to pay: a non-member (draft member), not yet a member');
assert.equal(d.member.subscriptions, 0);

step('a payment from an unknown email stays unmatched');
const stranger = await mock('/control/pay', { email: `stranger-${Date.now()}@example.test`, amount: 50 });
assert.match(stranger.delivery.status, /unmatched/);
step('a donation to another campaign is ignored');
assert.match((await mock('/control/pay', { email, amount: 500, campaign: 'DONATE' })).delivery.status, /ignored/);

step('step 3: dues payment');
const paid = await mock('/control/pay', { email, amount: 50, first_name: 'Ada', last_name: 'Tester' });
assert.match(paid.delivery.status, /applied/, paid.delivery.status);
let st = await api('status', { token: t2 });
assert.equal(st.stage, 'complete');
assert.equal(st.paid, true);
d = dump();
assert.equal(d.member.status, 1, 'member validated');
assert.equal(d.member.subscriptions, 1);
assert.equal(d.member.fields.options_payment_state, 'active');
assert.ok(d.member.fields.options_agreement_date, 'agreement date mirrored onto the member');
assert.equal(d.member.fields.options_payment_channel, 'Givebutter');

step('the same webhook delivered twice records dues once');
assert.match((await mock('/control/resend')).status, /duplicate/);
assert.equal(dump().member.subscriptions, 1);

step('hourly sync is harmless when nothing was missed');
console.log(tick('sync'));
assert.equal(dump().member.subscriptions, 1);

step('membership team was told');
assert.ok((await mailTo('membership-team@example.test')).some((s) => s.includes('New paying member')));

step('plan cancelled: reminders, then lapse');
assert.match((await mock(`/control/plan/${paid.plan.id}/cancel`)).delivery.status, /cancelled/);
assert.equal(dump().applicant.payment_state, 'cancelled');
const count = async () => (await mailTo(email)).filter((s) => s.includes('dues need attention')).length;
console.log(tick('daily', '1'));
assert.equal(await count(), 0, 'no reminder before day 3');
console.log(tick('daily', '4'));
assert.equal(await count(), 1);
console.log(tick('daily', '4'));
assert.equal(await count(), 1, 'a reminder is never sent twice');
console.log(tick('daily', '15'));
assert.equal(await count(), 2, 'days 7 and 14 coming due together send one email');
console.log(tick('daily', '45'));
d = dump();
assert.equal(d.applicant.payment_state, 'lapsed');
assert.equal(d.member.status, 0, 'member terminated');
assert.equal(d.member.fields.options_payment_state, 'lapsed');
assert.ok((await mailTo(email)).some((s) => s.includes('has ended')));
assert.ok((await mailTo('membership-team@example.test')).some((s) => s.includes('Membership ended')));

step('paying again brings them back');
assert.match((await mock('/control/pay', { email, amount: 100 })).delivery.status, /applied/);
d = dump();
assert.equal(d.member.status, 1);
assert.equal(d.applicant.payment_state, 'active');
assert.equal(d.member.subscriptions, 2);

step('a payment missed by the webhook is picked up by the sync');
const email2 = `e2e-sync-${Date.now()}@example.test`;
assert.equal((await api('start', { firstname: 'Syd', lastname: 'Sync', email: email2 })).ok, true);
await mock('/control/pay', { email: email2, amount: 50, silent: true });
console.log(tick('sync'));
const d2 = JSON.parse(tick('dump', email2).split('\n').pop());
assert.equal(d2.applicant.payment_state, 'active');
assert.equal(d2.applicant.stage, 'details', 'paid without paperwork is not complete');

step('abandoned signup is cleaned up');
const email3 = `e2e-gone-${Date.now()}@example.test`;
assert.equal((await api('start', { firstname: 'Gia', lastname: 'Gone', email: email3 })).ok, true);
console.log(tick('daily', '31'));
assert.equal(JSON.parse(tick('dump', email3).split('\n').pop()).applicant, null);

step('Connect Givebutter: the webhook is created through the API and checked by its signature');
console.log(tick('connect', 'http://dolibarr/custom/onboarding/public/givebutter.php'));
const email4 = `e2e-direct-${Date.now()}@example.test`;
assert.equal((await api('start', { firstname: 'Dee', lastname: 'Direct', email: email4 })).ok, true);
assert.match((await mock('/control/pay', { email: email4, amount: 50 })).delivery.status, /^200 .*applied/);
assert.equal(dumpOf(email4).applicant.payment_state, 'active');
const forged = await fetch(`${DOLI}/custom/onboarding/public/givebutter.php`, { method: 'POST', headers: { Signature: 'forged' }, body: '{"event":"transaction.succeeded","data":{"id":"x"}}' });
assert.equal(forged.status, 401);

step('legacy import');
const stamp = Date.now();
const sheet = [
  'name\tMember ID\taccess_code\temail\tdiscord\tlicense\twaiver\tagreement\tmember_type\tpayment_channel\tjoin_date\topen_item\tcomment',
  `Pat Paypal\t\tCGW-90\tLegacy-Pat-${stamp}@Example.test\t\tyes\t\t\tLegacy\tPayPal\t2020-03\t\thas key from Dan`,
  `Gil Van Butter\t\tnull\tlegacy-gil-${stamp}@example.test\tgil\t\t\t\tStandard\tGIvebutter\t2024-06\t\t`,
  `Sam Scholar\t\tCGW-90\tlegacy-sam-${stamp}@example.test\t\tyes\t\t\tScholarship\tN/A\t2026-05\trevisit\t`,
  `Ona Boarding\t\t\tlegacy-ona-${stamp}@example.test\t\t\t\t\tOnboarding\tGivebutter\t2026-10\tneeds ID card\t`,
  `Kay Keyholder\t\t07\tlegacy-kay-${stamp}@example.test\t\t\t\t\tStandard\tCheck (Yearly)\t2020-03\t\t`,
  `No Email\t\t\tnot-an-email\t\t\t\t\tLegacy\tPayPal\t2020-03\t\t`,
].join('\n');
const dry = tickIn(sheet, 'import');
console.log(dry);
assert.equal((dry.match(/would add/g) || []).length, 5);
assert.match(dry, /SKIPPED/);
assert.match(dry, /badge CGW-90 is also on Pat Paypal/);
assert.equal(dumpOf(`legacy-pat-${stamp}@example.test`).applicant, null, 'a check changes nothing');
console.log(tickIn(sheet, 'import', 'go'));
const pat = dumpOf(`legacy-pat-${stamp}@example.test`);
assert.equal(pat.member.status, 1);
assert.equal(pat.member.fields.options_member_code, 'CGW-90');
assert.equal(String(pat.member.fields.options_access_enabled), '1');
assert.equal(pat.member.fields.options_payment_channel, 'PayPal');
assert.equal(String(pat.member.fields.options_id_verified), '1');
assert.equal(pat.applicant.lastname, 'Paypal');
const gil = dumpOf(`legacy-gil-${stamp}@example.test`);
assert.equal(gil.applicant.firstname, 'Gil Van');
assert.equal(gil.member.fields.options_payment_channel, 'Givebutter');
assert.ok(!gil.member.fields.options_member_code, 'the word null is not a badge');
const kay = dumpOf(`legacy-kay-${stamp}@example.test`);
assert.ok(!kay.member.fields.options_member_code, 'an old key number is not a badge');
assert.equal(kay.member.fields.options_credential_id, 'old key 07');
assert.equal(String(kay.member.fields.options_access_enabled), '1', 'an old key still opens the door');
assert.equal(dumpOf(`legacy-sam-${stamp}@example.test`).member.fields.options_payment_channel, 'None');
assert.ok(!dumpOf(`legacy-sam-${stamp}@example.test`).member.fields.options_member_code, 'the duplicate badge was not written twice');
assert.equal(dumpOf(`legacy-ona-${stamp}@example.test`).member.status, -1, 'Onboarding rows become non-members');
assert.equal((tickIn(sheet, 'import', 'go').match(/already here/g) || []).length, 5, 'importing twice adds nobody twice');

step('imported members are not chased or lapsed by the reminder job');
const before = (await mailTo(`legacy-pat-${stamp}@example.test`)).length + (await mailTo(`legacy-gil-${stamp}@example.test`)).length;
console.log(tick('daily', '90'));
assert.equal(dumpOf(`legacy-pat-${stamp}@example.test`).member.status, 1);
assert.equal(dumpOf(`legacy-gil-${stamp}@example.test`).member.status, 1);
assert.equal((await mailTo(`legacy-pat-${stamp}@example.test`)).length + (await mailTo(`legacy-gil-${stamp}@example.test`)).length, before);

step('an imported Givebutter payer is picked up by their next payment');
assert.match((await mock('/control/pay', { email: `legacy-gil-${stamp}@example.test`, amount: 50 })).delivery.status, /applied/);
const gil2 = dumpOf(`legacy-gil-${stamp}@example.test`);
assert.equal(gil2.member.subscriptions, 1);
assert.ok(gil2.applicant.gb_plan_id, 'plan remembered for cancellation tracking');

step('email updates: a website signup becomes a tagged contact');
const contactOf = (who) => JSON.parse(tick('contact', who).split('\n').pop());
const sub1 = `e2e-news-${Date.now()}@example.test`;
assert.equal((await api('subscribe', { email: 'not an email' })).status, 400);
const s1 = await api('subscribe', { email: sub1.toUpperCase(), page: '/calendar/' });
assert.equal(s1.ok, true);
assert.equal(s1.result, 'added');
let c1 = contactOf(sub1);
assert.equal(c1.tagged, true);
assert.equal(c1.lastname, sub1, 'with no name, the address is the last name');
assert.equal(String(c1.fields.options_notify_news), '1');
assert.equal(String(c1.fields.options_notify_events), '1');
assert.match(c1.note, /\/calendar\//);
assert.equal((await api('subscribe', { email: sub1 })).result, 'already', 'signing up twice changes nothing');
assert.equal(contactOf(sub1).contacts, 1);

step('email updates: an existing contact is tagged, not duplicated');
assert.equal((await api('subscribe', { email: email2 })).result, 'tagged');
assert.equal(contactOf(email2).contacts, 1);
assert.ok(dumpOf(email2).contact_fields, 'the member keeps their contact card');

step('email updates: signing up again after unsubscribing resubscribes');
tick('unsubscribe', sub1);
assert.equal(contactOf(sub1).unsubscribed, true);
assert.equal((await api('subscribe', { email: sub1 })).result, 'tagged');
assert.equal(contactOf(sub1).unsubscribed, false);

step('email updates: a join signup with a notify box ticked is on the list, and stays after cleanup');
const sub2 = `e2e-joinnews-${Date.now()}@example.test`;
assert.equal((await api('start', { firstname: 'Nia', lastname: 'News', email: sub2, notify_news: true })).ok, true);
let c2 = contactOf(sub2);
assert.equal(c2.tagged, true);
assert.equal(String(c2.fields.options_notify_news), '1');
assert.ok(!Number(c2.fields.options_notify_events), 'only the ticked box is set');
console.log(tick('daily', '31'));
assert.equal(dumpOf(sub2).applicant, null, 'the abandoned signup is gone');
assert.equal(contactOf(sub2).tagged, true, 'but the contact stays on the list');

step('email updates: importing an old list');
const imp = `imp-${Date.now()}`;
tick('unsubscribe', `${imp}-gone@example.test`);
const oldList = [
  'Email,First Name,Last Name,Date Subscribed',
  `${imp}-a@example.test,Ada,Lovelace,2019-04-02 10:00:00`,
  `${imp}-b@example.test,,,`,
  `${imp}-gone@example.test,Old,Unsub,2018-01-01`,
  `${sub1},,,`,
  `not-an-email,,,`,
  `${imp}-A@example.test,Ada,Again,`,
].join('\n');
const checked = tickIn(oldList, 'emails');
console.log(checked);
assert.match(checked, /^2 new contacts would be added, 0 existing contacts would be added to the list, 1 were already on it, 1 skipped because they unsubscribed, 1 invalid, 1 repeated/);
assert.equal(contactOf(`${imp}-a@example.test`).id, 0, 'a check changes nothing');
console.log(tickIn(oldList, 'emails', 'go'));
const ada = contactOf(`${imp}-a@example.test`);
assert.equal(ada.tagged, true);
assert.equal(ada.firstname, 'Ada');
assert.equal(ada.lastname, 'Lovelace');
assert.match(ada.note, /2019-04-02.*sandbox import/);
assert.equal(contactOf(`${imp}-gone@example.test`).id, 0, 'an unsubscribe is never undone by an import');
assert.match(tickIn(oldList, 'emails', 'go'), /^0 new contacts were added, 0 existing contacts were added to the list, 3 were already on it/);
const longOne = `${'x'.repeat(30)}-${imp}@example.test`;
assert.match(tickIn(`email,first name,last name\n${longOne},${'F'.repeat(60)},${'L'.repeat(60)}\n`, 'emails', 'go'), /^1 new contacts were added/, 'long names are cut to fit, not refused');
assert.equal(contactOf(longOne).lastname.length, 50);

step('start over: an unpaid signup at the payment step is thrown away');
const quit = `e2e-quit-${Date.now()}@example.test`;
const q = await api('start', { firstname: 'Quinn', lastname: 'Quitter', email: quit });
for (const doc of ['waiver', 'agreement']) {
  assert.equal((await api('sign', { token: q.token, doc, name: 'Quinn Quitter', version: docs[doc].version, signature })).ok, true);
}
assert.equal((await api('id', { token: q.token, data: png().toString('base64') })).stage, 'payment');
const qd = dumpOf(quit);
assert.equal(qd.member.status, -1);
const sqlCount = (sql) => Number(execFileSync('docker', ['compose', 'exec', '-T', 'db', 'mariadb', '-N', '-udolidbuser', '-pdolidbpass', 'dolidb', '-e', sql], { encoding: 'utf8' }).trim());
assert.equal((await api('cancel', { token: q.token })).ok, true);
assert.equal(dumpOf(quit).applicant, null);
assert.equal(sqlCount(`SELECT COUNT(*) FROM llx_adherent WHERE rowid = ${qd.member.id}`), 0, 'the draft member is gone');
assert.equal(contactOf(quit).id, 0, 'the contact is gone');
assert.equal((await api('status', { token: q.token })).status, 404, 'the token no longer works');
assert.equal((await api('start', { firstname: 'Quinn', lastname: 'Again', email: quit })).token?.length, 48, 'the same email can start fresh');

step('start over: a signup that asked for updates keeps its contact on the list');
const quit2 = `e2e-quit2-${Date.now()}@example.test`;
const q2 = await api('start', { firstname: 'Rae', lastname: 'Reader', email: quit2, notify_events: true });
assert.equal((await api('cancel', { token: q2.token })).ok, true);
assert.equal(contactOf(quit2).tagged, true);

step('start over: refused once dues are paid');
const paidEmail = `e2e-paid-${Date.now()}@example.test`;
const p1 = await api('start', { firstname: 'Pia', lastname: 'Paid', email: paidEmail });
assert.match((await mock('/control/pay', { email: paidEmail, amount: 50 })).delivery.status, /applied/);
const refused = await api('cancel', { token: p1.token });
assert.equal(refused.status, 409);
assert.equal(refused.error, 'paid');
assert.ok(dumpOf(paidEmail).applicant, 'still there');

console.log('\nALL PASSED');
