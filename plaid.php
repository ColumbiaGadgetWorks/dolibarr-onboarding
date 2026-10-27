<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Bank feeds: connect the organization's own bank, card and payment accounts
 * through Plaid, choose which Dolibarr account each one feeds, and see each
 * one's balance at the bank against Dolibarr's. Also the page Plaid sends a
 * bank's own login (OAuth, e.g. US Bank) back to: register its address in the
 * Plaid dashboard (Allowed redirect URIs) and on the setup page.
 */

// Plaid Link's calls from the page keep the page's token, so the next one works too.
if (isset($_POST['action']) && in_array($_POST['action'], array('linktoken', 'exchange', 'reconnected'), true) && !defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}

$res = 0;
foreach (array('../main.inc.php', '../../main.inc.php', '../../../main.inc.php') as $path) {
	if (!$res && file_exists(__DIR__.'/'.$path)) {
		$res = @include __DIR__.'/'.$path;
	}
}
if (!$res) {
	die('Include of main fails');
}

dol_include_once('/onboarding/class/plaid.class.php');

if (!isModEnabled('onboarding') || !$user->hasRight('banque', 'lire')) {
	accessforbidden();
}
// Connecting a bank hands Dolibarr read access to it: administrators only.
$canwrite = !empty($user->admin);
$plaid = new OnboardingPlaid($db);
$action = GETPOST('action', 'aZ09');

