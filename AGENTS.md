# Instruções para agentes

## Escopo

Este repositório é um fork do módulo GPL-3.0 **Proxmox VE for WHMCS**. O módulo provisiona e administra VMs QEMU e containers LXC no Proxmox VE a partir do WHMCS.

Edite os dois módulos quando a mudança atravessar a integração:

- `modules/addons/pvewhmcs/`: administração, planos, pools IPv4, schema e cliente HTTP da API do Proxmox.
- `modules/servers/pvewhmcs/`: callbacks de provisioning do WHMCS, área do cliente, ciclo de vida das instâncias e noVNC.

O diretório `modules/servers/pvewhmcs/novnc/` é uma cópia vendorizada do noVNC. Não altere-o para corrigir código do módulo. Atualize-o somente como dependência vendorizada, com origem e versão explícitas.

O Console Relay (noVNC) não vive mais neste repositório: fonte e deploy estão em [`MasterMindTIBR/pvewhmcs-console-relay`](https://github.com/MasterMindTIBR/pvewhmcs-console-relay) (`git@github.com:MasterMindTIBR/pvewhmcs-console-relay.git`). `modules/servers/pvewhmcs/console-relay/README.md` aqui é só um ponteiro. Mudanças no protocolo do token (`pvewhmcs_build_console_token()`/`proxmox.php`) precisam de um commit correspondente naquele repo — releia o README dele antes de mexer no contrato do token.

## Arquitetura e dados

- `modules/addons/pvewhmcs/proxmox.php` define `PVE2_API`, usado por ambos os módulos. Alterações no transporte, autenticação ou TLS afetam todas as operações.
- `mod_pvewhmcs_plans` armazena os planos. `bridge` + `vmbr` formam o nome da bridge/rede usado no Provisioning.
- `mod_pvewhmcs_vms` associa `tblhosting.id` ao VMID, tipo QEMU/LXC, IP e cliente. Não apague ou reatribua linhas sem preservar essa relação.
- `db.sql` atende instalações novas. Mudanças de schema também exigem uma migração idempotente em `pvewhmcs_upgrade()` para instalações existentes. Ao adicionar uma tabela já presente em `db.sql`, use o DDL literal como string na migração (não o schema builder) e mantenha os dois idênticos caractere a caractere.
- `mod_pvewhmcs_logs` é a fonte das abas Actions → Action History / Failed Actions. Toda ação nova de lifecycle ou energia deve ser exposta por `pvewhmcs_run_tracked_action()` (`modules/servers/pvewhmcs/pvewhmcs.php`), que grava sucesso/erro via `pvewhmcs_log_action()` (`proxmox.php`) e sempre relança a falha original.
- A versão é duplicada em `modules/addons/pvewhmcs/pvewhmcs.php` (`pvewhmcs_version()`) e no arquivo raiz `version`; mantenha ambos sincronizados. `v1.3.6` está tagueada e publicada: não acrescente nada à entrada `[1.3.6]` do `CHANGELOG.md` nem ao bloco `version_compare(..., '1.3.6', 'lt')` de `pvewhmcs_upgrade()`. Mudanças novas entram numa seção `## [Unreleased]` no topo do `CHANGELOG.md`.
  - **Sem mudança de schema:** não incremente a versão do código. Acumule em `[Unreleased]` até o usuário pedir para liberar. Na liberação, renomeie para `## [X.Y.Z] - AAAA-MM-DD - _"Nome"_`, suba `pvewhmcs_version()` e `version` se ainda não estiverem em `X.Y.Z`, faça o commit `chore: cut vX.Y.Z` e crie a tag assinada e o release.
  - **Com mudança de schema:** desde 1.3.7 a mudança de schema não depende mais da versão registrada: `pvewhmcs_ensure_schema()` repara/cria todas as tabelas do módulo a cada callback. Mantenha a disciplina: `db.sql` e a normalização 1.3.7 continuam sendo o contrato literal, e `pvewhmcs_upgrade()` abaixo de 1.3.7 apenas chama a garantia (nunca acrescente blocos ao histórico). A seção do CHANGELOG continua `[Unreleased]` até a liberação.

## Regras de mudança

1. Trate callbacks do WHMCS como operações distribuídas. Uma ação pode criar um recurso no Proxmox e falhar antes de registrar o vínculo no banco. Preserve mensagens de erro úteis e evite deletar uma VM sem verificar `mod_pvewhmcs_vms`.
2. Preservar o suporte a IPs IPv4 e IPv6 literais. `PVE2_API` já delimita IPv6 com colchetes ao compor URLs.
3. Campos de plano administrados pelo usuário precisam de validação de servidor, mesmo quando a interface HTML tem `required`.
4. Para mudanças de rede, atualize todos os caminhos de criação: LXC direto, QEMU direto e clone QEMU. Não suponha que o campo de plano seja aplicado em todos eles.
5. Não introduza novas credenciais em logs, URLs ou mensagens de erro. O modo debug do WHMCS tem dados operacionais sensíveis.
6. TLS precisa validar certificado por padrão. A exceção por servidor usa a configuração Secure do WHMCS e deve ser documentada como uma decisão explícita.
7. Preservar compatibilidade de schema em upgrades. Toda migração precisa rodar sozinha via `pvewhmcs_upgrade()` (ver a regra de versão acima). O SQL manual em `_docs/UPDATE-SQL.md` é só remediação para instalações que registraram a versão antes da migração existir, nunca o caminho normal.
8. Correções pontuais verificadas devem receber commit convencional e `push` para `origin/master` sem pedir confirmação — num único `git push` em lote (pushes em sequência rápida podem cair no lock do receiver, `409`, e o GitHub não reenvia). O webhook GitHub (685685824) está DESATIVADO por decisão do dono: `push` NÃO faz mais deploy; atualizações de produção/staging são feitas manualmente via SSH a partir de um SHA mesclado em `master` (pacote `git archive` + manifest + SHA-256). Trate `push` como publicação e deploy como um passo explícito separado. Peça confirmação antes de uma mudança ampla de arquitetura, dependências, schema, comportamento de provisioning ou superfície de segurança.
9. Para conexões Proxmox, prefira `serverhostname`; `serverip` é o fallback. Porta vazia significa `8006`. Não troque validação TLS por bypass global: `Secure` continua a exceção explícita por servidor.
10. `pvewhmcs_AdminLink()` consulta estatísticas ao vivo. Falhas em `/cluster/status` ou `/cluster/resources` não podem remover nem atrasar o atalho de login de forma perceptível.
11. O console (noVNC) nunca conecta o browser direto no Proxmox. `pvewhmcs_build_console_token()` (`proxmox.php`) cifra e autentica host/porta/path/cookie do Proxmox num token v2 opaco (`v2.` + AES-256-GCM, chave derivada do Console Relay Secret) de vida curta; só o relay (repo separado, ver acima) decifra e abre a conexão real. O formato precisa ser idêntico ao `decodeTokenV2()` do relay. Não volte a expor `PVEAuthCookie`, host ou porta do Proxmox ao browser, nem em texto legível dentro do token — o ticket do `vnc@pve` vale para o console de qualquer VM em `/vms`.
12. Todas as abas de `pvewhmcs_output()` são navegação real (`&tab=nodes|guests|vmplans|ippools|actions|support|config|logs`), e o corpo de cada uma fica dentro de `if ($_GET['tab'] === '...')`, então só a aba visível executa. Nodes, Guests e Logs fazem login + `/cluster/resources`/`/cluster/tasks` + RRD só quando abertas. Ao adicionar uma aba, siga o mesmo padrão de link `&tab=` + gate. Nunca deixe uma chamada de API ou consulta pesada rodar incondicionalmente em toda carga de página.
13. Toda chamada cURL ao Proxmox (`login()` e `action()` em `proxmox.php`) precisa de `CURLOPT_CONNECTTIMEOUT`/`CURLOPT_TIMEOUT`. Sem isso, um PVE lento ou inalcançável trava a página até o `max_execution_time` do PHP estourar.
14. Abertura do console (`pvewhmcs_noVNC()`): o Proxmox derruba o `vncproxy` sem attach em ~10s, então o vncproxy + preconnect do relay precisam rodar síncrono em PHP antes de qualquer coisa no browser — nunca depois de um round-trip do próprio browser (fetch/AJAX). O botão custom da WHMCS (`ClientAreaCustomButtonArray`) é um array simples renderizado como `<form method="post">` pela própria WHMCS, não um `WHMCS\View\Menu\Item`; não existe `setAttribute()`/`target` nativo nele. Já tentamos interceptar o `submit` em fase de captura e forçar `form.target = "_blank"` (com `stopImmediatePropagation()`, sem `preventDefault()`) — o navegador processa o submit nativo, mas ainda assim não abre em aba separada de forma confiável; abandonado, console abre na mesma aba/página mesmo. Ao trocar a senha decriptada de `tblservers.password` em qualquer contexto novo, use `localAPI('DecryptPassword', ['password2' => ...])` como todo o resto do módulo (a antiga cifra caseira foi removida por produzir senha inválida com a criptografia atual da WHMCS).

## Verificação

Não há suíte PHP do módulo no repositório. Antes de entregar uma alteração PHP, execute lint com o interpretador PHP disponível e faça um smoke test contra um ambiente WHMCS + Proxmox descartável ou de homologação. Exercite o callback alterado e confirme no Proxmox e nas tabelas WHMCS.

Para mudanças no noVNC, use os scripts definidos em `modules/servers/pvewhmcs/novnc/package.json` e não misture a saída gerada com alterações do módulo PHP.
