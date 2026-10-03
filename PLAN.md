# Plano de correções e validação em staging

## Foco

Validar o fork em staging sem criar VM, IP, schema ou deploy parcial; manter a VM cancelada recuperável sem reutilizar seu IPv4 até liberação explícita do admin.

## Limites

- Base: `fix/production-readiness` em `cdf1989`; o SHA do merge é congelado e registrado antes do pacote de staging.
- O webhook GitHub `685685824` foi desativado. `git push origin master` não publica o host; esta rodada usa deploy manual por SSH.
- O pacote manual nasce de `git archive` do SHA de `master`, contém manifesto ordenado dos arquivos versionados dos dois módulos e SHA-256 do tar; segue por uma sessão tmux, recebe backup remoto e grava manifesto local com SHA e hash.
- O relay sobe de `4a5081f` para `73d396a` antes do módulo. O relay continua compatível com token v1 e v2.
- Versão do módulo: `1.3.7` em `pvewhmcs_version()` e no arquivo raiz `version`. Sem tag ou GitHub Release nesta rodada.
- Nenhum segredo vai para log, URL, teste ou documentação.
- Não apagar VMs, IPs, logs ou tabelas existentes automaticamente.

## Rock 0. Cutover manual de staging já autorizado

**Arquivos/processos:** branch local, `origin/master`, `/opt/pvewhmcs-console-relay` associado ao WHMCS de staging e `/var/www/vhosts/91008900.xyz/httpdocs` em nebula.

**Mudança:** concluir os Rocks 1 a 7 na branch `fix/production-readiness`, revisar e testar tudo nela; então congelar seu SHA, fazer merge sem fast-forward em `master`, executar um único `git push origin master` e gerar o pacote a partir desse SHA de `master`. Atualizar o relay associado ao site para `73d396a`, reiniciar seu serviço, confirmar `/healthz` e executar um teste local de decode v2 com segredo e token sintéticos. Antes de instalar o pacote, guardar o valor original de `MaintenanceMode`, suspender apenas o site `91008900.xyz` com `plesk bin site --suspend`, preservar o crontab completo de `nove1zz`, remover somente a entrada identificada que executa `httpdocs/crons/cron.php` e verificar que quaisquer outras entradas ficaram intactas. Fazer backup remoto, extrair e validar os módulos, restaurar exatamente essa entrada, o valor de manutenção e o site com `plesk bin site --on`. Nunca parar ou recarregar `plesk-php83-fpm` global.

**Pronto quando:** a árvore local `master` contém o SHA final revisado da branch; `origin/master` aponta para esse SHA; o relay associado ao staging responde `ok` no commit `73d396a` e aceita token v2 sintético; GitHub não executa deploy automático; o manifesto do site aponta para o mesmo SHA que `origin/master`; a entrada WHMCS, qualquer outro cron de `nove1zz`, o site e `MaintenanceMode` voltam ao estado original.

**Prova:** registrar `git rev-parse fix/production-readiness` antes do merge, `git rev-parse master` depois e `git rev-parse origin/master` após o push; no diretório do site, validar SHA-256, manifesto, backup, site ativo, a entrada WHMCS restaurada, as demais entradas de crontab inalteradas e o valor restaurado de `MaintenanceMode`; validar `systemctl is-active pvewhmcs-console-relay`, `curl -fsS http://127.0.0.1:8765/healthz` e um `node` descartável que cifra/decifra v2 sem segredo ou ticket de produção.

## Rock 1. Schema garantido e versão 1.3.7

**Arquivos:** `modules/addons/pvewhmcs/db.sql`, `modules/addons/pvewhmcs/pvewhmcs.php`, `modules/addons/pvewhmcs/proxmox.php`, `modules/servers/pvewhmcs/pvewhmcs.php`, `version`, `CHANGELOG.md`, `_docs/UPDATE-SQL.md`.

