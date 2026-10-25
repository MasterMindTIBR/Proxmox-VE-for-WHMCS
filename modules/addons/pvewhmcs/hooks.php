<?php

/*  
    Proxmox VE for WHMCS - Addon/Server Modules for WHMCS (& PVE)
    https://github.com/MasterMindTIBR/Proxmox-VE-for-WHMCS/ (MasterMind TI fork)
    Upstream: https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/
    File: /modules/addons/pvewhmcs/hooks.php (WHMCS Hooks)

    Copyright (C) The Network Crew Pty Ltd (TNC) & Co.
    Modified since 2026-09-25 by MasterMind TI (https://mastermindti.com.br).

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <https://www.gnu.org/licenses/>. 
*/

if (!defined("WHMCS"))
    die("This file cannot be accessed directly");

use WHMCS\Database\Capsule;

// Cancellation policy (all products): _docs/POLITICA-CANCELAMENTO.md

function pvewhmcs_hook_is_pt() {
	$lang = (string) ($_SESSION['Language'] ?? '');
	if ($lang === '' && !empty($_SESSION['uid'])) {
		$lang = (string) Capsule::table('tblclients')->where('id', (int) $_SESSION['uid'])->value('language');
	}
	if ($lang === '') {
		$lang = (string) Capsule::table('tblconfiguration')->where('setting', 'Language')->value('value');
	}

	return stripos($lang, 'portuguese') === 0;
}

// Rewriting the request is safe: WHMCS only executes cancellations in the daily cron.
// localAPI with an adminuser also sets adminid (CLI, API), so only the admin area keeps Immediate.
add_hook('CancellationRequest', 1, function ($vars) {
	if ((defined('ADMINAREA') && !empty($_SESSION['adminid'])) || ($vars['type'] ?? '') === 'End of Billing Period') {
		return;
	}
	$serviceId = (int) ($vars['relid'] ?? 0);
	$request = Capsule::table('tblcancelrequests')->where('relid', $serviceId)->orderBy('id', 'desc')->first();
	if ($request === null || $request->type === 'End of Billing Period') {
		return;
	}
	Capsule::table('tblcancelrequests')->where('id', $request->id)->update(array('type' => 'End of Billing Period'));
	logActivity('Cancellation request changed from Immediate to End of Billing Period (only the admin area cancels immediately) - Service ID: ' . $serviceId, (int) ($vars['userid'] ?? 0));
});

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
	if (($vars['templatefile'] ?? '') !== 'clientareacancelrequest') {
		return '';
	}

	return '<script>document.querySelectorAll(\'select[name="type"] option[value="Immediate"]\').forEach(function (o) { var s = o.parentNode; o.remove(); s.value = "End of Billing Period"; });</script>';
});

function pvewhmcs_hook_pending_cancellation($serviceId) {
	$clientId = (int) ($_SESSION['uid'] ?? 0);
	if ($clientId < 1 || $serviceId < 1) {
		return null;
	}
	$service = Capsule::table('tblhosting')->where('id', $serviceId)->where('userid', $clientId)->first(array('id', 'userid', 'domainstatus', 'nextduedate'));
	if ($service === null || !in_array($service->domainstatus, array('Active', 'Suspended'), true)) {
		return null; // already processed by the cron (Cancelled) or never active
	}
	$request = Capsule::table('tblcancelrequests')->where('relid', $serviceId)->where('type', 'End of Billing Period')->orderBy('id', 'desc')->first();

	return $request === null ? null : array($service, $request);
}

// The WHMCS log line is the only link between a request and the invoice it cancelled.
function pvewhmcs_hook_request_invoices($serviceId, $request) {
	$at = strtotime((string) $request->date);
	// Same HTTP request as the cancellation (seconds apart), and never before an earlier withdrawal.
	$after = (int) Capsule::table('tblactivitylog')->where('description', 'like', 'Client withdrew the cancellation request % - Service ID: ' . (int) $serviceId)->max('id');
	$logs = Capsule::table('tblactivitylog')
		->where('id', '>', $after)
		->where('description', 'like', 'Cancelled Outstanding Product Renewal Invoice - Invoice ID: % - Service ID: ' . (int) $serviceId)
		->whereBetween('date', array(date('Y-m-d H:i:s', $at - 5), date('Y-m-d H:i:s', $at + 10)))
		->orderBy('id')->get(array('id', 'description'));
	$reopen = array();
	$review = array();
	foreach ($logs as $log) {
		if (!preg_match('/Invoice ID: (\d+) - Service ID: /', $log->description, $m)) {
			continue;
		}
		$invoiceId = (int) $m[1];
		$touched = Capsule::table('tblactivitylog')->where('id', '>', $log->id)->where('description', 'like', '%Invoice ID: ' . $invoiceId . '%')->pluck('description')->all();
		$touched = preg_grep('/Invoice ID: ' . $invoiceId . '\b/', $touched);
		$status = Capsule::table('tblinvoices')->where('id', $invoiceId)->lock('for update')->value('status');
		if ($status === 'Cancelled' && empty($touched)) {
			$reopen[] = $invoiceId;
		} else {
			$review[] = $invoiceId;
		}
	}

	return array($reopen, $review);
}

