// A stand-in for Givebutter, for the sandbox and the automated test. No real
// money, no real Givebutter account. It does three things:
//
//   1. Serves a fake checkout page at /checkout, where "paying" creates a
//      transaction and a monthly plan and fires the same webhook Givebutter would.
//   2. Serves a control page at / to charge a plan again, fail it, cancel it or
//      resume it, each firing its webhook.
//   3. Answers the two API calls the module makes: GET /v1/transactions and
//      GET /v1/plans/{id}.
//   4. /control/pay also takes a campaign (training fees) and a fee (what
//      Givebutter keeps; 0 unless given).
//   5. Stands in for GitHub: serves the waiver and agreement at /legal/*.md,
//      changed with POST /control/doc {doc, text}.
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
const legal = {
  waiver: '---\ntitle: Liability waiver\n---\nSandbox waiver. Tools are sharp. I use the shop at my own risk and follow the posted safety rules.',
  agreement: '---\ntitle: Membership agreement\n---\nSandbox agreement. I follow the shop rules and keep my dues current.',
};
// Ids keep growing across restarts, so a restarted sandbox never repeats one.
let seq = Math.floor(Date.now() / 1000);

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
    fee: Number(plan.fee || 0),
    custom_fields: plan.custom_fields || [],
    currency: 'USD',
    transacted_at: iso(),
    created_at: iso(),
    updated_at: iso(),
  };
  transactions.unshift(t);
  return t;
}

