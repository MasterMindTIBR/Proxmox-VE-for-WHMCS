# Deploy do módulo por webhook GitHub

O receiver `modules/addons/pvewhmcs/github-webhook.php` recebe somente eventos `push` assinados do repositório `junglivre/Proxmox-VE-for-WHMCS`, branch `master`.

Ele baixa o ZIP do commit recebido, extrai e sincroniza somente:

- `modules/addons/pvewhmcs/`
- `modules/servers/pvewhmcs/`

O ZIP completo existe apenas no diretório temporário do PHP. README, imagens, documentação, metadados Git e qualquer outro arquivo do repositório não são copiados para o WHMCS.

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
- Uma entrega concorrente retorna `409`; use **Redeliver** no GitHub após a atualização ativa finalizar.
- Erros são enviados ao log de erro PHP/Plesk com o prefixo `PVEWHMCS GitHub webhook`.
- O deploy preserva `github-webhook.php`, `github-webhook.local.php` e `github-webhook.lock`. Arquivos antigos do módulo que não existam no commit novo são removidos.
- Faça backup de `modules/addons/pvewhmcs/` e `modules/servers/pvewhmcs/` antes do primeiro uso. O deploy substitui código, mas não altera as tabelas do banco nem executa a migração do módulo automaticamente.

Depois de um push, abra o addon no WHMCS. O processo de upgrade normal do WHMCS executa `pvewhmcs_upgrade()` quando reconhecer a nova versão.
