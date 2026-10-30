// End-to-end test of purchase requests against the sandbox stack, after e2e.mjs.
// Plays the part of the website Worker: the zone budgets it shows, who may file, who
// approves (the treasurer; over $100 the president; another zone's boss when filed for
// their zone), a rejection with a reason, reimbursed and ordered, and the guessing limits.
//
//   node e2e-purchase.mjs
import { execFileSync } from 'node:child_process';
import { deflateSync, crc32 } from 'node:zlib';
import assert from 'node:assert/strict';

const DOLI = process.env.DOLI_URL || 'http://localhost:8080';
const MAIL = process.env.MAIL_URL || 'http://localhost:8025';
const KEY = process.env.ONBOARDING_API_KEY || 'sandbox-key-sandbox-key-sandbox-key';
const ts = Date.now();

async function api(action, body = {}) {
  const url = `${DOLI}/custom/onboarding/public/api.php?action=${action}`;
  const init = { method: 'POST', headers: { 'content-type': 'application/json', 'X-Onboarding-Key': KEY }, body: JSON.stringify(body) };
  let r;
  try {
    r = await fetch(url, init);
  } catch (e) {
    if (e.cause?.code !== 'UND_ERR_SOCKET') throw e;
    r = await fetch(url, init); // Apache closed an idle connection; send it again
  }
  const text = await r.text();
  let json;
  try { json = JSON.parse(text); } catch { throw new Error(`${action}: HTTP ${r.status}, not JSON: ${text.slice(0, 600)}`); }
  return { status: r.status, ...json };
}
const tick = (...args) => execFileSync('docker', ['compose', 'exec', '-T', '-u', 'www-data', 'dolibarr', 'php', '/var/www/html/custom/onboarding/sandbox/tick.php', ...args], { encoding: 'utf8' }).trim();
const sh = (...args) => tick('purchase', ...args).split('\n').pop();
const show = (id) => JSON.parse(sh('show', String(id)));

async function mailsTo(addr) {
  const r = await (await fetch(`${MAIL}/api/v1/search?query=${encodeURIComponent(`to:${addr}`)}`)).json();
  return (r.messages || []).map((m) => m.Subject);
}
const heard = async (addr, text) => (await mailsTo(addr)).some((s) => s.includes(text));
async function bodyOf(addr, subject) {
  const r = await (await fetch(`${MAIL}/api/v1/search?query=${encodeURIComponent(`to:${addr}`)}`)).json();
  const m = (r.messages || []).find((x) => x.Subject.includes(subject));
  assert.ok(m, `no mail to ${addr} about ${subject}`);
  return (await (await fetch(`${MAIL}/api/v1/message/${m.ID}`)).json()).Text;
}

