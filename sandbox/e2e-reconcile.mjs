// Reconcile bank accounts: statement exports matched to Dolibarr's entries,
// what does not match left for a person, and PayPal read from PayPal.
//
//   node e2e-reconcile.mjs   (after e2e-paypal.mjs, which leaves the PayPal account and its entries)
import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';
import { deflateSync } from 'node:zlib';

const stamp = Date.now();
const MOCK = process.env.MOCK_URL || 'http://localhost:8090';
const tick = (args, input) => execFileSync('docker', ['compose', 'exec', '-T', '-u', 'www-data', 'dolibarr', 'php', '/var/www/html/custom/onboarding/sandbox/tick.php', ...args], { encoding: 'utf8', input }).trim();
const last = (out) => out.split('\n').pop();
const json = (...args) => JSON.parse(last(tick(args)));
const sql = (q) => execFileSync('docker', ['compose', 'exec', '-T', 'db', 'mariadb', '-N', '-udolidbuser', '-pdolidbpass', 'dolidb', '-e', q], { encoding: 'utf8' }).trim();
const step = (name) => console.log(`\n== ${name}`);

const day = (n) => new Date(Date.now() + n * 86400e3).toISOString().slice(0, 10);
const us = (n) => { const [y, m, d] = day(n).split('-'); return `${Number(m)}/${Number(d)}/${y}`; };
function account(label) {
  const id = last(tick(['purchase', 'bank', label]));
  assert.ok(Number(id) > 0, `account ${label}`);
  sql(`UPDATE llx_bank_account SET rappro = 1 WHERE rowid = ${Number(id)}`);
  return id;
}
const line = (acc, n, amount, label) => last(tick(['filing', 'line', acc, day(n), String(amount), label]));
const lines = (acc) => json('reconcile', 'lines', acc);
const reconciled = (acc) => lines(acc).filter((l) => Number(l.rappro) === 1);

tick(['purchase', 'const', 'ONBOARDING_RECONCILE_RULES', '# no rules while matching is tested']);

step('a checking account: a bank export with junk above the columns, withdrawals and deposits');
const chk = account(`Checking R${stamp}`);
line(chk, -40, 100, 'Older, before the statement');
const payout = line(chk, -20, 550.01, 'Givebutter payout');
line(chk, -15, -650, 'Rent check');
line(chk, -14, 40, 'Dues A');
line(chk, -14, 40, 'Dues B');
const stray = line(chk, -10, -12.34, 'Not on the statement');
const csv = [
  'POSTED TRANSACTIONS',
  'Collapse',
  'Date,Transaction Type,Description,Withdrawals,Deposits,Balance',
  `${us(-8)},Mobile Deposit,Check from a donor,,25.00,"$97.51"`,
  `${us(-12)},Service Charge,MONTHLY FEE,7.50,,72.51`,
  `${us(-14)},Other Deposit,POSTING CREDIT,,40.00,80.01`,
  `${us(-14)},Other Deposit,POSTING CREDIT,,40.00,40.01`,
  `${us(-15)},Check Paid,Check 1203,"$650.00",,0.01`,
  `${us(-19)},Pre-authorized ACH Credit,GIVEBUTTER STRIPE PAYOUT,,550.01,650.01`,
].join('\n');
assert.match(tick(['reconcile', 'upload', chk, 'central-bank.csv'], csv), /6 transactions read from central-bank\.csv, 4 entries reconciled, 2 to check/);
const after = lines(chk);
const byId = Object.fromEntries(after.map((l) => [l.rowid, l]));
assert.equal(Number(byId[payout].rappro), 1, 'the payout, posted a day later, is reconciled');
assert.equal(byId[payout].num_releve, day(-19).slice(0, 7).replace('-', ''), 'statement number is the month');
assert.equal(Number(byId[stray].rappro), 0, 'an entry the bank does not show is not');
assert.equal(after.filter((l) => /^Dues/.test(l.label) && Number(l.rappro) === 1).length, 2, 'two same-day $40 deposits, both matched');

