# Contexto do fork: Proxmox VE for WHMCS

## Objetivo

O projeto conecta o ciclo de vida de serviços do WHMCS ao Proxmox VE. Ele cria, suspende e reativa QEMU/LXC e, no cancelamento, para e marca o guest como `CANCELADO` sem apagá-lo; mostra estado e RRD na área do cliente; mantém planos, pools IPv4 e dados operacionais no addon do WHMCS.

A branch `fix/production-readiness` carrega a `1.3.7` (ainda não liberada): garantia automática de schema (`pvewhmcs_ensure_schema()`), provisioning recuperável, reservas de IPv4 para serviços cancelados, tarefas de runtime aguardadas e observabilidade por servidor. Pushes em `master` NÃO fazem mais deploy (webhook desativado por decisão do dono); os deploys são pacotes manuais via SSH. Mudanças novas entram em `## [Unreleased]` no `CHANGELOG.md` conforme a regra de versão do `AGENTS.md`. O remoto `origin` aponta para `MasterMindTIBR/Proxmox-VE-for-WHMCS`.

## Mapa de execução

| Área | Arquivo principal | Responsabilidade |
| --- | --- | --- |
| Addon administrativo | `modules/addons/pvewhmcs/pvewhmcs.php` | Interface administrativa, planos, pools, importação, configuração e upgrade de schema |
| Cliente da API Proxmox | `modules/addons/pvewhmcs/proxmox.php` | Login, tickets, requisições HTTP para `/api2/json` e descoberta de nós |
| Provisioning | `modules/servers/pvewhmcs/pvewhmcs.php` | Callbacks WHMCS, criação, clone, suspend, unsuspend, terminate, área do cliente e console |
| Schema | `modules/addons/pvewhmcs/db.sql` | Instalações novas |
| Console web | `modules/servers/pvewhmcs/novnc/` (vendorizado) + repo separado [`MasterMindTIBR/pvewhmcs-console-relay`](https://github.com/MasterMindTIBR/pvewhmcs-console-relay) | Cliente noVNC e relay Node.js WS↔WSS até o Proxmox |

## Deploy por webhook

> [!NOTE]
> O webhook foi **desativado no GitHub** (685685824) por decisão do dono: o endpoint permanece no código apenas para remoção posterior e **nada é deployado no push**. O deploy passou a ser pacote manual via SSH, conforme `PLAN.md`.

`modules/addons/pvewhmcs/github-webhook.php` recebe somente `push` HMAC-SHA256 assinado de `MasterMindTIBR/Proxmox-VE-for-WHMCS:master`. O receiver baixa o ZIP do SHA entregue, valida os caminhos e sincroniza somente os diretórios do addon e do provisioning module.

`github-webhook.local.php` guarda o segredo HMAC e, para repositório privado, um token GitHub com `Contents: Read-only`. O deploy nunca apaga esse arquivo nem o lock; o próprio `github-webhook.php` é atualizado a partir do repositório como os demais arquivos, mas mudanças nas regras de validação dele só valem depois de copiá-lo manualmente (o receiver instalado é quem aceita ou rejeita o push). Arquivos dos dois diretórios do módulo que não existam no commit recebido são removidos.

Consulte `_docs/GITHUB-WEBHOOK-DEPLOY.md` antes de expor o endpoint no Plesk. O arquivo local não entra no Git.

O módulo usa `Illuminate\Database\Capsule\Manager` para acesso ao banco. O serviço WHMCS usa `tblhosting.id` como chave da tabela `mod_pvewhmcs_vms` e guarda o VMID real em `vmid`.

## Fluxos operacionais

### Criação

1. Sob o lock advisory de VMID do servidor, `pvewhmcs_CreateAccount()` grava o marcador irreversível `allocating` (VMID, tipo, node, IP, modo) na mesma transação que reserva o endereço do pool IPv4 — antes do POST ao Proxmox.
2. Aceita a tarefa, registra o UPID (`pending`) e aguarda: QEMU clona um template (`template=1`), com o `PUT /config` pós-clone aguardado quando o Proxmox devolve UPID, e o start aguardado; sem template, cria LXC ou QEMU com os parâmetros do plano.
3. Tarefa `OK` → pós-configuração → `ready`, com o vínculo em `mod_pvewhmcs_vms` e o IP dedicado em `tblhosting`.
4. Tarefa com erro → `failed`, com IP e VMID mantidos reservados; queda incerta entre o marcador e o POST deixa `allocating`, que bloqueia todas as ações do guest; um novo CreateAccount retoma a tarefa registrada em vez de criar um segundo guest.

### Localização e ciclo de vida

Suspend, unsuspend, terminate, VNC e área do cliente usam `mod_pvewhmcs_vms` para localizar VMID e tipo. `pvewhmcs_find_guest_node()` consulta `/cluster/resources`, portanto a associação WHMCS→VMID precisa permanecer consistente.

### HA, armazenamento compartilhado e migração

O módulo não mantém afinidade de node no banco: antes de cada ação relevante consulta `/cluster/resources`, encontra o node que hospeda o VMID e chama a API daquele node. Live migration e recuperação HA alteram o node de execução, mas não quebram esse fluxo enquanto o cluster tiver quorum e a conta da API puder ler recursos.

O módulo não escolhe destino de migração, não configura grupos/regras HA, não faz storage migration e não aciona rebalancing. Em Ceph RBD, os discos são compartilhados e a live migration move principalmente estado de execução; em storage local o Proxmox precisa mover/copiar os discos. Rebalance/backfill do Ceph não é provocado por live migration, mas ambos disputam rede, CPU e I/O. Não planejar migração de manutenção durante `HEALTH_WARN`, recovery ou backfill.

`TerminateAccount` é o fluxo de cancelamento e preserva o guest para recuperação. Primeiro grava a config num único `PUT …/config` síncrono: desmarca `Start at boot` e substitui a tag de ciclo de vida por `CANCELADO`. Se o guest já tem recurso HA, o módulo só o põe em `disabled` e o CRM do HA desliga o guest (um stop direto viraria pedido HA `stopped`). O módulo **nunca cria** recurso HA: criar deixaria uma referência que trava a exclusão da VM/CT depois, mesmo num install single-node. Guest sem HA recebe `status/stop` e o módulo espera a tarefa. VM/CT e vínculo ficam, exceto quando o VMID sumiu do cluster ou pertence a outro serviço; nesse caso só o vínculo obsoleto é removido.

Em `SuspendAccount`, a config (`onboot=0` + `SUSPENSO`) também vem primeiro. Recurso HA em `started` muda para `stopped`, e `mod_pvewhmcs_vms.ha_suspended` registra que foi o módulo; os demais guests recebem stop direto com espera da tarefa. `UnsuspendAccount` remove a tag e religa `Start at boot` quando o plano tem `onboot`. Só restaura o HA para `started` quando `ha_suspended` está marcado **e** o recurso continua `stopped`; se um admin mudou o HA para outro estado, retorna erro e não altera nada. Sem mudança de HA feita pelo módulo, liga o guest direto e espera a tarefa. Suspend e Unsuspend recusam guest `CANCELADO`, e toda ação recusa VMID vinculado a mais de um serviço. Cores não vão pela API do guest: o administrador configura em **Datacenter → Options → Tag Style** o `color-map` `CANCELADO:#dc2626:#ffffff;SUSPENSO:#7e22ce:#ffffff`, preservando os mapeamentos existentes.

### Conexão com Proxmox

`pvewhmcs_connection_host()` prefere `serverhostname` e usa `serverip` apenas como fallback. Use o hostname DNS que aparece no SAN do certificado quando **Secure** estiver habilitado; ele pode resolver para um endereço privado.

`pvewhmcs_connection_port()` usa `8006` quando o campo de porta está vazio. Isso cobre Simple Mode e Advanced Mode do WHMCS, inclusive quando desmarcar **Secure** limpa o campo na interface.

`PVE2_API::login()` classifica certificado TLS, credenciais e conectividade. `pvewhmcs_TestConnection()` retorna essas mensagens ao WHMCS em vez de `An Unknown Error Occurred`.

### Console (noVNC) sem exposição pública

O browser nunca conversa direto com o Proxmox. `pvewhmcs_noVNC()` cifra e autentica host, porta, path e o `PVEAuthCookie` do usuário restrito `vnc@pve` num token v2 de vida curta (`v2.` + AES-256-GCM, chave derivada do Console Relay Secret; `pvewhmcs_build_console_token()`, em `proxmox.php`) e monta o link apontando `vnc.html` pro host resolvido por `pvewhmcs_relay_public_endpoint()`. Esse host é `mod_pvewhmcs.console_relay_host`/`console_relay_port` quando configurado (relay em subdomínio dedicado, roteado inteiramente pelo Plesk) ou o domínio do WHMCS como fallback (relay compartilhando domínio via `proxy_pass` no prefixo `/pve-console-ws/`). Em ambos os casos o relay — código-fonte em [`MasterMindTIBR/pvewhmcs-console-relay`](https://github.com/MasterMindTIBR/pvewhmcs-console-relay), repositório separado deste, não uma pasta aqui — decifra o token, abre a conexão real `wss://` pro Proxmox (apresentando o cookie ele mesmo) e faz o bridge de bytes. O token v2 exige o relay em `4a5081f` ou posterior. Segredo compartilhado: `mod_pvewhmcs.console_relay_secret` (WHMCS) = `config.json.secret` (relay). Isso elimina PTR, mesmo-domínio-registrável e o parsing de TLD de 2 partes que a versão anterior exigia.

### Rede

O nome de rede é montado por concatenação de `plan.bridge` e `plan.vmbr`. O sufixo é opcional e pode ser textual. `vmbr` usa `VARCHAR(64)` a partir da migração `1.3.6`, preservando `vmbr` + `0`, nomes completos como `private`, e sufixos textuais.

- LXC direto: `net0` e `net1`.
- QEMU direto: `net0` e, quando IPv6 está habilitado, `net1`.
- QEMU clonado: preserva a definição e o MAC da interface do template, mas substitui a bridge de `net0` e `net1` pela rede do plano.

## Salvaguardas e resumo do servidor

1. `PVE2_API` valida o certificado do Proxmox por padrão. A configuração **Secure** do servidor WHMCS controla a validação por servidor; desmarcá-la mantém HTTPS, mas ignora certificado e hostname.
2. A reserva IPv4 usa transação e `FOR UPDATE` antes de gravar `tblhosting.dedicatedip`. Reservas de serviços cancelados ficam excluídas de alocação e exclusão até um admin liberá-las (aba Reserved IPv4).
3. A seleção e o envio do VMID usam um advisory lock MySQL por servidor WHMCS até o Proxmox aceitar a criação.
4. Todo `POST` do addon passa pelo token CSRF do módulo, checado uma vez no início de `pvewhmcs_output()`; sem token válido, o POST é descartado e nada é gravado.
5. `pvewhmcs_AdminLink()` mostra acesso ao PVE em uma coluna e, em outra, cluster, nós, QEMU e LXC. O resumo consulta `/cluster/status` e `/cluster/resources`; falhas nunca removem o atalho de login.
6. `mod_pvewhmcs_logs` grava toda ação de lifecycle (`CreateAccount`, `SuspendAccount`, `UnsuspendAccount`, `TerminateAccount`) e de energia (`vmStart`, `vmReboot`, `vmShutdown`, `vmStop`) via `pvewhmcs_run_tracked_action()`, definido em `modules/servers/pvewhmcs/pvewhmcs.php`. A gravação em si (`pvewhmcs_log_action()`) vive em `proxmox.php`, compartilhado pelos dois módulos. O wrapper nunca engole falhas: registra e relança a exceção original ou a string `"Error ..."` do handler. As abas **Actions → Action History / Failed Actions** do addon leem essa tabela; qualquer nova ação de ciclo de vida deve passar por `pvewhmcs_run_tracked_action()` para aparecer ali. Cada entrada registra `auth_id` (admin executor; 0 para cron/checkout) e `server_id` imutável (servidor Proxmox do serviço no momento da ação); a aba **Actions** é paginada e renderiza um painel por servidor ativo. Instalações existentes recebem a tabela pela migração `1.3.6`, no mesmo bloco do ajuste de `vmbr`; o DDL é idêntico, caractere a caractere, ao de `db.sql`.

## Operação TLS

O certificado do Proxmox precisa incluir a cadeia completa no `pveproxy` em `8006`. Certificados folha Let’s Encrypt sem o intermediário falham no PHP cURL com `unable to get local issuer certificate`, mesmo quando alguns navegadores aceitam a conexão por terem o intermediário em cache. Instale `fullchain.pem`, não apenas `cert.pem`.

## Console (noVNC): superfície de segurança

Até a `v1.3.6` o token era só assinado: qualquer cliente lia o ticket do `vnc@pve` e o host do Proxmox decodificando a URL. O token v2 é cifrado. Depois do deploy, troque a senha do `vnc@pve` e restrinja a porta 8006 aos servidores do WHMCS e do relay. O relay continua um processo separado do PHP do WHMCS — trate logs dele (stdout/journalctl) como parte da superfície auditável ao investigar falhas de console.

## Commit de fork analisado

O commit `bastrian/Proxmox-VE-for-WHMCS@c2f92a6d4070773b48a73932a91a9272c9c02ca5` tem o título `Decode decrypted server password in addon API logins`. Ele não muda a regra de nome de interface.

Ele tem como pai o commit atual do fork e corrigiu três caminhos administrativos que, antes da 1.3.6:

- ignoram a porta configurada no servidor WHMCS e usam o padrão `8006`;
- enviam ao Proxmox uma senha decifrada ainda codificada como entidade HTML quando ela contém caracteres como `&`;
- falham nos painéis Nodes, Guests e Logs, embora o teste do servidor possa passar.

A correção foi aplicada na versão `1.3.6` junto com a configuração TLS por servidor. Valide senhas com caracteres HTML-significativos e porta não padrão no WHMCS de homologação.

## Verificação já executada

- `php -l` em `proxmox.php`, addon e provisioning module por `php:8.3-cli`.
- Smoke tests para sufixo de rede, fallback de porta `8006`, seleção de hostname, classificação TLS/autenticação/conectividade, webhook assinado e sincronização module-only.
- `git diff --check` antes de cada publicação.
