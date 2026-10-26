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
$_PVEWHMCS_LANG['btn_hardstop'] = 'Forçar desligamento';
$_PVEWHMCS_LANG['btn_console'] = 'Console';

$_PVEWHMCS_LANG['uptime_prefix'] = 'Ativo há';
$_PVEWHMCS_LANG['memory'] = 'Memória';
$_PVEWHMCS_LANG['memory_sub'] = '(RAM)';
$_PVEWHMCS_LANG['compute'] = 'Processamento';
$_PVEWHMCS_LANG['compute_sub'] = '(CPU)';
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
$_PVEWHMCS_LANG['os'] = 'Sistema operacional';
$_PVEWHMCS_LANG['kernel'] = 'Kernel';
$_PVEWHMCS_LANG['usage_title'] = 'Consumo';
$_PVEWHMCS_LANG['range_hour'] = 'Última hora';
$_PVEWHMCS_LANG['range_day'] = 'Último dia';
$_PVEWHMCS_LANG['range_week'] = 'Última semana';
$_PVEWHMCS_LANG['chart_mem'] = 'Memória';
$_PVEWHMCS_LANG['chart_net'] = 'Rede';
$_PVEWHMCS_LANG['chart_disk'] = 'Disco (leitura e escrita)';
$_PVEWHMCS_LANG['net_in'] = 'Entrada';
$_PVEWHMCS_LANG['net_out'] = 'Saída';
$_PVEWHMCS_LANG['disk_read'] = 'Leitura';
$_PVEWHMCS_LANG['disk_write'] = 'Escrita';
$_PVEWHMCS_LANG['chart_nodata'] = 'Ainda não há dados para este período.';
$_PVEWHMCS_LANG['chart_error'] = 'Não foi possível carregar os dados de consumo.';
$_PVEWHMCS_LANG['chart_updated'] = 'Atualizado às';
$_PVEWHMCS_LANG['rootpw_title'] = 'Senha root';
$_PVEWHMCS_LANG['rootpw_help'] = 'Troque a senha do usuário root. Se a VPS estiver ligada, ela reinicia para aplicar a senha; arquivos e chaves SSH não mudam.';
$_PVEWHMCS_LANG['rootpw_new'] = 'Nova senha';
$_PVEWHMCS_LANG['rootpw_repeat'] = 'Repita a nova senha';
$_PVEWHMCS_LANG['rootpw_submit'] = 'Trocar senha';
$_PVEWHMCS_LANG['rootpw_rule'] = 'A senha root precisa ter pelo menos 12 caracteres, com letras e números.';
$_PVEWHMCS_LANG['rootpw_mismatch'] = 'As senhas não conferem.';
$_PVEWHMCS_LANG['rootpw_confirm'] = 'A VPS será reiniciada agora para aplicar a nova senha. Continuar?';
$_PVEWHMCS_LANG['rootpw_post_only'] = 'Use o formulário da página do serviço para trocar a senha root.';
$_PVEWHMCS_LANG['rootpw_qemu_only'] = 'A troca de senha root só está disponível para máquinas virtuais.';
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
$_PVEWHMCS_LANG['novnc_unavailable'] = 'O console está indisponível no momento. Tente novamente em alguns minutos ou fale com o suporte.';

$_PVEWHMCS_LANG['service_not_active'] = 'O serviço não está ativo.';
$_PVEWHMCS_LANG['service_inactive_notice'] = 'Este serviço está %s; os dados e as ações da VPS não estão disponíveis.';
$_PVEWHMCS_LANG['hypervisor_unavailable'] = 'Erro: não foi possível obter os dados do hypervisor. Fale com o suporte técnico.';
