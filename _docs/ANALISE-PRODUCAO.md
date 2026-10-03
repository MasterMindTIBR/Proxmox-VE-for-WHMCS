# Análise de produção — Proxmox VE for WHMCS (fork MasterMind TI)

Data: 2026-10-02/03. Base analisada: `master` em `4582e77` (tag `v1.3.6`) e o relay `MasterMindTIBR/pvewhmcs-console-relay` em `88369ea`.

As correções descritas na seção 2 estão na branch `fix/production-readiness` e **não** estão em produção: todo push na `master` faz deploy pelo webhook, e a branch só deve entrar depois de revisão e teste em homologação (seção 6). A exceção é o relay: o commit `4a5081f` já roda em produção (compatível com o módulo atual); o `73d396a` (IPv6) está só no GitHub.

## 1. Como a análise foi feita

- Quatro revisões independentes: módulo de provisionamento, addon/schema/webhook, segurança e documentação.
- Métodos e parâmetros da API conferidos no schema oficial (`pve-docs/api-viewer/apidoc.js`) e no código do `qemu-server`, `pve-container` e `pve-ha-manager`.
- Produção consultada só com `SELECT` em 2026-10-02:
  - WHMCS 9.0.8, PHP 8.3 (FPM, Plesk) e MariaDB.
  - Um servidor Proxmox, com **Secure** ligado.
  - Um produto ("Padrao", plano 1 QEMU, campo ISO).
  - Nenhum serviço pvewhmcs ativo.
  - Quatro linhas órfãs em `mod_pvewhmcs_vms`: serviços 6 a 9, já excluídos do WHMCS, com VMIDs 400 a 403.
- Testes feitos na branch:
  - Lint PHP 8.3.
  - Cerca de 40 cenários de ciclo de vida com Proxmox simulado (QEMU e LXC, com e sem HA).
  - 47 verificações do addon contra MariaDB real.
  - 27 verificações extras do módulo de provisionamento.
  - 18 testes do relay.
  - **Nada foi testado contra um Proxmox real.**

## 2. Corrigido na branch `fix/production-readiness`

