# Política de cancelamento (v1)

Implementada em `modules/addons/pvewhmcs/hooks.php`. Vale para **todos os produtos** do WHMCS, não só para as VPS do módulo.

- **Sem cancelamento imediato para o cliente.** Como não há devolução de dinheiro, o cliente cancela no **fim do período pago**. A opção "Immediate" é removida do formulário do cliente, e um pedido "Immediate" (inclusive um POST manual) é gravado como "End of Billing Period". Só a área administrativa, com sessão de admin, mantém "Immediate". A mesma regra vale para `AddCancelRequest` pela API ou pela CLI, mesmo com usuário admin (`localAPI` com `adminuser` também abre `adminid` na sessão, por isso o hook exige `ADMINAREA`). Um admin logado como cliente segue a regra do cliente.
- **A VPS fica ligada até o vencimento.** A tarefa `CancellationRequests` do cron diário encerra o serviço quando `nextduedate` ≤ hoje (status final `Cancelled`; o módulo chama o `TerminateAccount`).
- **IP:** um serviço `Cancelled` ou `Terminated` mantém o IPv4 reservado enquanto existir a linha em `mod_pvewhmcs_vms`. O endereço só volta ao pool pelo "Release reservation" (Addon → IPv4 → Reserved IPv4) ou quando a linha é removida.
- **Desistir do cancelamento:** a página do serviço mostra "Cancelamento agendado para DD/MM/AAAA (fim do período pago)" e o botão "Desistir do cancelamento", enquanto o cron não tiver processado o pedido (serviço Active/Suspended). Ao desistir:
  - o pedido é apagado e a fatura de renovação que o próprio pedido cancelou volta para Unpaid;
  - o vínculo é a linha "Cancelled Outstanding Product Renewal Invoice - Invoice ID: N - Service ID: S" que o WHMCS (`CancelInvoiceOnCancellation`) grava no momento do pedido, aceita entre 5 s antes e 10 s depois dele e só depois da última desistência do mesmo serviço;
  - uma fatura que não está mais Cancelled, ou que tem registro posterior no Activity Log referente a ela (reconhecido pelo filtro de descrição), **não** é reaberta: fica no log como "check billing" para o admin. Alteração sem linha de log reconhecível não é detectada;
  - tudo roda numa transação com lock da linha do serviço, e o pedido só é apagado depois que todas as reaberturas retornam sucesso. Ainda falta validar que o lock serializa com o cron e que o rollback desfaz efeitos de `localAPI` e de hooks fora do banco.
- Política operacional sugerida (manual): reter a VM cancelada parada por 7 dias e depois destruí-la no PVE e liberar o IP.
