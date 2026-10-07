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
assert.equal(fresh.contact_fields.options_onb_discord, 'ada#1');
assert.equal(String(fresh.contact_fields.options_onb_notify_events), '1');

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
assert.equal(String(d.contact_fields.options_onb_waiver_signed), '1');
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
assert.equal(d.member.fields.options_onb_payment_state, 'active');
assert.equal(String(d.member.fields.options_onb_agreement_signed), '1');

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
assert.equal(d.member.fields.options_onb_payment_state, 'lapsed');
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
  `No Email\t\t\tnot-an-email\t\t\t\t\tLegacy\tPayPal\t2020-03\t\t`,
].join('\n');
const dry = tickIn(sheet, 'import');
console.log(dry);
assert.equal((dry.match(/would add/g) || []).length, 4);
assert.match(dry, /SKIPPED/);
assert.match(dry, /badge CGW-90 is also on Pat Paypal/);
assert.equal(dumpOf(`legacy-pat-${stamp}@example.test`).applicant, null, 'a check changes nothing');
console.log(tickIn(sheet, 'import', 'go'));
const pat = dumpOf(`legacy-pat-${stamp}@example.test`);
assert.equal(pat.member.status, 1);
assert.equal(pat.member.fields.options_onb_badge_id, 'CGW-90');
assert.equal(String(pat.member.fields.options_onb_badge_access), '1');
assert.equal(pat.member.fields.options_onb_payment_channel, 'paypal');
assert.equal(String(pat.member.fields.options_onb_id_uploaded), '1');
assert.equal(pat.applicant.lastname, 'Paypal');
const gil = dumpOf(`legacy-gil-${stamp}@example.test`);
assert.equal(gil.applicant.firstname, 'Gil Van');
assert.equal(gil.member.fields.options_onb_payment_channel, 'givebutter');
assert.ok(!gil.member.fields.options_onb_badge_id, 'the word null is not a badge');
assert.equal(dumpOf(`legacy-ona-${stamp}@example.test`).member.status, -1, 'Onboarding rows become non-members');
assert.equal((tickIn(sheet, 'import', 'go').match(/already here/g) || []).length, 4, 'importing twice adds nobody twice');

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

console.log('\nALL PASSED');