step('what is left: two statement lines, one Dolibarr entry; uploading again changes nothing');
let open = json('reconcile', 'open', chk);
assert.deepEqual(open.map((r) => Number(r.amount)).sort(), [-7.5, 25]);
assert.deepEqual(json('reconcile', 'missing', chk).map((l) => l.rowid), [stray], 'the stray entry; the one from before the statement is not blamed');
assert.match(tick(['reconcile', 'upload', chk, 'central-bank.csv'], csv), /0 entries reconciled, 2 to check/);
assert.equal(json('reconcile', 'open', chk).length, 2, 'the same lines are kept once');
open = json('reconcile', 'open', chk); // Read afresh, so new ids.
const file = json('reconcile', 'files', chk)[0];
assert.equal(Number(file.end_balance), 97.51, 'the ending balance, from the newest line');
assert.equal(file.end_date, day(-8));

step('a person decides: add the fee, match the deposit by hand, reconcile the stray entry');
const fee = open.find((r) => Number(r.amount) === -7.5);
assert.match(tick(['reconcile', 'decide', fee.rowid, 'add']), /Added to .* and reconciled/);
const dep = open.find((r) => Number(r.amount) === 25);
const typo = line(chk, -9, 52, 'Donation (typed as 52)');
assert.match(tick(['reconcile', 'decide', dep.rowid, 'match', typo]), /Matched and reconciled/);
assert.match(tick(['reconcile', 'force', stray]), /Reconciled/);
assert.equal(json('reconcile', 'open', chk).length, 0);
assert.equal(json('reconcile', 'missing', chk).length, 0);
assert.ok(lines(chk).some((l) => Number(l.amount) === -7.5 && Number(l.rappro) === 1), 'the added fee is an entry, reconciled');

step('a card export with purchases shown as positive is turned around');
const card = account(`Card R${stamp}`);
line(card, -6, -23.45, 'Hardware store');
line(card, -5, -10, 'Coffee');
const cardCsv = `"Date","Transaction","Name","Memo","Amount"\n"${day(-6)}","DEBIT","HARDWARE STORE","x","23.45"\n"${day(-4)}","DEBIT","COFFEE","x","10.00"\n`;
assert.match(tick(['reconcile', 'upload', card, 'usbank.csv'], cardCsv), /2 entries reconciled/);

step('payments with their fee on one line: full amount plus fee, or after the fee; memo and pending rows skipped');
const pp = account(`Processor R${stamp}`);
line(pp, -7, 40, 'Dues gross');
line(pp, -7, -1.89, 'Fee');
line(pp, -6, 38.11, 'Dues net');
const ppCsv = [
  '"Date","Time","TimeZone","Name","Type","Status","Currency","Gross","Fee","Net","Balance Impact","Transaction ID"',
  `"${us(-7)}","10:00:00","CDT","Ann","Subscription Payment","Completed","USD","40.00","-1.89","38.11","Credit","TX1"`,
  `"${us(-6)}","11:00:00","CDT","Bob","Subscription Payment","Completed","USD","40.00","-1.89","38.11","Credit","TX2"`,
  `"${us(-6)}","11:00:00","CDT","Bob","General Authorization","Pending","USD","40.00","0.00","40.00","Memo","TX3"`,
].join('\n');
assert.match(tick(['reconcile', 'upload', pp, 'paypal.csv'], ppCsv), /2 transactions read from paypal\.csv, 3 entries reconciled/);
assert.equal(json('reconcile', 'open', pp).length, 0, 'the netted fee is not left over');

step('OFX from online banking');
const ofxAcc = account(`OFX R${stamp}`);
line(ofxAcc, -3, -45.5, 'Insurance');
const ofx = `OFXHEADER:100\nDATA:OFXSGML\n<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKTRANLIST>\n<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>${day(-3).replace(/-/g, '')}120000<TRNAMT>-45.50<FITID>A1<NAME>INSURANCE CO\n</BANKTRANLIST><LEDGERBAL><BALAMT>-45.50<DTASOF>${day(-3).replace(/-/g, '')}</LEDGERBAL></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>`;
assert.match(tick(['reconcile', 'upload', ofxAcc, 'bank.qfx'], ofx), /1 transactions read from bank\.qfx, 1 entries reconciled/);
assert.equal(Number(json('reconcile', 'files', ofxAcc)[0].end_balance), -45.5);