function png(size = 48) {
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
const day = (offset) => new Date(Date.now() + offset * 86400e3).toISOString().slice(0, 10);

const leadEmail = `lead-${ts}@example.test`;
const pres = `pres-${ts}@example.test`;
const boss = `boss-${ts}@example.test`;
const plain = `plain-${ts}@example.test`;
const lockee = `locked-${ts}@example.test`;
const treasurer = `treasurer-${ts}@example.test`;
const dir1 = `director1-${ts}@example.test`;
const dir2 = `director2-${ts}@example.test`;
const IP = '198.51.100.7';

step('setup: members, zone leads, approvers, a bank account');
sh('member', leadEmail, 'CGW-41', 'Lena', `Lead${ts}`);
sh('member', pres, 'CGW-05', 'Pres', `Asker${ts}`);
sh('member', lockee, 'CGW-52', 'Lock', `Ee${ts}`);
sh('member', boss, 'CGW-06', 'Bo', `Boss${ts}`);
sh('member', plain, 'CGW-60', 'Pat', `Plain${ts}`);
sh('member', treasurer, 'CGW-70', 'Tess', `Treasurer${ts}`);
sh('member', dir1, 'CGW-71', 'Vic', `VicePres${ts}`);
sh('member', dir2, 'CGW-72', 'Dee', `Director${ts}`);
// Roles are tags on member cards. pres is the president: may file for any zone and the
// General fund, like everyone on the board. boss is the Crafting boss, lead the
// Woodworking boss. plain is a paying member with no role. The module reads emails and
// codes from the cards.
console.log(sh('roletags'));
for (const [who, role] of [[pres, 'president'], [treasurer, 'treasurer'], [dir1, 'vice-president'], [dir2, 'director'], [leadEmail, 'woodworking-boss'], [boss, 'crafting-boss']]) {
  sh('tag', who, role);
}
const bank = Number(sh('bank', `Sandbox Card ${ts}`));
assert.ok(bank > 0, `bank account created: ${bank}`);

const zones = async () => (await api('budget')).zones;
const zone = async (name) => (await zones()).find((z) => z.zone === name);

const ask = (over = {}) => api('purchase', {
  email: pres, pin: '05', zone: 'Woodworking', kind: 'reimburse', amount: 40, vendor: 'Home Depot',
  purchase_date: day(-1), description: 'Clamps for the bench', pay_method: 'venmo', pay_handle: '@pres', ack_tax: true, ip: IP, ...over,
});
const receipt = (r, data = png().toString('base64')) => api('purchasefile', { id: r.id, token: r.token, data });
const finish = (r) => api('purchasefinish', { id: r.id, token: r.token });
// Open a signed link the way a person would: look first, then press the button.
const press = async (id, email, action, expectStatus = 200, reason = '') => {
  const url = tick('purchase', 'link', String(id), email, action).split('\n').pop().replace(/^https?:\/\/[^/]+/, DOLI);
  const [base, query] = url.split('?');
  const r = await fetch(base, { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: query + (reason ? `&reason=${encodeURIComponent(reason)}` : '') });
  assert.equal(r.status, expectStatus, `${action} by ${email}: HTTP ${r.status}`);
  return r.text();
};

step('budget: totals per zone, nothing personal');
const w0 = await zone('Woodworking');
assert.equal(typeof w0.training, 'number', 'what each zone raised from trainings');
const c0 = await zone('Crafting');
assert.ok(w0 && c0, 'both zones listed');
sh('budget', 'Woodworking', '250');
sh('budget', 'Crafting', '250');
const w1 = await zone('Woodworking');
assert.equal(Math.round((w1.available - w0.available) * 100), 25000, 'budget added shows as available');
assert.equal(w1.committed, w0.committed);
const raw = JSON.stringify(await api('budget'));
assert.ok(!raw.includes('@') && !raw.includes(`Lead${ts}`) && !raw.includes('Pres'), 'no names or emails');
assert.equal((await api('budget').then((r) => r.status)), 200);

step('wrong key is refused');
const bad = await fetch(`${DOLI}/custom/onboarding/public/api.php?action=budget`, { method: 'POST', headers: { 'X-Onboarding-Key': 'nope' }, body: '{}' });
assert.equal(bad.status, 401);

step('a request is checked before anything is kept');
assert.equal((await ask({ kind: 'nonsense' })).field, 'kind');
assert.equal((await ask({ zone: 'Moonbase' })).field, 'zone');
assert.equal((await ask({ amount: 0 })).field, 'amount');
assert.equal((await ask({ amount: 99999 })).field, 'amount');
assert.equal((await ask({ description: '' })).field, 'description');
assert.equal((await ask({ ack_tax: false })).field, 'ack_tax');
assert.equal((await ask({ vendor: '' })).field, 'vendor');
assert.equal((await ask({ pay_handle: '' })).field, 'pay_method');
assert.equal((await ask({ purchase_date: day(3) })).field, 'purchase_date', 'not in the future');
const late = await ask({ purchase_date: day(-90) });
assert.equal(late.error, 'late', 'older than the receipt window');
assert.equal(late.days, 60);
assert.equal((await ask({ kind: 'purchase', link: '' })).field, 'link', 'a purchase needs its link');
assert.equal((await ask({ kind: 'purchase', link: 'javascript:alert(1)' })).field, 'link');
assert.equal((await ask({ kind: 'purchase', link: 'not a url' })).field, 'link');

step('who is asking: one answer for every wrong way in');
const wrongPin = await ask({ pin: '99' });
const unknown = await ask({ email: `nobody-${ts}@example.test` });
const noPin = await ask({ pin: '' });
for (const r of [wrongPin, unknown, noPin]) {
  assert.equal(r.status, 403);
  assert.equal(r.error, 'member');
}
assert.deepEqual(Object.keys(wrongPin).sort(), Object.keys(unknown).sort(), 'the answers look the same');

step('who may file for what: the board any zone, a zone boss any zone but the General fund, nobody else');
const nobody = await ask({ email: plain, pin: '60' });
assert.equal(nobody.error, 'notallowed', 'a paying member who is no zone boss and not on the board cannot file');
assert.deepEqual(nobody.allowed, []);
const generalDenied = await ask({ email: boss, pin: '06', zone: 'General fund' });
assert.equal(generalDenied.error, 'notallowed', 'a boss cannot file for the General fund');
assert.ok(generalDenied.allowed.includes('Crafting') && generalDenied.allowed.includes('Woodworking') && !generalDenied.allowed.includes('General fund'), 'a boss is told the zones they may use');
assert.equal((await ask({ email: boss, pin: '06', zone: 'Crafting' })).ok, true, 'a boss can file for their own zone');
assert.equal((await ask({ email: boss, pin: '06', zone: 'Woodworking' })).ok, true, 'and for another zone');
assert.equal((await ask({ zone: 'Machining' })).ok, true, 'the president can file for any zone');
assert.equal((await ask({ email: dir1, pin: '71', zone: 'General fund' })).ok, true, 'so can anyone on the board');
assert.equal((await ask({ email: plain, pin: '99' })).error, 'member', 'a wrong code is still just "member"');

step('the code can be typed three ways');
for (const pin of ['05', 'CGW-05', '140405']) {
  const r = await ask({ pin });
  assert.equal(r.ok, true, `${pin}: ${JSON.stringify(r)}`);
  assert.match(r.ref, /^PR-\d{4}$/);
}

step('reimbursement: needs a receipt, which must really be a picture or a PDF');
const r1 = await ask({ pin: '140405' });
assert.equal(r1.ok, true);
assert.equal((await finish(r1)).error, 'receipt', 'cannot submit with nothing attached');
assert.equal((await receipt(r1, Buffer.from('<?php echo 1; ?>'.repeat(20)).toString('base64'))).error, 'type', 'not an image or a PDF');
assert.equal((await api('purchasefile', { id: r1.id, token: 'f'.repeat(48), data: png().toString('base64') })).status, 404, 'wrong token');
assert.equal((await receipt(r1)).ok, true);
const pdf = Buffer.from('%PDF-1.4\n' + '1 0 obj<<>>endobj\n'.repeat(10) + '%%EOF');
assert.equal((await receipt(r1, pdf.toString('base64'))).files, 2, 'a PDF is fine too');
const sent1 = await finish(r1);
assert.equal(sent1.ok, true, JSON.stringify(sent1));
assert.equal(sent1.who, 'the treasurer and the Woodworking boss', 'small, filed for a zone by someone who is not its boss');
assert.equal(sent1.needs, 'lead');
assert.equal(show(r1.id).status, 'pending');
assert.equal((await finish(r1)).status, 404, 'cannot be submitted twice');

step('the treasurer, the boss and the member are emailed; the budget holds the money back');
assert.ok(await heard(pres, `${r1.ref}: we have your request`));
assert.ok(await heard(leadEmail, `Please decide: ${r1.ref}`), 'the Woodworking boss is asked');
assert.ok(await heard(treasurer, `Please decide: ${r1.ref}`), 'the treasurer is asked');
assert.ok(!(await heard(dir1, `Please decide: ${r1.ref}`)), 'other directors are not');
const w2 = await zone('Woodworking');
assert.equal(Math.round((w2.committed - w1.committed) * 100), 4000, 'committed');
assert.equal(Math.round((w1.available - w2.available) * 100), 4000, 'available went down');
assert.equal(w2.spent, w1.spent, 'nothing spent until it is paid');

step('deciding from the link: look first, then press');
const lookUrl = tick('purchase', 'link', String(r1.id), leadEmail, 'approve').split('\n').pop().replace(/^https?:\/\/[^/]+/, DOLI);
assert.equal((await fetch(lookUrl.replace(/k=\w+/, 'k=0000'))).status, 403, 'a changed link is refused');
const look = await (await fetch(lookUrl)).text();
assert.match(look, /Approve this request\?/);
assert.ok(look.includes('Home Depot') && look.includes('receipt 1') && look.includes('receipt 2'), 'shows what and the receipts');
assert.ok(look.includes('the treasurer and the Woodworking boss'), 'and who has to approve');
assert.equal(show(r1.id).status, 'pending', 'looking changes nothing');
const receiptUrl = look.match(/href="([^"]+a=view[^"]+n=1[^"]*)"/)?.[1]?.replaceAll('&amp;', '&').replace(/^https?:\/\/[^/]+/, DOLI);
assert.ok(receiptUrl, 'a receipt link is on the page');
const shown = await fetch(receiptUrl);
assert.equal(shown.status, 200);
assert.equal(shown.headers.get('content-type'), 'image/png');
assert.equal((await fetch(receiptUrl.replace(/k=\w+/, 'k=0000'))).status, 403);
assert.match(await press(r1.id, leadEmail, 'approve'), /waiting for the others/);
assert.equal(show(r1.id).status, 'pending', 'the boss alone is not enough');
assert.match(await press(r1.id, leadEmail, 'approve'), /already approved/);
assert.match(await press(r1.id, treasurer, 'approve'), /fully approved/);
assert.equal(show(r1.id).status, 'approved');
assert.match(await press(r1.id, leadEmail, 'approve'), /Already decided/);
assert.ok(await heard(pres, `${r1.ref}: approved`), 'the member is told');
assert.ok(await heard(treasurer, `${r1.ref} is approved, ready to reimburse`), 'the treasurer is told to pay them back');
assert.match(await bodyOf(treasurer, `${r1.ref} is approved`), /a=view&n=2/, 'with links to the receipts');