**Mudança:**
- Criar `pvewhmcs_ensure_schema()` compartilhado. Cada entrada pública chama essa função antes de consulta, log ou callback. A função usa um lock global de schema, reinspeciona depois do lock, só grava `schema_version = 1.3.7` ao terminar e falha sem seguir se não reparar tudo.
- Cobrir `mod_pvewhmcs`, planos, pools/endereços IPv4, vínculos de guest e logs. Instalações novas e atualizadas terminam no mesmo schema; o reparo preserva linhas existentes e inclui o backfill legado de `vmid`.
- Definir o contrato literal de 1.3.7 em `db.sql` e na migração nova: `mod_pvewhmcs.schema_version VARCHAR(20) NOT NULL DEFAULT ''`; em `mod_pvewhmcs_vms`, `provisioning_state VARCHAR(16) NOT NULL DEFAULT 'ready'`, `provisioning_upid VARCHAR(255) NULL`, `provisioning_node VARCHAR(255) NULL`, `provisioning_mode VARCHAR(16) NULL`, `provisioning_error TEXT NULL`, `provisioning_updated_at DATETIME NULL` e índice `provisioning_state`; em `mod_pvewhmcs_logs`, `server_id INT(11) NOT NULL DEFAULT 0`, índices `(server_id, timestamp)` e `(level, timestamp)`.
- Não alterar o bloco publicado `1.3.6`. Quando a versão instalada for anterior a 1.3.7, `pvewhmcs_upgrade()` chama a garantia 1.3.7 e retorna sem executar os blocos históricos. A garantia inclui todo backfill legado e DDL idempotente para deixar `plans.vmbr` em `VARCHAR(64)`.
- Chamar a garantia no começo de `pvewhmcs_output`, ConfigOptions, TestConnection, AdminLink, Client Area, console, botões de ciclo de vida e energia. Um callback não acessa `mod_pvewhmcs_vms` ou `mod_pvewhmcs_logs` antes dela.

**Pronto quando:** callback em banco incompleto cria as colunas/tabelas necessárias sob um único lock; uma segunda chamada não faz DDL; 1.3.7 atualiza `schema_version`; instalações antigas não entram no `vmbr->change()` publicado.

**Prova:** smoke MariaDB começa com schema pré-1.3.6 e outro sem tabela de logs; insere vínculos e logs legados, chama entradas públicas, valida `vmid`, defaults, índices, tipo e dados intactos sob duas chamadas concorrentes; `php -l` em todos os PHP alterados. O staging cria e remove um QEMU descartável com plano/pool `#1` como smoke real do callback.

## Rock 2. Provisionamento recuperável e clone correto

**Arquivos:** `modules/servers/pvewhmcs/pvewhmcs.php`, schema do Rock 1.

**Mudança:**
- Sob o lock por servidor, escolher VMID e bloquear a linha de IP; na mesma transação persistir um marcador `allocating` com VMID, tipo, nó, IP e modo antes do POST. Revalidar o vínculo depois de adquirir o lock para serializar duas tentativas do mesmo serviço.
- Depois do POST, gravar o UPID e promover para `pending` ainda sob a serialização. Tarefa `OK` aplica a finalização e troca o estado para `ready`; tarefa com erro grava `failed` e mantém o vínculo, IP e VMID bloqueados.
- Se PHP cair antes de registrar o UPID, manter `allocating` como estado incerto. A repetição nunca libera IP ou VMID por não encontrar o guest naquele momento; ela mostra o estado e bloqueia Lifecycle, Client Area e console até intervenção administrativa.
- Retomar `pending` na repetição de CreateAccount. O retorno só finaliza a mesma linha, sem criar guest adicional.
- Validar o source de clone QEMU: `GET /config` precisa indicar `template=1`.
- Aceitar `PUT /config` pós-clone como síncrono só quando a resposta não for UPID; se devolver UPID, aguardar a tarefa antes de iniciar a VM. A tarefa de start também precisa terminar em `OK`.
- Liberar uma pré-reserva apenas antes de criar o marcador, quando a operação Proxmox não foi chamada.

**Pronto quando:** timeout, queda entre passos, falha de tarefa e repetição do callback não criam VM ou IP duplicado; clone de VM normal é recusado; clone válido só inicia depois de aplicar a configuração; vínculo incerto não expõe controles de guest.