step('a statement PDF: sections give the sign, checks two to a line, the year from the period, the ending balance');
// A small PDF laid out like Central Bank's statements, its page compressed.
function pdf(rows) {
  const esc = (t) => t.replace(/[\\()]/g, (c) => `\\${c}`);
  const content = Buffer.from(rows.map(([x, y, t]) => `BT /F1 9 Tf ${x} ${y} Td (${esc(t)}) Tj ET`).join('\n'));
  const z = deflateSync(content);
  const objs = [
    Buffer.from('<< /Type /Catalog /Pages 2 0 R >>'),
    Buffer.from('<< /Type /Pages /Kids [3 0 R] /Count 1 >>'),
    Buffer.from('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>'),
    Buffer.concat([Buffer.from(`<< /Length ${z.length} /Filter /FlateDecode >>\nstream\n`), z, Buffer.from('\nendstream')]),
    Buffer.from('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'),
  ];
  const parts = [Buffer.from('%PDF-1.4\n')];
  const offsets = [];
  let at = parts[0].length;
  objs.forEach((o, i) => {
    const b = Buffer.concat([Buffer.from(`${i + 1} 0 obj\n`), o, Buffer.from('\nendobj\n')]);
    offsets.push(at);
    parts.push(b);
    at += b.length;
  });
  const xref = `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n${offsets.map((o) => `${String(o).padStart(10, '0')} 00000 n \n`).join('')}trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${at}\n%%EOF\n`;
  return Buffer.concat([...parts, Buffer.from(xref)]);
}
const short = (n) => { const d = new Date(`${day(n)}T12:00:00Z`); return `${d.toLocaleString('en-US', { month: 'short', timeZone: 'UTC' })}. ${String(d.getUTCDate()).padStart(2, '0')}`; };
const long = (n) => new Date(`${day(n)}T12:00:00Z`).toLocaleString('en-US', { month: 'long', day: 'numeric', year: 'numeric', timeZone: 'UTC' });
const mdy = (n) => { const [y, m, d] = day(n).split('-'); return `${m}/${d}/${y}`; };
let y = 760;
const row = (...cells) => { y -= 14; return cells.map((t, i) => [40 + i * 120, y, t]); };
const statement = pdf([
  ...row('COLUMBIA GADGET WORKS', 'Period', `${mdy(-20)} - ${mdy(-1)}`),
  ...row('Small Business Checking'),
  ...row('No. 001', 'Beginning Balance', long(-21), '$', '1,000.00'),
  ...row('Deposits'),
  ...row(short(-15), 'PAYPAL TRANSFER', '310.67'),
  ...row('MORE DETAIL ON THE NEXT LINE'),
  ...row('Total', '+$', '310.67'),
  ...row('Checks'),
  ...row('Check No.', 'Paid', 'Amount', 'Check No.', 'Paid', 'Amount'),
  ...row('1201', short(-12), '650.00', '1202', short(-11), '25.00'),
  ...row('Total', '-$', '675.00'),
  ...row('Withdrawals and other charges'),
  ...row(short(-9), 'MEETUP ORG ULTD NEW YO', '89.94'),
  ...row('Total', '-$', '89.94'),
  ...row('Ending Balance', long(-1), '$', '545.73'),
  ...row('Low ledger balance', '500.00'),
]);
const pdfAcc = account(`PDF R${stamp}`);
line(pdfAcc, -30, 1000, 'Opening balance');
const transfer = line(pdfAcc, -14, 310.67, 'PayPal transfer');
line(pdfAcc, -12, -650, 'Rent, check 1201');
line(pdfAcc, -11, -25, 'Check 1202');
line(pdfAcc, -9, -89.94, 'Meetup');
assert.match(tick(['reconcile', 'upload', pdfAcc, 'statement.pdf'], statement), /4 transactions read from statement\.pdf, 4 entries reconciled\./);
assert.equal(json('reconcile', 'open', pdfAcc).length, 0);
const pf = json('reconcile', 'files', pdfAcc)[0];
assert.equal(Number(pf.end_balance), 545.73, 'the ending balance from the PDF');
assert.equal(pf.end_date, day(-1));
assert.equal(lines(pdfAcc).find((l) => l.rowid === transfer).num_releve, day(-15).slice(0, 7).replace('-', ''));
assert.match(tick(['reconcile', 'upload', pdfAcc, 'scan.pdf'], pdf([])), /no transactions found in the PDF/);