add_hook('ClientAreaPageProductDetails', 1, function ($vars) {
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_POST['pvewhmcs_undo_cancellation'])) {
		return;
	}
	check_token();
	$serviceId = (int) ($_GET['id'] ?? 0);
	try {
		Capsule::connection()->transaction(function () use ($serviceId) {
			// Locking the service row serialises this with the cancellation cron.
			Capsule::table('tblhosting')->where('id', $serviceId)->lock('for update')->value('id');
			$pending = pvewhmcs_hook_pending_cancellation($serviceId);
			if ($pending === null) {
				return;
			}
			list($service, $request) = $pending;
			list($reopen, $review) = pvewhmcs_hook_request_invoices($serviceId, $request);
			foreach ($reopen as $invoiceId) {
				$result = localAPI('UpdateInvoice', array('invoiceid' => $invoiceId, 'status' => 'Unpaid'));
				if (($result['result'] ?? '') !== 'success') {
					throw new RuntimeException('invoice #' . $invoiceId . ' not reopened: ' . ($result['message'] ?? 'unknown error'));
				}
			}
			Capsule::table('tblcancelrequests')->where('relid', $serviceId)->delete();
			logActivity('Client withdrew the cancellation request (End of Billing Period, ' . $request->date . ')'
				. ($reopen ? '; invoice(s) #' . implode(', #', $reopen) . ' reopened' : '')
				. ($review ? '; invoice(s) #' . implode(', #', $review) . ' changed after the request, NOT reopened: check billing' : '')
				. ' - Service ID: ' . $serviceId, (int) $service->userid);
		});
	} catch (\Throwable $e) {
		logActivity('Cancellation withdrawal failed, request kept (' . $e->getMessage() . ') - Service ID: ' . $serviceId);
	}
	header('Location: clientarea.php?action=productdetails&id=' . $serviceId);
	exit;
});

add_hook('ClientAreaProductDetailsOutput', 1, function ($vars) {
	$serviceId = (int) ($vars['service']->id ?? ($_GET['id'] ?? 0));
	$pending = pvewhmcs_hook_pending_cancellation($serviceId);
	if ($pending === null) {
		return '';
	}
	$pt = pvewhmcs_hook_is_pt();
	$date = date('d/m/Y', strtotime((string) $pending[0]->nextduedate));
	$text = $pt ? 'Cancelamento agendado para ' . $date . ' (fim do período pago).' : 'Cancellation scheduled for ' . $date . ' (end of the paid period).';
	$button = $pt ? 'Desistir do cancelamento' : 'Withdraw cancellation';
	$confirm = $pt ? 'Desistir do cancelamento? A cobrança volta ao normal.' : 'Withdraw the cancellation? Billing resumes as normal.';

	return '<div class="alert alert-warning" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">'
		. '<span><i class="fas fa-calendar-times"></i> ' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</span>'
		. '<form method="post" action="clientarea.php?action=productdetails&amp;id=' . $serviceId . '" onsubmit="return confirm(' . htmlspecialchars(json_encode($confirm), ENT_QUOTES, 'UTF-8') . ')">'
		. '<input type="hidden" name="token" value="' . htmlspecialchars(generate_token('plain'), ENT_QUOTES, 'UTF-8') . '">'
		. '<input type="hidden" name="pvewhmcs_undo_cancellation" value="1">'
		. '<button type="submit" class="btn btn-default btn-sm">' . htmlspecialchars($button, ENT_QUOTES, 'UTF-8') . '</button>'
		. '</form></div>';
});

// Proxmox shows the client by tag and Notes, so a renamed client is rewritten there too.
add_hook('ClientEdit', 1, function ($vars) {
	$old = (array) ($vars['olddata'] ?? array());
	$changed = false;
	foreach (array('firstname', 'lastname', 'companyname', 'email') as $field) {
		$changed = $changed || (string) ($old[$field] ?? '') !== (string) ($vars[$field] ?? '');
	}
	$clientId = (int) ($vars['userid'] ?? 0);
	if (!$changed || $clientId <= 0) {
		return;
	}
	$serviceIds = Capsule::table('mod_pvewhmcs_vms as v')->join('tblhosting as h', 'h.id', '=', 'v.id')->where('h.userid', $clientId)->pluck('v.id')->all();
	if (!$serviceIds) {
		return;
	}
	$server = new \WHMCS\Module\Server();
	if (!$server->load('pvewhmcs') || !function_exists('pvewhmcs_refresh_client_identity')) {
		return;
	}
	foreach ($serviceIds as $serviceId) {
		try {
			pvewhmcs_refresh_client_identity($serviceId);
		} catch (\Throwable $e) {
			logActivity('PVEWHMCS ClientEdit: Proxmox tag/notes not updated for service #' . (int) $serviceId . ': ' . $e->getMessage(), $clientId);
		}
	}
});

