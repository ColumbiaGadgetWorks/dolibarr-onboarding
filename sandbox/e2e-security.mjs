// The information security and records policy's automatic parts: a login is
// disabled when its officer tag or membership ends (warned first, put right in
// time), the setting that turns it off, and bank data deleted after 7 years.
//
//   node e2e-security.mjs   (after e2e-purchase.mjs)
import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';

const MAIL = process.env.MAIL_URL || 'http://localhost:8025';
const stamp = Date.now();
const tick = (...args) => execFileSync('docker', ['compose', 'exec', '-T', '-u', 'www-data', 'dolibarr', 'php', '/var/www/html/custom/onboarding/sandbox/tick.php', ...args], { encoding: 'utf8' }).trim();
const tickIn = (input, ...args) => execFileSync('docker', ['compose', 'exec', '-T', '-u', 'www-data', 'dolibarr', 'php', '/var/www/html/custom/onboarding/sandbox/tick.php', ...args], { encoding: 'utf8', input }).trim();
const last = (out) => out.split('\n').pop();
const sec = (...args) => last(tick('security', ...args));
const pu = (...args) => last(tick('purchase', ...args));
const step = (name) => console.log(`\n== ${name}`);
const status = (login) => Number(sec('status', login));
const login = (name) => JSON.parse(sec('logins')).find((l) => l.login === name);
const ago = (years, days = 0) => {
  const d = new Date();
  d.setFullYear(d.getFullYear() - years);
  d.setDate(d.getDate() - days);
  return d.toISOString().slice(0, 10);
};
async function mailsTo(addr) {
  const r = await (await fetch(`${MAIL}/api/v1/search?query=${encodeURIComponent(`to:${addr}`)}`)).json();
  return (r.messages || []).map((m) => m.Subject);
}
const heard = async (addr, text) => (await mailsTo(addr)).some((s) => s.includes(text));

const owner = `security-${stamp}@example.org`;
try {
  pu('const', 'ONBOARDING_SECURITY_EMAIL', owner);
  pu('const', 'ONBOARDING_DEPROVISION', 'on');
  pu('roletags');

  step('the security owner: the address set, else whoever holds the security tag, else the secretary');
  assert.deepEqual(JSON.parse(sec('owners')), [owner]);
  pu('const', 'ONBOARDING_SECURITY_EMAIL', '');
  const secEmail = `sec-tag-${stamp}@example.org`;
  pu('member', secEmail, `SES${stamp % 100000}`, 'Sam', `Security${stamp}`);
  pu('tag', secEmail, 'security');
  assert.deepEqual(JSON.parse(sec('owners')), [secEmail]);
  pu('untag', secEmail, 'security');
  const clerkEmail = `clerk-${stamp}@example.org`;
  pu('member', clerkEmail, `SEC${(stamp + 1) % 100000}`, 'Cleo', `Clerk${stamp}`);
  pu('tag', clerkEmail, 'secretary');
  assert.ok(JSON.parse(sec('owners')).includes(clerkEmail), 'nobody tagged security: the secretary');
  pu('untag', clerkEmail, 'secretary');
  pu('const', 'ONBOARDING_SECURITY_EMAIL', owner);

  step('officers and members keep their logins');
  const offEmail = `officer-${stamp}@example.org`;
  const officer = pu('member', offEmail, `SEC${stamp % 100000}`, 'Ofelia', `Officer${stamp}`);
  pu('tag', offEmail, 'treasurer');
  const offLogin = `off${stamp}`;
  sec('user', offLogin, officer, 'admin');
  const memEmail = `lead-${stamp}@example.org`;
  const member = pu('member', memEmail, `SEL${stamp % 100000}`, 'Lee', `Lead${stamp}`);
  const memLogin = `lead${stamp}`;
  sec('user', memLogin, member, 'user');
  sec('run', '0');
  assert.equal(login(offLogin).reason, '');
  assert.deepEqual(login(offLogin).roles, ['treasurer']);
  assert.equal(login(memLogin).reason, '');

  step('an administrator who loses the officer tag is warned, and can be put right in time');
  pu('untag', offEmail, 'treasurer');
  assert.match(sec('run', '0'), /1 warned, 0 disabled/);
  assert.ok(await heard(owner, `Dolibarr login ${offLogin} will be disabled`), 'the security owner is warned');
  assert.ok(await heard(`${offLogin}@example.org`, `Dolibarr login ${offLogin} will be disabled`), 'and so is the person');
  assert.match(sec('run', '2'), /0 warned, 0 disabled/, 'still within the grace days');
  assert.equal(status(offLogin), 1);
  pu('tag', offEmail, 'treasurer');
  sec('run', '4');
  assert.equal(status(offLogin), 1, 'the tag was put back: the login stays');
  assert.equal(login(offLogin).since, null, 'and the warning is forgotten');

  step('left without the tag, the login goes, and the owner gets the checklist');
  pu('untag', offEmail, 'treasurer');
  sec('run', '0');
  assert.match(sec('run', '4'), /1 disabled/);
  assert.equal(status(offLogin), 0);
  assert.ok(await heard(owner, `Dolibarr login ${offLogin} disabled: remove`));

  step('a membership that ends ends the login too');
  sec('resign', member);
  assert.match(login(memLogin).reason, /membership has ended/);
  sec('run', '0');
  sec('run', '4');
  assert.equal(status(memLogin), 0);

  step('turned off, nothing is disabled');
  const offMember = pu('member', `off-${stamp}@example.org`, `SEO${stamp % 100000}`, 'Otto', `Off${stamp}`);
  const offLogin2 = `otto${stamp}`;
  sec('user', offLogin2, offMember, 'user');
  sec('resign', offMember);
  pu('const', 'ONBOARDING_DEPROVISION', 'off');
  assert.equal(sec('run', '9'), 'removing logins: off');
  assert.equal(status(offLogin2), 1);
  pu('const', 'ONBOARDING_DEPROVISION', 'on');

  step('bank data older than 7 years goes, with the statement files; newer stays');
  const acc = pu('bank', `Retention ${stamp}`);
  sec('line', acc, ago(7, 3));
  sec('line', acc, ago(6));
  tickIn(`Date,Description,Amount\n${ago(8)},OLD DEPOSIT,10.00\n${ago(8, -2)},OLD FEE,-1.00\n`, 'reconcile', 'upload', acc, 'old.csv');
  assert.equal(Number(sec('count', acc)), 4);
  assert.ok(JSON.parse(sec('review'))['Bank transactions read from the banks older than 7 years'] >= 3);
  assert.match(sec('purge', '0'), /old bank data: 1 lines and 1 statement files deleted/);
  assert.equal(Number(sec('count', acc)), 1, 'only the 6-year-old line is left');
  assert.deepEqual(JSON.parse(last(tick('reconcile', 'files', acc))), []);
  pu('const', 'ONBOARDING_BANKDATA_YEARS', '0');
  assert.equal(sec('purge', '9999'), 'old bank data: kept');
  console.log('\nAll security checks passed');
} finally {
  pu('const', 'ONBOARDING_BANKDATA_YEARS', '7');
  pu('const', 'ONBOARDING_DEPROVISION', 'on');
  pu('const', 'ONBOARDING_SECURITY_EMAIL', '');
}