step('lines from before the books are set aside; a fee the donor covered is no fee');
const gb = account(`Givebutter R${stamp}`);
line(gb, -20, 0.01, 'Opening balance');
line(gb, -10, 40, 'Dues, fee covered');
line(gb, -9, 25, 'Donation gross');
line(gb, -9, -1.05, 'Givebutter fee');
line(gb, -8, 50, 'Dues, fee not taken');
const gbCsv = [
  '"Campaign Title","Reference Number","First Name","Last Name","Status Friendly","Amount","Fee","Fee Covered","Donated","Payout","Transaction Date (UTC)"',
  `"CGW Membership","111","Old","Member","Succeeded","$40.00","$1.50","$1.50","$40.00","$40.00","${day(-400)} 10:00:00"`,
  `"One Time Donation","112","Old","Donor","Succeeded","$700.00","$21.22","","$700.00","$678.78","${day(-390)} 10:00:00"`,
  `"CGW Membership","113","Ann","Covered","Succeeded","$40.00","$1.50","$1.50","$40.00","$40.00","${day(-10)} 10:00:00"`,
  `"One Time Donation","114","Bob","Gives","Succeeded","$25.00","$1.05","","$25.00","$23.95","${day(-9)} 10:00:00"`,
  `"CGW Membership","115","Cy","Full","Succeeded","$50.00","$1.80","","$50.00","$50.00","${day(-8)} 10:00:00"`,
].join('\n');
assert.match(tick(['reconcile', 'upload', gb, 'givebutter.csv'], gbCsv), /5 transactions read from givebutter\.csv, 4 entries reconciled\./);
assert.equal(json('reconcile', 'open', gb).length, 0, 'no fee line for the covered fee, nor where the payout was the full amount; the old lines set aside');
assert.equal(json('reconcile', 'aside', gb).before, 3, 'two old payments and the old fee');
assert.equal(json('reconcile', 'missing', gb).length, 0, 'the opening balance is not a missing transaction');

step('the same month from two exports: the second is a duplicate; an entry reconciled by hand is found');
const dup = account(`Dup R${stamp}`);
line(dup, -30, 500, 'Opening balance');
line(dup, -6, -75, 'Electric');
const byHand = line(dup, -5, -19.99, 'Software');
assert.match(tick(['reconcile', 'force', byHand]), /Reconciled/);
tick(['reconcile', 'upload', dup, 'month.csv'], `Date,Description,Amount,Balance\n${day(-6)},ELECTRIC CO,-75.00,425.00\n`);
const again = `OFXHEADER:100\n<OFX><STMTTRN><DTPOSTED>${day(-6).replace(/-/g, '')}<TRNAMT>-75.00<FITID>E1<NAME>ELECTRIC</STMTTRN><STMTTRN><DTPOSTED>${day(-5).replace(/-/g, '')}<TRNAMT>-19.99<FITID>S1<NAME>SOFTWARE</STMTTRN></OFX>`;
tick(['reconcile', 'upload', dup, 'month.qfx'], again);
assert.equal(json('reconcile', 'open', dup).length, 0);
assert.deepEqual(json('reconcile', 'aside', dup), { already: 1, duplicate: 1 });

