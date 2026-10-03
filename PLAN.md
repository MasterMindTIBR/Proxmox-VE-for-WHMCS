# Plano de correções de produção

## Foco

Publicar o fork em produção sem criar VM, IP, schema ou deploy parcial; manter a VM cancelada recuperável sem reutilizar seu IPv4 até liberação explícita do admin.

## Limites

- Base: `fix/production-readiness` em `cdf1989`.
- O merge local em `master` e todos os commits desta rodada seguem em um único `git push origin master`, porque cada push dispara o webhook de produção.
- O relay sobe de `4a5081f` para `73d396a` antes do módulo. O relay continua compatível com token v1 e v2.
- Versão do módulo: `1.3.7` em `pvewhmcs_version()` e no arquivo raiz `version`. Sem tag ou GitHub Release nesta rodada.
- Nenhum segredo vai para log, URL, teste ou documentação.
- Não apagar VMs, IPs, logs ou tabelas existentes automaticamente.

## Rock 0. Cutover já autorizado

**Arquivos/processos:** branch local, `origin/master`, `/opt/pvewhmcs-console-relay` em nebula.

**Mudança:** depois da revisão do plano, fazer merge sem fast-forward de `fix/production-readiness` em `master`; atualizar o relay para `73d396a`, reiniciar o serviço e confirmar `/healthz`. Segurar o push de `master` até os Rocks 1 a 7 passarem.

**Pronto quando:** a árvore local `master` contém `cdf1989`; o relay em produção responde `ok` no commit `73d396a`; a `origin/master` ainda não recebeu o módulo até a validação final.

**Prova:** `git merge-base --is-ancestor cdf1989 master`; no host, `git rev-parse --short HEAD`, `systemctl is-active pvewhmcs-console-relay` e `curl -fsS http://127.0.0.1:8765/healthz`.

## Rock 1. Schema garantido e versão 1.3.7

**Arquivos:** `modules/addons/pvewhmcs/db.sql`, `modules/addons/pvewhmcs/pvewhmcs.php`, `modules/addons/pvewhmcs/proxmox.php`, `modules/servers/pvewhmcs/pvewhmcs.php`, `version`, `CHANGELOG.md`, `_docs/UPDATE-SQL.md`.

**Mudança:**
- Criar um mecanismo compartilhado, idempotente e executado uma vez por requisição que verifica as tabelas e colunas usadas pelo módulo; se faltar estrutura, cria ou repara antes de qualquer callback.
- Cobrir `mod_pvewhmcs`, planos, pools/endereços IPv4, vínculos de guest e logs. Instalações novas e atualizadas precisam terminar no mesmo schema.
- Introduzir `schema_version` e os campos de provisionamento do Rock 2 em `db.sql` e no bloco novo `version_compare(..., '1.3.7', 'lt')`.
- Trocar o `vmbr->change()` desprotegido da migração 1.3.6 por caminho que não interrompe upgrades antigos; a garantia 1.3.7 corrige o tipo para `VARCHAR(64)` mesmo quando o bloco antigo falhar.
- Chamar a garantia nos callbacks administrativos, lifecycle, Client Area e console antes de acessarem as tabelas.

**Pronto quando:** callback em banco incompleto cria as colunas/tabelas necessárias; a estrutura completa não faz DDL repetido na mesma requisição; 1.3.7 atualiza `schema_version`; instalações antigas não param em `vmbr`.

**Prova:** smoke MariaDB que começa com um schema pré-1.3.6 e outro sem tabela de logs, chama os callbacks e valida colunas, tipos e versão; `php -l` em todos os PHP alterados.

## Rock 2. Provisionamento recuperável e clone correto

**Arquivos:** `modules/servers/pvewhmcs/pvewhmcs.php`, schema do Rock 1.

**Mudança:**
- Gravar uma linha de provisionamento antes de enviar Create/Clone ao Proxmox, com VMID, tipo, IP, estado e UPID. A linha impede uma segunda VM quando PHP cai ou o polling expira.
- Retomar uma tarefa pendente ao repetir CreateAccount. Tarefa concluída finaliza o vínculo; tarefa falha continua visível e bloqueia novo guest, sem apagar recurso remoto por suposição.
- Se o processo morrer antes de obter o UPID, conferir o VMID no cluster antes de remover a reserva local. Se o guest existe, manter o vínculo para intervenção administrativa.
- Validar o source de clone QEMU: `GET /config` precisa indicar `template=1`.
- Trocar a configuração pós-clone por `PUT /config` síncrono e esperar a tarefa de start quando o plano liga o guest.
- Liberar um IP pré-reservado apenas quando a criação não foi aceita pelo Proxmox e não existe marcador/guest para proteger.

**Pronto quando:** timeout, queda entre passos, falha de tarefa e repetição do callback não criam VM ou IP duplicado; clone de VM normal é recusado; clone válido só inicia depois de aplicar a configuração.

**Prova:** mock de Proxmox cobre Create/Clone pendente, OK, falha, crash antes/depois do UPID, source sem `template`, config síncrona e start aguardado.

## Rock 3. IPv4 reservado para VM CANCELADO

**Arquivos:** `modules/servers/pvewhmcs/pvewhmcs.php`, `modules/addons/pvewhmcs/pvewhmcs.php`, `CHANGELOG.md`, `README.md`.