| Área | Problema em `v1.3.6` | Efeito | Correção |
| --- | --- | --- | --- |
| Ciclo de vida LXC | Suspend/Unsuspend/Terminate usam `POST …/lxc/{vmid}/config`, que não existe (LXC só tem GET/PUT) | Falha (501) em **todo** container | Um `PUT /config` síncrono com `onboot` e tags |
| Console LXC | `vncproxy` envia `generate-password`, parâmetro inexistente para LXC | Console nunca abre em container | Só `websocket=1` (válido para QEMU e LXC) |
| Token do console | Payload só assinado, legível no navegador: ticket do `vnc@pve`, host e porta | Cliente que alcance a porta 8006 pode abrir o console de qualquer VM do cluster | Token v2 cifrado (AES-256-GCM); o relay já aceita v1 e v2 |
| Relay | Sessão de console encerrada ~2 min após abrir | Console cai sozinho | Corrigido no relay `4a5081f` (**já em produção**) |
| Ciclo de vida | Stop/start não aguardam a tarefa; suspend de CT parado e unsuspend de CT ligado falham no LXC | "Sucesso" no WHMCS com o guest no estado errado | Espera o UPID (60 s), pula stop/start desnecessário, grava config antes de mexer em HA/energia |
| HA | Unsuspend devolvia sucesso com o guest parado quando o HA tinha sido alterado fora do módulo | Serviço ativo no WHMCS com VM desligada | Retorna erro explícito; HA sem `state` conta como `started`; nova tentativa de suspend preserva `ha_suspended` |
| Cancelamento + VMID | Linha mantida após cancelar + VMID reaproveitado pelo Proxmox ligam um serviço antigo à VM de outro cliente | Botões/console de um cliente agindo na VM de outro | O alocador pula VMIDs vinculados; toda ação recusa VMID duplicado |
| CreateAccount | Serviço com vínculo existente cria VM nova e quebra na chave primária | VM órfã + IP reservado a cada tentativa | Recusa antes de reservar IP ou chamar o Proxmox |
| CreateAccount | Nomes com `_`, `..` ou `-` na borda são rejeitados pelo formato `dns-name` | Provisionamento falha | Sanitização por token e no nome final (máx. 63) |
| CreateAccount | Campos do produto usados direto no caminho da API | Valor adulterado vira caminho/volume no Proxmox | Validação de formato: KVMTemplate numérico, nó existente, ISO só nome, Template `storage:vztmpl/arquivo` |
| Addon | CSRF só nos formulários de exclusão | Página maliciosa aberta por admin logado altera config, planos ou importa guest | Checagem central em todo POST do addon |
| Addon | Editar plano apaga a VLAN; plano LXC novo nunca fica unprivileged; nenhum campo validado | Serviços novos sem tag (rede errada); containers privilegiados | Formulários corrigidos + validação no servidor (planos, IPv4, import, config) |
| IPv4 | Bloco inválido entra pela metade; `Pending` não conta como em uso; excluir pool apaga IPs em uso | IP duplicado ou perdido | Transação, `/22`–`/30` ou endereço único, duplicados ignorados, exclusão recusada se em uso |
| Import de guest | Mesmo VMID vinculável a dois serviços; dois inserts sem transação | Dois clientes controlando a mesma VM | Recusa VMID já vinculado; um único insert transacional |
| Logs | Senha root do Proxmox e senha do cliente no Module Log; stack trace com argumentos | Vazamento para qualquer admin com acesso aos logs | Segredos mascarados; trace sem argumentos |
| Área do cliente | Cliente suspenso podia ligar a VM e abrir o console; erros do console mostravam host interno | Contorna a suspensão; vaza infraestrutura | Bloqueio para serviço não ativo; mensagem genérica (detalhe só para admin) |
| Área do cliente | 16 gráficos RRD buscados em toda abertura da página | Página lenta | RRD só na tela "Statistics" |
| Atualização | Verificador lia o repositório upstream, sem timeout e sem escape | Pede para instalar o upstream por cima do fork; página travada se o GitHub não responde | Lê o `version` do fork, timeout de 5 s, cache de 12 h, `version_compare` |
| Addon | Um servidor inacessível derrubava as abas Nodes/Guests inteiras | Painel inutilizável | Erro isolado por servidor |
| `PVE2_API` | Status HTTP lido do primeiro bloco de cabeçalho; erro de cURL virava "Invalid HTTP Response"; ticket nunca expirava; IPv6 entre colchetes falhava | Falhas mal explicadas e frágeis atrás de proxy | Status e corpo pelo cURL, erro com método/caminho, `Expect:` vazio, expiração correta, IPv6 |
| Código morto | SPICE com ticket na URL, decriptador PHP 4, helpers quebrados no PHP 8 | Risco sem uso | Removido |

Mudanças de comportamento na branch que precisam ser comunicadas:

- **Unsuspend** volta a ligar `Start at boot` quando o plano tem `onboot` ativo. Na `v1.3.6` ficava desligado.
- **Suspend e Unsuspend** recusam guest marcado `CANCELADO`.
- **Cancelamento de guest com HA:** só põe o recurso HA em `disabled`, e o CRM desliga o guest. Não há stop direto.
- **Ordem das operações:** tag e `onboot` são gravados antes de qualquer mudança de HA ou de energia.

Fora do código, em 2026-10-03:
- Issues e reporte privado de vulnerabilidades foram ativados no GitHub do fork.
- A homepage do repositório (página da TNC no WHMCS Marketplace) foi removida.
- A MasterMind TI passou a ser creditada como mantenedora do fork. A TNC continua creditada como upstream, como pede a GPL-3.0.

## 3. Pontos de falha que continuam

### Alta

1. **Migração depende de mudança de versão.**
   - O webhook publica o código, mas o WHMCS só roda `pvewhmcs_upgrade()` quando a versão registrada muda.
   - Já aconteceu: o `ha_suspended` foi aplicado à mão.
   - Callbacks do cron rodam código novo com schema antigo até alguém abrir o addon.
   - Recomendação: `pvewhmcs_ensure_schema()` idempotente, com marcador de schema próprio, chamado no início de cada callback. Até lá vale a regra do `AGENTS.md`: migração nova exige versão nova no mesmo commit.
2. **Vínculo gravado só no fim do CreateAccount.**
   - A linha em `mod_pvewhmcs_vms` só é criada depois do polling (até 150 s).
   - Timeout, `max_execution_time` ou queda deixam a VM criada sem vínculo e o IP reservado. Um novo "Create" cria outra VM.
   - Recomendação: gravar o vínculo assim que o Proxmox aceitar a tarefa e retomar na nova tentativa.