step('a statement against Dolibarr: where a difference comes from');
const cmpAcc = account(`Compare R${stamp}`);
line(cmpAcc, -40, 1000, 'Opening balance, typed wrong');
line(cmpAcc, -8, 200, 'Deposit');
const cmpCsv = `Date,Description,Withdrawals,Deposits,Balance\n${day(-4)},CHECK 1186,1142.00,,8.00\n${day(-8)},DEPOSIT,,200.00,1150.00\n`;
tick(['reconcile', 'upload', cmpAcc, 'compare.csv'], cmpCsv);
const cf = json('reconcile', 'files', cmpAcc)[0];
assert.equal(Number(cf.begin_balance), 950, 'the balance before the first line');
const cmp = json('reconcile', 'compare', cf.rowid);
assert.deepEqual(cmp.stmt, { begin: 950, in: 200, out: 1142, end: 8 });
assert.deepEqual(cmp.doli, { begin: 1000, in: 200, out: 0, end: 1200 }, 'Dolibarr: 50 too high at the start, the check never entered');
const check = json('reconcile', 'open', cmpAcc)[0];
assert.match(tick(['reconcile', 'why', check.rowid]), /Nothing like it in Dolibarr/);

step('entries typed as one total: a month of $1 sodas and their fees, card purchases paid in one go');
const vm = account(`Venmo R${stamp}`);
line(vm, -30, 0.01, 'Opening balance');
line(vm, -3, 4, 'Sodas, by the month');
line(vm, -3, -0.44, 'Venmo fees, by the month');
const venmo = ['Account Statement - (@Columbia-Gadget-Works)', 'Account Activity',
  ',ID,Datetime,Type,Status,Note,From,To,Amount (total),Amount (tip),Amount (fee)',
  ...[-10, -8, -6, -4].map((n, i) => `,${stamp}${i},${day(n)}T15:00:00,Payment,Complete,Soda,Someone,Columbia Gadget Works,+ $1.00,,- $0.11`)].join('\n');
assert.match(tick(['reconcile', 'upload', vm, 'venmo.csv'], venmo), /4 transactions read from venmo\.csv/);
assert.equal(json('reconcile', 'open', vm).length, 0, 'every soda and every fee matched to its total');
assert.equal(json('reconcile', 'aside', vm).lump, 8);
const cardL = account(`Card lump R${stamp}`);
line(cardL, -40, 0.01, 'Opening balance');
const lump = line(cardL, -2, -77.72, 'Card charges paid by the bank payment');
const cardLump = `Date,Name,Amount\n${day(-5)},WESTLAKE,-7.10\n${day(-20)},AMAZON A,-7.99\n${day(-20)},AMAZON B,-46.36\n${day(-20)},AMAZON C,-5.39\n${day(-25)},AMAZON D,-17.98\n`;
tick(['reconcile', 'upload', cardL, 'card.csv'], cardLump);
assert.deepEqual(json('reconcile', 'open', cardL).map((r) => Number(r.amount)), [-7.1], 'the four that add up to it are matched; the new one is left');
assert.equal(Number(lines(cardL).find((l) => l.rowid === lump).rappro), 1);

step('a transfer between our own accounts: reconciled on one statement, the other side follows');
const bankT = account(`Bank T R${stamp}`);
const gbT = account(`Wallet T R${stamp}`);
line(bankT, -30, 0.01, 'Opening balance');
line(gbT, -30, 1000, 'Opening balance');
const [outLine, inLine] = last(tick(['reconcile', 'transfer', gbT, bankT, day(-6), '700'])).split(' ');
tick(['reconcile', 'upload', bankT, 'bank-t.csv'], `Date,Description,Amount\n${day(-5)},GIVEBUTTER PAYOUT,700.00\n`);
assert.equal(Number(lines(bankT).find((l) => l.rowid === inLine).rappro), 1, 'the bank side, from the bank statement');
tick(['reconcile', 'daily']);
assert.equal(Number(lines(gbT).find((l) => l.rowid === outLine).rappro), 1, 'the wallet side, because the bank side is');

