// Free classes against the sandbox stack and its fake Google Calendar behind a
// fake Apps Script web app: the shared secret, picking a date of the monthly
// series and writing only
// that date, an extra class with its own event, signing up on the website (a new
// person, a member, email updates, full, the same email twice), giving up a seat
// from the email link, the day-before reminder and class list, the daily check
// that puts back a hand edit, and cancelling.
//
//   node e2e-freeclass.mjs   (after e2e.mjs)
import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';

const DOLI = process.env.DOLI_URL || 'http://localhost:8080';
const MAIL = process.env.MAIL_URL || 'http://localhost:8025';
const MOCK = process.env.MOCK_URL || 'http://localhost:8090';
const KEY = process.env.ONBOARDING_API_KEY || 'sandbox-key-sandbox-key-sandbox-key';
const ts = Date.now();

const tick = (...args) => execFileSync('docker', ['compose', 'exec', '-T', '-u', 'www-data', 'dolibarr', 'php', '/var/www/html/custom/onboarding/sandbox/tick.php', ...args], { encoding: 'utf8' }).trim();
const last = (out) => out.split('\n').pop();
const fc = (...args) => last(tick('freeclass', ...args));
const fcj = (...args) => JSON.parse(fc(...args));
const pu = (...args) => last(tick('purchase', ...args));
const step = (name) => console.log(`\n== ${name}`);