3. **Clone QEMU não aguarda a configuração.**
   - O `POST /config` pós-clone é assíncrono, e o `status/start` vem em seguida.
   - Pode falhar com lock ou subir antes do cloud-init aplicado.
   - Recomendação: `PUT` síncrono ou esperar o UPID.
4. **IP do serviço cancelado volta ao pool.**
   - O serviço vira `Terminated` e o IP fica livre, mas a VM mantida continua configurada com ele.
   - Religar essa VM para recuperação causa conflito de IP com o novo dono.
   - **Decisão necessária** (seção 5).
5. **Webhook de deploy frágil.**
   - Push concorrente recebe `409` e o GitHub não reenvia.
   - Reenviar uma entrega antiga faz rollback da produção.
   - A troca de arquivos não é atômica e não há `set_time_limit`.
   - Recomendação: lock bloqueante, deploy do HEAD atual da `master`, troca atômica de diretórios e resposta `202` antes de copiar.

### Média

6. **Botões de energia** (Start/Reboot/Shutdown/Stop) não aguardam a tarefa: mostram sucesso mesmo se o Proxmox falhar depois (lock de backup, por exemplo).
7. **Opção Secure** tem duas fontes: `$params['serversecure']` e `tblservers.secure`, com semânticas diferentes. Desmarcar Secure pode não valer nos botões do cliente nem nas abas do admin. Em produção o Secure está ligado, então não há impacto hoje.
8. **`mod_pvewhmcs_logs`** cresce sem limite. Os campos `auth_id` (quem executou), `request` e `node_id` nunca são preenchidos.
9. **Campos do produto:** a validação é só de formato. `KVMTemplate` aceita qualquer VMID numérico; o ideal é exigir `template: 1` na origem ou uma lista permitida por produto.
10. **Conta do Proxmox:** o módulo usa `root@pam` com senha (realm fixo no código). Falta suporte a API Token com privilégio mínimo.
11. **Migração `1.3.6`:** chama `->change()` na coluna `vmbr` sem `try/catch`. Se falhar, o resto do bloco não roda.
12. **Schema divergente:** `db.sql` tem tabelas e colunas que nunca foram migradas nem são usadas (`mod_pvewhmcs_iso`, `_nodes`, `_ssh_keys`, `_templates`, `plans.ssh-keys`, `vms.node_id`). Colunas antigas (`debug_mode`, `v6prefix`, `ipv6`, `balloon`, `vlanid`) só existem via SQL manual.
13. **Aba Logs** só consulta o primeiro servidor. A aba Actions mostra as 200 últimas ações, sem filtro nem paginação.
14. **Desempenho:** login completo no Proxmox a cada ação, `/cluster/resources` inteiro para achar um VMID e RRD sequencial. Plano em `PROXMOX-CALL-OPTIMIZATION-PLAN.md`.
15. **AdminLink** faz login no Proxmox a cada visualização da lista de servidores. A branch reduziu os timeouts, mas não há cache.
16. **Unsuspend sem ação de HA do módulo** (`ha_suspended = 0`) usa `status/start`. Se o guest tiver HA parado por um admin, o próprio Proxmox converte isso em pedido HA `started`.
17. **Linhas órfãs:** há quatro em produção (VMIDs 400 a 403) e não existe ferramenta para limpar ou religar. É preciso conferir se essas VMs ainda existem no Proxmox, na aba Guests → Show All.

### Baixa

18. O modo IPv6 `prefix` aparece na interface mas não faz nada.
19. A franquia mensal (`bw`) do plano não é usada.
20. O módulo só tem tradução para inglês e português; as mensagens de erro do admin estão só em inglês.
21. A lista de planos depende da codificação global de entrada do WHMCS (os valores não são escapados de novo).
22. O `hooks.php` não registra nenhum hook.
23. `time2format()` é declarado nos dois módulos sem `function_exists()`. Nenhum fluxo carrega os dois arquivos na mesma requisição (sempre foi assim no upstream). Se algum fluxo passar a carregar, o PHP para com `Cannot redeclare`.

## 4. Sugestões de funções

