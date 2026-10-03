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
| Provisioning | Vínculo e reserva gravados só no fim do CreateAccount; queda no meio cria segunda VM | VM duplicada, IP perdido | Marcador irreversível `allocating` (VMID, nó, IP, modo) gravado na mesma transação da reserva do IP, antes do POST; UPID registrado (`pending`); tarefa falha → `failed` com IP/VMID reservados; queda incerta entre marcador e POST fica `allocating` e bloqueia o guest; retry retoma a tarefa registrada |
| Provisioning | Clone QEMU não aguardava config nem start; origem podia não ser template | VM sobe antes do cloud-init | Origem precisa ser template QEMU (`template=1`); `PUT /config` pós-clone aguardado quando o Proxmox devolve UPID; tarefa de start aguardada |
| IPv4 | IP do serviço cancelado voltava ao pool com a VM ainda configurada com ele | Conflito de IP ao religar a VM cancelada | Endereço fica reservado enquanto `dedicatedip` apontar e existir o vínculo do guest; excluído de alocação e de exclusão (individual/lote/pool, com revalidação sob `FOR UPDATE`); aba **Reserved IPv4** no addon lista as reservas e libera com confirmação |
| Provisioning | Botões de energia não aguardavam a tarefa | "Sucesso" com falha posterior | Start/Reboot/Shutdown/Stop aguardam o UPID (60 s); resposta sem UPID é erro |
| Provisioning (HA) | Unsuspend sem registro de suspensão do módulo podia forçar start de guest gerenciado por HA externamente | Conflito com o cluster HA | HA lido ANTES de qualquer mudança de config/tag: sem recurso HA → start direto; `stopped` + suspensão registrada → restaura `started`; qualquer outro caso → erro sem alterar o guest |
| Conexões | Semântica do `Secure` divergia entre caminhos | Desmarcar Secure podia não desligar a verificação TLS | `NULL` → verificação ligada (legado); qualquer outro valor passa por `FILTER_VALIDATE_BOOLEAN` em ambos os módulos (`''`/off/false desligam); produção grava `on` |
| Observabilidade | Actions sem filtro/paginação; Logs só o primeiro servidor; sem autoria | Painéis limitados e sem auditoria | Cada ação grava `auth_id` e `server_id` imutável; Action History/Failed Actions em painel por servidor ativo, paginado (25/50/100/200, padrão 50) com coluna Admin; aba Logs mostra tarefas de cluster de todos os servidores com erro isolado por servidor |
| Addon | Lista de planos confiava na codificação global de entrada | Valores persistidos renderizados sem escape | Valores do plano (title, vmtype, ostype, disktype, diskio, storage, netmode, bridge/vmbr, netmodel, ipv6) escapados na renderização |
| Schema | Migração dependia de mudança de versão registrada | Cron rodava código novo com schema antigo | `pvewhmcs_ensure_schema()` roda no início de todo callback, sob lock advisory, e repara/cria todas as tabelas no formato 1.3.7 (idempotente, marcador `schema_version`); `pvewhmcs_upgrade()` abaixo de 1.3.7 só chama a garantia |

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

1. **Webhook de deploy frágil.**
   - Push concorrente recebe `409` e o GitHub não reenvia.
   - Reenviar uma entrega antiga faz rollback da produção.
   - A troca de arquivos não é atômica e não há `set_time_limit`.
   - O webhook (685685824) foi **desativado por decisão do dono** — a remoção do endpoint está planejada; os motivos acima continuam sendo a razão de ele permanecer desligado.

### Média

1. **`mod_pvewhmcs_logs`** cresce sem limite. As ações agora registram `auth_id` e `server_id`; `request` segue vazio por decisão. Não há retenção configurada.
2. **Campos do produto:** a validação é só de formato. `KVMTemplate` aceita qualquer VMID numérico; o ideal é exigir `template: 1` na origem ou uma lista permitida por produto.
3. **Conta do Proxmox:** o módulo usa `root@pam` com senha (realm fixo no código). Falta suporte a API Token com privilégio mínimo.
4. **Schema divergente:** `db.sql` tem tabelas e colunas que nunca foram migradas nem são usadas (`mod_pvewhmcs_iso`, `_nodes`, `_ssh_keys`, `_templates`, `plans.ssh-keys`, `vms.node_id`). Colunas antigas (`debug_mode`, `v6prefix`, `ipv6`, `balloon`, `vlanid`) só existem via SQL manual.
5. **Desempenho:** login completo no Proxmox a cada ação, `/cluster/resources` inteiro para achar um VMID e RRD sequencial. Plano em `PROXMOX-CALL-OPTIMIZATION-PLAN.md`.
6. **AdminLink** faz login no Proxmox a cada visualização da lista de servidores. A branch reduziu os timeouts, mas não há cache.
7. **Linhas órfãs:** há quatro em produção (VMIDs 400 a 403) e não existe ferramenta para limpar ou religar. É preciso conferir se essas VMs ainda existem no Proxmox, na aba Guests → Show All.

### Baixa

1. O modo IPv6 `prefix` aparece na interface mas não faz nada.
2. A franquia mensal (`bw`) do plano não é usada.
3. O módulo só tem tradução para inglês e português; as mensagens de erro do admin estão só em inglês.
4. O `hooks.php` não registra nenhum hook.

## 4. Sugestões de funções

**Operação e admin**
- Painel de guests cancelados e órfãos. Listar VMs `CANCELADO`, vínculos sem serviço e VMs sem vínculo, com as ações: religar, destruir VM e liberar IP, remover vínculo.
- Retenção configurável: destruir a VM cancelada após N dias, com aviso.
- Aba no serviço do admin (`AdminServicesTabFields`) com VMID, node, estado, tags, HA e `ha_suspended`, e edição do vínculo.
- Botão "Sincronizar" para reaplicar tags, `onboot` e HA conforme o status do WHMCS.
- Retenção de logs (executor e paginação por servidor já existem; falta política de retenção).
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
2. **IP do serviço cancelado.** **RESOLVIDO na branch:** opção (a) implementada — o IP fica reservado até um admin liberá-lo na aba Reserved IPv4. Opções restantes:
   - (b) liberar o IP e tirar a rede da VM no cancelamento;
   - (c) retenção por prazo com destruição automática.
3. **Secure:** **RESOLVIDO na branch** — semântica unificada (`NULL` → verificação ligada; `''`/off/false → desligada, via `FILTER_VALIDATE_BOOLEAN`); produção usa `on`.
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
   - excluir pool com IP em uso;
   - conferir a aba Reserved IPv4 e a liberação com confirmação;
6. **Admin:**
   - importar guest e repetir com o mesmo VMID;
   - salvar a configuração com valores inválidos;
   - abrir Nodes e Guests com um servidor inacessível;
   - conferir a aba Support (versão mais recente).
