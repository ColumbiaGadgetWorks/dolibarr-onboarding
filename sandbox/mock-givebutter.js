// A stand-in for Givebutter, for the sandbox and the automated test. No real
// money, no real Givebutter account. It does three things:
//
//   1. Serves a fake checkout page at /checkout, where "paying" creates a
//      transaction and a monthly plan and fires the same webhook Givebutter would.
//   2. Serves a control page at / to charge a plan again, fail it, cancel it or
//      resume it, each firing its webhook.
//   3. Answers the two API calls the module makes: GET /v1/transactions and
//      GET /v1/plans/{id}.
//   4. /control/pay also takes custom_fields (the checkout questions, as
//      [{title, value}]) and a campaign, for training fees.
//
// Environment:
//   WEBHOOK_URL        where webhooks go
//   WEBHOOK_SIGNATURE  sent as the Signature header, as Givebutter does
//   DOLIBARR_KEY       optional, sent as X-Onboarding-Key when the webhook goes
//                      straight to Dolibarr instead of through the website
//   CAMPAIGN_CODE      campaign code put on dues transactions (default SANDBOX)
//   RETURN_URL         "back to the signup" link shown after paying
const http = require('node:http');

const PORT = Number(process.env.PORT || 8090);
let WEBHOOK_URL = process.env.WEBHOOK_URL || '';
let SIGNATURE = process.env.WEBHOOK_SIGNATURE || 'sandbox-signature';
let registered = null; // set when the module's "Connect Givebutter" button calls POST /v1/webhooks
const DOLIBARR_KEY = process.env.DOLIBARR_KEY || '';
const CAMPAIGN = process.env.CAMPAIGN_CODE || 'SANDBOX';
const RETURN_URL = process.env.RETURN_URL || '';

const plans = new Map();
const transactions = [];
const deliveries = [];
let seq = 1000;

const iso = () => new Date().toISOString();
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

async function deliver(event, data) {
  const body = JSON.stringify({ id: `evt_${++seq}`, event, data });
  const entry = { at: iso(), event, target: WEBHOOK_URL, status: 'not sent (no WEBHOOK_URL)', body };
  deliveries.unshift(entry);
  if (!WEBHOOK_URL) return entry;
  try {
    const headers = { 'content-type': 'application/json', Signature: SIGNATURE };
    if (DOLIBARR_KEY && !registered) headers['X-Onboarding-Key'] = DOLIBARR_KEY;
    const r = await fetch(WEBHOOK_URL, { method: 'POST', headers, body });
    entry.status = `${r.status} ${(await r.text()).slice(0, 200)}`;
  } catch (e) {
    entry.status = `failed: ${e.message}`;
  }
  return entry;
}

function charge(plan, campaign = CAMPAIGN) {
  const t = {
    id: `tx_${++seq}`,
    plan_id: plan.recurring ? plan.id : null,
    campaign_code: campaign,
    contact_id: plan.contact_id,
    first_name: plan.first_name,
    last_name: plan.last_name,
    email: plan.email,
    status: 'succeeded',
    amount: Number(plan.amount),
    donated: Number(plan.amount),
    custom_fields: plan.custom_fields || [],
    currency: 'USD',
    transacted_at: iso(),
    created_at: iso(),
    updated_at: iso(),
  };
  transactions.unshift(t);
  return t;
}

async function pay({ email, first_name, last_name, amount, frequency, campaign, silent, custom_fields }) {
  const plan = {
    id: `plan_${++seq}`,
    contact_id: `contact_${++seq}`,
    first_name: first_name || 'Test',
    last_name: last_name || 'Payer',
    email: String(email || '').trim(),
    frequency: frequency || 'monthly',
    recurring: frequency !== 'once',
    status: 'active',
    amount: String(amount || 50),
    created_at: iso(),
    updated_at: iso(),
    canceled_at: null,
    custom_fields: Array.isArray(custom_fields) ? custom_fields : [],
  };
  if (plan.recurring) plans.set(plan.id, plan);
  const transaction = charge(plan, campaign);
  // "silent" skips the webhook, to exercise the hourly sync instead.
  const delivery = silent ? null : await deliver('transaction.succeeded', transaction);
  return { plan, transaction, delivery };
}

