# Plano de otimização das chamadas ao Proxmox

Status:
- **B e a parte RRD da área do cliente foram feitas** (branch `fix/production-readiness`).
- **O bug de expiração do ticket foi corrigido.**
- A, C e D continuam como plano.

As referências usam nomes de função, não números de linha.

## Evidência coletada

### 1. Nenhum reaproveitamento de sessão entre requisições

`PVE2_API::login()` (`modules/addons/pvewhmcs/proxmox.php`) guarda `ticket` e `CSRFPreventionToken` só na memória da instância. Cada requisição HTTP do WHMCS é um processo PHP novo, então nada sobrevive entre carregamentos de página.

Resultado: toda ação cria um `PVE2_API` novo e faz login do zero. Isso vale para:
- `pvewhmcs_vmStart_impl()` e as outras ações de energia;
- `pvewhmcs_ClientArea()` e `pvewhmcs_noVNC()`;
- os callbacks de ciclo de vida;
- `pvewhmcs_AdminLink()`;
- as abas Nodes, Guests e Logs do addon.

Login no Proxmox é uma autenticação PAM completa no servidor.

Dentro de uma mesma requisição, `check_login_ticket()` renova o ticket 5 minutos antes das 2 h de validade (`LOGIN_TICKET_LIFETIME - LOGIN_TICKET_RENEW_MARGIN`).

### 2. `/cluster/resources` para achar o node de um guest

`pvewhmcs_find_guest_resource()` busca `/cluster/resources` inteiro (node + qemu + lxc + storage + pool do cluster) para achar o node de **um** VMID. `pvewhmcs_find_guest_node()` o chama nos seguintes pontos:
- ações de energia;
- `pvewhmcs_noVNC()`;
- callbacks de ciclo de vida.

`pvewhmcs_ClientArea()` já faz uma leitura só e reaproveita o resultado para o node e para o status (opção B, feita).

### 3. `mod_pvewhmcs_vms.node_id` existe no schema mas não é usado

O `db.sql` tem a coluna `node_id`, mas nada grava nem lê dela. O node é sempre redescoberto pelo scan do cluster, mesmo quando o guest não migrou desde a criação (o caso comum).

### 4. Aba Nodes: RRD síncrono, 4 chamadas por node

`pvewhmcs_addon_fetch_rrd()` é chamado 4 vezes por node (cpu, memused, netin/netout, iowait), em requisições sequenciais que o Proxmox responde renderizando um PNG. Um cluster de 5 nodes faz 20 round-trips seguidos só de gráfico.

Na área do cliente, os 16 gráficos de `pvewhmcs_fetch_rrd_stat()` só são buscados na tela Statistics (`a=vmStat`); antes eram buscados em toda abertura da página.

## Opções (independentes, podem ser combinadas)

### A. Cache do ticket de login por servidor (maior impacto, risco baixo)

**Proposta:**
- Guardar `{ticket, CSRFPreventionToken, criado_em}` por `tblservers.id` num cache compartilhado: APCu, ou uma tabela dedicada se o APCu não estiver disponível.
- TTL abaixo da validade real (ex.: 100 de 120 minutos).
- `PVE2_API::login()` consulta o cache primeiro e só faz `POST /access/ticket` quando o ticket está ausente, expirado ou foi rejeitado (401).

**Ganho:** elimina a autenticação PAM em quase toda ação.

**Riscos:**
- A escrita concorrente entre processos PHP precisa ser segura (há o mesmo padrão no advisory lock de VMID).
- O cache precisa ser invalidado quando a senha do servidor mudar.
- O ticket fica em repouso no banco ou no APCu, o que o torna um segredo a proteger.

### B. Uma leitura de `/cluster/resources` em `pvewhmcs_ClientArea()` — **feito**

`pvewhmcs_find_guest_resource()` aceita o payload já lido. A área do cliente faz uma única leitura e usa o mesmo resultado para node e `vm_status`.

### C. Cachear o node resolvido em `mod_pvewhmcs_vms.node_id`

**Proposta:**
- Gravar o node na primeira resolução.
- Nas ações seguintes, chamar direto `/nodes/{node}/{vtype}/{vmid}/status/current`.
- Voltar ao scan de `/cluster/resources` só se essa chamada falhar (o guest migrou).

**Riscos:**
- Exige migração de schema idempotente com versão nova (regra do `AGENTS.md`): a coluna entrou no `db.sql` em 2025-08-01 (`9c8a677`) e nenhuma migração a cria, então instalações ativadas antes disso não a têm.
- É preciso definir a invalidação após live migration feita fora do WHMCS.

### D. Paralelizar o RRD da aba Nodes

**Proposta:** `curl_multi_exec` para buscar os 4 gráficos de cada node em paralelo, e também entre nodes.

**Riscos:**
- É a maior mudança estrutural em `PVE2_API` e `pvewhmcs_addon_fetch_rrd()`.
- Um gráfico com falha ou timeout não pode travar os outros.

### E. (Descartada por ora) Cache do payload de `/cluster/resources`

Cachear por 5 a 10 s evitaria refetch entre ações próximas, mas logo após uma ação (ex.: Start seguido de leitura de status) a tela mostraria estado antigo. A e C resolvem a maior parte do custo sem esse problema.

## Ordem sugerida (para discussão)

```
C (schema pequeno, elimina o scan no caso comum)
  → A (maior ganho agregado, mais delicado por ser cache compartilhado)
    → D (só se a aba Nodes continuar lenta)
```
