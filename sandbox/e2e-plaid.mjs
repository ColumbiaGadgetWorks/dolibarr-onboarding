// Bank feeds through Plaid, against the fake Plaid in the sandbox: connect a
// bank, feed its accounts to Dolibarr accounts, read transactions into
// reconciling (matched, pending left, taken back), balances, the bank wanting
// the login again, and disconnecting.
//
//   node e2e-plaid.mjs   (after e2e-reconcile.mjs)
import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';

const MOCK = process.env.MOCK_URL || 'http://localhost:8090';
const stamp = Date.now();
const tick = (args) => execFileSync('docker', ['compose', 'exec', '-T', '-u', 'www-data', 'dolibarr', 'php', '/var/www/html/custom/onboarding/sandbox/tick.php', ...args], { encoding: 'utf8' }).trim();
const last = (out) => out.split('\n').pop();
const json = (...args) => JSON.parse(last(tick(args)));
const sql = (q) => execFileSync('docker', ['compose', 'exec', '-T', 'db', 'mariadb', '-N', '-udolidbuser', '-pdolidbpass', 'dolidb', '-e', q], { encoding: 'utf8' }).trim();
const step = (name) => console.log(`\n== ${name}`);
const setConst = (k, v) => tick(['purchase', 'const', k, v]);
const day = (n) => new Date(Date.now() + n * 86400e3).toISOString().slice(0, 10);
const mock = async (body) => (await fetch(`${MOCK}/control/plaid`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(body) })).json();
const plaid = async (route, body) => (await fetch(`${MOCK}/plaid${route}`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ client_id: 'sandbox-plaid-id', secret: 'sandbox-plaid-secret', ...body }) })).json();
function account(label) {
  const id = last(tick(['purchase', 'bank', label]));
  sql(`UPDATE llx_bank_account SET rappro = 1 WHERE rowid = ${Number(id)}`);
  return id;
}
const line = (acc, n, amount, label) => last(tick(['filing', 'line', acc, day(n), String(amount), label]));
const lines = (acc) => json('reconcile', 'lines', acc);
const open = (acc) => json('reconcile', 'open', acc).map((r) => Number(r.amount)).sort((a, b) => a - b);

try {
  setConst('ONBOARDING_RECONCILE_RULES', '# no rules while bank feeds are tested');
  setConst('ONBOARDING_PLAID_API_BASE', 'http://givebutter:8090/plaid');

  step('wrong keys are reported; right keys give a link token for Plaid Link');
  setConst('ONBOARDING_PLAID_CLIENT_ID', 'sandbox-plaid-id');
  setConst('ONBOARDING_PLAID_SECRET', 'wrong');
  assert.equal(last(tick(['plaid', 'token'])), '');
  setConst('ONBOARDING_PLAID_SECRET', 'sandbox-plaid-secret');
  assert.match(last(tick(['plaid', 'token'])), /^link-sandbox-/);

  step('connecting keeps the login (its token encrypted) and reads its accounts');
  const { public_token } = await plaid('/sandbox/public_token/create', { institution_id: 'ins_109508', initial_products: ['transactions'] });
  assert.match(tick(['plaid', 'connect', public_token, `Central Bank ${stamp}`]), /connected: 2 accounts/);
  assert.notEqual(last(tick(['plaid', 'stored'])), 'access-sa', 'the access token is not kept in plain text');
  const item = json('plaid', 'items').find((i) => i.institution === `Central Bank ${stamp}`);
  assert.equal(item.accounts.length, 2);
  const chkP = item.accounts.find((a) => a.subtype === 'checking');
  const ccP = item.accounts.find((a) => a.type === 'credit');
  assert.equal(Number(chkP.balance), 1000);

  step('each account feeds a Dolibarr account; transactions go to reconciling');
  const chk = account(`Plaid checking ${stamp}`);
  const cc = account(`Plaid card ${stamp}`);
  line(chk, -30, 0.01, 'Opening balance');
  line(cc, -30, 0.01, 'Opening balance');
  const rent = line(chk, -5, -650, 'Rent, check 1201');
  tick(['plaid', 'map', chkP.rowid, chk]);
  tick(['plaid', 'map', ccP.rowid, cc]);
  await mock({ item: item.item_id, add: { account: 'chk', amount: 650, date: day(-5), name: 'CHECK 1201' } });
  const deposit = (await mock({ item: item.item_id, add: { account: 'chk', amount: -25, date: day(-3), name: 'MOBILE DEPOSIT' } })).transaction_id;
  await mock({ item: item.item_id, add: { account: 'chk', amount: 12, date: day(-1), name: 'STILL PENDING', pending: true } });
  await mock({ item: item.item_id, add: { account: 'cc', amount: 14.49, date: day(-4), name: 'AMZN Mktp US', merchant: 'Amazon' } });
  const out = tick(['plaid', 'sync']);
  assert.match(out, new RegExp(`Central Bank ${stamp}: 3 transactions, 1 entries reconciled`));
  assert.equal(Number(lines(chk).find((l) => l.rowid === rent).rappro), 1, 'the check Plaid reports matched the rent entry');
  assert.deepEqual(open(chk), [25], 'money in is positive; the pending one waits until it posts');
  assert.deepEqual(open(cc), [-14.49], 'a card purchase is money out');
  assert.match(json('reconcile', 'open', cc)[0].label, /^Amazon - AMZN Mktp US/);

  step('reading again brings only what is new; a transaction the bank takes back goes');
  assert.match(tick(['plaid', 'sync']), /0 transactions/);
  await mock({ item: item.item_id, remove: deposit });
  tick(['plaid', 'sync']);
  assert.deepEqual(open(chk), [], 'taken back');

  step('the bank wants the login again: said, and Reconnect gets a link token');
  await mock({ item: item.item_id, login_required: true });
  assert.match(tick(['plaid', 'sync']), /wants the login again/);
  assert.equal(json('plaid', 'items').find((i) => i.rowid === item.rowid).status, 'login');
  assert.match(last(tick(['plaid', 'token', item.rowid])), /^link-sandbox-/);
  await mock({ item: item.item_id, login_required: false });
  tick(['plaid', 'sync']);
  assert.equal(json('plaid', 'items').find((i) => i.rowid === item.rowid).status, 'ok');

  step('disconnecting: Plaid forgets it, and so does Dolibarr; the books keep what it fed');
  assert.match(tick(['plaid', 'disconnect', item.rowid]), /Disconnected: Plaid no longer reads/);
  assert.ok(!json('plaid', 'items').some((i) => i.rowid === item.rowid));
  assert.equal(Number(lines(chk).find((l) => l.rowid === rent).rappro), 1);
  console.log('\nAll bank feed checks passed');
} finally {
  setConst('ONBOARDING_RECONCILE_RULES', '');
}
