// On-the-spot trainings: a trainer asks for their link on the booking page,
// sends a request to the person in front of them, and the trainee says yes
// (signs and pays, at the member price if they are one) or no (the secretary
// and the trainer are told). Requests expire after 4 hours; a trainer needs a
// time on the tool and to be trained on it; nobody already trained can be sent
// one; at most three wait at once.
//
//   node e2e-spot.mjs   (after e2e-training.mjs, same stack)
import { execFileSync } from 'node:child_process';
import { deflateSync, crc32 } from 'node:zlib';
import assert from 'node:assert/strict';

const DOLI = process.env.DOLI_URL || 'http://localhost:8080';
const MOCK = process.env.MOCK_URL || 'http://localhost:8090';
const MAIL = process.env.MAIL_URL || 'http://localhost:8025';
const KEY = process.env.ONBOARDING_API_KEY || 'sandbox-key-sandbox-key-sandbox-key';
const ts = Date.now();

async function post(url, init) {
  try {
    return await fetch(url, init);
  } catch (e) {
    if (e.cause?.code !== 'UND_ERR_SOCKET') throw e;
    return fetch(url, init);
  }
}
async function api(action, body = {}) {
  const r = await post(`${DOLI}/custom/onboarding/public/api.php?action=${action}`, { method: 'POST', headers: { 'content-type': 'application/json', 'X-Onboarding-Key': KEY }, body: JSON.stringify(body) });
  const text = await r.text();
  try { return { status: r.status, ...JSON.parse(text) }; } catch { throw new Error(`${action}: HTTP ${r.status}: ${text.slice(0, 600)}`); }
}
const tick = (...args) => execFileSync('docker', ['compose', 'exec', '-T', '-u', 'www-data', 'dolibarr', 'php', '/var/www/html/custom/onboarding/sandbox/tick.php', ...args], { encoding: 'utf8' }).trim().split('\n').pop();
const bk = (...args) => tick('booking', ...args);
const sql = (q) => execFileSync('docker', ['compose', 'exec', '-T', 'db', 'mariadb', '-udolidbuser', '-pdolidbpass', 'dolidb', '-N', '-e', q], { encoding: 'utf8' }).trim();
const pay = async (email, amount, fee = 0) => (await post(`${MOCK}/control/pay`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ email, amount, fee, frequency: 'once', campaign: 'TRAINING', first_name: 'Pay', last_name: 'Er' }) })).json();
async function mails(addr) {
  const r = await (await fetch(`${MAIL}/api/v1/search?query=${encodeURIComponent(`to:${addr}`)}&limit=200`)).json();
  return r.messages || [];
}
const subjects = async (addr) => (await mails(addr)).map((m) => m.Subject);
const heard = async (addr, text) => (await subjects(addr)).some((s) => s.includes(text));
async function lastText(addr, includes) {
  const m = (await mails(addr)).find((x) => x.Subject.includes(includes));
  assert.ok(m, `no email to ${addr} with "${includes}": ${JSON.stringify(await subjects(addr))}`);
  return (await (await fetch(`${MAIL}/api/v1/message/${m.ID}`)).json()).Text;
}
const linkAfter = (text, label) => {
  // Emails wrap long lines: a space in the label matches any run of spaces and line breaks.
  const i = text.search(new RegExp(label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/ /g, '\\s+')));
  assert.ok(i >= 0, `"${label}" in the email`);
  return text.slice(i).match(/https?:\/\/\S+/)[0];
};
const local = (link) => link.replace(/^https?:\/\/[^/]+\/training\/link\//, `${DOLI}/custom/onboarding/public/booking.php`).replace(/^https?:\/\/[^/]+/, DOLI);
const page = async (link) => (await fetch(local(link))).text();
async function press(link, fields) {
  const [url, query] = local(link).split('?');
  const body = new URLSearchParams(query);
  body.delete('a');
  for (const [k, v] of Object.entries(fields)) body.set(k, v);
  return (await fetch(url, { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body })).text();
}
function png(size = 32) {
  const chunk = (type, data) => {
    const body = Buffer.concat([Buffer.from(type), data]);
    const out = Buffer.alloc(body.length + 8);
    out.writeUInt32BE(data.length, 0); body.copy(out, 4); out.writeUInt32BE(crc32(body), body.length + 4);
    return out;
  };
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(size, 0); ihdr.writeUInt32BE(size, 4); ihdr[8] = 8; ihdr[9] = 2;
  const raw = Buffer.alloc((size * 3 + 1) * size);
  for (let i = 0; i < raw.length; i++) raw[i] = i % (size * 3 + 1) === 0 ? 0 : Math.floor(Math.random() * 256);
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]).toString('base64');
}
async function signAll(token, needs) {
  const d = (await api('docs')).docs;
  for (const doc of needs) {
    const r = await api('sign', { token, doc, name: 'Signer Person', version: d[doc].version, signature: png(48), ip: '203.0.113.7', birthdate: '1990-05-01' });
    assert.equal(r.ok, true, JSON.stringify(r));
  }
}
const status = (ref) => sql(`SELECT status FROM llx_onboarding_booking WHERE ref = '${ref}'`);
const step = (name) => console.log(`\n== ${name}`);

