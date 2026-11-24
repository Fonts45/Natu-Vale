# Natus · Atendimento Natu Vale

Execute com Node.js 24 ou superior. No PowerShell, a partir da raiz do projeto:

```powershell
node .\nvc-private\natus\server.mjs --demo
```

Abra http://127.0.0.1:8787. Entre com o usuário **equipe** e a senha **natus-demo**. Escolha uma conversa para experimentar o painel e abra **Simulação local** para testar mensagens do cliente, respostas pelo WhatsApp Business e o lembrete de 20 minutos. Os dados de demonstração são recriados ao reiniciar.

## Configuração com WhatsApp

Inicie o serviço sem `--demo` e configure estas variáveis no ambiente do serviço:

| Variável | Uso |
|---|---|
| `NATUS_USERS` | Objeto JSON com um usuário e uma senha forte por atendente. |
| `NATUS_PORT` | Porta local. O padrão é 8787. |
| `META_API_VERSION` | Versão da Graph API usada pelo aplicativo da Meta. |
| `META_PHONE_ID` | ID do número no WhatsApp Business. |
| `META_TOKEN` | Token com permissão para enviar mensagens. |
| `META_APP_SECRET` | Segredo do aplicativo para validar assinaturas do webhook. |
| `META_VERIFY_TOKEN` | Token definido pela NVC para verificar o webhook. |

Coloque o serviço atrás de um proxy HTTPS. Cadastre `/webhook` no aplicativo da Meta e assine `messages` e, para atendimento pelo aplicativo com o mesmo número, `smb_message_echoes`. Confirme a configuração de coexistência e as mensagens de teste antes de usar o número em produção. O painel escuta somente em loopback e usa cookies Secure fora da demonstração.

O histórico de produção fica em `natus.sqlite`, ao lado do servidor, dentro de `nvc-private`. Mantenha o serviço em um processo contínuo para verificar os lembretes e inclua o banco no backup privado. Os anexos aparecem no histórico pelo tipo. Na opção 4, a equipe recebe os dados do cliente no painel para consultar o rastreamento.
