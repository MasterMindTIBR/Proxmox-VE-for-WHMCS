# Deploy do módulo por webhook GitHub

O receiver `modules/addons/pvewhmcs/github-webhook.php` recebe somente eventos `push` assinados do repositório `MasterMindTIBR/Proxmox-VE-for-WHMCS`, branch `master`.

Ele baixa o ZIP do commit recebido, extrai e sincroniza somente:

- `modules/addons/pvewhmcs/`
- `modules/servers/pvewhmcs/`

O ZIP completo existe apenas no diretório temporário do PHP. README, imagens e documentação da raiz, metadados Git e qualquer arquivo fora desses dois diretórios não são copiados para o WHMCS. Arquivos dentro deles (por exemplo `modules/servers/pvewhmcs/console-relay/README.md` e o noVNC vendorizado) são copiados.

## Pré-requisitos no Plesk

- PHP 8.0 ou superior.
- Extensões PHP `curl` e `zip` habilitadas para o domínio.
- Permissão de escrita do usuário PHP nos dois diretórios do módulo.
- Saída HTTPS do servidor para `api.github.com` e para o host de download redirecionado pelo GitHub.

O webhook processa uma atualização por vez com `flock`. Não configure mais de um endpoint para a mesma instalação.

## Instalação inicial

1. Copie manualmente `github-webhook.php` para:

   ```text
   <WHMCS>/modules/addons/pvewhmcs/github-webhook.php
   ```

2. Crie no mesmo diretório o arquivo **não versionado** `github-webhook.local.php`:

   ```php
   <?php

   return array(
       'secret' => 'COLE_UM_SEGREDO_ALEATORIO_DE_64_CARACTERES_OU_MAIS',
       // Necessário apenas se o repositório for privado.
       'github_token' => '',
   );
   ```

3. Gere o segredo fora do repositório:

   ```bash
   openssl rand -hex 32
   ```

4. Restrinja o arquivo local ao usuário do Plesk:

   ```bash
   chmod 600 <WHMCS>/modules/addons/pvewhmcs/github-webhook.local.php
   ```

O `.gitignore` já exclui o arquivo local e o lock do webhook. Nunca adicione o segredo ou token ao Git.

## Configuração no GitHub

Em **Settings → Webhooks → Add webhook**:

| Campo | Valor |
| --- | --- |
| Payload URL | `https://<dominio-whmcs>/modules/addons/pvewhmcs/github-webhook.php` |
| Content type | `application/json` |
| Secret | O mesmo valor de `secret` no arquivo local |
| SSL verification | Enabled |
| Events | `Just the push event` |
| Active | Enabled |

O GitHub envia um `ping` ao salvar. Uma resposta `200` com `{"ok":true,"message":"Webhook verified."}` confirma assinatura e acesso ao endpoint.

O receiver ignora pushes para qualquer branch diferente de `master`, exclusões de branch e eventos que não sejam `push`.

## Repositório privado

Para repositório privado, crie um fine-grained personal access token limitado a este repositório com permissão **Contents: Read-only**. Grave-o somente como `github_token` em `github-webhook.local.php` e mantenha o arquivo com permissão `0600`.

O webhook usa o SHA recebido no evento, e não o nome da branch, para baixar um artefato imutável da entrega validada.

## Operação e recuperação

- Cada resposta bem-sucedida informa o SHA e as quantidades de arquivos sincronizados.
- Uma entrega concorrente retorna `409` e o GitHub não a reenvia sozinho. Confira em **Recent Deliveries** e, se o `409` era do push mais recente, use **Redeliver** depois que a atualização ativa terminar. Não reenvie entregas antigas: o receiver implanta exatamente o SHA da entrega, então reenviar uma entrega antiga volta produção para aquele commit.
- Erros são enviados ao log de erro PHP/Plesk com o prefixo `PVEWHMCS GitHub webhook`.
- `github-webhook.php` é atualizado a partir do repositório como qualquer outro arquivo do módulo; `github-webhook.local.php` e `github-webhook.lock` não estão no repositório e nunca são apagados. Como quem valida o push é o receiver **já instalado**, uma mudança nas próprias regras de validação (por exemplo `PVEWHMCS_WEBHOOK_REPOSITORY` depois de mover o repositório) só vale depois de copiar o arquivo novo manualmente; até lá o receiver antigo rejeita os pushes com `400 Unexpected repository payload`.
- Em `modules/servers/pvewhmcs/` nada é preservado: qualquer arquivo local que não exista no commit é removido. Nos dois diretórios, arquivos antigos do módulo que não existam no commit novo são removidos.
- Faça backup de `modules/addons/pvewhmcs/` e `modules/servers/pvewhmcs/` antes do primeiro uso. O deploy substitui código, mas não altera as tabelas do banco nem executa a migração do módulo automaticamente.

Depois de um push, abra o addon no WHMCS. O WHMCS só executa `pvewhmcs_upgrade()` quando `pvewhmcs_version()` difere da versão registrada em `tbladdonmodules`. Uma migração acrescentada a um bloco de versão já registrado não roda sozinha: aplique o SQL equivalente de `_docs/UPDATE-SQL.md`.
