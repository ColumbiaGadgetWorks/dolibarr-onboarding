// Checks the whole sandbox, website included: a signup through the website's
// Worker reaches Dolibarr, and a fake Givebutter payment comes back through the
// Worker's webhook route.
import assert from 'node:assert/strict';
import { deflateSync, crc32 } from 'node:zlib';
function png(size = 48) {
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
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}
const SITE = process.env.SITE_URL || 'http://localhost:8787';
const MOCK = process.env.MOCK_URL || 'http://localhost:8090';
const post = async (url, body) => { const r = await fetch(url, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(body) }); return { status: r.status, ...(await r.json()) }; };

const page = await (await fetch(`${SITE}/membership/join/`)).text();
assert.match(page, /id="?join-1/, 'join page is served');
const docs = await (await fetch(`${SITE}/api/join/docs`)).json();
assert.ok(docs.ok && docs.docs.waiver.version, 'documents come through the Worker');
const email = `stack-${Date.now()}@example.test`;
const started = await post(`${SITE}/api/join/start`, { firstname: 'Stack', lastname: 'Test', email, notify_events: true });
assert.match(started.token || '', /^[a-f0-9]{48}$/, JSON.stringify(started));
const signed = await post(`${SITE}/api/join/sign`, { token: started.token, doc: 'waiver', name: 'Stack Test', version: docs.docs.waiver.version, signature: png().toString('base64') });
assert.equal(signed.waiver, true, JSON.stringify(signed));
const paid = await post(`${MOCK}/control/pay`, { email, amount: 50 });
assert.match(paid.delivery.status, /^200 .*applied/, paid.delivery.status);
const st = await post(`${SITE}/api/join/status`, { token: started.token });
assert.equal(st.paid, true, JSON.stringify(st));
const bad = await fetch(`${SITE}/api/givebutter-webhook`, { method: 'POST', headers: { Signature: 'wrong' }, body: '{}' });
assert.equal(bad.status, 401);

// Training booking through the Worker, when the website branch has it.
const cat = await fetch(`${SITE}/api/training/catalog`);
if (cat.status === 404) {
  console.log('(this website branch has no training booking yet; skipped)');
} else {
  const c = await cat.json();
  assert.ok(Array.isArray(c.tools), 'the Worker serves a training catalog');
  assert.match(await (await fetch(`${SITE}/training/book/`)).text(), /id="?tb-email/, 'booking page is served');
  const t = await post(`${SITE}/api/training/trainee`, { email: `stack-train-${Date.now()}@example.test` });
  assert.equal(t.kind, 'new', JSON.stringify(t));
  assert.ok(t.session, 'a session for the next steps');
  const slots = await (await fetch(`${SITE}/api/training/slots?tool=3d-printers`)).json();
  assert.equal(slots.ok, true, JSON.stringify(slots));
  const look = await post(`${SITE}/api/training/lookup`, { email: 'nobody@example.test' });
  assert.equal(look.found, false);
  // On-the-spot trainings, when the website branch has them.
  const tr = await fetch(`${SITE}/api/training/trainer`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ email: 'nobody@example.test' }) });
  if (tr.status === 404) {
    console.log('(this website branch has no on-the-spot trainings yet; skipped)');
  } else {
    assert.equal((await tr.json()).ok, true, 'the same answer for anyone');
    assert.equal((await post(`${SITE}/api/training/spot`, { ref: 'TR-0000', key: 'f'.repeat(48) })).status, 404);
  }
}
console.log('STACK OK');