async function planAction(id, action) {
  const plan = plans.get(id);
  if (!plan) return null;
  plan.updated_at = iso();
  if (action === 'charge') return { plan, delivery: await deliver('transaction.succeeded', charge(plan)) };
  if (action === 'cancel') { plan.status = 'cancelled'; plan.canceled_at = iso(); return { plan, delivery: await deliver('plan.canceled', plan) }; }
  if (action === 'fail') { plan.status = 'past_due'; return { plan, delivery: await deliver('plan.failed', plan) }; }
  if (action === 'resume') { plan.status = 'active'; plan.canceled_at = null; return { plan, delivery: await deliver('plan.resumed', plan) }; }
  return null;
}

const page = (title, body) => `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(title)}</title>
<style>body{font:16px Arial,sans-serif;max-width:760px;margin:2rem auto;padding:0 1rem;color:#222}
.banner{border:2px solid #b45309;color:#b45309;padding:.5rem .75rem;font-weight:bold;margin-bottom:1.5rem}
label{display:block;margin:.75rem 0 .25rem}input,select{font:inherit;padding:.4rem;width:100%;box-sizing:border-box}
button{font:inherit;padding:.5rem 1rem;margin:.25rem .25rem .25rem 0;cursor:pointer}table{border-collapse:collapse;width:100%}
td,th{border-bottom:1px solid #ccc;padding:.4rem;text-align:left;vertical-align:top;font-size:14px}form.inline{display:inline}</style>
<div class="banner">SANDBOX. This is a fake Givebutter. No money moves.</div>${body}`;

function checkoutPage(q) {
  return page('Fake Givebutter checkout', `<h1>Membership dues</h1>
<form method="post" action="/checkout">
<label>Email</label><input name="email" type="email" required value="${esc(q.get('email') || '')}">
<label>First name</label><input name="first_name" value="Test">
<label>Last name</label><input name="last_name" value="Payer">
<label>Amount (USD)</label><input name="amount" type="number" value="${esc(q.get('amount') || '50')}">
<label>Frequency</label><select name="frequency">${['monthly', 'quarterly', 'yearly', 'once'].map((f) => `<option${(q.get('frequency') || 'monthly') === f ? ' selected' : ''}>${f}</option>`).join('')}</select>
<p><button type="submit">Pay (pretend)</button></p></form>
<p><a href="/">Control page</a></p>`);
}

function controlPage() {
  const rows = [...plans.values()].map((p) => `<tr><td>${esc(p.email)}</td><td>$${esc(p.amount)} ${esc(p.frequency)}</td><td>${esc(p.status)}</td><td>
${['charge', 'fail', 'cancel', 'resume'].map((a) => `<form class="inline" method="post" action="/plan/${esc(p.id)}/${a}"><button>${a}</button></form>`).join('')}</td></tr>`).join('');
  const log = deliveries.slice(0, 25).map((d) => `<tr><td>${esc(d.at)}</td><td>${esc(d.event)}</td><td>${esc(d.status)}</td></tr>`).join('');
  return page('Fake Givebutter', `<h1>Fake Givebutter</h1>
<p><a href="/checkout">Open the checkout page</a>. Webhooks go to <code>${esc(WEBHOOK_URL || '(nowhere)')}</code>.</p>
<h2>Recurring plans</h2><table><tr><th>Email</th><th>Plan</th><th>Status</th><th>Do</th></tr>${rows || '<tr><td colspan="4">None yet. Pay through the checkout first.</td></tr>'}</table>
<h2>Webhooks sent</h2><table><tr><th>When</th><th>Event</th><th>Answer</th></tr>${log || '<tr><td colspan="3">None yet.</td></tr>'}</table>`);
}