// Plaid Link's calls from the page's script: answered as JSON.
if ($canwrite && in_array($action, array('linktoken', 'exchange', 'reconnected'), true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
	header('Content-Type: application/json');
	if ($action == 'linktoken') {
		$t = $plaid->linkToken($user, (int) GETPOST('item', 'int'));
		print json_encode($t !== '' ? array('link_token' => $t) : array('error' => $plaid->error));
	} elseif ($action == 'exchange') {
		$msg = $plaid->connect(GETPOST('public_token', 'alphanohtml'), GETPOST('institution', 'alphanohtml'));
		setEventMessages($msg, null, strpos($msg, 'Error') === 0 ? 'errors' : 'mesgs');
		print json_encode(array('ok' => strpos($msg, 'Error') !== 0));
	} else {
		$id = (int) GETPOST('item', 'int');
		$db->query("UPDATE ".$db->prefix()."onboarding_plaid_item SET status = 'ok', last_error = NULL WHERE rowid = ".$id);
		$plaid->refreshAccounts($id);
		setEventMessages('Reconnected. '.$plaid->sync(), null);
		print json_encode(array('ok' => true));
	}
	$db->close();
	exit;
}
if ($canwrite && in_array($action, array('map', 'sync', 'disconnect'), true)) {
	if ($action == 'map') {
		foreach ((array) GETPOST('feeds', 'array') as $acc => $bank) {
			$plaid->map((int) $acc, (int) $bank);
		}
		setEventMessages('Saved. New transactions go to the accounts chosen; press Read now to read them straight away.', null);
	} elseif ($action == 'sync') {
		$out = $plaid->sync();
		setEventMessages($out, null, strpos($out, 'failed') !== false ? 'warnings' : 'mesgs');
	} else {
		setEventMessages($plaid->disconnect((int) GETPOST('item', 'int')), null);
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

llxHeader('', 'Bank feeds');
print load_fiche_titre('Bank feeds', '', 'bank_account');
print '<p class="opacitymedium">The organization\'s own bank, card and payment accounts send their transactions here through Plaid, every hour. They go to <a href="'.dol_buildpath('/onboarding/reconcile.php', 1).'">Reconcile bank accounts</a>: matched to the entries already in Dolibarr, entered by the rules when nobody typed them, and listed for a person when neither. Connecting opens Plaid\'s own window, where the bank login is typed; Dolibarr never sees it.</p>';

if (!OnboardingPlaid::configured()) {
	print '<div class="warning">Plaid is not set up: enter the Client ID and secret on the <a href="'.dol_buildpath('/onboarding/admin/setup.php', 1).'">setup page</a> (Bank feeds), from the Plaid dashboard, Developers, Keys.</div>';
	llxFooter();
	$db->close();
	exit;
}
print '<div class="'.(OnboardingPlaid::env() == 'production' ? 'ok' : 'warning').'">Plaid '.(OnboardingPlaid::env() == 'production' ? 'production: real banks.' : 'sandbox: test banks only (user_good / pass_good). Do not feed a real Dolibarr account from it; make a test account, and disconnect when done.').'</div>';

// Dolibarr's balance per account, to set against the bank's.
$ours = array();
$res = $db->query("SELECT fk_account, SUM(amount) AS s FROM ".$db->prefix()."bank GROUP BY fk_account");
while ($res && ($o = $db->fetch_object($res))) {
	$ours[(int) $o->fk_account] = round((float) $o->s, 2);
}
$rec = new OnboardingReconcile($db);
$banks = $rec->accounts();
$items = $plaid->items();

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="map">';
print '<table class="noborder centpercent"><tr class="liste_titre"><td>Bank and account</td><td>Feeds</td><td class="right">At the bank</td><td class="right">In Dolibarr</td><td class="right">Difference</td><td></td></tr>';
foreach ($items as $it) {
	$state = $it->status == 'ok' ? '<span class="badge badge-status4">connected</span>' : ($it->status == 'login' ? '<span class="badge badge-status8">the bank wants the login again</span>' : '<span class="badge badge-status8">failing</span>');
	print '<tr class="liste_titre"><td colspan="5">'.dol_escape_htmltag($it->institution).' '.$state.' <span class="opacitymedium small">'.($it->last_sync ? 'read '.dol_print_date($db->jdate($it->last_sync), 'dayhour') : 'not read yet').($it->last_error ? ' &middot; '.dol_escape_htmltag($it->last_error) : '').'</span></td><td class="right nowraponall">';
	if ($canwrite) {
		if ($it->status != 'ok') {
			print '<button type="button" class="butAction smallpaddingimp" onclick="onbPlaid('.((int) $it->rowid).')">Reconnect</button> ';
		}
		print '<button type="submit" form="dis-'.((int) $it->rowid).'" class="butActionDelete smallpaddingimp" onclick="return confirm(\'Disconnect '.dol_escape_js($it->institution).'? Plaid stops reading it and its access is deleted. What it already fed stays in the books.\')">Disconnect</button>';
	}
	print '</td></tr>';
	foreach ($it->accounts as $a) {
		// A card's balance at the bank is what is owed: negative in Dolibarr.
		$owed = in_array($a->type, array('credit', 'loan'), true);
		$bankBal = $a->balance !== null ? ($owed ? -1 : 1) * (float) $a->balance : null;
		$fed = (int) $a->fk_bank_account;
		print '<tr class="oddeven"><td>'.dol_escape_htmltag($a->name).($a->mask ? ' &bull;&bull;'.dol_escape_htmltag($a->mask) : '').' <span class="opacitymedium small">'.dol_escape_htmltag($a->subtype ?: $a->type).'</span></td><td>';
		if ($canwrite) {
			print '<select name="feeds['.((int) $a->rowid).']" class="minwidth200"><option value="0">(not fed)</option>';
			foreach ($banks as $b) {
				print '<option value="'.((int) $b->rowid).'"'.($fed === (int) $b->rowid ? ' selected' : '').'>'.dol_escape_htmltag($b->label).'</option>';
			}
			print '</select>';
		} else {
			print $fed && isset($banks[$fed]) ? dol_escape_htmltag($banks[$fed]->label) : '<span class="opacitymedium">not fed</span>';
		}
		print '</td><td class="right">'.($bankBal !== null ? price($bankBal) : '').'</td><td class="right">'.($fed && isset($ours[$fed]) ? price($ours[$fed]) : '').'</td><td class="right">';
		if ($fed && $bankBal !== null && isset($ours[$fed])) {
			$d = round($ours[$fed] - $bankBal, 2);
			print abs($d) < 0.005 ? '<span class="ok">&#10003;</span>' : '<span class="error">'.price($d).'</span>';
		}
		print '</td><td></td></tr>';
	}
}
if (!$items) {
	print '<tr><td colspan="6" class="opacitymedium">No banks connected yet.</td></tr>';
}
print '</table>';
if ($canwrite && $items) {
	print '<div class="center"><button type="submit" class="button button-save">Save</button></div>';
}
print '</form>';
foreach ($items as $it) {
	print '<form id="dis-'.((int) $it->rowid).'" method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="disconnect"><input type="hidden" name="item" value="'.((int) $it->rowid).'"></form>';
}
print '<p class="opacitymedium small">The difference is Dolibarr\'s balance less the bank\'s, today. It is zero once every transaction is in Dolibarr; transactions still pending at the bank make a short-lived difference.</p>';

if ($canwrite) {
	print '<div class="tabsAction" style="text-align:left"><a class="butAction" href="#" onclick="onbPlaid(0); return false;">Connect a bank</a>';
	print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=sync&token='.newToken().'">Read now</a></div>';
	$self = dol_buildpath('/onboarding/plaid.php', 1);
	$tok = newToken();
	?>
<script src="https://cdn.plaid.com/link/v2/stable/link-initialize.js"></script>
<script>
// Plaid Link: a link token from Dolibarr, Plaid's window, then the result back to Dolibarr.
function onbPost(action, data) {
	var body = new URLSearchParams(Object.assign({ action: action, token: <?php print json_encode($tok); ?> }, data || {}));
	return fetch(<?php print json_encode($self); ?>, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) { return r.json(); });
}
function onbOpen(linkToken, item, received) {
	var config = {
		token: linkToken,
		onSuccess: function (publicToken, meta) {
			try { localStorage.removeItem('onbPlaidLink'); } catch (e) {}
			var p = item ? onbPost('reconnected', { item: item }) : onbPost('exchange', { public_token: publicToken, institution: (meta && meta.institution && meta.institution.name) || 'Bank' });
			p.then(function () { window.location = <?php print json_encode($self); ?>; });
		},
		onExit: function (err) { if (err) { alert('Plaid: ' + (err.display_message || err.error_message || err.error_code)); } }
	};
	if (received) { config.receivedRedirectUri = received; }
	Plaid.create(config).open();
}
function onbPlaid(item) {
	onbPost('linktoken', { item: item }).then(function (r) {
		if (!r.link_token) { alert(r.error || 'Plaid could not start.'); return; }
		// Kept for a bank whose own login (OAuth) sends the browser away and back.
		try { localStorage.setItem('onbPlaidLink', JSON.stringify({ token: r.link_token, item: item })); } catch (e) {}
		onbOpen(r.link_token, item, null);
	});
}
// Back from a bank's own login: carry on where Plaid Link left off.
if (window.location.search.indexOf('oauth_state_id=') !== -1) {
	try {
		var saved = JSON.parse(localStorage.getItem('onbPlaidLink') || 'null');
		if (saved) { onbOpen(saved.token, saved.item, window.location.href); }
	} catch (e) {}
}
</script>
	<?php
}

llxFooter();
$db->close();
