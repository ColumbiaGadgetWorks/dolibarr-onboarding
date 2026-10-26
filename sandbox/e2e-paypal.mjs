// PayPal sync: dues from PayPal's Transaction search are recorded on member
// cards, once, with bank lines; unknown payers wait under Unmatched payments.
//
//   node e2e-paypal.mjs   (after e2e.mjs; turns the PayPal sync off again at the end)
import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';

const MOCK = process.env.MOCK_URL || 'http://localhost:8090';
const tick = (...args) => execFileSync('docker', ['compose', 'exec', '-T', '-u', 'www-data', 'dolibarr', 'php', '/var/www/html/custom/onboarding/sandbox/tick.php', ...args], { encoding: 'utf8' }).trim();
const setConst = (k, v) => tick('purchase', 'const', k, v);
const step = (name) => console.log(`\n== ${name}`);
const mock = async (body) => (await fetch(`${MOCK}/control/paypal`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(body) })).json();
const subs = (id) => JSON.parse(tick('pp', 'subs', id));
const stamp = Date.now();

try {
  setConst('ONBOARDING_PAYPAL_CLIENT_ID', 'sandbox-paypal-id');
  setConst('ONBOARDING_PAYPAL_SECRET', 'sandbox-paypal-secret');
  tick('pp', 'reset');
  assert.match(tick('pp', 'bank'), /created|exists/);

  step('a subscription payment from a member\'s email is recorded, with its bank lines');
  const annEmail = `pp-ann-${stamp}@example.test`;
  const ann = tick('purchase', 'member', annEmail, '', 'Ann', `Pal${stamp}`);
  const before = subs(ann).length;
  const t1 = (await mock({ email: annEmail, given_name: 'Ann', surname: `Pal${stamp}`, amount: 40, fee: 1.89, days_ago: 3, payer_id: `ANN${stamp}` })).txid;
  assert.match(tick('pp', 'sync'), /dues recorded/);
  let s = subs(ann);
  assert.equal(s.length, before + 1);
  const rec = s.at(-1);
  assert.match(rec.note, new RegExp(`^PayPal ${t1}`));
  assert.equal(rec.amount, 40);
  assert.ok(rec.bank > 0, 'linked to its line on the PAYPAL bank account');
  assert.equal(JSON.parse(tick('pp', 'row', t1)).status, 'matched');

  step('syncing again records nothing twice');
  tick('pp', 'sync');
  assert.equal(subs(ann).length, before + 1);

  step('three months\' dues pay three months');
  await mock({ email: annEmail, given_name: 'Ann', surname: `Pal${stamp}`, amount: 120, days_ago: 1, payer_id: `ANN${stamp}` });
  tick('pp', 'sync');
  const q = subs(ann).at(-1);
  const span = (Date.parse(q.to) - Date.parse(q.from)) / 86400000;
  assert.ok(span > 85 && span < 95, `three months: ${q.from} to ${q.to}`);

  step('a payment the dues import already recorded is not recorded again');
  const bobEmail = `pp-bob-${stamp}@example.test`;
  const bob = tick('purchase', 'member', bobEmail, '', 'Bob', `Imp${stamp}`);
  tick('pp', 'importsub', bob, '1');
  const bobBefore = subs(bob).length;
  const t2 = (await mock({ email: bobEmail, given_name: 'Bob', surname: `Imp${stamp}`, amount: 40, days_ago: 1 })).txid;
  tick('pp', 'sync');
  assert.equal(subs(bob).length, bobBefore);
  assert.equal(JSON.parse(tick('pp', 'row', t2)).note, 'already on the card');

  step('an unknown payer waits; once assigned, their next payment matches on its own');
  const calEmail = `pp-cal-${stamp}@example.test`;
  const cal = tick('purchase', 'member', calEmail, '', 'Cal', `Known${stamp}`);
  const t3 = (await mock({ email: `elsewhere-${stamp}@example.test`, given_name: 'C', surname: 'Unknown', amount: 40, days_ago: 1, payer_id: `CAL${stamp}` })).txid;
  assert.match(tick('pp', 'sync'), /waiting under Unmatched payments/);
  assert.equal(JSON.parse(tick('pp', 'row', t3)).status, 'unmatched');
  assert.match(tick('pp', 'assign', t3, cal), /^recorded/);
  const t4 = (await mock({ email: `elsewhere-${stamp}@example.test`, given_name: 'C', surname: 'Unknown', amount: 40, days_ago: 0, payer_id: `CAL${stamp}` })).txid;
  tick('pp', 'sync');
  assert.equal(JSON.parse(tick('pp', 'row', t4)).status, 'matched', 'matched by the PayPal account assigned before');
  // One left unmatched for the staff page check in smoke-pages.sh.
  await mock({ email: `pp-stranger-${stamp}@example.test`, given_name: 'Pp', surname: 'Stranger', amount: 40, days_ago: 1 });

  step('payments that are not dues are left alone');
  const t5 = (await mock({ email: annEmail, given_name: 'Ann', surname: `Pal${stamp}`, amount: 25, code: 'T0006' })).txid;
  tick('pp', 'sync');
  assert.equal(JSON.parse(tick('pp', 'row', t5)), null);

  step('PayPal members are judged by how far the sync has read');
  const through = tick('pp', 'through');
  assert.equal(through.slice(0, 10), new Date().toISOString().slice(0, 10), `PayPal data through today: ${through}`);

  step('a missing Transaction search permission or a wrong secret is reported plainly');
  await mock({ search: false });
  assert.match(tick('pp', 'sync'), /failed.*Transaction search/);
  await mock({ search: true });
  setConst('ONBOARDING_PAYPAL_SECRET', 'wrong');
  assert.match(tick('pp', 'sync'), /failed.*refused the Client ID and Secret/);
} finally {
  setConst('ONBOARDING_PAYPAL_CLIENT_ID', '');
  setConst('ONBOARDING_PAYPAL_SECRET', '');
  tick('pp', 'reset');
}

console.log('\nPAYPAL SYNC PASSED');