const tool = `e2e-spot-${ts}`;
const toolName = `E2E spot lathe ${ts}`;
const tina = `tina-${ts}@example.test`;
const secretary = `spotsec-${ts}@example.test`;

step('setup: a tool, Tina with a weekly time on it, a secretary');
const tinaId = Number(tick('purchase', 'member', tina, `CGW-P${ts % 1000}`, 'Tina', 'Trainer'));
tick('purchase', 'member', secretary, `CGW-Q${ts % 1000}`, 'Sue', 'Secretary');
tick('purchase', 'roletags');
tick('purchase', 'tag', secretary, 'secretary');
assert.equal(JSON.parse(bk('tool', JSON.stringify({ ref: tool, label: toolName, zone: 'Metal', fee: 20, member_discount: 50, seats: 1, minutes: 30, wiki_url: 'https://wiki.example.test/lathe', bookable: 1, active: 1 }))).ok, true);
const toolId = Number(sql(`SELECT rowid FROM llx_onboarding_tool WHERE ref = '${tool}'`));
const weekday = ((new Date(Date.now() + 4 * 86400e3).getUTCDay() + 6) % 7) + 1;
assert.equal(JSON.parse(bk('slot', tool, String(tinaId), String(weekday), '18:00')).ok, true);

step('a trainer not yet trained on the tool gets no link; the answer is the same for anyone');
assert.equal((await api('spotstart', { email: tina })).ok, true);
assert.equal((await api('spotstart', { email: `nobody-${ts}@example.test` })).ok, true);
assert.equal((await api('spotstart', { email: 'not an email' })).status, 400);
assert.equal(await heard(tina, 'Train someone now'), false, 'not trained on it herself: no link');

step('trained, Tina gets her link (once a minute at most)');
assert.equal(JSON.parse(bk('free', JSON.stringify({ email: tina, tool: toolId, reason: 'Trainer sign-off' }))).ok, true);
await api('spotstart', { email: tina });
await api('spotstart', { email: tina });
assert.equal((await subjects(tina)).filter((s) => s.includes('Train someone now')).length, 1);
const tinaLink = linkAfter(await lastText(tina, 'Train someone now'), 'works until');
const form = await page(tinaLink);
assert.match(form, /Train someone now/);
assert.ok(form.includes(toolName), 'her tool is offered');
assert.match(form, /expires in 4 hours/);
assert.match(await page(tinaLink.replace(/k=[0-9a-f]+/, 'k=00')), /Link not valid/);
const ask = (email) => press(tinaLink, { do: 'spot', tool: String(toolId), email });

step('refused: herself, and someone already trained on the tool');
assert.match(await ask(tina), /cannot train yourself/);
const olga = `olga-${ts}@example.test`;
assert.equal(JSON.parse(bk('free', JSON.stringify({ email: olga, firstname: 'Olga', lastname: 'Old', tool: toolId, reason: 'Already trained' }))).ok, true);
assert.match(await ask(olga), /already marked as trained/);

step('a request to someone new: they are asked by email, the request expires in 4 hours');
const nell = `nell-${ts}@example.test`;
assert.match(await ask(nell), /Sent\. The trainee has an email/);
const nellText = await lastText(nell, 'wants to train you on the');
assert.match(nellText, /Tina Trainer says you are with them/);
assert.match(nellText, /\$10\.00 for members,\s+\$20\.00 for everyone else/);
assert.match(nellText, /expires at [^,]+,\s+4\s+hours\s+after\s+it\s+was\s+sent/);
const nellRef = nellText.match(/\((TR-\d+)\)/)?.[1] || sql(`SELECT ref FROM llx_onboarding_booking WHERE email = '${nell}'`);
assert.equal(status(nellRef), 'requested');
assert.match(await press(tinaLink, { do: 'spot', tool: String(toolId), email: nell }), /already has a booking or a request open/);

step('"No": cancelled, the secretary and the trainer are told');
const dan = `dan-${ts}@example.test`;
await ask(dan);
const danNo = linkAfter(await lastText(dan, 'wants to train you on the'), "No, I didn't ask");
assert.match(await page(danNo), /Did you ask for this training\?/);
assert.match(await press(danNo, { do: 'spot_no' }), /cancelled and nothing was charged/);
assert.equal(status(sql(`SELECT ref FROM llx_onboarding_booking WHERE email = '${dan}'`)), 'cancelled');
assert.ok(await heard(secretary, 'On-the-spot training declined'));
assert.ok(await heard(tina, 'Training request declined'));
assert.match(await press(danNo, { do: 'spot_yes' }), /no longer open|Nothing to change/);