add_hook('ClientAreaPrimarySidebar', 1, function ($sidebar) {
	$actions = $sidebar->getChild('Service Details Actions');
	if ($actions === null) {
		return;
	}
	// Lagom draws its own "ls" glyph and hides the Font Awesome one; other themes show Font Awesome.
	$icons = array(
		'vmStart' => 'fas fa-play ls ls-arrow-play',
		'vmReboot' => 'fas fa-sync ls ls-refresh',
		'vmShutdown' => 'fas fa-power-off ls ls-sign-out',
		'vmStop' => 'fas fa-stop ls ls-close',
		'noVNC' => 'fas fa-terminal ls ls-input-code',
	);
	foreach ($actions->getChildren() as $item) {
		if (preg_match('/[?&]modop=custom&a=(\w+)/', (string) $item->getUri(), $m) && isset($icons[$m[1]])) {
			$item->setIcon($icons[$m[1]]);
		}
	}
});

add_hook('ClientAreaFooterOutput', 2, function ($vars) {
	if (($vars['templatefile'] ?? '') !== 'clientareaproductdetails') {
		return '';
	}
	$ask = pvewhmcs_hook_is_pt()
		? array('vmShutdown' => 'Desligar a VPS? O sistema operacional recebe o pedido de desligamento.', 'vmStop' => 'Forçar o desligamento? A VPS é cortada na hora, como tirar da tomada; dados não salvos podem se perder.')
		: array('vmShutdown' => 'Power off the VPS? The operating system is asked to shut down.', 'vmStop' => 'Hard stop the VPS? It is cut off at once, like pulling the plug; unsaved data may be lost.');

	// WHMCS client.js later swaps these hrefs for "#" and posts on click (honouring target), so the confirmation
	// listens in the capture phase and stops that handler when the client cancels.
	return '<script>(function () {
	var ask = ' . json_encode($ask, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';
	document.querySelectorAll(\'a[href*="modop=custom"]\').forEach(function (link) {
		var m = link.href.match(/[?&]a=(\w+)/);
		if (!m) { return; }
		link.setAttribute("data-pvewhmcs-action", m[1]);
		if (m[1] === "noVNC") { link.target = "_blank"; link.rel = "noopener"; }
	});
	document.addEventListener("click", function (e) {
		var link = e.target.closest && e.target.closest("a[data-pvewhmcs-action]");
		var question = link && ask[link.getAttribute("data-pvewhmcs-action")];
		if (question && !window.confirm(question)) { e.preventDefault(); e.stopImmediatePropagation(); }
	}, true);
})();</script>';
});


// Nameserver prefixes of WHMCS server products mean nothing for a VPS; the theme hides them when hideNSFields is set.
function pvewhmcs_hook_is_vps_product($pid) {
	return (string) Capsule::table('tblproducts')->where('id', (int) $pid)->value('servertype') === 'pvewhmcs';
}

add_hook('ClientAreaPageProductDetails', 2, function ($vars) {
	$pid = Capsule::table('tblhosting')->where('id', (int) ($vars['serviceid'] ?? $_GET['id'] ?? 0))->value('packageid');

	return pvewhmcs_hook_is_vps_product($pid) ? array('hideNSFields' => true) : array();
});

add_hook('ClientAreaPageCart', 1, function ($vars) {
	if (($vars['templatefile'] ?? '') !== 'configureproduct') {
		return array();
	}

	if (!pvewhmcs_hook_is_vps_product($vars['productinfo']['pid'] ?? 0)) {
		return array();
	}
	$GLOBALS['pvewhmcs_hide_ns'] = true;

	return array('hideNSFields' => true);
});

// standard_cart ignores hideNSFields and WHMCS still requires the prefixes, so fill and hide them on the page.
add_hook('ClientAreaFooterOutput', 3, function ($vars) {
	if (empty($GLOBALS['pvewhmcs_hide_ns'])) {
		return '';
	}

	return '<script>document.addEventListener("DOMContentLoaded",function(){["ns1","ns2"].forEach(function(n){var i=document.getElementById("input"+n.charAt(0).toUpperCase()+n.slice(1)+"prefix");if(!i){return;}if(!i.value){i.value=n;}var c=i.closest(".col-sm-6,.col-md-6,.form-group")||i.parentNode;c.style.display="none";});});</script>';
});