step('a payment entered twice: the copy the statement did not match is offered as a double entry');
const tw = account(`Twin R${stamp}`);
line(tw, -40, 0.01, 'Opening balance');
line(tw, -12, 10, 'Something');
const byHandDues = line(tw, -5, 40, 'Dues typed by hand');
const synced = line(tw, -5, 40, 'Dues 2026-10 Ann (from the sync)');
const twMember = last(tick(['purchase', 'member', `twin-${stamp}@example.test`, `CGW-T${String(stamp).slice(-5)}`, 'Twin', 'Member']));
tick(['reconcile', 'link', synced, twMember]);
line(tw, -1, 5, 'Other');
tick(['reconcile', 'upload', tw, 'twin.csv'], `Date,Description,Amount\n${day(-12)},X,10.00\n${day(-5)},DUES,40.00\n${day(-1)},Y,5.00\n`);
assert.deepEqual(json('reconcile', 'missing', tw).map((l) => l.rowid), [synced]);
assert.match(tick(['reconcile', 'twin', synced]), /Double entry fixed/);
const twl = lines(tw);
assert.ok(!twl.some((l) => l.rowid === byHandDues), 'the hand copy, linked to nothing, is deleted');
assert.equal(Number(twl.find((l) => l.rowid === synced).rappro), 1, 'the copy linked to the member is kept and reconciled');
assert.equal(json('reconcile', 'missing', tw).length, 0);

step('a file read again keeps its sign, though its entries are reconciled by then');
const again2 = account(`Again R${stamp}`);
line(again2, -30, 0.01, 'Opening balance');
line(again2, -6, 50, 'Dues A');
line(again2, -4, 50, 'Dues B');
line(again2, -3, -700, 'Payout, entered as a transfer would be');
const againCsv = `Date,Description,Amount\n${day(-6)},CGW Membership,50.00\n${day(-4)},CGW Membership,50.00\n${day(-2)},CGW Membership,50.00\n`;
tick(['reconcile', 'upload', again2, 'again.csv'], againCsv);
tick(['reconcile', 'upload', again2, 'again.csv'], againCsv);
assert.deepEqual(json('reconcile', 'open', again2).map((r) => Number(r.amount)), [50], 'still +50, the one nobody entered');

step('a lump is never a transfer or a payout');
const tr2 = account(`Transfer lump R${stamp}`);
const bank2 = account(`Transfer bank R${stamp}`);
line(tr2, -30, 1000, 'Opening balance');
line(bank2, -30, 0.01, 'Opening balance');
const [payOut] = last(tick(['reconcile', 'transfer', tr2, bank2, day(-3), '100'])).split(' ');
tick(['reconcile', 'upload', tr2, 'tr2.csv'], `Date,Description,Amount\n${day(-5)},Refund,-50.00\n${day(-4)},Refund,-50.00\n`);
assert.equal(Number(lines(tr2).find((l) => l.rowid === payOut).rappro), 0, 'two -50s are not the -100 transfer');

step('undo an upload: what it reconciled is unreconciled again');
const und = account(`Undo R${stamp}`);
line(und, -30, 0.01, 'Opening balance');
const undLine = line(und, -5, 12.34, 'Something');
tick(['reconcile', 'upload', und, 'undo.csv'], `Date,Description,Amount\n${day(-5)},SOMETHING,12.34\n`);
assert.equal(Number(lines(und).find((l) => l.rowid === undLine).rappro), 1);
assert.match(tick(['reconcile', 'undo', json('reconcile', 'files', und)[0].rowid]), /1 entries are unreconciled again/);
assert.equal(Number(lines(und).find((l) => l.rowid === undLine).rappro), 0);
assert.equal(json('reconcile', 'files', und).length, 0);