async function pay({ email, first_name, last_name, amount, frequency, campaign, silent, custom_fields, fee }) {
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
    fee: Number(fee || 0),
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
  const campaign = q.get('campaign') || CAMPAIGN;
  return page('Fake Givebutter checkout', `<h1>${campaign === CAMPAIGN ? 'Membership dues' : `Campaign ${esc(campaign)}`}</h1>
<form method="post" action="/checkout">
<input type="hidden" name="campaign" value="${esc(campaign)}">
<label>Email</label><input name="email" type="email" required value="${esc(q.get('gb_email') || q.get('email') || '')}">
<label>First name</label><input name="first_name" value="${esc(q.get('gb_first_name') || 'Test')}">
<label>Last name</label><input name="last_name" value="${esc(q.get('gb_last_name') || 'Payer')}">
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

const github = [];
// Fake PayPal (see /control/paypal). The sandbox's Client ID and Secret:
const PAYPAL_ID = 'sandbox-paypal-id';
const PAYPAL_SECRET = 'sandbox-paypal-secret';
const paypalTx = [];
// Fake Plaid. The sandbox's keys:
const PLAID_ID = 'sandbox-plaid-id';
const PLAID_SECRET = 'sandbox-plaid-secret';
const plaidItems = [];
let PAYPAL_SEARCH = true;
let GITHUB_EXPIRES = '2099-01-01 00:00:00 UTC';

const send = (res, code, body, type = 'text/html; charset=utf-8', extra = {}) => { res.writeHead(code, { 'content-type': type, ...extra }); res.end(body); };
const json = (res, code, obj) => send(res, code, JSON.stringify(obj), 'application/json');

http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  const path = url.pathname;
  try {
    // Fake Plaid: link tokens, the token exchange, accounts and transactions sync,
    // fed by POST /control/plaid. Keys as on the setup page in the sandbox.
    if (path.startsWith('/plaid/') && req.method === 'POST') {
      const b = await readBody(req);
      const route = path.slice('/plaid'.length);
      if (b.client_id !== PLAID_ID || b.secret !== PLAID_SECRET) return json(res, 400, { error_type: 'INVALID_INPUT', error_code: 'INVALID_API_KEYS', error_message: 'invalid client_id or secret provided' });
      const item = plaidItems.find((i) => i.access_token === b.access_token);
      if (route === '/link/token/create') return json(res, 200, { link_token: `link-sandbox-${++seq}`, expiration: new Date(Date.now() + 4 * 3600e3).toISOString() });
      if (route === '/sandbox/public_token/create') return json(res, 200, { public_token: `public-sandbox-${b.institution_id || 'ins_1'}-${++seq}` });
      if (route === '/item/public_token/exchange') {
        if (!/^public-sandbox-/.test(b.public_token || '')) return json(res, 400, { error_code: 'INVALID_PUBLIC_TOKEN', error_message: 'bad public token' });
        const n = ++seq;
        const it = { item_id: `item-${n}`, access_token: `access-sandbox-${n}`, login_required: false, tx: [], accounts: [
          { account_id: `acc-${n}-chk`, name: 'Business Checking', official_name: 'Central Bank Business Checking', mask: '9681', type: 'depository', subtype: 'checking', balances: { current: 1000, available: 1000, iso_currency_code: 'USD' } },
          { account_id: `acc-${n}-cc`, name: 'MasterCard', official_name: 'US Bank MasterCard', mask: '4944', type: 'credit', subtype: 'credit card', balances: { current: 77.72, available: null, iso_currency_code: 'USD' } },
        ] };
        plaidItems.push(it);
        return json(res, 200, { access_token: it.access_token, item_id: it.item_id });
      }
      if (!item) return json(res, 400, { error_code: 'INVALID_ACCESS_TOKEN', error_message: 'invalid access token' });
      if (item.login_required) return json(res, 400, { error_type: 'ITEM_ERROR', error_code: 'ITEM_LOGIN_REQUIRED', error_message: 'the login details of this item have changed' });
      if (route === '/accounts/get') return json(res, 200, { accounts: item.accounts, item: { item_id: item.item_id } });
      if (route === '/item/remove') { plaidItems.splice(plaidItems.indexOf(item), 1); return json(res, 200, {}); }
      if (route === '/transactions/sync') {
        // The cursor is how many changes were sent; one change per transaction event.
        const from = Number(b.cursor || 0);
        const size = Number(b.count || 100);
        const page = item.tx.slice(from, from + size);
        return json(res, 200, {
          added: page.filter((e) => e.kind === 'added').map((e) => e.t),
          modified: page.filter((e) => e.kind === 'modified').map((e) => e.t),
          removed: page.filter((e) => e.kind === 'removed').map((e) => ({ transaction_id: e.t.transaction_id })),
          next_cursor: String(from + page.length), has_more: from + page.length < item.tx.length, accounts: item.accounts,
        });
      }
      return json(res, 404, { error_code: 'NOT_FOUND', error_message: route });
    }
    if (path === '/control/plaid' && req.method === 'POST') {
      // { item, add: {account: 'chk'|'cc', amount, date, name, pending} } | { item, remove: id } | { item, login_required }
      const b = await readBody(req);
      const it = b.item ? plaidItems.find((i) => i.item_id === b.item) : plaidItems.at(-1);
      if (!it) return json(res, 404, { error: 'no item' });
      if (b.login_required !== undefined) it.login_required = !!b.login_required;
      if (b.add) {
        const t = { transaction_id: `tx-${++seq}`, account_id: it.accounts.find((a) => a.account_id.endsWith(b.add.account || 'chk')).account_id, amount: Number(b.add.amount), date: b.add.date, name: b.add.name || 'Something', merchant_name: b.add.merchant || null, pending: !!b.add.pending, iso_currency_code: 'USD' };
        it.tx.push({ kind: 'added', t });
        return json(res, 200, { item: it.item_id, transaction_id: t.transaction_id });
      }
      if (b.remove) it.tx.push({ kind: 'removed', t: { transaction_id: b.remove } });
      if (b.balance !== undefined) it.accounts[0].balances.current = Number(b.balance);
      return json(res, 200, { item: it.item_id });
    }
    // Fake PayPal: OAuth and Transaction search, fed by POST /control/paypal.
    if (path === '/v1/oauth2/token' && req.method === 'POST') {
      const want = 'Basic ' + Buffer.from(`${PAYPAL_ID}:${PAYPAL_SECRET}`).toString('base64');
      if (req.headers.authorization !== want) return json(res, 401, { error: 'invalid_client' });
      return json(res, 200, { access_token: 'pp-token', token_type: 'Bearer', expires_in: 32400 });
    }
    if (path === '/v1/reporting/transactions' && req.method === 'GET') {
      if (req.headers.authorization !== 'Bearer pp-token') return json(res, 401, { name: 'AUTHENTICATION_FAILURE' });
      if (!PAYPAL_SEARCH) return json(res, 403, { name: 'NOT_AUTHORIZED' });
      const iso = (v) => Date.parse(String(v || '').replace(/([+-]\d{2})(\d{2})$/, '$1:$2'));
      const from = iso(url.searchParams.get('start_date'));
      const to = iso(url.searchParams.get('end_date'));
      if (!(to > from) || to - from > 31 * 86400000) return json(res, 400, { name: 'INVALID_REQUEST', message: 'Date range is greater than 31 days' });
      const hits = paypalTx.filter((t) => { const d = iso(t.transaction_info.transaction_initiation_date); return d >= from && d <= to; });
      const size = Number(url.searchParams.get('page_size') || 100);
      const pageNo = Number(url.searchParams.get('page') || 1);
      return json(res, 200, { transaction_details: hits.slice((pageNo - 1) * size, pageNo * size), page: pageNo, total_items: hits.length, total_pages: Math.max(1, Math.ceil(hits.length / size)), last_refreshed_datetime: new Date().toISOString().replace(/\.\d+Z$/, '+0000') });
    }
    if (path === '/control/paypal' && req.method === 'POST') {
      const b = await readBody(req);
      if (b.search !== undefined) { PAYPAL_SEARCH = !!b.search; return json(res, 200, { ok: true }); }
      const txid = `PP${++seq}`;
      const when = new Date(Date.now() - Number(b.days_ago || 0) * 86400000).toISOString().replace(/\.\d+Z$/, '+0000');
      paypalTx.push({
        transaction_info: { transaction_id: txid, transaction_event_code: b.code || 'T0002', transaction_initiation_date: when, transaction_amount: { currency_code: 'USD', value: String(Number(b.amount || 40).toFixed(2)) }, fee_amount: { currency_code: 'USD', value: String((-Number(b.fee || 0)).toFixed(2)) }, transaction_status: b.status || 'S' },
        payer_info: { account_id: b.payer_id || `PAYER${seq}`, email_address: b.email || '', payer_name: { given_name: b.given_name || '', surname: b.surname || '' } },
      });
      return json(res, 200, { txid });
    }
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
    if (path.startsWith('/github/')) {
      // Fake GitHub API for member votes: tag the ballot text, post the result.
      if (!/^Bearer \S+/.test(req.headers.authorization || '')) return json(res, 401, { message: 'Bad credentials' });
      const gh = (code, obj) => send(res, code, JSON.stringify(obj), 'application/json', { 'github-authentication-token-expiration': GITHUB_EXPIRES });
      const b = req.method === 'POST' ? await readBody(req) : {};
      github.push({ method: req.method, path: path.slice(7), body: b });
      let g = path.match(/^\/github\/repos\/([^/]+\/[^/]+)\/pulls\/(\d+)$/);
      if (g && req.method === 'GET') return gh(200, { number: Number(g[2]), head: { sha: 'abc1234def5678abc1234def5678abc1234def56' } });
      g = path.match(/^\/github\/repos\/([^/]+\/[^/]+)\/git\/refs$/);
      if (g && req.method === 'POST') return gh(201, { ref: b.ref, object: { sha: b.sha } });
      g = path.match(/^\/github\/repos\/([^/]+\/[^/]+)\/issues\/(\d+)\/comments$/);
      if (g && req.method === 'POST') return gh(201, { html_url: `https://github.com/${g[1]}/pull/${g[2]}#issuecomment-${++seq}` });
      g = path.match(/^\/github\/repos\/([^/]+\/[^/]+)\/issues$/);
      if (g && req.method === 'POST') return gh(201, { html_url: `https://github.com/${g[1]}/issues/${++seq}` });
      return gh(404, { message: 'Not Found' });
    }
    if (path === '/control/github') {
      if (req.method === 'POST') { const b = await readBody(req); if (b.expires) GITHUB_EXPIRES = b.expires; github.length = 0; }
      return json(res, 200, { calls: github });
    }
    // Stands in for the raw module descriptor on GitHub, for the update check.
    if (path === '/update/modOnboarding.class.php') return send(res, 200, "<?php\n\t\t$this->version = '9.9.9';\n", 'text/plain');
    if (path === '/health') return json(res, 200, { ok: true });
    let lm = path.match(/^\/legal\/(waiver|agreement)\.md$/);
    if (lm && req.method === 'GET') return send(res, 200, legal[lm[1]], 'text/plain; charset=utf-8');
    if (path === '/control/doc' && req.method === 'POST') { const b = await readBody(req); if (legal[b.doc] !== undefined) legal[b.doc] = String(b.text || ''); return json(res, 200, { ok: true }); }
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