step('reimbursed: the zone budget is spent and a bank line is made');
const msg = sh('pay', String(r1.id), String(bank));
assert.match(msg, /marked reimbursed/, msg);
const paid = show(r1.id);
assert.equal(paid.status, 'paid');
assert.ok(Number(paid.fk_bank_line) > 0, 'bank line');
const w3 = await zone('Woodworking');
assert.equal(Math.round((w3.spent - w1.spent) * 100), 4000, 'spent');
assert.equal(w3.committed, w1.committed, 'no longer held back');
assert.equal(Math.round((w1.available - w3.available) * 100), 4000, 'available stays down by the amount');
assert.match(sh('pay', String(r1.id), String(bank)), /^Error/, 'cannot be paid twice');
assert.ok(await heard(pres, `${r1.ref}: reimbursed`));

step('over $100: the treasurer and the president');
const c1 = await zone('Crafting');
const r2 = await ask({ email: boss, pin: '06', zone: 'Crafting', amount: 150, vendor: 'Joann', description: 'A cutting table', pay_handle: '@boss' });
await receipt(r2);
const sent2 = await finish(r2);
assert.equal(sent2.who, 'the treasurer and the president', 'a boss asking for their own zone needs no other boss');
assert.equal(sent2.needs, 'board');
for (const who of [treasurer, pres]) assert.ok(await heard(who, `Please decide: ${r2.ref}`), `${who} is asked`);
for (const who of [dir1, boss]) assert.ok(!(await heard(who, `Please decide: ${r2.ref}`)), `${who} is not asked`);
await press(r2.id, dir1, 'approve', 403);
await press(r2.id, boss, 'approve', 403);
assert.match(await press(r2.id, treasurer, 'approve'), /Approval recorded/);
assert.equal(show(r2.id).status, 'pending', 'the treasurer alone is not enough');
assert.match(await press(r2.id, pres, 'approve'), /fully approved/);
assert.equal(show(r2.id).status, 'approved');
assert.equal(Math.round(((await zone('Crafting')).committed - c1.committed) * 100), 15000, 'held back until paid');

