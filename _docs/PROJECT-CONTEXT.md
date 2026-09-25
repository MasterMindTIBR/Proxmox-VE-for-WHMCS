# Contexto do fork: Proxmox VE for WHMCS

## Objetivo

O projeto conecta o ciclo de vida de serviços do WHMCS ao Proxmox VE. Ele cria, suspende, reativa e remove QEMU/LXC; mostra estado e RRD na área do cliente; mantém planos, pools IPv4 e dados operacionais no addon do WHMCS.

O fork está preparando a versão `1.3.6`, derivada do commit upstream `7ff41ccecde7`. O remoto `origin` aponta para `junglivre/Proxmox-VE-for-WHMCS`.

## Mapa de execução

| Área | Arquivo principal | Responsabilidade |
| --- | --- | --- |
| Addon administrativo | `modules/addons/pvewhmcs/pvewhmcs.php` | Interface administrativa, planos, pools, importação, configuração e upgrade de schema |
| Cliente da API Proxmox | `modules/addons/pvewhmcs/proxmox.php` | Login, tickets, requisições HTTP para `/api2/json` e descoberta de nós |
| Provisioning | `modules/servers/pvewhmcs/pvewhmcs.php` | Callbacks WHMCS, criação, clone, suspend, unsuspend, terminate, área do cliente e console |
| Schema | `modules/addons/pvewhmcs/db.sql` | Instalações novas |
| Console web | `modules/servers/pvewhmcs/novnc_router.php` e `novnc/` | Ticket de console e noVNC vendorizado |

## Deploy por webhook

`modules/addons/pvewhmcs/github-webhook.php` recebe pushes assinados de `junglivre/Proxmox-VE-for-WHMCS:master`, baixa o arquivo do SHA recebido e sincroniza somente os diretórios do addon e do server module. A configuração local `github-webhook.local.php` contém o segredo HMAC e, para repositório privado, um token GitHub de leitura. Ela é ignorada pelo Git e não pode ser removida pelo deploy.

Consulte `_docs/GITHUB-WEBHOOK-DEPLOY.md` antes de expor o endpoint no Plesk.

O módulo usa `Illuminate\Database\Capsule\Manager` para acesso ao banco. O serviço WHMCS usa `tblhosting.id` como chave da tabela `mod_pvewhmcs_vms` e guarda o VMID real em `vmid`.

## Fluxos operacionais

### Criação

1. `pvewhmcs_CreateAccount()` carrega o plano e escolhe um endereço do pool IPv4.
2. Para `KVMTemplate`, clona uma QEMU e aplica ajustes de cloud-init.
3. Sem template, cria LXC ou QEMU com parâmetros do plano.
4. Depois de a tarefa Proxmox concluir com `OK`, grava o vínculo em `mod_pvewhmcs_vms` e o IP dedicado em `tblhosting`.

### Localização e ciclo de vida

Suspend, unsuspend, terminate, VNC e área do cliente usam `mod_pvewhmcs_vms` para localizar VMID e tipo. `pvewhmcs_find_guest_node()` consulta `/cluster/resources`, portanto a associação WHMCS→VMID precisa permanecer consistente.

### Rede

O nome de rede é montado por concatenação de `plan.bridge` e `plan.vmbr`. O sufixo é opcional e pode ser textual. `vmbr` usa `VARCHAR(64)` a partir da migração `1.3.6`, preservando `vmbr` + `0`, nomes completos como `private`, e sufixos textuais.

- LXC direto: `net0` e `net1`.
- QEMU direto: `net0` e, quando IPv6 está habilitado, `net1`.
- QEMU clonado: preserva a definição e o MAC da interface do template, mas substitui a bridge de `net0` e `net1` pela rede do plano.

## Salvaguardas implementadas na versão 1.3.6

1. `PVE2_API` valida o certificado do Proxmox por padrão. A configuração **Secure** do servidor WHMCS controla a validação por servidor; desmarcá-la mantém HTTPS, mas ignora certificado e hostname.
2. A reserva IPv4 usa transação e `FOR UPDATE` antes de gravar `tblhosting.dedicatedip`.
3. A seleção e o envio do VMID usam um advisory lock MySQL por servidor WHMCS até o Proxmox aceitar a criação.
4. Exclusões administrativas de planos, pools e IPs usam `POST` protegido por token CSRF.

## Ponto pendente antes de produção

O console entrega tickets no query string e cria um cookie compartilhado pelo domínio registrável. Trate noVNC como superfície de segurança e valide a topologia de domínios, logs HTTP e proxy reverso antes de mudar esse fluxo.

## Commit de fork analisado

O commit `bastrian/Proxmox-VE-for-WHMCS@c2f92a6d4070773b48a73932a91a9272c9c02ca5` tem o título `Decode decrypted server password in addon API logins`. Ele não muda a regra de nome de interface.

Ele tem como pai o commit atual do fork e corrige três caminhos administrativos que hoje:

- ignoram a porta configurada no servidor WHMCS e usam o padrão `8006`;
- enviam ao Proxmox uma senha decifrada ainda codificada como entidade HTML quando ela contém caracteres como `&`;
- falham nos painéis Nodes, Guests e Logs, embora o teste do servidor possa passar.

A correção foi aplicada na versão `1.3.6` junto com a configuração TLS por servidor. Valide senhas com caracteres HTML-significativos e porta não padrão no WHMCS de homologação.