step('dues recorded twice by the sync: the second record goes with its entry');
const tw2 = account(`Twin2 R${stamp}`);
line(tw2, -40, 0.01, 'Opening balance');
line(tw2, -12, 10, 'Something');
line(tw2, -1, 5, 'Other');
const twoMember = last(tick(['purchase', 'member', `twin2-${stamp}@example.test`, `CGW-U${String(stamp).slice(-5)}`, 'Twice', 'Recorded']));
const copyA = line(tw2, -5, 40, 'Dues 2026-10 Twice Recorded');
const copyB = line(tw2, -5, 40, 'Dues 2026-10 Twice Recorded');
tick(['reconcile', 'link', copyA, twoMember]);
tick(['reconcile', 'link', copyB, twoMember]);
tick(['pp', 'importsub', twoMember, '5']);
// The same dues recorded twice, as happened live (Dolibarr's own path refuses a second one).
sql(`INSERT INTO llx_subscription (datec, fk_adherent, fk_type, dateadh, datef, subscription, note) SELECT datec, fk_adherent, fk_type, DATE_ADD(dateadh, INTERVAL 8 DAY), datef, subscription, note FROM llx_subscription WHERE fk_adherent = ${Number(twoMember)}`);
const subs2 = sql(`SELECT rowid FROM llx_subscription WHERE fk_adherent = ${Number(twoMember)} ORDER BY rowid`).split('\n').map(Number);
assert.equal(subs2.length, 2);
sql(`UPDATE llx_subscription SET fk_bank = ${Number(copyA)} WHERE rowid = ${subs2[0]}`);
sql(`UPDATE llx_subscription SET fk_bank = ${Number(copyB)} WHERE rowid = ${subs2[1]}`);
tick(['reconcile', 'upload', tw2, 'tw2.csv'], `Date,Description,Amount\n${day(-12)},X,10.00\n${day(-5)},DUES,40.00\n${day(-1)},Y,5.00\n`);
const loose = json('reconcile', 'missing', tw2).map((l) => l.rowid);
assert.equal(loose.length, 1);
assert.match(tick(['reconcile', 'twin', loose[0]]), /Double entry fixed.*recorded twice on the member card/);
assert.equal(sql(`SELECT COUNT(*) FROM llx_subscription WHERE fk_adherent = ${Number(twoMember)}`), '1');
assert.equal(lines(tw2).filter((l) => Number(l.amount) === 40).length, 1);

step('rules: fees, soda, a bank charge entered by themselves; a transfer to the bank; undo');
tick(['purchase', 'const', 'ONBOARDING_RECONCILE_RULES', `Fee: => 6300 Processing fees\nSERVICE CHARGE => 6310 Bank fees\n[Rules R] in: soda => 4900 Other income (soda)\n[PPRule] out: transfer to the bank => transfer RulesBank R${stamp}`]);
const ru = account(`Rules R${stamp}`);
line(ru, -30, 0.01, 'Opening balance');
const ruCsv = `Date,Description,Amount,Fee\n${day(-6)},Soda,1.00,-0.11\n${day(-5)},MONTHLY SERVICE CHARGE,-1.50,\n${day(-4)},Something else,-9.99,\n`;
tick(['reconcile', 'upload', ru, 'rules.csv'], ruCsv);
assert.deepEqual(json('reconcile', 'open', ru).map((r) => Number(r.amount)), [-9.99], 'only the line no rule fits waits');
const ruled = json('reconcile', 'rules', ru);
assert.deepEqual(ruled.map((r) => r.accountancy_code).sort(), ['4900', '6300', '6310']);
assert.ok(lines(ru).filter((l) => Number(l.rappro) === 1).length >= 3, 'entered and reconciled');
const soda = ruled.find((r) => r.accountancy_code === '4900');
assert.match(tick(['reconcile', 'unrule', soda.rowid]), /Taken back/);
tick(['reconcile', 'daily']);
assert.deepEqual(json('reconcile', 'open', ru).map((r) => Number(r.amount)).sort(), [-9.99, 1], 'taken back, and not entered again');
const ppr = account(`PPRule R${stamp}`);
const rb = account(`RulesBank R${stamp}`);
line(ppr, -30, 500, 'Opening balance');
line(rb, -30, 0.01, 'Opening balance');
tick(['reconcile', 'upload', ppr, 'ppr.csv'], `Date,Description,Amount\n${day(-4)},PayPal transfer to the bank,-38.11\n`);
assert.equal(json('reconcile', 'open', ppr).length, 0);
assert.ok(lines(rb).some((l) => Number(l.amount) === 38.11), 'the bank side of the transfer is made');
tick(['reconcile', 'upload', rb, 'rb.csv'], `Date,Description,Amount\n${day(-3)},PAYPAL TRANSFER,38.11\n`);
assert.equal(json('reconcile', 'open', rb).length, 0, 'and the bank statement finds it');
tick(['purchase', 'const', 'ONBOARDING_RECONCILE_RULES', '# no rules while matching is tested']);