async function readBody(req) {
  const chunks = [];
  for await (const c of req) chunks.push(c);
  const raw = Buffer.concat(chunks).toString('utf8');
  if ((req.headers['content-type'] || '').includes('application/json')) {
    try { return JSON.parse(raw || '{}'); } catch { return {}; }
  }
  return Object.fromEntries(new URLSearchParams(raw));
}

const send = (res, code, body, type = 'text/html; charset=utf-8', extra = {}) => { res.writeHead(code, { 'content-type': type, ...extra }); res.end(body); };
const json = (res, code, obj) => send(res, code, JSON.stringify(obj), 'application/json');

http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  const path = url.pathname;
  try {
    if (path.startsWith('/v1/')) {
      if (!/^Bearer \S+/.test(req.headers.authorization || '')) return json(res, 401, { message: 'Unauthenticated.' });
      if (path === '/v1/transactions') return json(res, 200, { data: transactions, links: { next: null }, meta: { total: transactions.length } });
      if (path === '/v1/webhooks' && req.method === 'POST') {
        const b = await readBody(req);
        if (!b.url) return json(res, 422, { message: 'The url field is required.' });
        registered = { id: `wh_${++seq}`, name: b.name || null, url: b.url, events: b.events || [], enabled: true, signature: `sig_${Math.random().toString(36).slice(2)}` };
        WEBHOOK_URL = registered.url;
        SIGNATURE = registered.signature;
        return json(res, 201, registered);
      }
      if (/^\/v1\/webhooks\/[^/]+$/.test(path) && req.method === 'DELETE') { registered = null; return json(res, 200, {}); }
      const m = path.match(/^\/v1\/plans\/([^/]+)$/);
      if (m) return plans.has(m[1]) ? json(res, 200, plans.get(m[1])) : json(res, 404, { message: 'Not found.' });
      return json(res, 404, { message: 'Not found.' });
    }
    if (path === '/health') return json(res, 200, { ok: true });
    if (path === '/' && req.method === 'GET') return send(res, 200, controlPage());
    if (path === '/checkout' && req.method === 'GET') return send(res, 200, checkoutPage(url.searchParams));
    if (path === '/checkout' && req.method === 'POST') {
      const r = await pay(await readBody(req));
      return send(res, 200, page('Paid', `<h1>Thanks, ${esc(r.plan.first_name)}</h1><p>Pretend payment of $${esc(r.plan.amount)} recorded for <strong>${esc(r.plan.email)}</strong>.</p>
<p>Webhook answer: <code>${esc(r.delivery.status)}</code></p>
${RETURN_URL ? `<p><a href="${esc(RETURN_URL)}">Back to the signup</a></p>` : ''}<p><a href="/">Control page</a></p>`));
    }
    let m = path.match(/^\/plan\/([^/]+)\/(charge|fail|cancel|resume)$/);
    if (m && req.method === 'POST') { await planAction(m[1], m[2]); return send(res, 303, '', 'text/plain', { location: '/' }); }

    // JSON control API, used by the automated test.
    if (path === '/control/pay' && req.method === 'POST') return json(res, 200, await pay(await readBody(req)));
    m = path.match(/^\/control\/plan\/([^/]+)\/(charge|fail|cancel|resume)$/);
    if (m && req.method === 'POST') { const r = await planAction(m[1], m[2]); return r ? json(res, 200, r) : json(res, 404, {}); }
    if (path === '/control/resend' && req.method === 'POST') {
      const last = deliveries.find((d) => d.event === 'transaction.succeeded');
      if (!last) return json(res, 404, {});
      const b = JSON.parse(last.body);
      return json(res, 200, await deliver(b.event, b.data));
    }
    return send(res, 404, 'Not found', 'text/plain');
  } catch (e) {
    return send(res, 500, String(e && e.stack || e), 'text/plain');
  }
}).listen(PORT, () => console.log(`Fake Givebutter listening on ${PORT}, webhooks to ${WEBHOOK_URL || '(nowhere)'}`));