**Operação e admin**
- Schema garantido automaticamente (item 1 acima).
- Painel de guests cancelados e órfãos. Listar VMs `CANCELADO`, vínculos sem serviço e VMs sem vínculo, com as ações: religar, destruir VM e liberar IP, remover vínculo.
- Retenção configurável: destruir a VM cancelada após N dias, com aviso.
- Aba no serviço do admin (`AdminServicesTabFields`) com VMID, node, estado, tags, HA e `ha_suspended`, e edição do vínculo.
- Botão "Sincronizar" para reaplicar tags, `onboot` e HA conforme o status do WHMCS.
- Auditoria de quem executou cada ação (admin, cliente ou cron), com filtros, paginação e retenção.
- Alerta (e-mail ou WhatsApp interno) quando uma ação do cron falhar.
- Live migration entre nodes pela interface do admin.
- HA opcional por produto: criar o recurso HA no provisionamento, com grupo ou regra.

**Cobrança**
- `ChangePackage`: upgrade e downgrade de CPU, RAM e disco (disco só cresce).
- Configurable Options do WHMCS: CPU, RAM, disco e IPs extras escolhidos no pedido.
- `UsageUpdate`: tráfego (netin/netout) e disco para relatório ou cobrança de excedente, usando a coluna `bw`.

**Cliente**
- Reinstalar o SO a partir de template ou ISO.
- Redefinir a senha root e as chaves SSH (cloud-init).
- Snapshots com limite por plano, e backup/restauração via PBS.
- Console serial (xterm.js) para LXC.
- Modo resgate, com boot por ISO.
- Regras simples do firewall do Proxmox por VM.

**Segurança**
- API Token no lugar de `root@pam`.
- Porta 8006 liberada só para o WHMCS e o relay.
- Remover o realm fixo `pam` para permitir usuário `@pve` ou API Token.

**Desempenho**
- Cache do ticket por servidor, node gravado em cache e RRD em paralelo (ver `PROXMOX-CALL-OPTIMIZATION-PLAN.md`).

## 5. Decisões pendentes

1. **Branch `fix/production-readiness`:**
   - Revisar e testar em homologação (seção 6).
   - O merge na `master` é o deploy.
   - A branch não muda schema, então não exige versão nova.
2. **IP do serviço cancelado.** Opções:
   - (a) manter o IP reservado enquanto existir a VM `CANCELADO`;
   - (b) liberar o IP e tirar a rede da VM no cancelamento;
   - (c) retenção por prazo com destruição automática.
3. **Secure:** conferir em `tblservers.secure` o valor gravado quando a opção é desmarcada, antes de unificar as duas fontes.
4. **Relay `73d396a`:** fazer o deploy só se algum servidor Proxmox for configurado por IPv6 literal.
5. **Após o deploy da branch:** trocar a senha do `vnc@pve` (os tokens v1 a expunham) e restringir a porta 8006 aos servidores do WHMCS e do relay.

## 6. Roteiro de validação em homologação

1. **Ciclo de vida:** QEMU e LXC, com e sem HA, passando por Create → Suspend → Unsuspend → Terminate. Conferir em cada passo:
   - tags e `onboot`;
   - estado do recurso HA;
   - `mod_pvewhmcs_vms.ha_suspended`;
   - status no WHMCS.
2. **Casos de erro:**
   - Unsuspend com HA alterado à mão: deve retornar erro.
   - Suspend de guest `CANCELADO`: deve ser recusado.
   - Create em serviço já vinculado: deve ser recusado.
3. **Console:** noVNC em QEMU e em LXC, com a sessão aberta por mais de 2 minutos. Testar também o cliente com serviço suspenso, que deve ser bloqueado.
4. **Planos:**
   - criar e editar mantendo a VLAN;
   - criar plano LXC unprivileged;
   - tentar salvar valores inválidos;
   - tentar excluir plano em uso.
5. **IPv4:**
   - adicionar `/28` e um endereço `/32`;
   - tentar `/31` e `/8`;
   - excluir IP em uso e IP livre;
   - excluir pool com IP em uso.
6. **Admin:**
   - importar guest e repetir com o mesmo VMID;
   - salvar a configuração com valores inválidos;
   - abrir Nodes e Guests com um servidor inacessível;
   - conferir a aba Support (versão mais recente).
