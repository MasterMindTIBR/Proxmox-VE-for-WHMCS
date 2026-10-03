# Same Page Meeting

Core focus: publicar o fork em produção sem criar VM, IP, schema ou deploy parcial; manter a VM cancelada recuperável sem reutilizar seu IPv4 até liberação explícita do admin.

## Round 1

### Integrator findings (Codex, verbatim)

- [FIX] O plano tenta alterar o bloco publicado `1.3.6`, contrariando a regra do repositório para migrações já registráveis -> não toque nele; faça toda a reparação idempotente no novo bloco `1.3.7`.
- [FIX] A garantia de schema pode ser chamada depois de `pvewhmcs_run_tracked_action()` já consultar logs e vínculos inexistentes -> execute-a no bootstrap de cada entrada, antes de qualquer consulta ou logging, e falhe fechado se não completar.
- [FIX] Duas requisições podem observar schema incompleto e executar DDL concorrente ou operar entre alterações -> use lock global de schema, reinspecione após o lock e grave `schema_version` somente depois de toda a garantia concluir.
- [FIX] O plano não especifica o contrato exato do novo schema de provisionamento nem o DDL literal exigido para qualquer tabela nova -> defina colunas, índices, defaults e DDL idêntico em `db.sql` e na migração `1.3.7`.
- [FIX] Ausência do guest logo após cair entre POST e UPID não prova que o Proxmox não aceitou a criação -> mantenha marcador, IP e VMID bloqueados como estado incerto; nunca os libere automaticamente nessa situação.
- [FIX] Uma linha pendente em `mod_pvewhmcs_vms` será interpretada pelos fluxos atuais como guest real -> adicione estado explícito e bloqueie lifecycle, Client Area e console enquanto o vínculo não estiver confirmado.
- [FIX] O marcador, a reserva de IP e a escolha de VMID precisam ser persistidos sob a mesma serialização antes do POST -> faça-os atômicos sob o lock por servidor e valide VMID/tipo/nó antes de promover o vínculo.
- [FIX] `PUT /config` no Proxmox pode devolver UPID e não é síncrono só por usar PUT -> aguarde também a tarefa de configuração antes de iniciar o clone.
- [FIX] A prova por mock pode passar sem cobrir morte real do processo após o POST nem o contrato WHMCS/PVE -> inclua smoke descartável com callback WHMCS e PVE/fake-PVE, kill entre POST e persistência, e repetição posterior.
- [FIX] Após “Release reservation”, `mod_pvewhmcs_vms.ipaddress` preservará o histórico e pode continuar parecendo reservado -> torne a elegibilidade dependente do `tblhosting.dedicatedip` atual ou de um estado explícito de liberação e prove que o mesmo IP é realmente realocado.
- [FIX] Reserva, liberação e exclusão de IP/pool não compartilham um protocolo de lock e podem correr entre si -> use a mesma linha de endereço bloqueada e ordem de locks definida em todos os três caminhos.
- [FIX] A confirmação HTML da liberação não impede um POST adulterado ou a liberação de serviço/IP não elegível -> revalide no servidor status `Terminated`, vínculo, pool e IP antes de limpar `dedicatedip`.
- [FIX] Dois `rename` de diretórios distintos não publicam addon e server module atomicamente como uma aplicação -> drene requisições PHP durante o swap ou declare e elimine a janela de versões mistas; “atômico por árvore” não cumpre o Core Focus.
- [FIX] Responder `202` antes do deploy torna uma morte do worker invisível ao GitHub e pode deixar publicação incompleta sem retry -> responda sucesso só após o swap ou persista uma fila recuperável antes do `202`.
- [FIX] Lock bloqueante em requests webhook pode esgotar workers enquanto GitHub expira e reentrega -> use estado/fila durável e retorno idempotente para entrega já em andamento, em vez de manter workers esperando.
- [FIX] O SHA aplicado só é confiável se for gravado após os dois swaps e se o HEAD remoto for autenticado e validado -> persista-o atomicamente no fim e adicione recuperação de backup/estado após queda do processo.
- [FIX] O comportamento de `secure=''` em instalações legadas é ambíguo e a mudança pode transformar servidores existentes em TLS sem validação -> confirme a semântica real do WHMCS e migre/documente explicitamente qualquer valor legado antes de mudar o default.
- [FIX] O estado HA em Unsuspend precisa de uma matriz explícita para `started`, `stopped`, `disabled` e `ignored` -> nunca faça start direto quando existir recurso HA não administrado pelo módulo; retorne erro fora do único estado seguro definido.
- [FIX] Logs não possuem `server_id` imutável, portanto filtrar pelo servidor atual do serviço atribui histórico errado após mudança de servidor -> registre `server_id` no momento da ação e indexe/pagine por ele.
- [DEFER] Paginação, tela multi-servidor de Logs e `auth_id` melhoram operação, mas não evitam VM, IP, schema ou deploy parcial nesta publicação -> mova o Rock 6 para `ISSUES.md`.
- [DEFER] A duplicação potencial de `time2format()` não ocorre nos fluxos atuais e ampliar o carregamento compartilhado nesta rodada aumenta risco sem servir ao foco -> mova para `ISSUES.md`.
- [FIX] Os testes planejados não incluem a homologação WHMCS+Proxmox exigida pelo repositório nem uma verificação pós-push do upgrade efetivo -> condicione publicação a esse smoke e à checagem de schema, relay e estado do webhook em produção.

