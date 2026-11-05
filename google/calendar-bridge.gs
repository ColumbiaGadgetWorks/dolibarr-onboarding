// ---------------------------------------------------------------------------
// Dolibarr bridge: lets the onboarding module's Free classes put classes on the
// calendar. Paste this at the end of the Apps Script project that already holds
// the Open Hack Night holiday script (it uses that script's CALENDAR_ID), or in a
// new project with a CALENDAR_ID line of its own.
//
// Dolibarr posts to this script's web app address with a shared secret. The
// script can only read and change events on CALENDAR_ID, and only these ways:
// list the repeating events, list one series' dates, read, change, add or remove
// one event.
//
// Set up once (see the module's README, Free classes):
//   1. Services (+ next to Services, left) > Google Calendar API > Add.
//      Keep the identifier "Calendar".
//   2. Project Settings (gear) > Script properties > Add script property:
//      DOLIBARR_SECRET = a long random string (at least 20 characters).
//   3. Deploy > New deployment > type Web app.
//      Execute as: Me. Who has access: Anyone. Deploy, and allow access.
//   4. Copy the Web app URL. In Dolibarr, setup page, Free classes: paste the
//      URL and the same secret.
// After changing this code: Deploy > Manage deployments > edit (pencil) >
// Version: New version > Deploy. The URL stays the same.
// ---------------------------------------------------------------------------

function doPost(e) {
  let req;
  try {
    req = JSON.parse(e.postData.contents);
  } catch (err) {
    return dolibarrReply({ ok: false, code: 400, message: 'Bad request' });
  }
  const secret = PropertiesService.getScriptProperties().getProperty('DOLIBARR_SECRET') || '';
  if (secret.length < 20 || req.secret !== secret) {
    return dolibarrReply({ ok: false, code: 403, message: 'Wrong secret' });
  }
  try {
    return dolibarrReply({ ok: true, result: dolibarrCall(req) });
  } catch (err) {
    const m = String((err && err.message) || err);
    const code = /not found/i.test(m) ? 404 : /deleted/i.test(m) ? 410 : /invalid|bad request/i.test(m) ? 400 : 500;
    return dolibarrReply({ ok: false, code: code, message: m });
  }
}

// Opening the web app address in a browser shows that it is running.
function doGet() {
  return ContentService.createTextOutput('The Dolibarr calendar bridge is running.');
}

function dolibarrCall(req) {
  const id = String(req.id || '');
  const q = req.query || {};
  switch (req.op) {
    case 'list': return Calendar.Events.list(CALENDAR_ID, q);
    case 'instances': return Calendar.Events.instances(CALENDAR_ID, id, q);
    case 'get': return Calendar.Events.get(CALENDAR_ID, id);
    case 'patch': return Calendar.Events.patch(req.body || {}, CALENDAR_ID, id);
    case 'insert': return Calendar.Events.insert(req.body || {}, CALENDAR_ID);
    case 'delete': Calendar.Events.remove(CALENDAR_ID, id); return {};
    default: throw new Error('Bad request: unknown op ' + req.op);
  }
}

function dolibarrReply(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}