**Mudança:**
- Tratar como reservado um endereço de pool ligado a serviço `Terminated` que ainda possui vínculo em `mod_pvewhmcs_vms`.
- Excluir esses endereços da reserva de novos serviços, da exclusão de endereço e da exclusão de pool.
- Criar a opção **Reserved IPv4** na aba IPv4. Listar IP, pool, serviço, VMID e cliente.
- Implementar **Release reservation** com POST + CSRF. A ação limpa somente `tblhosting.dedicatedip`, mantém a VM e o histórico do IP na linha do guest, e exige confirmação que a VM CANCELADO não pode voltar à rede com aquele IP.

**Pronto quando:** Terminate mantém o IP fora do pool; a tela mostra a reserva; a liberação torna o IP elegível para nova alocação sem apagar guest ou endereço do pool.

**Prova:** smoke MariaDB com serviço Terminated + guest vinculado, alocação concorrente, listagem, tentativa de excluir pool e liberação manual.

## Rock 4. Webhook serial, atual e atômico por árvore

**Arquivos:** `modules/addons/pvewhmcs/github-webhook.php`, `_docs/GITHUB-WEBHOOK-DEPLOY.md`, testes descartáveis do webhook.

**Mudança:**
- Usar lock bloqueante fora da árvore trocada e manter estado local do último SHA aplicado.
- Ignorar `after` como alvo de deploy. Depois do lock, consultar o HEAD atual de `master` pela API GitHub e pular se já estiver aplicado.
- Configurar `ignore_user_abort(true)` e limite de execução; responder 202 antes do trabalho quando PHP-FPM fornecer `fastcgi_finish_request()`.
- Extrair, copiar preservados locais e montar as duas árvores em staging no mesmo filesystem. Trocar cada árvore com `rename`, manter backup, e reverter a primeira se a segunda falhar.
- Preservar `github-webhook.local.php` e os arquivos de estado fora da árvore de código. O `github-webhook.php` versionado continua atualizável.

**Pronto quando:** entrega concorrente espera em vez de receber 409; entrega antiga não faz rollback; falha no segundo swap restaura a primeira árvore; código novo não começa com diretório parcialmente copiado.

**Prova:** harness com filesystem temporário e GitHub HTTP simulado cobre lock, SHA antigo, SHA repetido, swap com falha e preservação de arquivo local.

## Rock 5. Ações de runtime coerentes

**Arquivos:** `modules/servers/pvewhmcs/pvewhmcs.php`, `modules/addons/pvewhmcs/pvewhmcs.php`, `CHANGELOG.md`, `README.md`.

**Mudança:**
- Fazer Start/Reboot/Shutdown/Stop esperar o UPID e reportar falha ou timeout; não mudar estado de HA fora da intenção registrada.
- Em Unsuspend sem `ha_suspended`, ler o estado HA atual. Se existe recurso HA em estado administrativamente alterado, retornar erro em vez de chamar start direto.
- Unificar Secure: `NULL` mantém TLS seguro por padrão; valor vazio ou falso de `tblservers.secure` desliga a validação em todos os caminhos, como o checkbox do WHMCS documenta.
- Escapar valores persistidos de planos na listagem e remover a duplicação futura de `time2format()` sem introduzir carregamento cruzado entre módulos.

**Pronto quando:** ações de energia só retornam sucesso depois de `OK`; serviço HA alterado fora do módulo não é reativado pelo Unsuspend; os dois módulos calculam TLS igual; título de plano malicioso renderiza como texto.

**Prova:** mocks de UPID OK/falha/timeout, HA `disabled`, TLS `NULL`/`on`/vazio, e saída HTML de plano com caracteres especiais.

## Rock 6. Observabilidade administrativa sem apagar histórico

**Arquivos:** `modules/addons/pvewhmcs/proxmox.php`, `modules/addons/pvewhmcs/pvewhmcs.php`, schema do Rock 1, `CHANGELOG.md`, `README.md`.

**Mudança:**
- Preencher `auth_id` quando uma sessão admin executa a ação; não gravar parâmetros ou senhas em `request`.
- Mostrar Logs para todos os servidores habilitados, com isolamento por servidor como Nodes e Guests.
- Paginar Action History e Failed Actions com limite explícito e filtros seguros de página/resultado.
- Adicionar índices para as consultas de logs. Não introduzir retenção automática.

**Pronto quando:** ação de admin registra o executor; uma falha em um servidor não remove logs dos demais; páginas posteriores mostram linhas distintas; tabela de logs suporta as consultas usadas pela UI.

**Prova:** smoke MariaDB com dois servidores, entradas de log > limite e sessão admin; validar paginação, isolamento e ausência de segredos no registro.

## Rock 7. Documento e publicação única

**Arquivos:** `CHANGELOG.md`, `README.md`, `_docs/ANALISE-PRODUCAO.md`, `_docs/PROJECT-CONTEXT.md`, `_docs/UPDATE-SQL.md`, `AGENTS.md` se a garantia alterar a regra operacional.

**Mudança:** documentar 1.3.7 em `[Unreleased]`, o esquema garantido, a reserva manual de IP, o comportamento do webhook e os limites que ficaram para discussão. Atualizar a análise para retirar correções concluídas e manter sugestões adiadas.

**Pronto quando:** documentação descreve o código publicado; não declara tag/release inexistente; os comandos de recuperação correspondem ao schema real.

**Prova:** links locais válidos, `git diff --check`, revisão do diff completo e smoke final dos Rocks 1 a 6.
