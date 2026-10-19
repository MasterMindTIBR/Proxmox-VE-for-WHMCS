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

// Cancellation policy (all products, not only pvewhmcs): a client cancels only at the
// end of the paid period, because nothing is refunded. Admins may still cancel at once.

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

// Server-side rule: a client request (or a hand-made POST) for "Immediate" is stored as
// "End of Billing Period". Safe because WHMCS only executes requests in the daily cron.
add_hook('CancellationRequest', 1, function ($vars) {
	if (!empty($_SESSION['adminid']) || ($vars['type'] ?? '') === 'End of Billing Period') {
		return;
	}
	$serviceId = (int) ($vars['relid'] ?? 0);
	$request = Capsule::table('tblcancelrequests')->where('relid', $serviceId)->orderBy('id', 'desc')->first();
	if ($request === null || $request->type === 'End of Billing Period') {
		return;
	}
	Capsule::table('tblcancelrequests')->where('id', $request->id)->update(array('type' => 'End of Billing Period'));
	logActivity('Cancellation request changed from Immediate to End of Billing Period (client cancellations are end of period only) - Service ID: ' . $serviceId, (int) ($vars['userid'] ?? 0));
});

// Client form: only "End of Billing Period" is offered.
add_hook('ClientAreaFooterOutput', 1, function ($vars) {
	if (($vars['templatefile'] ?? '') !== 'clientareacancelrequest') {
		return '';
	}

	return '<script>document.querySelectorAll(\'select[name="type"] option[value="Immediate"]\').forEach(function (o) { var s = o.parentNode; o.remove(); s.value = "End of Billing Period"; });</script>';
});

// The client's own service with a pending end-of-period request, or null.
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

// "Undo cancellation": drops the pending request and reopens the renewal invoice that
// CancelInvoiceOnCancellation cancelled when the request was made, so billing resumes.
add_hook('ClientAreaPageProductDetails', 1, function ($vars) {
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_POST['pvewhmcs_undo_cancellation'])) {
		return;
	}
	check_token();
	$serviceId = (int) ($_GET['id'] ?? 0);
	$pending = pvewhmcs_hook_pending_cancellation($serviceId);
	if ($pending !== null) {
		list($service, $request) = $pending;
		Capsule::table('tblcancelrequests')->where('relid', $serviceId)->delete();
		$invoiceIds = Capsule::table('tblinvoices as i')
			->join('tblinvoiceitems as t', 't.invoiceid', '=', 'i.id')
			->where('t.type', 'Hosting')->where('t.relid', $serviceId)
			->where('i.userid', (int) $service->userid)->where('i.status', 'Cancelled')
			->where('i.date_cancelled', '>=', $request->date)
			->distinct()->pluck('i.id')->all();
		foreach ($invoiceIds as $invoiceId) {
			localAPI('UpdateInvoice', array('invoiceid' => (int) $invoiceId, 'status' => 'Unpaid'));
		}
		logActivity('Client withdrew the cancellation request (End of Billing Period, ' . $request->date . ')' . ($invoiceIds ? '; invoice(s) #' . implode(', #', $invoiceIds) . ' reopened' : '') . ' - Service ID: ' . $serviceId, (int) $service->userid);
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