async function api(action, body = {}) {
  const r = await fetch(`${DOLI}/custom/onboarding/public/api.php?action=${action}`, { method: 'POST', headers: { 'content-type': 'application/json', 'X-Onboarding-Key': KEY }, body: JSON.stringify(body) });
  return { status: r.status, ...(await r.json()) };
}
const gcal = async (body) => (await fetch(`${MOCK}/control/gcal`, body ? { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(body) } : {})).json();
const event = async (id) => { const g = await gcal(); return [...g.instances, ...g.singles].find((e) => e.id === id); };
async function mailsTo(addr) {
  const r = await (await fetch(`${MAIL}/api/v1/search?query=${encodeURIComponent(`to:${addr}`)}`)).json();
  return r.messages || [];
}
const heard = async (addr, text) => (await mailsTo(addr)).some((m) => m.Subject.includes(text));
async function bodyOf(addr, subject) {
  const m = (await mailsTo(addr)).find((x) => x.Subject.includes(subject));
  assert.ok(m, `no mail to ${addr} about ${subject}`);
  return (await (await fetch(`${MAIL}/api/v1/message/${m.ID}`)).json()).Text;
}
// The link in the email goes through the website; the test opens Dolibarr's page directly.
const direct = (link) => `${DOLI}/custom/onboarding/public/freeclass.php?${link.split('?')[1]}`;
// A date and time on the shop's clock, hours from now.
function local(hoursAhead) {
  const d = new Date(Date.now() + hoursAhead * 3600e3);
  const p = Object.fromEntries(new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Chicago', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(d).map((x) => [x.type, x.value]));
  return { day: `${p.year}-${p.month}-${p.day}`, time: `${p.hour}:${p.minute}` };
}

const secret = `calendar-secret-${ts}-0123456789`;

try {
  step('not set up: no monthly dates, and a monthly class cannot be made');
  pu('const', 'ONBOARDING_GCAL_SECRET', '');
  assert.equal(fc('dates'), 'null');
  assert.match(fcj('save', JSON.stringify({ kind: 'oca', date: 'x', title: 'Soldering', seats: 5 })).error, /could not be read/);

  step('the calendar script answers only to its secret, through its redirect');
  await gcal({ reset: true, secret });
  pu('const', 'ONBOARDING_GCAL_SCRIPT_URL', 'http://givebutter:8090/gcal/script');
  pu('const', 'ONBOARDING_GCAL_SECRET', 'not-the-secret-not-the-secret');
  assert.equal(fc('series'), 'null', 'a wrong secret is refused');
  pu('const', 'ONBOARDING_GCAL_SECRET', secret);
  const series = fcj('series');
  assert.equal(series['oca-series'], 'Free public class, City of Columbia series', 'the repeating events are listed to pick from');
  const listCall = (await gcal()).calls.find((c) => c.op === 'list');
  assert.equal(listCall.query.singleEvents, false, 'query values go to the script as true and false, not strings');
  assert.equal(listCall.query.maxResults, 250);
  pu('const', 'ONBOARDING_GCAL_SERIES', 'oca-series');

  step('the monthly dates: first Tuesdays, 6 to 8 PM');
  const dates = fcj('dates');
  assert.equal(dates.length, 12);
  for (const d of dates) {
    const when = new Intl.DateTimeFormat('en-US', { timeZone: 'America/Chicago', weekday: 'short', hour: 'numeric', hourCycle: 'h23' }).format(new Date(d.start * 1000));
    assert.match(when, /^Tue,? 18$/, when);
    assert.equal(d.minutes, 120);
  }

  step('a monthly class: only that date of the series changes on the calendar');
  const inst = pu('member', `instructor-${ts}@example.test`, `FCI${ts % 100000}`, 'Ivy', `Instructor${ts}`);
  const made = fcj('save', JSON.stringify({ kind: 'oca', date: dates[0].id, title: 'Intro to soldering', description: 'Make a blinking badge to take home.', seats: 2, instructor: Number(inst) }));
  assert.equal(made.ok, true, JSON.stringify(made));
  const oca = made.id;
  let e = await event(dates[0].id);
  assert.equal(e.summary, 'Intro to soldering');
  assert.match(e.description, /Make a blinking badge[\s\S]*sign up at .*\/classes\/#class-\d+[\s\S]*City of Columbia Office of Cultural Affairs/);
  assert.equal(e.start.dateTime, new Date(dates[0].start * 1000).toISOString(), 'the time stays as the series has it');
  assert.equal((await event(dates[1].id)).summary, 'Free public class, City of Columbia series', 'the next date is untouched');
  assert.equal((await gcal()).series.summary, 'Free public class, City of Columbia series', 'and so is the series');
  assert.ok(!fcj('dates').some((d) => d.id === dates[0].id), 'a date with a class is not offered again');
  assert.equal(fcj('save', JSON.stringify({ kind: 'oca', date: dates[0].id, title: 'Twice', seats: 3 })).ok, false, 'nor can it be taken twice');

  step('the website lists it: seats left, the City credit, nothing personal');
  let list = await api('freeclasses');
  let mine = list.classes.find((c) => c.id === oca);
  assert.equal(mine.left, 2);
  assert.equal(mine.full, false);
  assert.match(mine.sponsor, /Office of Cultural Affairs/);
  assert.ok(!JSON.stringify(list).includes('@'), 'no emails');

  step('signing up: checked first');
  assert.equal((await api('freeclasssignup', { id: oca, name: 'Ann Newcomer', email: 'not-an-email' })).field, 'email');
  assert.equal((await api('freeclasssignup', { id: oca, name: '', email: `ann-${ts}@example.test` })).field, 'name');
  assert.equal((await api('freeclasssignup', { id: 999999, name: 'Ann Newcomer', email: `ann-${ts}@example.test` })).error, 'closed');

  step('someone new becomes a contact; a member is matched to their card and gets email updates');
  const ann = `ann-${ts}@example.test`;
  let r = await api('freeclasssignup', { id: oca, name: 'Ann Newcomer', email: ann, updates: false });
  assert.equal(r.ok, true, JSON.stringify(r));
  assert.equal(r.result, 'signed');
  assert.equal(r.left, 1);
  const memEmail = `member-${ts}@example.test`;
  const mem = pu('member', memEmail, `FCM${ts % 100000}`, 'Max', `Member${ts}`);
  r = await api('freeclasssignup', { id: oca, name: 'Max M', email: memEmail.toUpperCase(), updates: true });
  assert.equal(r.left, 0);
  let shown = fcj('show', String(oca));
  const annRow = shown.signups.find((s) => s.email === ann);
  const memRow = shown.signups.find((s) => s.email === memEmail);
  assert.ok(Number(annRow.fk_socpeople) > 0 && !annRow.fk_adherent, 'a contact for someone new');
  assert.equal(Number(memRow.fk_adherent), Number(mem), 'the member is recognised by email');
  assert.equal(Number(memRow.updates), 1);
  assert.ok(await heard(ann, 'Signed up: Intro to soldering'));
  const confirm = await bodyOf(ann, 'Signed up: Intro to soldering');
  assert.match(confirm, /6:00\s+PM\s+to\s+8:00\s+PM[\s\S]*Office\s+of\s+Cultural\s+Affairs/);

  step('full: nobody more; the same email again keeps its one seat');
  assert.equal((await api('freeclasssignup', { id: oca, name: 'Late Larry', email: `larry-${ts}@example.test` })).error, 'full');
  r = await api('freeclasssignup', { id: oca, name: 'Ann Newcomer', email: ann });
  assert.equal(r.result, 'already');
  assert.equal(fcj('show', String(oca)).signups.filter((s) => s.status === 'signed').length, 2);
  assert.equal((await api('freeclasses')).classes.find((c) => c.id === oca).full, true);

  step('giving up a seat from the email: look first, then press; the seat frees');
  const link = fc('link', String(annRow.rowid));
  assert.match(link, /\/classes\/link\/\?s=\d+&k=[0-9a-f]+$/, 'the link goes through the website');
  assert.equal((await fetch(direct(link).replace(/k=[0-9a-f]+/, 'k=0000'))).status, 403);
  assert.match(await (await fetch(direct(link))).text(), /give up my seat/);
  assert.equal(fcj('show', String(oca)).signups.find((s) => s.email === ann).status, 'signed', 'opening the link changes nothing');
  const [base, query] = direct(link).split('?');
  assert.match(await (await fetch(`${base}?${query}`, { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: `${query}&do=cancel` })).text(), /Seat given up/);
  assert.equal((await api('freeclasssignup', { id: oca, name: 'Late Larry', email: `larry-${ts}@example.test` })).result, 'signed', 'someone else can have it');

  step('changing the class rewrites its date; a hand edit on the calendar is put back');
  assert.equal(fcj('save', JSON.stringify({ title: 'Soldering for beginners', description: 'Make a blinking badge.', seats: 3, instructor: Number(inst) }), String(oca)).ok, true);
  assert.equal((await event(dates[0].id)).summary, 'Soldering for beginners');
  assert.match(fc('check'), /0 calendar events put back/);
  await gcal({ edit: { id: dates[0].id, summary: 'Someone typed over it' } });
  assert.match(fc('check'), /1 calendar events put back/);
  assert.equal((await event(dates[0].id)).summary, 'Soldering for beginners');

  step('an extra class: any date and time, its own event');
  const soon = local(20);
  const ex = fcj('save', JSON.stringify({ kind: 'extra', day: soon.day, time: soon.time, minutes: 90, title: 'Arduino night', description: 'Bring a laptop.', seats: 5, instructor: Number(inst) }));
  assert.equal(ex.ok, true, JSON.stringify(ex));
  const exc = fcj('show', String(ex.id)).class;
  e = await event(exc.gcal_event_id);
  assert.equal(e.summary, 'Arduino night');
  assert.ok(!/Cultural Affairs/.test(e.description), 'only monthly classes carry the City credit');
  assert.equal(Date.parse(e.end.dateTime) - Date.parse(e.start.dateTime), 90 * 60e3);
  assert.equal(fcj('save', JSON.stringify({ kind: 'extra', day: '2020-01-01', time: '18:00', minutes: 60, title: 'Past', seats: 5 })).ok, false, 'not in the past');
  const bo = `bo-${ts}@example.test`;
  await api('freeclasssignup', { id: ex.id, name: 'Bo Builder', email: bo });

  step('moving an extra class moves its event, and the people signed up are told');
  const later = local(21);
  assert.equal(fcj('save', JSON.stringify({ day: later.day, time: later.time, minutes: 90, title: 'Arduino night', description: 'Bring a laptop.', seats: 5, instructor: Number(inst) }), String(ex.id)).ok, true);
  assert.ok(Date.parse((await event(exc.gcal_event_id)).start.dateTime) - Date.parse(e.start.dateTime) >= 59 * 60e3);
  assert.ok(await heard(bo, 'New time: Arduino night'));

  step('the day before: a reminder for each person, the list for the instructor');
  assert.match(fc('run', '0'), /1 reminders/);
  assert.ok(await heard(bo, 'Tomorrow: Arduino night'));
  assert.ok(await heard(`instructor-${ts}@example.test`, 'Class list: Arduino night'));
  assert.match(await bodyOf(`instructor-${ts}@example.test`, 'Class list: Arduino night'), /Bo Builder/);
  assert.match(fc('run', '0'), /0 reminders/, 'once');

  step('cancelling: off the calendar, everyone told, no more sign-ups');
  assert.match(fc('cancel', String(ex.id), 'The instructor is ill; we will set a new date soon.'), /cancelled; 1 people emailed, and it is off the calendar/);
  assert.equal(await event(exc.gcal_event_id), undefined, 'its event is gone');
  assert.match(await bodyOf(bo, 'Cancelled: Arduino night'), /instructor\s+is\s+ill/);
  assert.equal((await api('freeclasssignup', { id: ex.id, name: 'Bo Builder', email: `bo2-${ts}@example.test` })).error, 'closed');
  assert.match(fc('cancel', String(oca)), /off the calendar/);
  assert.equal((await event(dates[0].id)).status, 'cancelled', 'a monthly date is cancelled on its own');
  assert.equal((await event(dates[1].id)).status, 'confirmed', 'the rest of the series stays');
  assert.ok(await heard(memEmail, 'Cancelled: Soldering for beginners'));
  assert.ok(!(await heard(ann, 'Cancelled: Soldering for beginners')), 'not someone who gave up the seat');
  list = await api('freeclasses');
  assert.ok(!list.classes.some((c) => c.id === oca || c.id === ex.id), 'cancelled classes are not listed');

  step('over: a class that has happened is marked done');
  const third = fcj('save', JSON.stringify({ kind: 'oca', date: dates[2].id, title: 'Laser cutting', seats: 4 }));
  assert.match(fc('run', String(24 * 120)), /[1-9]\d* done/);
  assert.equal(fcj('show', String(third.id)).class.status, 'done');

  console.log('\nFREE CLASSES PASSED');
} finally {
  pu('const', 'ONBOARDING_GCAL_SECRET', '');
  pu('const', 'ONBOARDING_GCAL_SERIES', '');
}
