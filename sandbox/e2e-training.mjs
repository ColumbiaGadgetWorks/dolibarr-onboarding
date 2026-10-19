// End-to-end test of training bookings against the sandbox stack. Plays the
// website Worker: a tool and its trainer, people booking (new, non-member,
// member, under 18), paying on the fake Givebutter, the trainer's email
// buttons, cancelling, no-shows, the scheduled job moving through time, free
// trainings, a group class, the masked lookup and the waiver versions.
//
//   node e2e-training.mjs   (after e2e.mjs, same stack)
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
const show = (ref) => JSON.parse(bk('show', ref));
const sql = (q) => execFileSync('docker', ['compose', 'exec', '-T', 'db', 'mariadb', '-udolidbuser', '-pdolidbpass', 'dolidb', '-N', '-e', q], { encoding: 'utf8' }).trim();
const mock = async (path, body = {}) => (await post(`${MOCK}${path}`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(body) })).json();
const pay = (email, amount, fee = 0) => mock('/control/pay', { email, amount, fee, frequency: 'once', campaign: 'TRAINING', first_name: 'Pay', last_name: 'Er' });
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
// The links in emails point at Dolibarr's own address; reach it from here.
const local = (link) => link.replace(/^https?:\/\/[^/]+/, DOLI);
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
const r2 = (n) => Math.round(n * 100) / 100;
const step = (name) => console.log(`\n== ${name}`);
const minutesUntil = (iso) => Math.round((Date.parse(iso) - Date.now()) / 60000);
const docs = async () => (await api('docs')).docs;
async function signAll(token, needs, extra = {}) {
  const d = await docs();
  for (const doc of needs) {
    const r = await api('sign', { token, doc, name: 'Signer Person', version: d[doc].version, signature: png(48), ip: '203.0.113.7', birthdate: '1990-05-01', ...extra });
    assert.equal(r.ok, true, JSON.stringify(r));
  }
}
// Sign up and sign, as the booking page would: the signing token comes by email.
async function ready(email, first = 'Tess', last = 'Trainee') {
  const t = await api('trainee', { email });
  if (t.kind === 'new') assert.equal((await api('traineenew', { email, firstname: first, lastname: last, discord: '', ip: '203.0.113.7' })).ok, true);
  const p = await api('trainee', { email });
  if (p.needs.length) {
    assert.equal((await api('signlink', { email })).sent, true);
    const token = ((await lastText(email, 'Sign to book')).match(/#s=([a-f0-9]{48})/) || [])[1];
    assert.ok(token, 'signing link in the email');
    await signAll(token, p.needs);
  }
  return api('trainee', { email });
}

const tool = `e2e-printer-${ts}`;
const toolName = `E2E printer ${ts}`;
const trainerEmail = `adam-${ts}@example.test`;
const secretary = `sec-${ts}@example.test`;
const treasurer = `treas-${ts}@example.test`;

step('setup: a trainer, a secretary, a treasurer, a tool');
const adam = Number(tick('purchase', 'member', trainerEmail, `CGW-T${ts % 1000}`, 'Adam', 'Trainer'));
tick('purchase', 'member', secretary, `CGW-S${ts % 1000}`, 'Sec', 'Retary');
tick('purchase', 'member', treasurer, `CGW-R${ts % 1000}`, 'Tre', 'Asurer');
tick('purchase', 'roletags');
tick('purchase', 'tag', secretary, 'secretary');
tick('purchase', 'tag', treasurer, 'treasurer');
assert.equal(JSON.parse(bk('tool', JSON.stringify({ ref: tool, label: toolName, zone: 'Digital Fab', fee: 10, member_discount: 50, seats: 2, minutes: 60, wiki_url: 'https://wiki.example.test/printer', bookable: 1, active: 1 }))).ok, true);
// A weekly time four days from now, so it is past the 48-hour cutoff.
const weekday = ((new Date(Date.now() + 4 * 86400e3).getUTCDay() + 6) % 7) + 1;
assert.equal(JSON.parse(bk('slot', tool, String(adam), String(weekday), '17:00')).ok, true);
assert.equal(sql(`SELECT COUNT(*) FROM llx_categorie_member cm JOIN llx_categorie c ON c.rowid = cm.fk_categorie WHERE c.label = 'Trainer: ${toolName}' AND cm.fk_member = ${adam}`), '1', 'trainer tag');

step('catalog: the tool, its prices and Adam\'s weekly time; nothing private');
const cat = (await api('catalog', { have: 0 })).catalog;
const entry = cat.tools.find((t) => t.id === tool);
assert.equal(entry.fee, 10);
assert.equal(entry.member_fee, 5);
assert.equal(entry.bookable, true);
assert.equal(entry.slots[0].trainer, 'Adam');
assert.ok(!JSON.stringify(cat).includes(trainerEmail), 'no emails in the catalog');

step('slots: sessions from 48 hours ahead');
const slots = (await api('slots', { tool })).slots;
assert.ok(slots.length >= 5, `weekly sessions for six weeks: ${slots.length}`);
assert.ok(minutesUntil(slots[0].start) > 48 * 60);
const [s1, s2, s3, s4, s5] = slots;

step('a new person becomes a non-member and must sign the waiver first');
const nina = `nina-${ts}@example.test`;
assert.equal((await api('trainee', { email: nina })).kind, 'new');
assert.equal((await api('book', { email: nina, tool, slot: s1.slot, start: s1.start })).status, 400, 'nobody new can book');
assert.equal((await api('traineenew', { email: nina, firstname: 'Nina', lastname: 'Newcomer', ip: '203.0.113.7' })).kind, 'nonmember');
const np = JSON.parse(bk('person', nina));
assert.ok(np.member > 0 && np.contact > 0, 'contact and draft member card');
assert.equal(Number(np.applicant.training_only), 1);
assert.deepEqual((await api('trainee', { email: nina })).needs, ['waiver'], 'non-members sign the waiver only');
const refused = await api('book', { email: nina, tool, slot: s1.slot, start: s1.start });
assert.equal(refused.error, 'paperwork');

step('signing: the link comes by email; a date of birth is needed; an adult signs for themselves');
assert.equal((await api('signlink', { email: nina })).sent, true);
const token = ((await lastText(nina, 'Sign to book')).match(/#s=([a-f0-9]{48})/) || [])[1];
const d0 = await docs();
assert.equal((await api('sign', { token, doc: 'waiver', name: 'Nina Newcomer', version: d0.waiver.version, signature: png(48) })).error, 'birthdate');
const signed = await api('sign', { token, doc: 'waiver', name: 'Nina Newcomer', version: d0.waiver.version, signature: png(48), birthdate: '1995-02-03' });
assert.equal(signed.ok, true);
assert.deepEqual(signed.needs, []);
assert.equal(sql(`SELECT waiver_version FROM llx_onboarding_applicant WHERE email = '${nina}'`), d0.waiver.version, 'the version signed is recorded');

step('booking holds the seat at the full price');
const b1 = await api('book', { email: nina, tool, slot: s1.slot, start: s1.start, ip: '203.0.113.7' });
assert.equal(b1.ok, true, JSON.stringify(b1));
assert.equal(b1.amount, 10);
assert.equal(b1.trainer, 'Adam');
assert.equal((await api('book', { email: nina, tool, slot: s1.slot, start: s1.start })).error, 'already', 'one open booking per tool');
assert.equal((await api('bookingstatus', { ref: b1.ref, key: b1.key })).status, 'held');
assert.equal((await api('bookingstatus', { ref: b1.ref, key: 'nope' })).status, 404);

step('payment: matched by email and amount; split later on what arrives after Givebutter\'s fee');
const paid1 = await pay(nina.toUpperCase(), 10, 0.6);
assert.match(paid1.delivery.status, new RegExp(`booking ${b1.ref} paid`), paid1.delivery.status);
assert.equal((await api('bookingstatus', { ref: b1.ref, key: b1.key })).paid, true);
assert.equal(Number(show(b1.ref).amount_net), 9.4);
assert.ok(await heard(nina, `You are booked: ${toolName}`));
assert.ok(await heard(trainerEmail, `Training booked: ${toolName}`));
assert.match((await mock('/control/resend')).status, /duplicate/, 'the same webhook twice changes nothing');

step('trainer confirms from the email; opening the link alone changes nothing');
const confirmLink = ((await lastText(trainerEmail, 'Training booked')).match(/https?:\/\/\S+public\/booking\.php\?\S+a=confirm/) || [])[0];
assert.ok(confirmLink);
assert.equal((await fetch(local(confirmLink).replace(/k=\w+/, 'k=0000'))).status, 403, 'a changed link is refused');
assert.match(await page(confirmLink), /Will you be there\?/);
assert.equal(show(b1.ref).status, 'paid');
assert.match(await press(confirmLink, { do: 'confirm' }), /session is confirmed/);
assert.equal(show(b1.ref).status, 'confirmed');

step('a paid-up member pays half; joining a confirmed session needs no new confirmation');
const mo = `mo-${ts}@example.test`;
tick('purchase', 'member', mo, `CGW-M${ts % 1000}`, 'Mo', 'Member');
const mp = await api('trainee', { email: mo });
assert.equal(mp.kind, 'member');
assert.deepEqual(mp.needs.sort(), ['agreement', 'waiver'], 'members sign the waiver and the agreement');
await ready(mo);
const b2 = await api('book', { email: mo, tool, slot: s1.slot, start: s1.start });
assert.equal(b2.amount, 5);
assert.match((await pay(mo, 5)).delivery.status, /paid/);
assert.equal(show(b2.ref).status, 'confirmed');
assert.ok(await heard(trainerEmail, `Another booking: ${toolName}`));

step('a full session is not offered');
assert.ok(!(await api('slots', { tool })).slots.some((s) => s.start === s1.start), 'two seats, two bookings');
const full = `full-${ts}@example.test`;
await ready(full, 'Fu', 'Ll');
assert.equal((await api('book', { email: full, tool, slot: s1.slot, start: s1.start })).error, 'full');

step('trainee cancels for a refund (more than 2 hours before): the seat frees, the treasurer is told');
const cancelLink = bk('link', b2.ref, 'trainee') + '&a=refund';
assert.match(await page(cancelLink), /Cancel and refund me/);
assert.match(await press(cancelLink, { do: 'refund', comment: 'Plans changed' }), /refund you/);
assert.equal(show(b2.ref).status, 'refund_requested');
assert.ok(await heard(treasurer, 'Refund to make'));
assert.ok(await heard(trainerEmail, 'A seat is free'));
assert.ok((await api('slots', { tool })).slots.some((s) => s.start === s1.start), 'the seat is free again');

step('a held seat nobody pays for is let go; a late payment still finds it if the seat is free');
const b3 = await api('book', { email: full, tool, slot: s1.slot, start: s1.start });
assert.equal(b3.ok, true);
tick('bookings', '31');
assert.equal(show(b3.ref).status, 'expired');
assert.match((await pay(full, 10)).delivery.status, /paid/);
assert.equal(show(b3.ref).status, 'confirmed', 'the session was confirmed already');

step('day of the session, then the questions to the trainer at the end, an hour on, a day on');
const end1 = minutesUntil(s1.start) + 60;
tick('bookings', String(end1 + 1));
assert.ok(await heard(trainerEmail, `How did the ${toolName} training go?`));
const afterCount = async () => (await subjects(trainerEmail)).filter((s) => s.includes('How did the')).length;
const n0 = await afterCount();
tick('bookings', String(end1 + 5));
assert.equal(await afterCount(), n0, 'not again five minutes later');
tick('bookings', String(end1 + 61));
assert.equal(await afterCount(), n0 + 2, 'one hour on, for each trainee');
tick('bookings', String(end1 + 24 * 60 + 1));
assert.equal(await afterCount(), n0 + 4, 'a day on');

step('the trainer says Nina was trained: recorded, tagged, split 50/50 after the fee, emailed');
const trainedLink = bk('link', b1.ref, 'trainer') + '&a=trained';
assert.doesNotMatch(await page(trainedLink), /showed up/, 'the link offers the answers only once the session has started');
assert.match(await press(trainedLink, { do: 'trained' }), /has not happened yet/);
const before = Number(tick('training', 'balance', 'trainer', String(adam)));
const zoneBefore = Number(tick('training', 'balance', 'zone', 'Digital Fab'));
// The page refuses answers before the session; the test answers as if it were over.
assert.equal(JSON.parse(bk('answer', b1.ref, 'trained', String(end1 + 10))).ok, true);
assert.equal(show(b1.ref).status, 'trained');
assert.equal(Number(tick('training', 'balance', 'trainer', String(adam))), r2(before + 4.7));
assert.equal(Number(tick('training', 'balance', 'zone', 'Digital Fab')), r2(zoneBefore + 4.7));
assert.ok(await heard(nina, `You are trained on the ${toolName}`));
assert.equal(sql(`SELECT COUNT(*) FROM llx_categorie_member cm JOIN llx_categorie c ON c.rowid = cm.fk_categorie WHERE c.label = 'Trained: ${toolName}' AND cm.fk_member = ${np.member}`), '1');
assert.match(sql(`SELECT trained_on FROM llx_adherent_extrafields WHERE fk_object = ${np.member}`), new RegExp(toolName));
assert.equal(sql(`SELECT trainings_given FROM llx_adherent_extrafields WHERE fk_object = ${adam}`), '1');
assert.equal(JSON.parse(bk('answer', b1.ref, 'trained', String(end1 + 11))).ok, false, 'answered once');

step('lookup: masked email and name, then the tools; the same answer for nobody');
const look = await api('lookup', { email: nina });
assert.equal(look.found, true);
assert.equal(look.email, `n******${ts % 10}@example.test`);
assert.equal(look.name, 'N*** N***');
assert.deepEqual(look.trainings.map((t) => t.tool), [toolName]);
const nobody = await api('lookup', { email: `nobody-${ts}@example.test` });
assert.deepEqual([nobody.found, nobody.email, nobody.trainings], [false, '', []]);
assert.deepEqual((await api('lookup', { email: mo })).trainings, [], 'booked but not trained: nothing');

step('trainer still silent two days after the end: the trainee is asked, and says the trainer did not show');
tick('bookings', String(end1 + 49 * 60));
assert.ok(await heard(full, 'Did your'));
assert.ok(await heard(secretary, 'No answer from the trainer'));
const noTrainer = bk('link', b3.ref, 'trainee') + '&a=no_trainer';
assert.equal(JSON.parse(bk('show', b3.ref)).status, 'confirmed');
// Only after the session can it be said; the page checks real time, so post as the follow-up email would later.
assert.match(await press(noTrainer, { do: 'no_trainer', comment: 'Nobody was there' }), /secretary has your message/);
assert.equal(show(b3.ref).status, 'other');
assert.ok(await heard(secretary, 'Trainer did not show up'));

step('no-show: one free new date, then the fee is kept');
const nora = `nora-${ts}@example.test`;
await ready(nora, 'Nora', 'Noshow');
const b4 = await api('book', { email: nora, tool, slot: s2.slot, start: s2.start });
await pay(nora, 10);
await press(bk('link', b4.ref, 'trainer') + '&a=confirm', { do: 'confirm' });
const end2 = minutesUntil(s2.start) + 60;
assert.equal(JSON.parse(bk('answer', b4.ref, 'noshow', String(end2 + 5))).ok, true);
assert.equal(show(b4.ref).status, 'needs_new_date');
assert.ok(await heard(nora, 'We missed you at your'));
const moveLink = bk('link', b4.ref, 'trainee') + '&a=move';
const pickPage = await page(moveLink);
assert.match(pickPage, /Pick a new date/);
assert.doesNotMatch(pickPage, /Cancel and refund me/, 'no refund after a no-show');
assert.match(await press(moveLink, { do: 'pick', slot: String(s3.slot), start: String(Date.parse(s3.start) / 1000) }), /Moved/);
const moved = show(b4.ref);
assert.equal(moved.status, 'paid');
assert.equal(Number(moved.rebooks), 1);
await press(bk('link', b4.ref, 'trainer') + '&a=confirm', { do: 'confirm' });
const kept = Number(tick('training', 'balance', 'trainer', String(adam)));
assert.equal(JSON.parse(bk('answer', b4.ref, 'noshow', String(minutesUntil(s3.start) + 70))).ok, true);
assert.equal(show(b4.ref).status, 'no_show');
assert.equal(Number(tick('training', 'balance', 'trainer', String(adam))), r2(kept + 5), 'second no-show: fee split as usual');
assert.ok(await heard(nora, 'We missed you again'));
assert.deepEqual((await api('lookup', { email: nora })).trainings, [], 'not trained');

step('trainer can\'t make it: the trainee chooses reschedule, refund or other');
const cara = `cara-${ts}@example.test`;
await ready(cara, 'Cara', 'Cant');
const b5 = await api('book', { email: cara, tool, slot: s4.slot, start: s4.start });
await pay(cara, 10);
assert.match(await press(bk('link', b5.ref, 'trainer') + '&a=cant', { do: 'cant' }), /emailed to reschedule/);
assert.equal(show(b5.ref).status, 'needs_new_date');
const lost = await lastText(cara, 'is cancelled');
assert.match(lost, /Reschedule/);
assert.match(lost, /Refund/);
assert.match(lost, /Other/);
assert.match(await press(bk('link', b5.ref, 'trainee') + '&a=other', { do: 'other', comment: 'Can we do a weekend?' }), /secretary has your message/);
assert.ok(await heard(secretary, 'Trainee needs something'));

step('trainer never confirms: cancelled 24 hours before, the secretary is told');
const ula = `ula-${ts}@example.test`;
await ready(ula, 'Ula', 'Unconfirmed');
const b6 = await api('book', { email: ula, tool, slot: s5.slot, start: s5.start });
await pay(ula, 10);
tick('bookings', String(24 * 60 + 5));
assert.ok(await heard(trainerEmail, `Reminder: Training booked: ${toolName}`), 'reminded a day after paying');
tick('bookings', String(minutesUntil(s5.start) - 23 * 60));
assert.equal(show(b6.ref).status, 'needs_new_date');
assert.ok(await heard(secretary, 'Trainer did not confirm'));
assert.ok(await heard(ula, 'is cancelled'));

step('Open Hack Night cancelled on the calendar: that day is closed and its bookings lose the date');
const later = (await api('slots', { tool })).slots.at(-1);
const vic = `vic-${ts}@example.test`;
await ready(vic, 'Vic', 'Calendar');
const b7 = await api('book', { email: vic, tool, slot: later.slot, start: later.start });
await pay(vic, 10);
const day = new Date(Date.parse(later.start) - 6 * 3600e3).toISOString().slice(0, 10);
assert.equal(JSON.parse(bk('calendar', JSON.stringify([{ day, reason: 'Open Hack Night cancelled' }]))).moved, 1);
assert.equal(show(b7.ref).status, 'needs_new_date');
assert.ok(!(await api('slots', { tool })).slots.some((s) => s.start === later.start), 'not offered');
assert.equal(JSON.parse(bk('calendar', '[]')).removed, 1, 'gone from the calendar: reopened');

step('a payment with no booking waits for the secretary');
const stray = await pay(`stray-${ts}@example.test`, 10);
assert.match(stray.delivery.status, /paid without a booking/);
assert.ok(await heard(secretary, 'Training payment without a booking'));

step('free training by a board member; someone new is made a non-member');
const fran = `fran-${ts}@example.test`;
const toolId = sql(`SELECT rowid FROM llx_onboarding_tool WHERE ref = '${tool}'`);
assert.equal(JSON.parse(bk('free', JSON.stringify({ email: fran, firstname: 'Fran', lastname: 'Free', tool: Number(toolId), trainer: adam, reason: 'Board approved' }))).ok, true);
assert.deepEqual((await api('lookup', { email: fran })).trainings.map((t) => t.tool), [toolName]);
assert.equal(JSON.parse(bk('free', JSON.stringify({ email: fran, tool: Number(toolId), reason: 'again' }))).ok, false, 'only once');

step('group class: code, new people sign in, a minor needs a guardian, the trainer confirms the list');
const nowLocal = new Date(Date.now() - 6 * 3600e3);
const klass = JSON.parse(bk('class', JSON.stringify({ tool: Number(toolId), trainer: adam, day: nowLocal.toISOString().slice(0, 10), time: nowLocal.toISOString().slice(11, 16), minutes: 60, seats: 3 })));
assert.equal(klass.ok, true, JSON.stringify(klass));
const info = await api('classinfo', { code: klass.code.toLowerCase() });
assert.equal(info.ok, true, JSON.stringify(info));
assert.equal(info.seats_left, 3);
assert.equal((await api('classinfo', { code: 'NOPE-0000' })).error, 'closed');
const kid = `kid-${ts}@example.test`;
const j1 = await api('classjoin', { code: klass.code, email: kid, firstname: 'Kit', lastname: 'Kid' });
assert.equal(j1.done, false);
assert.deepEqual(j1.needs, ['waiver']);
const dk = await docs();
const young = new Date(Date.now() - 15 * 365.25 * 86400e3).toISOString().slice(0, 10);
const tiny = new Date(Date.now() - 8 * 365.25 * 86400e3).toISOString().slice(0, 10);
assert.equal((await api('sign', { token: j1.token, doc: 'waiver', name: 'Kit Kid', version: dk.waiver.version, signature: png(48), birthdate: tiny })).error, 'too_young');
assert.equal((await api('sign', { token: j1.token, doc: 'waiver', name: 'Kit Kid', version: dk.waiver.version, signature: png(48), birthdate: young })).error, 'guardian');
assert.equal((await api('sign', { token: j1.token, doc: 'waiver', name: 'ignored', version: dk.waiver.version, signature: png(48), birthdate: young, guardian_name: 'Pat Parent', guardian_relation: 'Parent' })).ok, true);
assert.equal(sql(`SELECT waiver_name FROM llx_onboarding_applicant WHERE email = '${kid}'`), 'Pat Parent', 'the guardian signed');
assert.equal((await api('classjoin', { code: klass.code, email: kid })).done, true);
assert.equal((await api('classjoin', { code: klass.code, email: kid })).done, true, 'signing in twice is harmless');
assert.equal((await api('classjoin', { code: klass.code, email: nina })).done, true, 'already known and signed');
assert.equal((await api('classinfo', { code: klass.code })).seats_left, 1);
tick('bookings', '61');
assert.ok(await heard(trainerEmail, `Group class list to confirm: ${toolName}`));
assert.match(bk('classfinish', String(klass.id)), /^2 marked trained/);
assert.deepEqual((await api('lookup', { email: kid })).trainings.map((t) => t.tool), [toolName]);
assert.equal(sql(`SELECT source FROM llx_onboarding_training WHERE email = '${kid}'`), 'class');

step('waiver versions: a new wording in GitHub becomes the version people sign; the old one is kept');
const oldVersion = (await docs()).waiver.version;
await mock('/control/doc', { doc: 'waiver', text: `---\ntitle: Liability waiver\n---\nSandbox waiver, revised ${ts}.` });
assert.match(tick('docs'), /waiver: new version/);
const nd = await docs();
assert.notEqual(nd.waiver.version, oldVersion);
assert.equal(nd.waiver.text, `Sandbox waiver, revised ${ts}.`);
assert.equal(sql(`SELECT COUNT(*) FROM llx_onboarding_doc_version WHERE version = '${oldVersion}'`), '1', 'the old wording is kept');
assert.deepEqual((await api('trainee', { email: nina })).needs, [], 'someone who signed the old one can still book');

step('alerts come from Dolibarr: the website silent for 30 minutes, and its outage report');
const alerts = 'alerts@example.test';
const count = async (text) => (await subjects(alerts)).filter((x) => x.includes(text)).length;
// The steps above ran the job days ahead; start this one clean.
tick('purchase', 'const', 'ONBOARDING_ALERT_WEBSITE_CHECKIN', '0');
await api('catalog', {});
const quiet0 = await count('has not checked in');
tick('bookings', '20');
assert.equal(await count('has not checked in'), quiet0, 'not after 20 minutes');
tick('bookings', '40');
assert.equal(await count('The website has not checked in with Dolibarr'), quiet0 + 1);
const fixed0 = await count('checks in with Dolibarr again');
await api('catalog', {});
tick('bookings', '1');
assert.equal(await count('checks in with Dolibarr again'), fixed0 + 1);
assert.equal((await api('websitereport', { down_since: new Date(Date.now() - 25 * 60e3).toISOString(), back: new Date().toISOString(), minutes: 25, waiting: 2 })).reported, true);
assert.ok(await heard(alerts, 'could not reach Dolibarr for 25 minutes'));
assert.equal((await api('websitereport', { minutes: 3 })).reported, false, 'short blips are not reported');

console.log('\nALL PASSED');
