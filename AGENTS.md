# Instruções para agentes

## Escopo

Este repositório é um fork do módulo GPL-3.0 **Proxmox VE for WHMCS**. O módulo provisiona e administra VMs QEMU e containers LXC no Proxmox VE a partir do WHMCS.

Edite os dois módulos quando a mudança atravessar a integração:

- `modules/addons/pvewhmcs/`: administração, planos, pools IPv4, schema e cliente HTTP da API do Proxmox.
- `modules/servers/pvewhmcs/`: callbacks de provisioning do WHMCS, área do cliente, ciclo de vida das instâncias e noVNC.

O diretório `modules/servers/pvewhmcs/novnc/` é uma cópia vendorizada do noVNC. Não altere-o para corrigir código do módulo. Atualize-o somente como dependência vendorizada, com origem e versão explícitas.

## Arquitetura e dados

- `modules/addons/pvewhmcs/proxmox.php` define `PVE2_API`, usado por ambos os módulos. Alterações no transporte, autenticação ou TLS afetam todas as operações.
- `mod_pvewhmcs_plans` armazena os planos. `bridge` + `vmbr` formam o nome da bridge/rede usado no Provisioning.
- `mod_pvewhmcs_vms` associa `tblhosting.id` ao VMID, tipo QEMU/LXC, IP e cliente. Não apague ou reatribua linhas sem preservar essa relação.
- `db.sql` atende instalações novas. Mudanças de schema também exigem uma migração idempotente em `pvewhmcs_upgrade()` para instalações existentes.
- A versão é duplicada em `modules/addons/pvewhmcs/pvewhmcs.php` e no arquivo raiz `version`; mantenha ambos sincronizados e atualize `CHANGELOG.md` para uma mudança liberável.

## Regras de mudança

1. Trate callbacks do WHMCS como operações distribuídas. Uma ação pode criar um recurso no Proxmox e falhar antes de registrar o vínculo no banco. Preserve mensagens de erro úteis e evite deletar uma VM sem verificar `mod_pvewhmcs_vms`.
2. Preservar o suporte a IPs IPv4 e IPv6 literais. `PVE2_API` já delimita IPv6 com colchetes ao compor URLs.
3. Campos de plano administrados pelo usuário precisam de validação de servidor, mesmo quando a interface HTML tem `required`.
4. Para mudanças de rede, atualize todos os caminhos de criação: LXC direto, QEMU direto e clone QEMU. Não suponha que o campo de plano seja aplicado em todos eles.
5. Não introduza novas credenciais em logs, URLs ou mensagens de erro. O modo debug do WHMCS tem dados operacionais sensíveis.
6. TLS precisa validar certificado por padrão. A exceção por servidor usa a configuração Secure do WHMCS e deve ser documentada como uma decisão explícita.
7. Preservar compatibilidade de schema em upgrades. Não dependa de reinstalação ou de edição manual da base.
8. Correções pontuais verificadas devem receber commit convencional e `push` para `origin/master` sem pedir confirmação. Peça confirmação antes de uma mudança ampla de arquitetura, dependências, schema, comportamento de provisioning ou superfície de segurança.
9. Para conexões Proxmox, prefira `serverhostname`; `serverip` é o fallback. Porta vazia significa `8006`. Não troque validação TLS por bypass global: `Secure` continua a exceção explícita por servidor.
10. `pvewhmcs_AdminLink()` consulta estatísticas ao vivo. Falhas em `/cluster/status` ou `/cluster/resources` não podem remover nem atrasar o atalho de login de forma perceptível.

## Verificação

Não há suíte PHP do módulo no repositório. Antes de entregar uma alteração PHP, execute lint com o interpretador PHP disponível e faça um smoke test contra um ambiente WHMCS + Proxmox descartável ou de homologação. Exercite o callback alterado e confirme no Proxmox e nas tabelas WHMCS.

Para mudanças no noVNC, use os scripts definidos em `modules/servers/pvewhmcs/novnc/package.json` e não misture a saída gerada com alterações do módulo PHP.
