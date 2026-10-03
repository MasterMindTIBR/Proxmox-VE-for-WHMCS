# Correções de produção: inventário

## Executar nesta rodada

1. **Schema sem garantia.** Código novo pode rodar contra tabelas e colunas antigas. A migração depende da tela do addon e de um bump de versão.
2. **CreateAccount não é recuperável.** O módulo reserva o IP antes da operação Proxmox e grava o vínculo serviço/guest só depois de esperar a tarefa. Um timeout deixa guest e IP fora de sincronia.
3. **Clone QEMU inicia cedo.** O módulo manda `POST /config` assíncrono e inicia a VM sem esperar a configuração.
4. **Origem de clone não é confirmada como template.** Um VMID numérico válido pode apontar para uma VM comum.
5. **IP de serviço cancelado volta ao pool.** A VM retida conserva a configuração do IP. O usuário definiu a política: reservar até um admin liberar manualmente.
6. **Webhook perde ou reordena deploys.** O lock não bloqueante devolve 409, a entrega usa `after` de um payload antigo e a cópia modifica arquivos vivos um a um.
7. **Botões de energia não esperam a tarefa Proxmox.** WHMCS mostra sucesso antes de o Proxmox confirmar Start/Reboot/Shutdown/Stop.
8. **Secure tem semântica divergente.** Addon e server module tratam `tblservers.secure = ''` como TLS ligado, embora o checkbox desmarcado represente TLS sem validação.
9. **Unsuspend pode iniciar HA fora da intenção registrada.** Quando `ha_suspended = 0`, o start direto pode virar uma solicitação HA para um recurso alterado fora do módulo.
10. **Logs administrativos incompletos.** A aba Logs consulta só o primeiro servidor; Actions não pagina; `auth_id` fica zero.
11. **UI de planos confia na codificação global do WHMCS.** Valores do banco devem receber escape na renderização.
12. **`time2format()` está declarado em dois módulos.** Nenhum fluxo atual carrega ambos, mas um carregamento conjunto termina em `Cannot redeclare`.
13. **Upgrade 1.3.6 pode parar no `vmbr->change()`.** A falha impede a continuação daquele upgrade.
14. **Schema fresco e schema migrado divergem.** Instalações antigas podem não ter colunas usadas pelo código atual.

## Adiar para a conversa sobre sugestões

- API Token e realm configurável no lugar de `root@pam`.
- Retenção automática de logs. Apaga dados operacionais e precisa de prazo definido.
- Cache de ticket, cache de node e RRD paralelo.
- IPv6 por prefixo, medição de banda e cobrança de uso.
- ChangePackage, reinstalação, snapshots, PBS, rDNS, firewall e migração assistida.
- Tela de limpeza/relink de guests órfãos. Não deve apagar uma VM sem política de retenção e confirmação explícita.
- Traduções adicionais.

## Fora de código nesta rodada

- Merge da branch `fix/production-readiness` em `master`.
- Deploy do relay `73d396a` quando o host de produção voltar a aceitar SSH.
- O usuário vai trocar a senha de `vnc@pve` depois do deploy.