VERDICT: NOT YET

### Visionary response (Fable)

- ACCEPTED: reparação do bloco publicado -> o plano não altera 1.3.6; a garantia 1.3.7 repara o schema antes das fronteiras legadas.
- ACCEPTED: bootstrap de schema -> o plano nomeia cada entrada pública e exige falha fechada antes de consulta ou log.
- ACCEPTED: corrida de DDL -> o plano exige lock global, reinspeção e marcador gravado por último.
- ACCEPTED: contrato de schema indefinido -> o plano lista colunas, tipos, defaults e índices de 1.3.7.
- ACCEPTED: ausência de guest não prova falha -> `allocating` mantém VMID, IP e vínculo bloqueados.
- ACCEPTED: marcador pendente parece guest pronto -> lifecycle, Client Area e console bloqueiam `allocating`, `pending` e `failed`.
- ACCEPTED: serialização incompleta -> VMID, IP e marcador são criados sob o lock por servidor e transação de endereço.
- ACCEPTED: PUT pode ser assíncrono -> o plano aguarda UPID da configuração e do start.
- ACCEPTED: mock sem morte de processo -> o plano exige fake-PVE, callback WHMCS e kill entre os passos persistentes.
- ACCEPTED: IP histórico parece reservado -> a reserva consulta somente `tblhosting.dedicatedip` atual.
- ACCEPTED: corrida no pool -> os três caminhos usam a mesma ordem de locks e revalidam os dados.
- ACCEPTED: POST adulterado de liberação -> a ação revalida pool, IP, status e vínculo no servidor.
- ACCEPTED: dois swaps não são um cutover -> o plano migra ambos para symlinks estáveis sob um único ponteiro atômico e controla o bootstrap com manutenção FPM.
- ACCEPTED: 202 sem recuperação -> o plano persiste a fila antes de responder e instala worker CLI por cron.
- ACCEPTED: lock bloqueante em HTTP -> a fila retorna 202 e o worker serializa o deploy.
- ACCEPTED: SHA sem ponto de recuperação -> estado é gravado depois do ponteiro e o worker reconcilia estado, ponteiro e fila.
- ACCEPTED: Secure ambíguo -> a documentação WHMCS define `serversecure` como booleano e a produção tem somente `on`; o plano documenta o caminho inseguro.
- ACCEPTED: matriz HA incompleta -> o plano define estados seguros e recusa qualquer recurso HA externo.
- ACCEPTED: histórico sem servidor -> o plano adiciona `server_id` imutável e índices.
- REJECTED: adiar observabilidade -> o usuário pediu todas as correções verificadas; o rock é limitado, não apaga histórico e não adiciona produto novo.
- DEFERRED: `time2format()` -> `ISSUES.md`; o carregamento atual não dispara a colisão.
- ACCEPTED: homologação e pós-push -> o plano exige fake-PVE/WHMCS/MariaDB e checagens de produção sem criar guest de teste.

## Round 2

### Integrator findings (Codex, verbatim)

- [FIX] `pvewhmcs_upgrade()` continuará entrando no `version_compare(..., '1.3.6', 'lt')` após a garantia e executará o `vmbr->change()` publicado que o plano pretende evitar -> faça a garantia 1.3.7 substituir explicitamente o percurso legado com segurança, sem alterar o bloco 1.3.6.
- [FIX] O smoke de schema não prova backfill nem preservação de tabelas parcialmente migradas com dados reais -> inclua linhas legadas de `vms` e `logs`, valide `vmid`, novos defaults, índices e dados intactos após a reparação.
- [FIX] Um ponteiro symlink único não impede uma requisição PHP já em andamento de resolver server e addon em releases diferentes, e OPcache/realpath cache pode manter código antigo -> drene/reinicie PHP-FPM em cada cutover ou implemente compatibilidade explícita entre releases durante a janela.
- [FIX] O bootstrap de symlinks é circular: ele precisa trocar diretórios físicos antes de o primeiro webhook poder publicar a release -> durante a manutenção, crie e valide um release imutável do código atualmente em produção, aponte ambos os links para ele e só então habilite o receiver/worker.
- [CLARIFY] O bootstrap manual, novo cron e manutenção recorrente do PHP-FPM são uma alteração operacional/arquitetural ampla além do push autorizado -> o owner autoriza essa operação permanente no host de produção e sua janela de manutenção?
- [FIX] A matriz HA não define `disabled`/`ignored` com `ha_suspended=1` nem trata `started` como alteração externa -> retorne erro e não altere config, tags ou marcador nesses estados; somente `stopped` é restaurável.
- [FIX] O Rock 0 prova apenas que o relay está vivo, não que `73d396a` aceita e encaminha corretamente token v2 -> acrescente teste seguro de decode/preconnect v2 sem expor segredo ou ticket.
- [FIX] A condição de merge continua provar apenas que `cdf1989` é ancestral, embora o branch local esteja em commit posterior -> fixe e registre o SHA exato revisado que será mesclado e publicado.

