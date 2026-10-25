// End-to-end test of member votes against the sandbox stack: the Secretary and
// the President working the vote from their email links, members running for
// seats and voting, the 30-day and full-member rules, the secret ballot, the
// count with quorum, two-thirds and a tiebreak, GitHub tagging and posting
// (on the fake GitHub in mock-givebutter.js), and a practice vote.
//
//   node e2e-vote.mjs   (after e2e.mjs, same stack)
import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';

const DOLI = process.env.DOLI_URL || 'http://localhost:8080';
const MOCK = process.env.MOCK_URL || 'http://localhost:8090';
const MAIL = process.env.MAIL_URL || 'http://localhost:8025';
const KEY = process.env.ONBOARDING_API_KEY || 'sandbox-key-sandbox-key-sandbox-key';
const ts = Date.now();

const run = (args) => execFileSync('docker', ['compose', 'exec', '-T', '-u', 'www-data', 'dolibarr', 'php', '/var/www/html/custom/onboarding/sandbox/tick.php', ...args], { encoding: 'utf8' }).trim().split('\n').pop();
const tick = (...args) => run(args);
const vote = (...args) => tick('vote', ...args);
const sql = (q) => execFileSync('docker', ['compose', 'exec', '-T', 'db', 'mariadb', '-udolidbuser', '-pdolidbpass', 'dolidb', '-N', '-e', q], { encoding: 'utf8' }).trim();
async function api(action, body = {}) {
  const r = await fetch(`${DOLI}/custom/onboarding/public/api.php?action=${action}`, { method: 'POST', headers: { 'content-type': 'application/json', 'X-Onboarding-Key': KEY }, body: JSON.stringify(body) });
  return { status: r.status, ...(await r.json()) };
}
async function mails(addr) {
  const r = await (await fetch(`${MAIL}/api/v1/search?query=${encodeURIComponent(`to:${addr}`)}&limit=200`)).json();
  return r.messages || [];
}
const subjects = async (addr) => (await mails(addr)).map((m) => m.Subject);
const count = async (addr, text) => (await subjects(addr)).filter((s) => s.includes(text)).length;
async function text(addr, includes) {
  const m = (await mails(addr)).find((x) => x.Subject.includes(includes));
  assert.ok(m, `no email to ${addr} with "${includes}": ${JSON.stringify(await subjects(addr))}`);
  return (await (await fetch(`${MAIL}/api/v1/message/${m.ID}`)).json()).Text;
}
const linkIn = (t, who) => {
  const m = t.match(new RegExp(`https?://\\S+[?&]w=${who}&k=[0-9a-f]+`));
  assert.ok(m, `no ${who} link in: ${t}`);
  return m[0];
};
// Links point at the website's /voting/link/ (or Dolibarr's own address); open Dolibarr's page.
const local = (link) => link.replace(/^https?:\/\/[^/]+\/voting\/link\//, `${DOLI}/custom/onboarding/public/vote.php`).replace(/^https?:\/\/[^/]+(?=\/custom\/)/, DOLI);
const page = async (link) => (await fetch(local(link))).text();
const status = async (link) => (await fetch(local(link))).status;
async function press(link, fields) {
  const [url, query] = local(link).split('?');
  const body = new URLSearchParams(query);
  for (const [k, v] of Object.entries(fields)) body.set(k, v);
  return (await fetch(url, { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body })).text();
}
const github = async (reset) => (await (await fetch(`${MOCK}/control/github`, reset ? { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(reset) } : {})).json()).calls;
const optionIds = (html) => [...html.matchAll(/name="q\[(\d+)\]" value="(\d+)"[^>]*> ([^<]+)<\/label>/g)].map((m) => ({ q: m[1], o: m[2], label: m[3] }));
async function castVote(link, picks) {
  // picks: [question heading, answer label] pairs
  const html = await page(link);
  const fields = { do: 'cast', final: '1' };
  const legends = [...html.matchAll(/<legend>([^<]+)<\/legend>([\s\S]*?)<\/fieldset>/g)].map((m) => ({ legend: m[1], body: m[2] }));
  for (const [legend, label] of picks) {
    const f = legends.find((l) => l.legend.includes(legend));
    assert.ok(f, `no question "${legend}" on the ballot`);
    const o = optionIds(f.body).find((x) => x.label.trim() === label);
    assert.ok(o, `no answer "${label}" for "${legend}": ${JSON.stringify(optionIds(f.body))}`);
    fields[`q[${o.q}]`] = o.o;
  }
  return press(link, fields);
}

const email = (n) => `vote-${n}-${ts}@example.org`;
const people = {};
function member(n, first, joinedDaysAgo) {
  const e = email(n);
  tick('purchase', 'member', e, `V${ts % 1000000}-${String(Object.keys(people).length + 41).padStart(2, '0')}`, first, `Voter${n}`);
  vote('joined', e, String(joinedDaysAgo));
  sql(`UPDATE llx_adherent SET fk_adherent_type = ${FULL} WHERE email = '${e}'`);
  people[n] = { email: e, first, code: String(Object.keys(people).length + 41).padStart(2, '0') };
  return e;
}

// ---------------------------------------------------------------- setup
console.log('setup');
sql('DELETE FROM llx_onboarding_vote_mail; DELETE FROM llx_onboarding_vote_roll; DELETE FROM llx_onboarding_vote_option; DELETE FROM llx_onboarding_vote_candidate; DELETE FROM llx_onboarding_vote_question; DELETE FROM llx_onboarding_vote');
// Only this run's officers hold the tags.
sql("DELETE cm FROM llx_categorie_member cm JOIN llx_categorie c ON c.rowid = cm.fk_categorie WHERE LOWER(c.label) IN ('president','secretary','vice-president','treasurer','director')");
tick('purchase', 'const', 'ONBOARDING_VOTE_GITHUB_TOKEN', 'test-token');
tick('purchase', 'const', 'ONBOARDING_GITHUB_API', 'http://givebutter:8090/github');
// A member type of this test's own, so members other tests left behind are not voters here.
sql("INSERT INTO llx_adherent_type (entity, libelle, statut, subscription, vote, morphy) SELECT 1, 'Vote test full', 1, 1, 1, 'phy' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM llx_adherent_type WHERE libelle = 'Vote test full')");
const FULL = sql("SELECT rowid FROM llx_adherent_type WHERE libelle = 'Vote test full' LIMIT 1");
tick('purchase', 'const', 'ONBOARDING_VOTE_TYPES', FULL);
sql(`UPDATE llx_adherent SET statut = 0 WHERE email LIKE 'vote-%@example.org'`); // earlier runs' voters
await github({ expires: '2099-01-01 00:00:00 UTC' });
const S = member('sec', 'Sally', 40);
const P = member('pres', 'Pat', 40);
for (const n of [1, 2, 3, 4, 5]) member(`v${n}`, `Val${n}`, 40);
const NEW = member('new', 'Newt', 5); // 25 days a member at the meeting
const X = member('hardship', 'Hal', 40);
sql("INSERT INTO llx_adherent_type (entity, libelle, statut, subscription, vote, morphy) SELECT 1, 'Hardship', 1, 0, 1, 'phy' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM llx_adherent_type WHERE libelle = 'Hardship')");
sql(`UPDATE llx_adherent SET fk_adherent_type = (SELECT rowid FROM llx_adherent_type WHERE libelle = 'Hardship' LIMIT 1) WHERE email = '${X}'`);
tick('purchase', 'tag', S, 'secretary');
tick('purchase', 'tag', P, 'president');

// ---------------------------------------------------------------- set up and notice
console.log('vote set up: the officers are emailed their panel');
const id = vote('create', '20', 'Annual meeting test');
assert.ok(Number(id) > 0, `vote not created: ${id}`);
const panelS = linkIn(await text(S, 'Set up the vote'), 'panel');
const panelP = linkIn(await text(P, 'Set up the vote'), 'panel');
let html = await page(panelS);
assert.match(html, /Send notice/);
assert.match(html, /Do you ratify the bylaws\?/, 'the first vote asks to ratify the bylaws');
assert.equal(await status(panelS.replace(/k=[0-9a-f]+/, 'k=0000')), 403, 'a forged link is refused');
assert.equal(await status(vote('link', id, people.v1.email, 'panel')), 403, 'a member who is not an officer cannot open the panel');

console.log('the Secretary adds a question and the pull request');
html = await press(panelS, { do: 'settings', pr_url: 'https://github.com/ColumbiaGadgetWorks/bylaws/pull/9', ratify: '1' });
assert.match(html, /Saved/);
html = await press(panelS, { do: 'saveq', title: 'Do you approve the dues change?', url: 'https://github.com/ColumbiaGadgetWorks/bylaws/pull/9/files', threshold: 'majority' });
assert.match(html, /Question added/);
html = await press(panelS, { do: 'notice' });
assert.match(html, /Tick the box/);
html = await press(panelS, { do: 'notice', sure: '1' });
assert.match(html, /going out to 7 members/, 'officers and five voters: not the new member, not the hardship member');
await page(panelS); // sends the queued notices
assert.equal(await count(people.v1.email, 'Notice:'), 1);
assert.equal(await count(NEW, 'Notice:'), 0, 'a member for 25 days at the meeting: no notice');
assert.equal(await count(X, 'Notice:'), 0, 'not a full member: no notice');
const notice = await text(people.v1.email, 'Notice:');
assert.match(notice, /Do you approve the dues change\?[\s\S]*Needs a\s+majority/);
assert.match(notice, /member number ends in 43/);
html = await press(panelS, { do: 'notice', sure: '1' });
assert.match(html, /already gone out/);

// ---------------------------------------------------------------- running for seats
console.log('members run for seats');
const runLink = async (n) => linkIn(await text(people[n].email, 'Notice:'), 'run');
const seatId = (h, seat) => h.match(new RegExp(`name="seat" value="(\\d+)"[^>]*> ${seat}<`))[1];
let runPage = await page(await runLink('v1'));
assert.match(runPage, /Pick one seat/);
html = await press(await runLink('v1'), { do: 'run', seat: seatId(runPage, 'President'), statement: 'I will keep the lights on.', adult: '1' });
assert.match(html, /You are running for President/);
assert.ok(await count(S, 'Candidate:') >= 1, 'the officers hear about candidates');
html = await press(await runLink('v2'), { do: 'run', seat: seatId(runPage, 'President'), statement: '', adult: '1' });
assert.match(html, /You are running for President/);
html = await press(await runLink('v3'), { do: 'run', seat: seatId(runPage, 'Treasurer'), adult: '1' });
assert.match(html, /running for Treasurer/);
html = await press(await runLink('v4'), { do: 'run', seat: seatId(runPage, 'Secretary'), adult: '1' });
html = await press(await runLink('v4'), { do: 'run', seat: seatId(runPage, 'Vice President'), adult: '1' });
assert.match(html, /now running for Vice President.*no longer running for Secretary/s, 'one seat per candidate');
vote('birth', people.v5.email, '2012-01-01');
html = await press(await runLink('v5'), { do: 'run', seat: seatId(runPage, 'Secretary'), adult: '1' });
assert.match(html, /18 or older/, 'under 18 cannot run, whatever they tick');
html = await page(vote('link', id, X, 'run'));
assert.match(html, /Only full members/);

console.log('discussion reminder');
html = await press(panelS, { do: 'discussion' });
assert.match(html, /going out to 7 members/);
await page(panelS);
const disc = await text(people.v2.email, 'Next week:');
assert.match(disc, /President: Val1 Voterv1, Val2 Voterv2/);

// ---------------------------------------------------------------- the meeting
console.log('at the meeting: floor nomination, open voting');
html = await press(panelS, { do: 'open', sure: '1' });
assert.match(html, /Voting opens during the meeting/, 'not before the meeting day');
vote('meeting', id, '0');
html = await press(panelP, { do: 'addcand', email: people.v5.email, seat: seatId(runPage, 'Secretary'), adult: '1' });
assert.match(html, /18 or older/);
html = await press(panelP, { do: 'addcand', email: NEW, seat: seatId(runPage, 'Secretary'), statement: 'New but keen.', adult: '1' });
assert.match(html, /Newt Voternew added/, 'a member can run without the 30 days');
await github({});
html = await press(panelP, { do: 'open' });
assert.match(html, /Tick the box/);
html = await press(panelP, { do: 'open', sure: '1' });
assert.match(html, /Voting is open\. Ballots are going to 7 members/);
assert.match(html, /tagged ballot\//);
let calls = await github();
assert.ok(calls.some((c) => c.method === 'POST' && c.path === '/repos/ColumbiaGadgetWorks/bylaws/git/refs' && /^refs\/tags\/ballot\/\d{4}-\d\d-\d\d$/.test(c.body.ref) && c.body.sha.startsWith('abc1234')), `tag not created: ${JSON.stringify(calls)}`);
html = await press(await runLink('v1'), { do: 'withdraw' });
assert.match(html, /Nominations closed/);
await page(panelS);
assert.equal(await count(people.v1.email, 'Your ballot:'), 1);
assert.equal(await count(NEW, 'Your ballot:'), 0, 'the new member is not on the roll');
assert.match(await page(vote('link', id, NEW, 'ballot')), /not on the roll/);

// ---------------------------------------------------------------- voting
console.log('voting');
const ballot = async (n) => linkIn(await text(people[n].email, 'Your ballot:'), 'ballot');
html = await page(await ballot('v1'));
assert.match(html, /frozen as/);
assert.match(html, /I will keep the lights on\./, 'candidate statements are on the ballot');
html = await press(await ballot('v1'), { do: 'cast', final: '1' });
assert.match(html, /Answer every question/);
const plan = {
  sec: ['Yes', 'Yes', 'Val1 Voterv1', 'Yes, elect Val4 Voterv4', 'No', 'Yes, elect Val3 Voterv3'],
  pres: ['Yes', 'No', 'Val2 Voterv2', 'Yes, elect Val4 Voterv4', 'No', 'Yes, elect Val3 Voterv3'],
  v1: ['Yes', 'Yes', 'Val1 Voterv1', 'Yes, elect Val4 Voterv4', 'Yes, elect Newt Voternew', 'Yes, elect Val3 Voterv3'],
  v2: ['Yes', 'No', 'Val2 Voterv2', 'No', 'No', 'Yes, elect Val3 Voterv3'],
  v3: ['Yes', 'Yes', 'Val1 Voterv1', 'Abstain', 'Yes, elect Newt Voternew', 'Yes, elect Val3 Voterv3'],
  v4: ['No', 'No', 'Val2 Voterv2', 'Yes, elect Val4 Voterv4', 'No', 'Yes, elect Val3 Voterv3'],
  v5: ['No', 'Abstain', 'Abstain', 'Abstain', 'Abstain', 'Yes, elect Val3 Voterv3'],
};
const legends = ['Do you ratify the bylaws?', 'Do you approve the dues change?', 'President', 'Vice President', 'Secretary', 'Treasurer'];
html = await castVote(await ballot('v1'), legends.map((l, i) => [l, plan.v1[i]]));
assert.match(html, /Your ballot was counted/);
assert.match(await page(await ballot('v1')), /You have voted/);
html = await press(await ballot('v1'), { do: 'cast', final: '1', 'q[1]': '1' });
assert.match(html, /already voted|You have voted/, 'no second ballot');
assert.equal(await count(people.v1.email, 'Your ballot is in'), 1);

console.log('lost ballot email: resend with email and two digits');
const before = await count(people.v5.email, 'Your ballot:');
assert.equal((await api('voteresend', { email: people.v5.email, pin: '00' })).ok, true);
assert.equal((await api('voteresend', { email: `nobody-${ts}@example.org`, pin: '12' })).ok, true, 'same answer for anyone');
assert.equal(await count(people.v5.email, 'Your ballot:'), before, 'wrong digits: nothing sent');
await api('voteresend', { email: people.v5.email, pin: people.v5.code });
assert.equal(await count(people.v5.email, 'Your ballot:'), before + 1);
await api('voteresend', { email: people.v5.email, pin: people.v5.code });
assert.equal(await count(people.v5.email, 'Your ballot:'), before + 1, 'at most one resend every 10 minutes');

console.log('remind non-voters');
html = await press(panelS, { do: 'nonvoters' });
assert.match(html, /Reminding the 6 members/);
await page(panelS);
assert.equal(await count(people.v1.email, 'Reminder: vote by'), 0, 'already voted');
assert.equal(await count(people.v2.email, 'Reminder: vote by'), 1);
html = await page(panelS);
assert.match(html, /<strong>1<\/strong> of 7 voters have voted/);
assert.doesNotMatch(html, /Val1 Voterv1: \d/, 'no running totals');

for (const n of ['sec', 'pres', 'v2', 'v3', 'v4', 'v5']) {
  html = await castVote(await ballot(n), legends.map((l, i) => [l, plan[n][i]]));
  assert.match(html, /Your ballot was counted/, `${n}: ${html.slice(0, 400)}`);
}

console.log('the ballot is secret: nothing ties a voter to an answer');
const cols = JSON.parse(vote('tables'));
assert.deepEqual(cols.onboarding_vote_roll.map((c) => c.split(' ')[0]).sort(), ['email', 'fk_adherent', 'fk_vote', 'resent_at', 'rowid', 'voted_on']);
assert.match(cols.onboarding_vote_roll.find((c) => c.startsWith('voted_on')), /date$/, 'a date, not a time');
assert.ok(!cols.onboarding_vote_option.some((c) => /adherent|member|roll/.test(c)), 'answers have no member');

// ---------------------------------------------------------------- closing and counting
console.log('voting closes after 8 days: counted, emailed, posted');
await github({});
assert.match(vote('run', String(8 * 24 * 60 + 5)), /Closed and counted/);
let r = JSON.parse(vote('show', id));
assert.equal(r.status, 'closed');
assert.equal(r.voters, 7);
assert.equal(r.ballots, 7);
assert.equal(r.quorum, 5);
assert.equal(r.met, true);
const out = Object.fromEntries(r.questions.map((q) => [q.title, q.outcome]));
assert.match(out['Do you ratify the bylaws?'], /^Passed/, '5 yes, 2 no is over two-thirds');
assert.match(out['Do you approve the dues change?'], /^Failed/, '3 to 3 is not a majority');
assert.match(out.President, /^Tie between Val1 Voterv1 and Val2 Voterv2/);
assert.match(out['Vice President'], /^Elected: Val4 Voterv4/);
assert.match(out.Secretary, /not elected: vacant/);
assert.match(out.Treasurer, /^Elected: Val3 Voterv3/);
calls = await github();
const comment = calls.find((c) => c.path === '/repos/ColumbiaGadgetWorks/bylaws/issues/9/comments');
assert.ok(comment && /Eligible voters: 7/.test(comment.body.body) && /Ballots cast: 7/.test(comment.body.body), `result not posted: ${JSON.stringify(calls)}`);
assert.match(await text(S, 'Result:'), /posted on GitHub/);
assert.match(await page(await ballot('v2')), /Voting has closed/);

console.log('the President breaks the tie');
assert.match(await text(P, 'Break a tie'), /tie between Val1 Voterv1 and Val2 Voterv2/);
assert.equal(await count(S, 'Break a tie'), 0, 'the Secretary only breaks it when the President is tied');
html = await page(panelS);
assert.doesNotMatch(html, /Elect Val1/);
html = await page(panelP);
const oid = html.match(/name="oid" value="(\d+)">[\s\S]*?Elect Val1 Voterv1/)?.[1];
const qid = html.match(/name="qid" value="(\d+)"><input type="hidden" name="oid"/)?.[1];
assert.ok(oid && qid, 'tie buttons on the President\'s panel');
html = await press(panelP, { do: 'tie', qid, oid });
assert.match(html, /Tie broken/);
r = JSON.parse(vote('show', id));
assert.match(r.questions.find((q) => q.title === 'President').outcome, /Elected: Val1 Voterv1 \(tie broken by Pat Voterpres \(President\)\)/);
calls = await github();
assert.ok(calls.filter((c) => c.path.endsWith('/issues/9/comments')).length >= 2, 'the updated result is posted too');

// ---------------------------------------------------------------- practice vote
console.log('practice vote: the board only, no date rules, not posted');
await github({});
const pid = vote('create', '0.04', 'Practice vote', 'test');
const ppanel = linkIn(await text(S, 'Set up the vote: Practice vote'), 'panel');
html = await press(ppanel, { do: 'notice', sure: '1' });
assert.match(html, /going out to 2 members/, 'the two officers are the board here');
html = await press(ppanel, { do: 'open', sure: '1' });
assert.match(html, /Voting is open\. Ballots are going to 2 members/);
await page(ppanel);
const pb = linkIn((await mails(P)).length && await text(P, 'Your ballot: Practice vote'), 'ballot');
html = await castVote(pb, [['Practice question', 'Yes']]);
assert.match(html, /counted/);
html = await press(ppanel, { do: 'closetest' });
assert.match(html, /Closed and counted/);
r = JSON.parse(vote('show', pid));
assert.equal(r.status, 'closed');
assert.equal(r.met, false, 'one ballot is short of quorum');
assert.equal((await github()).length, 0, 'nothing sent to GitHub');

console.log('a GitHub token that expires soon is warned about');
tick('purchase', 'const', 'ONBOARDING_ALERT_VOTE_GITHUB_TOKEN', '');
const alertTo = sql("SELECT value FROM llx_const WHERE name = 'ONBOARDING_ALERT_EMAIL'") || S;
await github({ expires: new Date(Date.now() + 10 * 86400000).toISOString().replace('T', ' ').slice(0, 19) + ' UTC' });
await press(panelS, { do: 'repost' });
vote('run', '0');
assert.ok(await count(alertTo.split(',')[0].trim(), 'GitHub token for member votes expires') >= 1, `no expiry alert to ${alertTo}`);
await github({ expires: '2099-01-01 00:00:00 UTC' });
await press(panelS, { do: 'repost' });
tick('purchase', 'const', 'ONBOARDING_VOTE_GH_EXPIRES', '');

console.log('the scheduled job asks the officers for each step on its day');
const sid = vote('create', '21', 'Schedule test');
const parts = Object.fromEntries(new Intl.DateTimeFormat('en-US', { timeZone: 'America/Chicago', hour: 'numeric', minute: 'numeric', hourCycle: 'h23' }).formatToParts(new Date()).map((p) => [p.type, p.value]));
const late = Number(parts.hour) * 60 + Number(parts.minute);
const offset = late < 9 * 60 + 5 ? 9 * 60 + 5 - late : 0; // on the shop's clock, after 9:00 today
assert.match(vote('run', String(offset)), /Schedule test: asked the officers to notice/);
assert.doesNotMatch(vote('run', String(offset)), /Schedule test: asked/, 'once per step');
assert.match(await text(S, 'Send the notice by'), /must go out\s+by/);
void sid;

tick('purchase', 'const', 'ONBOARDING_VOTE_TYPES', '');
console.log('all vote checks passed');