step('a rejection says why, ends it and frees the money');
const w4 = await zone('Woodworking');
const r3 = await ask({ amount: 20, vendor: 'Lowes', description: 'Sandpaper' });
await receipt(r3);
await finish(r3);
const rejectPage = await (await fetch(tick('purchase', 'link', String(r3.id), leadEmail, 'reject').split('\n').pop().replace(/^https?:\/\/[^/]+/, DOLI))).text();
assert.match(rejectPage, /name="reason"/, 'the reject page asks why');
assert.match(await press(r3.id, leadEmail, 'reject', 200, 'We still have a full box in the cabinet.'), /rejected/i);
assert.equal(show(r3.id).status, 'rejected');
assert.ok(await heard(pres, `${r3.ref}: not approved`));
assert.match(await bodyOf(pres, `${r3.ref}: not approved`), /Why: We still have a full box in the cabinet\./, 'the member sees the reason');
assert.match(show(r3.id).decided_note, /full box/);
assert.equal((await zone('Woodworking')).committed, w4.committed, 'a rejected request holds nothing back');

step('nobody approves their own: another director stands in for the president');
const r4 = await ask({ amount: 150, vendor: 'Rockler', description: 'A router table' });
await receipt(r4);
assert.equal((await finish(r4)).who, 'the treasurer, another director and the Woodworking boss');
assert.ok(!(await heard(pres, `Please decide: ${r4.ref}`)), 'not sent to the person who asked');
await press(r4.id, pres, 'approve', 403);
await press(r4.id, treasurer, 'approve');
await press(r4.id, leadEmail, 'approve');
assert.equal(show(r4.id).status, 'pending', 'the treasurer and the boss are not enough');
assert.match(await press(r4.id, dir2, 'approve'), /fully approved/);