**Prova:** fake-PVE descartável acionado por callback WHMCS cobre Create/Clone pendente, OK, falha, kill entre marcador/POST/UPID, source sem `template`, config UPID e start aguardado. Em staging, criar um QEMU direto descartável pelo plano/pool `#1`, exercitar CreateAccount, Start, Reboot, Shutdown e Stop, e removê-lo no fim.

## Rock 3. IPv4 reservado para VM CANCELADO

**Arquivos:** `modules/servers/pvewhmcs/pvewhmcs.php`, `modules/addons/pvewhmcs/pvewhmcs.php`, `CHANGELOG.md`, `README.md`.

**Mudança:**
- Tratar como reservado um endereço de pool cujo `tblhosting.dedicatedip` ainda aponta para ele, o serviço está `Terminated` e existe vínculo em `mod_pvewhmcs_vms`. A elegibilidade depende de `dedicatedip` atual; `vms.ipaddress` fica só como histórico.
- Usar a mesma ordem de locks em reserva, liberação e exclusão: primeiro a linha de endereço do pool com `FOR UPDATE`, depois a linha do serviço. Revalidar pool, IP, vínculo e status no servidor antes de alterar.
- Excluir esses endereços da reserva de novos serviços, da exclusão de endereço e da exclusão de pool.
- Criar a opção **Reserved IPv4** na aba IPv4. Listar IP, pool, serviço, VMID e cliente.
- Implementar **Release reservation** com POST + CSRF. A ação só limpa `tblhosting.dedicatedip` de uma reserva válida, mantém a VM e o histórico do IP na linha do guest, e exige confirmação de que a VM CANCELADO não pode voltar à rede com aquele IP.

**Pronto quando:** Terminate mantém o IP fora do pool; a tela mostra a reserva; a liberação torna o IP elegível para nova alocação sem apagar guest ou endereço do pool; POST adulterado e corrida com alocação não liberam o IP errado.

**Prova:** smoke MariaDB com serviço Terminated + guest vinculado, transações concorrentes de alocação/liberação/exclusão, listagem, tentativa de excluir pool e realocação efetiva depois da liberação. O guest descartável de staging passa por Terminate, aparece como reserva e só libera o IP depois da ação administrativa; depois o guest, vínculo e serviço temporários são removidos.

## Rock 4. Deploy manual de staging com recuperação

**Arquivos/processos:** GitHub webhook `685685824`, `/var/www/vhosts/91008900.xyz/httpdocs/modules`, backup remoto e `_docs/GITHUB-WEBHOOK-DEPLOY.md`.

**Mudança:**
- Manter o webhook GitHub desativado. O endpoint PHP continua no código só para a remoção posterior já decidida pelo usuário; esta rodada não altera sua arquitetura.
- Gerar um pacote imutável com `git archive` no SHA de `master`, manifestar com `git ls-tree -r --name-only` as duas árvores do módulo, calcular `sha256sum` e transferir tudo por SSH em tmux.
- No diretório do site staging, validar SHA-256 e manifesto, guardar/restaurar `MaintenanceMode`, suspender/reativar somente `91008900.xyz`, preservar o crontab completo de `nove1zz` e pausar somente a entrada WHMCS que chama `httpdocs/crons/cron.php`. Verificar que qualquer entrada restante não foi alterada e restaurar a linha exata ao fim. Criar backup datado dos dois diretórios do módulo e do arquivo local, instalar o pacote sem parar ou recarregar o PHP-FPM global e restaurar/validar o arquivo local. Só remover caminhos que estavam no manifesto anterior e não constam no manifesto novo; nunca remover `github-webhook.local.php` ou os manifestos locais.
- Gravar `modules/.pvewhmcs-manual-deploy.json` com SHA, hash, data e backup usado, e guardar `modules/.pvewhmcs-manual-deploy-files.txt` como fonte autoritativa da próxima limpeza. Manter o backup até a homologação terminar.

**Pronto quando:** um push GitHub não inicia cópia automática; o staging executa somente o SHA do pacote validado; rollback restaura os dois módulos e o arquivo local a partir do backup; somente a entrada WHMCS fica pausada durante a janela.