VERDICT: NOT YET

### Visionary response (Fable)

- ACCEPTED: percurso 1.3.6 -> upgrade anterior a 1.3.7 executa a garantia e retorna antes dos blocos publicados.
- ACCEPTED: smoke de dados legados -> o plano inclui linhas de `vms` e `logs`, backfill, defaults, índices e preservação.
- DEFERRED: ponteiro symlink e OPcache -> o usuário rejeitou symlinks e migrou a validação para deploy manual no site staging.
- DEFERRED: bootstrap de symlink -> o webhook automático foi desativado e o usuário pediu remoção posterior, não uma nova arquitetura.
- RESOLVED BY OWNER: `91008900.xyz` é o único site de staging; o host nebula continua atendendo outros serviços. O deploy manual não para nem recarrega PHP-FPM.
- ACCEPTED: matriz HA -> somente `stopped` criado pelo módulo é restaurável; os outros estados existentes recusam sem tocar no guest.
- ACCEPTED: validação do relay -> o plano exige decode v2 sintético no código implantado, além do healthcheck.
- ACCEPTED: SHA do merge -> o plano congela e registra o SHA revisado antes do merge e do pacote.

## Round 3

### Integrator findings (Codex, verbatim)

- [FIX] Extrair arquivos diretamente nos módulos ativos permite que requisições web ou a automação WHMCS leiam uma mistura de versões -> coloque somente o site staging em manutenção e suspenda sua automação durante backup, instalação, validação e eventual rollback.
- [FIX] “Hash do pacote” e “arquivos obsoletos controlados” não definem algoritmo nem fonte autoritativa, permitindo aceitar pacote incompleto ou apagar arquivo local -> use SHA-256 do `git archive` e uma lista de arquivos do próprio archive, com exclusões explícitas para `github-webhook.local.php` e o manifesto.
- [FIX] `AGENTS.md` ainda instrui que todo push em `master` faz deploy automático, contrariando o webhook desativado e o novo processo manual -> torne obrigatória sua atualização no Rock 7 antes do merge/push.
- [CLARIFY] O plano só exercita callbacks contra fake-PVE, enquanto a regra do repositório exige smoke contra WHMCS+Proxmox sem criar guest em staging -> qual guest/ambiente Proxmox descartável já existente está autorizado para validar os callbacks alterados?

VERDICT: NOT YET

### Visionary response (Fable)

- ACCEPTED: troca manual de arquivos -> o plano ativa e restaura somente `MaintenanceMode` do site `91008900.xyz`; nenhum PHP-FPM do host é alterado.
- ACCEPTED: pacote sem fonte autoritativa -> o plano usa SHA-256, manifesto de `git ls-tree`, exclusões explícitas e manifesto anterior para remoção controlada.
- ACCEPTED: regra de deploy contraditória -> `AGENTS.md` passa a exigir deploy manual enquanto o webhook estiver desativado, antes de qualquer push.
- RESOLVED BY OWNER: está autorizado criar e remover um QEMU descartável no staging. O inventário atual fornece plano/pool `#1`, node `alegry` e nenhum guest existente será tocado.

## Round 4

### Integrator findings (Codex, verbatim)

- [FIX] `MaintenanceMode` não impede cron, API ou sessões administrativas do WHMCS de executar callbacks enquanto os dois módulos são extraídos em arquivos ativos -> desative e restaure a automação/cron específica do staging e bloqueie ações administrativas do módulo durante a janela de manutenção.
- [FIX] Rock 0 manda fazer merge antes dos Rocks, mas Rock 7 exige atualizar `AGENTS.md` antes do merge e o manifesto exige coincidir com `origin/master` -> construa e revise tudo na branch, faça um único merge final, `git push origin master` explícito e só então gere o archive desse SHA.

VERDICT: NOT YET

### Visionary response (Fable)

- ACCEPTED: MaintenanceMode incompleto -> o plano suspende somente o site staging, salva/remove/restaura o cron `nove1zz` e reativa o site; não altera serviços globais.
- ACCEPTED: ordem de merge ambígua -> todos os Rocks terminam, são testados e documentados na branch; depois há um merge final, único push e archive do SHA de `origin/master`.

## Round 5

### Integrator finding (Codex, verbatim)

- [FIX] O plano remove o crontab inteiro de `nove1zz`, mas a autorização cobre somente o cron por minuto do staging -> remova e restaure apenas a entrada WHMCS identificada de `91008900.xyz`, preservando e verificando as demais entradas do usuário.

VERDICT: NOT YET

### Owner decision

- ACCEPTED: pausar somente a linha WHMCS durante a janela.
- Evidência operacional: o staging não tem serviços PVE atuais, mas `tblmodulequeue` contém três entradas `pvewhmcs`, incluindo `TerminateAccount` pendente para o serviço 9; o cron pode executá-la durante a substituição dos módulos.
- Limite atingido após cinco rodadas; a decisão do proprietário resolve o último ponto sem ampliar a janela para outros crons.