step('at most three requests waiting at once');
const p1 = `pat-${ts}@example.test`;
const mia = `mia-${ts}@example.test`;
tick('purchase', 'member', mia, `CGW-M${ts % 1000}`, 'Mia', 'Member');
await ask(p1);
await ask(mia);
assert.match(await ask(`quinn-${ts}@example.test`), /already have 3 requests waiting/);

step('"Yes" from someone new: their name, the waiver, then the non-member price');
const nellYes = linkAfter(nellText, "Yes, that's me");
const yes = await press(nellYes, { do: 'spot_yes' });
const m = yes.match(/#spot=([^".]+)\.([a-f0-9]{48})/);
assert.ok(m, 'continue link to the booking page');
const [, ref, key] = m;
assert.equal(decodeURIComponent(ref), nellRef);
let info = await api('spotinfo', { ref: nellRef, key });
assert.equal(info.kind, 'new');
assert.equal(info.status, 'held');
assert.match(info.expires_label, /\d:\d\d [AP]M/);
assert.equal((await api('spotinfo', { ref: nellRef, key: 'f'.repeat(48) })).status, 404);
info = await api('spotnew', { ref: nellRef, key, firstname: 'Nell', lastname: 'Newcomer', ip: '203.0.113.9' });
assert.equal(info.kind, 'nonmember');
assert.deepEqual(info.needs, ['waiver']);
assert.equal(info.amount, 20);
await signAll(info.sign, info.needs);
info = await api('spotinfo', { ref: nellRef, key });
assert.deepEqual(info.needs, []);
assert.equal(info.firstname, 'Nell');

step('paid: the trainee hears so, the trainer gets the buttons; trained');
await pay(nell, 20, 0.9);
let paid = false;
for (let i = 0; i < 10 && !paid; i++) {
  paid = (await api('bookingstatus', { ref: nellRef, key })).paid;
  if (!paid) await new Promise((r) => setTimeout(r, 2500));
}
assert.ok(paid, 'the payment matched');
assert.ok(await heard(nell, 'Paid:'));
const tinaPaid = await lastText(tina, `Paid: ${toolName} training for Nell`);
assert.match(await press(linkAfter(tinaPaid, 'was trained:'), { do: 'trained' }), /Recorded as trained/);
assert.equal(status(nellRef), 'trained');
assert.ok(await heard(nell, `You are trained on the ${toolName}`));
assert.match(await ask(nell), /already marked as trained/, 'trained now: no second request');

step('a member: the waiver and the agreement, then the member price; "did not happen" offers a date or a refund');
const miaText = await lastText(mia, 'wants to train you on the');
const miaYes = await press(linkAfter(miaText, "Yes, that's me"), { do: 'spot_yes' });
const [, miaRef, miaKey] = miaYes.match(/#spot=([^".]+)\.([a-f0-9]{48})/);
info = await api('spotinfo', { ref: decodeURIComponent(miaRef), key: miaKey });
assert.equal(info.kind, 'member');
assert.deepEqual([...info.needs].sort(), ['agreement', 'waiver']);
assert.equal(info.amount, 10);
await signAll(info.sign, info.needs);
await pay(mia, 10, 0.6);
for (let i = 0; i < 10 && !(await api('bookingstatus', { ref: decodeURIComponent(miaRef), key: miaKey })).paid; i++) await new Promise((r) => setTimeout(r, 2500));
const tinaMia = await lastText(tina, `Paid: ${toolName} training for Mia`);
assert.match(await press(linkAfter(tinaMia, 'did not happen'), { do: 'noshow' }), /Not marked as trained/);
assert.equal(status(decodeURIComponent(miaRef)), 'needs_new_date');
assert.ok(await heard(mia, 'is cancelled'), 'asked to pick a date or ask for a refund');

step('after 4 hours an unpaid request expires and the trainer is told');
const patRef = sql(`SELECT ref FROM llx_onboarding_booking WHERE email = '${p1}'`);
assert.equal(status(patRef), 'requested');
tick('bookings', '241');
assert.equal(status(patRef), 'expired');
assert.ok(await heard(tina, 'Training request expired'));
assert.match(await press(linkAfter(await lastText(p1, 'wants to train you on the'), "Yes, that's me"), { do: 'spot_yes' }), /no longer open/);

step('the Trainings page lists them');
console.log('(checked by smoke-pages.sh)');
console.log('\nON-THE-SPOT TRAININGS PASSED');
