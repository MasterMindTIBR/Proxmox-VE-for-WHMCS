<?php

/*
	Proxmox VE for WHMCS - Client Area language file (Brazilian Portuguese)
	File: /modules/servers/pvewhmcs/lang/portuguese-br.php

	Matches WHMCS's own language system name "portuguese-br" (locale pt_BR),
	so it loads automatically for clients using that WHMCS language. See
	english.php in this same directory for what each key is used for.
*/

if (!defined('WHMCS')) {
	die('This file cannot be accessed directly');
}

$_PVEWHMCS_LANG['btn_start'] = 'Ligar';
$_PVEWHMCS_LANG['btn_reboot'] = 'Reiniciar';
$_PVEWHMCS_LANG['btn_poweroff'] = 'Desligar';
$_PVEWHMCS_LANG['btn_hardstop'] = 'Forçar Parada';
$_PVEWHMCS_LANG['btn_statistics'] = 'Estatísticas';
$_PVEWHMCS_LANG['btn_checkstatus'] = 'Verificar Status';
$_PVEWHMCS_LANG['btn_console'] = 'Console (HTML5)';

$_PVEWHMCS_LANG['uptime_prefix'] = 'Ativo há';
$_PVEWHMCS_LANG['memory'] = 'Memória';
$_PVEWHMCS_LANG['memory_sub'] = '(RAM)';
$_PVEWHMCS_LANG['compute'] = 'Processamento';
$_PVEWHMCS_LANG['compute_sub'] = '(CPU)';
$_PVEWHMCS_LANG['cores_suffix'] = 'núcleo(s)';
$_PVEWHMCS_LANG['sockets_on'] = 'em';
$_PVEWHMCS_LANG['sockets_suffix'] = 'soquete(s)';
$_PVEWHMCS_LANG['storage'] = 'Armazenamento';
$_PVEWHMCS_LANG['storage_sub'] = '(SSD/HDD)';
$_PVEWHMCS_LANG['ipv4'] = 'IPv4';
$_PVEWHMCS_LANG['ipv4_sub'] = '(Rede)';
$_PVEWHMCS_LANG['mask_label'] = 'Máscara';
$_PVEWHMCS_LANG['gateway_label'] = 'Gateway';
$_PVEWHMCS_LANG['ip_config'] = 'Config. de IP';
$_PVEWHMCS_LANG['ip_config_sub'] = '(IPv4/v6)';
$_PVEWHMCS_LANG['nic0'] = 'Placa de Rede #0';
$_PVEWHMCS_LANG['nic0_sub'] = '(Principal)';
$_PVEWHMCS_LANG['nic1'] = 'Placa de Rede #1';
$_PVEWHMCS_LANG['nic1_sub'] = '(Secundária)';
$_PVEWHMCS_LANG['ssh_keys'] = 'Chaves SSH';
$_PVEWHMCS_LANG['ssh_keys_sub'] = '(Pública)';
$_PVEWHMCS_LANG['kernel'] = 'Kernel';
$_PVEWHMCS_LANG['kernel_sub'] = '(SO)';
$_PVEWHMCS_LANG['net_io'] = 'Rede (E/S)';
$_PVEWHMCS_LANG['disk_io'] = 'Disco (E/S)';

$_PVEWHMCS_LANG['guest_statistics'] = 'Estatísticas do Servidor';
$_PVEWHMCS_LANG['daily'] = 'Diário';
$_PVEWHMCS_LANG['weekly'] = 'Semanal';
$_PVEWHMCS_LANG['monthly'] = 'Mensal';
$_PVEWHMCS_LANG['yearly'] = 'Anual';
$_PVEWHMCS_LANG['stats_error'] = 'Erro nas estatísticas: RRD indisponível. Peça ao suporte para atualizar/migrar os dados RRD usando:';

$_PVEWHMCS_LANG['novnc_opening'] = 'Abrindo o console noVNC...';
$_PVEWHMCS_LANG['novnc_manual_prefix'] = 'Se não abrir automaticamente,';
$_PVEWHMCS_LANG['novnc_manual_link'] = 'clique aqui para abrir o console';
$_PVEWHMCS_LANG['novnc_prepare_failed'] = 'Falha ao preparar o noVNC.';