**Prova:** `gh api` mostra `active=false`; hash local/remoto e manifesto coincidem; teste de rollback em staging; addon e Client Area carregam depois da instalação; comparação antes/depois demonstra que entradas não-WHMCS de `nove1zz` permaneceram iguais.

## Rock 5. Ações de runtime coerentes

**Arquivos:** `modules/servers/pvewhmcs/pvewhmcs.php`, `modules/addons/pvewhmcs/pvewhmcs.php`, `CHANGELOG.md`, `README.md`.

**Mudança:**
- Fazer Start/Reboot/Shutdown/Stop esperar o UPID e reportar falha ou timeout.
- Em Unsuspend, sempre ler o recurso HA antes de tocar em tags, config ou marcador. A matriz é: sem recurso HA, start direto; somente `stopped` com `ha_suspended = 1`, restaurar `started`; qualquer recurso HA `started`, `disabled` ou `ignored`, com qualquer marcador, e `stopped` sem suspensão criada pelo módulo retornam erro sem alterar o guest.
- Unificar Secure com a semântica documentada pelo WHMCS: `serversecure` é booleano. `NULL` mantém TLS seguro por padrão, `on`/verdadeiro valida e vazio/falso desliga a validação em todos os caminhos. O staging só tem o valor `on`; documentar que desmarcar Secure é exceção explícita.
- Escapar valores persistidos de planos na listagem.

**Pronto quando:** ações de energia só retornam sucesso depois de `OK`; nenhum recurso HA externo é reativado pelo Unsuspend; os dois módulos calculam TLS igual; título de plano malicioso renderiza como texto.

**Prova:** mocks de UPID OK/falha/timeout, toda a matriz HA, TLS `NULL`/`on`/vazio e saída HTML de plano com caracteres especiais.

## Rock 6. Observabilidade administrativa sem apagar histórico

**Arquivos:** `modules/addons/pvewhmcs/proxmox.php`, `modules/addons/pvewhmcs/pvewhmcs.php`, schema do Rock 1, `CHANGELOG.md`, `README.md`.

**Mudança:**
- Registrar `auth_id` de uma sessão admin e `server_id` imutável no momento da ação; não gravar parâmetros ou senhas em `request`.
- Mostrar Logs para todos os servidores habilitados, com isolamento por servidor como Nodes e Guests.
- Paginar Action History e Failed Actions com limite explícito e filtros seguros de página/resultado.
- Adicionar os índices definidos no Rock 1. Não introduzir retenção automática.

**Pronto quando:** ação de admin registra executor e servidor original; uma falha em um servidor não remove logs dos demais; páginas posteriores mostram linhas distintas; mudança posterior de servidor do serviço não reclassifica o histórico.

**Prova:** smoke MariaDB com dois servidores, entradas de log > limite, sessão admin e serviço movido de servidor; validar paginação, isolamento, server_id histórico e ausência de segredos no registro.

## Rock 7. Documento e publicação única

**Arquivos:** `CHANGELOG.md`, `README.md`, `_docs/ANALISE-PRODUCAO.md`, `_docs/PROJECT-CONTEXT.md`, `_docs/UPDATE-SQL.md`, `AGENTS.md`.

**Mudança:** atualizar `AGENTS.md` antes do merge para substituir a regra de deploy automático por deploy manual no site staging enquanto o webhook estiver desativado. Documentar 1.3.7 em `[Unreleased]`, o esquema garantido, a reserva manual de IP e o pacote manual por SSH. Atualizar a análise para retirar correções concluídas e manter sugestões adiadas. Mover a hipótese não exercida de `time2format()` para `ISSUES.md`.

**Pronto quando:** documentação descreve o código publicado; não declara tag/release inexistente; os comandos de recuperação correspondem ao schema real.

**Prova:** links locais válidos, `git diff --check`, revisão do diff completo, fake-PVE + WHMCS/MariaDB descartáveis e, em staging, versão 1.3.7, schema, relay, manifesto, webhook desativado, leitura autenticada do Proxmox, QEMU temporário removido e `MaintenanceMode` restaurado.
