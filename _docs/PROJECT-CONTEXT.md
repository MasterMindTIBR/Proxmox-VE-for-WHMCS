# Contexto do fork: Proxmox VE for WHMCS

## Objetivo

O projeto conecta o ciclo de vida de serviços do WHMCS ao Proxmox VE. Ele cria, suspende, reativa e remove QEMU/LXC; mostra estado e RRD na área do cliente; mantém planos, pools IPv4 e dados operacionais no addon do WHMCS.

O fork está na versão `1.3.6`, ainda não liberada, derivada do commit upstream `7ff41ccecde7`. Correções e funcionalidades continuam se acumulando nessa mesma versão até uma decisão explícita de liberar; a branch `master` contém a linha publicada do fork e o remoto `origin` aponta para `junglivre/Proxmox-VE-for-WHMCS`.

## Mapa de execução

| Área | Arquivo principal | Responsabilidade |
| --- | --- | --- |
| Addon administrativo | `modules/addons/pvewhmcs/pvewhmcs.php` | Interface administrativa, planos, pools, importação, configuração e upgrade de schema |
| Cliente da API Proxmox | `modules/addons/pvewhmcs/proxmox.php` | Login, tickets, requisições HTTP para `/api2/json` e descoberta de nós |
| Provisioning | `modules/servers/pvewhmcs/pvewhmcs.php` | Callbacks WHMCS, criação, clone, suspend, unsuspend, terminate, área do cliente e console |
| Schema | `modules/addons/pvewhmcs/db.sql` | Instalações novas |
| Console web | `modules/servers/pvewhmcs/console-relay/` e `novnc/` | Relay Node.js WS↔WSS até o Proxmox e cliente noVNC vendorizado |

## Deploy por webhook

`modules/addons/pvewhmcs/github-webhook.php` recebe somente `push` HMAC-SHA256 assinado de `junglivre/Proxmox-VE-for-WHMCS:master`. O receiver baixa o ZIP do SHA entregue, valida os caminhos e sincroniza somente os diretórios do addon e do provisioning module.

`github-webhook.local.php` guarda o segredo HMAC e, para repositório privado, um token GitHub com `Contents: Read-only`. O deploy preserva esse arquivo, o receiver e o lock; remove arquivos antigos do módulo que não existam no commit recebido.

Consulte `_docs/GITHUB-WEBHOOK-DEPLOY.md` antes de expor o endpoint no Plesk. O arquivo local não entra no Git.

O módulo usa `Illuminate\Database\Capsule\Manager` para acesso ao banco. O serviço WHMCS usa `tblhosting.id` como chave da tabela `mod_pvewhmcs_vms` e guarda o VMID real em `vmid`.

## Fluxos operacionais

### Criação

1. `pvewhmcs_CreateAccount()` carrega o plano e escolhe um endereço do pool IPv4.
2. Para `KVMTemplate`, clona uma QEMU e aplica ajustes de cloud-init.
3. Sem template, cria LXC ou QEMU com parâmetros do plano.
4. Depois de a tarefa Proxmox concluir com `OK`, grava o vínculo em `mod_pvewhmcs_vms` e o IP dedicado em `tblhosting`.

### Localização e ciclo de vida

Suspend, unsuspend, terminate, VNC e área do cliente usam `mod_pvewhmcs_vms` para localizar VMID e tipo. `pvewhmcs_find_guest_node()` consulta `/cluster/resources`, portanto a associação WHMCS→VMID precisa permanecer consistente.

### Conexão com Proxmox

`pvewhmcs_connection_host()` prefere `serverhostname` e usa `serverip` apenas como fallback. Use o hostname DNS que aparece no SAN do certificado quando **Secure** estiver habilitado; ele pode resolver para um endereço privado.

`pvewhmcs_connection_port()` usa `8006` quando o campo de porta está vazio. Isso cobre Simple Mode e Advanced Mode do WHMCS, inclusive quando desmarcar **Secure** limpa o campo na interface.

`PVE2_API::login()` classifica certificado TLS, credenciais e conectividade. `pvewhmcs_TestConnection()` retorna essas mensagens ao WHMCS em vez de `An Unknown Error Occurred`.

### Console (noVNC) sem exposição pública

O browser nunca conversa direto com o Proxmox. `pvewhmcs_noVNC()` assina host, path e o `PVEAuthCookie` do usuário restrito `vnc@pve` num token HMAC de vida curta (`pvewhmcs_build_console_token()`, em `proxmox.php`) e monta o link apontando `vnc.html` pro host resolvido por `pvewhmcs_relay_public_endpoint()`. Esse host é `mod_pvewhmcs.console_relay_host`/`console_relay_port` quando configurado (relay em subdomínio dedicado, roteado inteiramente pelo Plesk) ou o domínio do WHMCS como fallback (relay compartilhando domínio via `proxy_pass` no prefixo `/pve-console-ws/`). Em ambos os casos o processo Node em `modules/servers/pvewhmcs/console-relay/` decodifica o token, abre a conexão real `wss://` pro Proxmox (apresentando o cookie ele mesmo) e faz o bridge de bytes. Segredo compartilhado: `mod_pvewhmcs.console_relay_secret` (WHMCS) = `config.json.secret` (relay). Isso elimina PTR, mesmo-domínio-registrável e o parsing de TLD de 2 partes que a versão anterior exigia.