step('a boss files for another zone: that zone\'s boss approves, its budget pays, the boss is paid back');
const w5 = await zone('Woodworking');
const c5 = await zone('Crafting');
const r5 = await ask({ email: boss, pin: '06', zone: 'Woodworking', amount: 30, vendor: 'Harbor Freight', description: 'Clamps for the woodshop', pay_handle: '@boss' });
await receipt(r5);
assert.equal((await finish(r5)).who, 'the treasurer and the Woodworking boss');
await press(r5.id, treasurer, 'approve');
assert.equal(show(r5.id).status, 'pending', 'waits for the Woodworking boss');
assert.match(await press(r5.id, leadEmail, 'approve'), /fully approved/);
assert.match(sh('pay', String(r5.id), String(bank)), /marked reimbursed/);
assert.ok(await heard(boss, `${r5.ref}: reimbursed`), 'the boss who paid is paid back');
assert.equal(Math.round(((await zone('Woodworking')).spent - w5.spent) * 100), 3000, 'out of the Woodworking budget');
assert.equal((await zone('Crafting')).spent, c5.spent, 'not Crafting\'s');

step('over the zone\'s budget, or a zone with no boss');
const r6x = await ask({ zone: 'Machining', amount: 10, vendor: 'Grainger', description: 'Drill bits' });
await receipt(r6x);
assert.equal((await finish(r6x)).who, 'the treasurer and another director', 'Machining has no money: over budget, so the president\'s place goes to another director');
const t1 = await ask({ email: treasurer, pin: '70', zone: 'General fund', amount: 25, vendor: 'Staples', description: 'Printer paper', pay_handle: '@tess' });
await receipt(t1);
assert.equal((await finish(t1)).who, 'another director', 'the treasurer asking: another director stands in');
await press(t1.id, treasurer, 'approve', 403);
assert.match(await press(t1.id, dir1, 'approve'), /fully approved/);

step('a request for purchase: line items, each a link and an estimated price');
const pBase = { kind: 'purchase', vendor: '', purchase_date: '', pay_method: '', pay_handle: '', ack_tax: false, description: 'More clamps' };
assert.equal((await ask({ ...pBase, items: [{ link: 'https://www.example.com/p/clamps', price: '12' }, { link: 'not a url', price: '3' }] })).field, 'link', 'every item needs a web link');
assert.equal((await ask({ ...pBase, items: [{ link: 'https://www.example.com/p/clamps', price: '0' }] })).field, 'amount', 'and a price');
assert.equal((await ask({ ...pBase, items: Array.from({ length: 11 }, () => ({ link: 'https://www.example.com/p/x', price: '1' })) })).field, 'link', 'ten at most');
const one = await ask({ ...pBase, amount: 9, link: 'https://www.example.com/p/one' });
assert.equal(one.ok, true, 'one link and an amount still work');
const r6 = await ask({ ...pBase, amount: 999, items: [{ link: 'https://www.example.com/p/clamps', price: '12.50' }, { link: 'https://www.example.com/p/glue', price: '7.50' }] });
assert.equal(r6.ok, true, JSON.stringify(r6));
const sent6 = await finish(r6);
assert.equal(sent6.ok, true, JSON.stringify(sent6));
assert.equal(sent6.who, 'the treasurer and the Woodworking boss');
const p6 = show(r6.id);
assert.equal(p6.kind, 'purchase');
assert.equal(Number(p6.amount), 20, 'the amount is the total of the items, not what was typed');
assert.deepEqual(JSON.parse(p6.items).map((i) => i.link), ['https://www.example.com/p/clamps', 'https://www.example.com/p/glue']);
const look6 = await (await fetch(tick('purchase', 'link', String(r6.id), leadEmail, 'approve').split('\n').pop().replace(/^https?:\/\/[^/]+/, DOLI))).text();
assert.ok(look6.includes('/p/glue') && /estimate/.test(look6), 'the approver sees every item, and that the price is an estimate');
await press(r6.id, leadEmail, 'approve');
await press(r6.id, treasurer, 'approve');
assert.ok(await heard(treasurer, `${r6.ref} is approved, ready to order`));
assert.match(await bodyOf(treasurer, `${r6.ref} is approved`), /p\/glue[\s\S]*final amount/, 'the treasurer gets every link and is told to record the final amount');

