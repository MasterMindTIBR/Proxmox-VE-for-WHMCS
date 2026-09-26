# Plano de otimização das chamadas ao Proxmox

Status: **plano, não implementado**. Este documento só registra evidência e opções; nenhuma mudança de código foi feita a partir dele.

## Evidência coletada (com linha/arquivo)

### 1. Nenhum reaproveitamento de sessão entre requisições

`PVE2_API::login()` (`modules/addons/pvewhmcs/proxmox.php:228`) guarda `ticket`/`CSRFPreventionToken` só em memória da instância (`$this->login_ticket`). Como cada requisição HTTP do WHMCS é um processo PHP novo, isso nunca sobrevive entre page loads. Resultado: **toda** ação — clique em Start/Reboot/Shutdown/Stop, abrir a área do cliente, abrir noVNC/SPICE, cada aba admin — cria um `PVE2_API` novo e faz login do zero (`modules/servers/pvewhmcs/pvewhmcs.php:955,997,1047,1404,1539,1554`; `modules/addons/pvewhmcs/pvewhmcs.php:544,745,1205`). Login no Proxmox é uma autenticação PAM completa no servidor, não é barato.

O ticket já é válido por até 2h (`check_login_ticket()`, `proxmox.php:339`), mas isso nunca é aproveitado entre requisições.

### 2. `/cluster/resources` buscado duas vezes na mesma requisição

Em `pvewhmcs_ClientArea()` (`modules/servers/pvewhmcs/pvewhmcs.php:1386`), toda vez que o cliente abre a página do serviço:

```
1408: pvewhmcs_find_guest_node($proxmox, $guest, ...)   → dentro, chama GET /cluster/resources (linha 1909)
1417: $cluster_resources = $proxmox->get('/cluster/resources');   → chama de novo, mesmo payload
```

O mesmo padrão (login + `find_guest_node` chamando `/cluster/resources` inteiro só para achar o node de UM guest) se repete em `vmStart`/`vmReboot`/`vmShutdown`/`vmStop` (linhas 955-1048) e em `noVNC`/`SPICE` (linhas 1404-1408, 1539-1558). `/cluster/resources` devolve node+qemu+lxc+storage+pool do cluster inteiro — caro para clusters com muitos guests, e buscado só para achar o node de UM VMID.

### 3. `mod_pvewhmcs_vms.node_id` existe no schema mas não é usado

`db.sql:127` já tem a coluna `node_id`, mas nada grava ou lê dela — `pvewhmcs_find_guest_node()` sempre redescobre o node via scan completo do cluster, mesmo quando o guest não migrou de node desde a criação (o caso comum).

### 4. Aba Nodes: RRD é síncrono, 4 chamadas por node

`pvewhmcs_addon_fetch_rrd()` é chamado 4x por node (cpu/mem/net/io — `modules/addons/pvewhmcs/pvewhmcs.php` dentro do loop de nodes), cada uma uma requisição HTTP separada e sequencial que o Proxmox responde renderizando um PNG no próprio servidor. Cluster com 5 nodes = 20 round-trips sequenciais só de gráfico, symptom que bate exatamente com "lentidão bem grande" ao abrir essa aba.

## Opções (independentes, podem ser combinadas)

### A. Cache do ticket de login por servidor (maior impacto, risco baixo)

Persistir `{ticket, CSRFPreventionToken, criado_em}` por `tblservers.id` num cache rápido (APCu se disponível; senão uma linha em `mod_pvewhmcs` ou tabela dedicada) com TTL menor que a validade real do Proxmox (ex.: 100min de 120min). `PVE2_API::login()` primeiro confere o cache; só faz POST `/access/ticket` se ausente/expirado/rejeitado (fallback automático em caso de 401).

Elimina a autenticação PAM completa em quase toda ação — o maior custo fixo por requisição.

**Risco:** cache compartilhado entre processos PHP precisa ser thread-safe (comparar com o advisory lock de VMID já usado no projeto); precisa invalidar corretamente se a senha do servidor mudar.

### B. Eliminar o `/cluster/resources` duplicado em `pvewhmcs_ClientArea()`

Buscar `/cluster/resources` **uma vez**, usar o mesmo resultado para achar o node E o `vm_status`, em vez de `find_guest_node()` buscar de novo internamente. Mudança pequena, localizada, zero risco de regressão de comportamento.

### C. Cachear o node resolvido em `mod_pvewhmcs_vms.node_id`

Gravar o node achado por `find_guest_node()` na primeira resolução; ações seguintes tentam usar esse valor direto contra `/nodes/{node}/{vtype}/{vmid}/status/current` e só caem para o scan completo de `/cluster/resources` se a chamada direta falhar (404 = migrou de node). Reduz o `/cluster/resources` completo para o caso raro (migração), não o caso comum (toda ação).

**Risco:** precisa de uma migração de schema idempotente (o padrão já documentado em `AGENTS.md`) e de decidir quando invalidar (ex.: após uma live migration manual fora do WHMCS).

### D. Paralelizar as 4 chamadas de RRD por node

Usar `curl_multi_exec` (ou `Fibers`/promises se o cliente HTTP for trocado) para buscar cpu/mem/net/io em paralelo por node, e paralelizar entre nodes também. 4 chamadas sequenciais por node viram ~1 round-trip de latência.

**Risco:** maior mudança estrutural em `PVE2_API`/`pvewhmcs_addon_fetch_rrd()`; precisa tratar timeout parcial (1 gráfico falha, outros 3 não devem travar a aba).

### E. (Descartada por ora) Cache do payload de `/cluster/resources` em si

Cachear o cluster_resources por alguns segundos (ex. 5-10s) evitaria refetch entre ações próximas no tempo, mas introduz dado potencialmente desatualizado logo após uma ação (ex.: Start e checar status na sequência veria estado antigo). Combinar com B+C já resolve a maior parte do custo sem esse risco de staleness — só reconsiderar se B+C não bastarem.

## Ordem sugerida (não decidida, para discussão)

```
B (zero risco, imediato)
  → C (schema pequeno, elimina custo do caso comum)
    → A (maior ganho agregado, mais delicado por ser cache compartilhado)
      → D (só se a aba Nodes continuar lenta após A+B+C)
```

## Fora de escopo deste plano

Bug pré-existente e não relacionado encontrado durante a investigação: `check_login_ticket()` (`proxmox.php:339`) compara `$this->login_ticket_timestamp >= (time() + 7200)`, que parece sempre falso (deveria provavelmente ser `<= time() - 7200`, ou seja, "criado há mais de 2h"). Não mexi nisso agora — está fora do pedido desta rodada, mas relevante se a opção A for implementada (o cache proposto tem sua própria lógica de TTL e não depende deste método).