### Rede

O nome de rede é montado por concatenação de `plan.bridge` e `plan.vmbr`. O sufixo é opcional e pode ser textual. `vmbr` usa `VARCHAR(64)` a partir da migração `1.3.6`, preservando `vmbr` + `0`, nomes completos como `private`, e sufixos textuais.

- LXC direto: `net0` e `net1`.
- QEMU direto: `net0` e, quando IPv6 está habilitado, `net1`.
- QEMU clonado: preserva a definição e o MAC da interface do template, mas substitui a bridge de `net0` e `net1` pela rede do plano.

## Salvaguardas e resumo do servidor

1. `PVE2_API` valida o certificado do Proxmox por padrão. A configuração **Secure** do servidor WHMCS controla a validação por servidor; desmarcá-la mantém HTTPS, mas ignora certificado e hostname.
2. A reserva IPv4 usa transação e `FOR UPDATE` antes de gravar `tblhosting.dedicatedip`.
3. A seleção e o envio do VMID usam um advisory lock MySQL por servidor WHMCS até o Proxmox aceitar a criação.
4. Exclusões administrativas de planos, pools e IPs usam `POST` protegido por token CSRF.
5. `pvewhmcs_AdminLink()` mostra acesso ao PVE em uma coluna e, em outra, cluster, nós, QEMU e LXC. O resumo consulta `/cluster/status` e `/cluster/resources`; falhas nunca removem o atalho de login.
6. `mod_pvewhmcs_logs` grava toda ação de lifecycle (`CreateAccount`, `SuspendAccount`, `UnsuspendAccount`, `TerminateAccount`) e de energia (`vmStart`, `vmReboot`, `vmShutdown`, `vmStop`) via `pvewhmcs_run_tracked_action()`, definido em `modules/servers/pvewhmcs/pvewhmcs.php`. A gravação em si (`pvewhmcs_log_action()`) vive em `proxmox.php`, compartilhado pelos dois módulos. O wrapper nunca engole falhas: registra e relança a exceção original ou a string `"Error ..."` do handler. As abas **Actions → Action History / Failed Actions** do addon leem essa tabela; qualquer nova ação de ciclo de vida deve passar por `pvewhmcs_run_tracked_action()` para aparecer ali. Instalações existentes recebem a tabela pela migração `1.3.6`, no mesmo bloco do ajuste de `vmbr`; o DDL é idêntico, caractere a caractere, ao de `db.sql`.

## Operação TLS

O certificado do Proxmox precisa incluir a cadeia completa no `pveproxy` em `8006`. Certificados folha Let’s Encrypt sem o intermediário falham no PHP cURL com `unable to get local issuer certificate`, mesmo quando alguns navegadores aceitam a conexão por terem o intermediário em cache. Instale `fullchain.pem`, não apenas `cert.pem`.

## Console (noVNC): superfície de segurança resolvida

O ponto pendente anterior (ticket no query string + cookie compartilhado por domínio registrável) foi resolvido: veja "Console (noVNC) sem exposição pública" acima. O relay ainda é um processo separado do PHP do WHMCS — trate logs dele (stdout/journalctl) como parte da superfície auditável ao investigar falhas de console.

## Commit de fork analisado

O commit `bastrian/Proxmox-VE-for-WHMCS@c2f92a6d4070773b48a73932a91a9272c9c02ca5` tem o título `Decode decrypted server password in addon API logins`. Ele não muda a regra de nome de interface.

Ele tem como pai o commit atual do fork e corrige três caminhos administrativos que hoje:

- ignoram a porta configurada no servidor WHMCS e usam o padrão `8006`;
- enviam ao Proxmox uma senha decifrada ainda codificada como entidade HTML quando ela contém caracteres como `&`;
- falham nos painéis Nodes, Guests e Logs, embora o teste do servidor possa passar.

A correção foi aplicada na versão `1.3.6` junto com a configuração TLS por servidor. Valide senhas com caracteres HTML-significativos e porta não padrão no WHMCS de homologação.

## Verificação já executada

- `php -l` em `proxmox.php`, addon e provisioning module por `php:8.3-cli`.
- Smoke tests para sufixo de rede, fallback de porta `8006`, seleção de hostname, classificação TLS/autenticação/conectividade, webhook assinado e sincronização module-only.
- `git diff --check` antes de cada publicação.