step('a file that is not a statement is refused');
assert.match(tick(['reconcile', 'upload', chk, 'notes.csv'], 'hello,world\n1,2\n'), /no transactions found/);
assert.match(tick(['reconcile', 'upload', chk, 'virus.exe'], 'MZ'), /not a statement export/);

step('PayPal from PayPal: its entries from the sync are reconciled');
tick(['purchase', 'const', 'ONBOARDING_PAYPAL_CLIENT_ID', 'sandbox-paypal-id']);
tick(['purchase', 'const', 'ONBOARDING_PAYPAL_SECRET', 'sandbox-paypal-secret']);
const ppAccount = sql("SELECT rowid FROM llx_bank_account WHERE ref = 'PAYPAL'");
sql(`UPDATE llx_bank_account SET rappro = 1 WHERE rowid = ${Number(ppAccount)}`);
// A payment in PayPal and its entry in Dolibarr, made by hand.
const amount = (17 + (stamp % 1000) / 100).toFixed(2);
await (await fetch(`${MOCK}/control/paypal`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ amount, fee: 0.5, days_ago: 2, code: 'T0006', email: `rec-${stamp}@example.test`, given_name: 'Rita' }) })).json();
// The treasurer typed a member's PayPal dues in before the sync saw them: the sync links it, no second copy.
const deeEmail = `pp-dee-${stamp}@example.test`;
last(tick(['purchase', 'member', deeEmail, `CGW-D${String(stamp).slice(-5)}`, 'Dee', `Typed${stamp}`]));
const typed = line(ppAccount, -1, 40, `Dee ${stamp} dues, typed in`);
await (await fetch(`${MOCK}/control/paypal`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ amount: 40, fee: 1.89, days_ago: 1, email: deeEmail, given_name: 'Dee', surname: `Typed${stamp}`, payer_id: `DEE${stamp}` }) })).json();
tick(['pp', 'sync']);
const deeLines = lines(ppAccount).filter((l) => l.label.includes(String(stamp)) && /Dee/.test(l.label) && Number(l.amount) === 40);
assert.deepEqual(deeLines.map((l) => l.rowid), [typed], 'no second copy of the dues');
assert.equal(sql(`SELECT COUNT(*) FROM llx_subscription WHERE fk_bank = ${Number(typed)}`), '1', 'the typed entry is the subscription\'s');
const gross = line(ppAccount, -2, amount, 'Sale, entered by hand');
const feeLine = line(ppAccount, -2, -0.5, 'PayPal fee');
const out = tick(['reconcile', 'paypal']);
assert.match(out, /PayPal: \d+ transactions read, \d+ entries reconciled/);
const pl = Object.fromEntries(lines(ppAccount).map((l) => [l.rowid, l]));
assert.equal(Number(pl[gross].rappro), 1, `the payment is reconciled (${out})`);
assert.equal(Number(pl[feeLine].rappro), 1, 'and its fee');
assert.match(tick(['reconcile', 'daily']), /PayPal/);
tick(['purchase', 'const', 'ONBOARDING_PAYPAL_CLIENT_ID', '']);
tick(['purchase', 'const', 'ONBOARDING_PAYPAL_SECRET', '']);

tick(['purchase', 'const', 'ONBOARDING_RECONCILE_RULES', '']);
console.log('\nAll reconciling checks passed');