step('ordered: the treasurer attaches the receipt and the final amount, which is what the budget pays');
const w6 = await zone('Woodworking');
assert.match(sh('pay', String(r6.id), String(bank)), /^Error: type the final amount/);
assert.match(sh('pay', String(r6.id), String(bank), '23.91', 'none'), /^Error: attach the order receipt/);
assert.equal(show(r6.id).status, 'approved', 'nothing changed');
assert.match(sh('pay', String(r6.id), String(bank), '23.91'), /marked ordered: \$23\.91 \(estimated \$20(\.00)?\)/);
const paid6 = show(r6.id);
assert.equal(Number(paid6.amount_final), 23.91);
assert.equal(JSON.parse(paid6.files).length, 1, 'the order receipt is kept with the request');
const w7 = await zone('Woodworking');
assert.equal(Math.round((w7.spent - w6.spent) * 100), 2391, 'tax and shipping included');
assert.equal(Math.round((w6.committed - w7.committed) * 100), 2000, 'the estimate is no longer held back');
assert.match(await bodyOf(pres, `${r6.ref}: ordered`), /\$23\.91 with tax, shipping and fees/);

step('the General fund: up to $100 the treasurer alone, and it spends no zone');
const r7 = await ask({ zone: 'General fund', amount: 25, vendor: 'Staples', description: 'Printer paper' });
assert.equal(r7.ok, true, JSON.stringify(r7));
await receipt(r7);
assert.equal((await finish(r7)).who, 'the treasurer');
assert.ok(!(await heard(leadEmail, `Please decide: ${r7.ref}`)), 'no zone boss involved');
await press(r7.id, treasurer, 'approve');
assert.equal(show(r7.id).status, 'approved');
assert.match(sh('pay', String(r7.id), String(bank)), /marked reimbursed/);
assert.ok(!(await zones()).some((z) => z.zone === 'General fund'), 'the General fund is not a zone budget');

step('roles follow the tag: move the Woodworking boss tag and the new person gets everything');
const newlead = `nia-${ts}@example.test`; // not a superstring of the old boss's address: the mail search matches substrings
sh('member', newlead, 'CGW-80', 'Nia', `NewLead${ts}`);
sh('untag', leadEmail, 'woodworking-boss');
sh('tag', newlead, 'woodworking-boss');
const oldBoss = await ask({ email: leadEmail, pin: '41', zone: 'Woodworking' });
assert.equal(oldBoss.error, 'notallowed', 'the old boss can no longer file');
assert.deepEqual(oldBoss.allowed, [], 'for any zone');
const newBoss = await ask({ email: newlead, pin: '80', zone: 'Woodworking', amount: 15, vendor: 'Lowes', description: 'Screws' });
assert.equal(newBoss.ok, true, 'the new boss can');
const r8 = await ask({ amount: 12, vendor: 'Lowes', description: 'Washers' });
await receipt(r8);
await finish(r8);
assert.ok(await heard(newlead, `Please decide: ${r8.ref}`), 'a Woodworking request now goes to the new boss');
assert.ok(!(await heard(leadEmail, `Please decide: ${r8.ref}`)), 'and not to the old one');
await press(r8.id, leadEmail, 'approve', 403);
assert.equal(show(r8.id).status, 'pending', 'the old boss cannot approve it either');
await press(r8.id, newlead, 'approve');
assert.match(await press(r8.id, treasurer, 'approve'), /fully approved/);

step('PayPal is not a way to be paid back');
assert.equal((await ask({ pay_method: 'paypal', pay_handle: 'a@b.test' })).field, 'pay_method');

step('guessing: five wrong tries lock an email for the day, even for the right code');
for (let i = 0; i < 5; i++) {
  const r = await ask({ email: lockee, pin: String(10 + i) });
  assert.equal(r.error, 'member', `try ${i + 1}`);
}
const locked = await ask({ email: lockee, pin: '52' });
assert.equal(locked.status, 429);
assert.equal(locked.error, 'slow');
const still = await ask({ pin: '05' });
assert.equal(still.ok, true, 'other members are not affected');

step('the staff page lists the requests');
console.log('(checked by smoke-pages.sh)');

console.log('\nPURCHASE REQUESTS PASSED');
