# SOFIA IA

Sistema **backend** de autoatendimento inteligente para WhatsApp e para o chat do portfólio (Vercel). A **SOFIA IA** conduz triagem comercial, organiza informações do projeto e prepara o contexto para orçamento do Alisson — **sem inventar preços**.

Não há painel web. O Ollama, Redis, MySQL e Horizon permanecem só em localhost; o que fica público é o Laravel, via túnel HTTPS.

## Arquitetura

```text
WhatsApp (wpp-server / API)              Portfólio (Vercel)
        │                                        │
        ▼                                        ▼
Webhook Laravel (/webhook/whatsapp)     API /api/portfolio/*  (Bearer)
        │                                        │
        ├── salva mensagem (MySQL)               ├── identidade por session_id
        ├── verifica atendimento                 ├── ignora horário comercial
        └── Job → ProcessWhatsAppMessage         └── SofiaReplyService (JSON)
                │                                        │
                ▼                                        ▼
        SofiaReplyService ───────────────────────────────┘
                │
                ├── AIService → Ollama localhost (qwen3:4b)
                └── WhatsAppService (somente canal WhatsApp)
```

## Stack

- Laravel 13 + PHP 8.3+
- MySQL 8
- Redis + Laravel Queue + Horizon
- Ollama + Qwen3 4B
- API REST + Webhooks

## Requisitos

- PHP 8.3+ com extensões: `pdo_mysql`, `mbstring`, `openssl`, `curl`
- Composer
- MySQL 8
- Redis
- Ollama

## Instalação

```bash
cd sofia-ia
cp .env.example .env
composer install
php artisan key:generate
```

### MySQL

```bash
sudo mysql -e "
CREATE DATABASE sofia_ia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'sofia'@'localhost' IDENTIFIED BY 'SUBSTITUA_POR_UMA_SENHA_FORTE';
GRANT ALL PRIVILEGES ON sofia_ia.* TO 'sofia'@'localhost';
FLUSH PRIVILEGES;
"
```

Configure no `.env`:

```env
DB_DATABASE=sofia_ia
DB_USERNAME=sofia
DB_PASSWORD=SUBSTITUA_POR_UMA_SENHA_FORTE
```

### Migrations e seed

```bash
php artisan migrate
php artisan db:seed
```

O seed cria horários comerciais padrão (seg–sáb 08:00–18:00, domingo fechado) e configurações iniciais da IA.

### Ollama + Qwen3 4B

```bash
ollama pull qwen3:4b
ollama serve
```

```env
OLLAMA_URL=http://127.0.0.1:11434
OLLAMA_MODEL=qwen3:4b
```

### Redis e Horizon

```env
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_CLIENT=predis
```

```bash
php artisan horizon
```

Horizon (monitoramento de filas): `http://localhost:8000/horizon`

### WhatsApp

```env
WHATSAPP_API_URL=http://127.0.0.1:3000
WHATSAPP_VERIFY_TOKEN=gere-um-segredo-aleatorio-longo
```

**Receber mensagens** — POST em `http://localhost:8000/webhook/whatsapp`:

```json
{
  "from": "5511999999999@c.us",
  "chatId": "5511999999999@c.us",
  "body": "Olá",
  "messageId": "opcional-para-dedup"
}
```

**Enviar mensagens** — POST em `{WHATSAPP_API_URL}/send`:

```json
{
  "to": "5511999999999@c.us",
  "message": "Resposta da SOFIA"
}
```

Reutilize o `wpp-server` do projeto `atendimento-whatsapp`.

## Execução local

```bash
# Terminal 1
ollama serve

# Terminal 2
php artisan horizon

# Terminal 3
php artisan serve
```

Health check: `GET http://localhost:8000/` retorna status JSON. Liveness do Laravel: `GET /up`.

## Portfólio (Vercel)

O site na Vercel chama esta API **somente no servidor** (`chat.ts` / Route Handler). O token **nunca** vai para o browser.

Contrato completo: [`docs/vercel-portfolio-chat.md`](docs/vercel-portfolio-chat.md).

```env
PORTFOLIO_API_TOKEN=um-segredo-longo
APP_URL=https://sofia.seudominio.com
```

| Método | Rota | Função |
|--------|------|--------|
| `GET` | `/api/portfolio/status` | `200` `{ "online": true, "llm": true\|false }` ou `503` se IA global off |
| `POST` | `/api/portfolio/chat` | `200` resposta, `202` Ollama down (grava o lead), `503` IA off |
| `GET` | `/api/portfolio/chat?session_id=` | Poll das últimas mensagens (timeout da Vercel) |

Auth: `Authorization: Bearer {PORTFOLIO_API_TOKEN}`. Throttle: 60/min por IP e por `session_id`.

O canal `portfolio` **não** envia WhatsApp e **não** bloqueia por horário comercial / `human_online`. Ainda respeita `ai_enabled` e status `human` / `waiting` / `closed`.

### Túnel (só o Laravel)

Exponha **apenas** o HTTP do Laravel (ex. `php artisan serve` ou nginx em `:8000`). **Nunca** faça túnel da porta `11434` (Ollama), Redis, MySQL ou Horizon.

**Cloudflare Tunnel (preferido)**

```bash
cloudflared tunnel login
cloudflared tunnel create sofia-ia
cloudflared tunnel route dns sofia-ia sofia.seudominio.com
```

`~/.cloudflared/config.yml` (ajuste o `credentials-file`):

```yaml
tunnel: sofia-ia
ingress:
  - hostname: sofia.seudominio.com
    service: http://127.0.0.1:8000
  - service: http_status:404
```

```bash
cloudflared tunnel run sofia-ia
```

**ngrok (alternativa)**

```bash
ngrok http 8000
```

No `.env`, `APP_URL` deve ser a URL **HTTPS pública** do túnel.

Checklist:

1. Laravel no ar (`php artisan serve` ou nginx).
2. Ollama só em `127.0.0.1:11434` (sem túnel).
3. De outra rede (4G): `curl https://sofia.seudominio.com/up` → 200.
4. `curl https://sofia.seudominio.com/api/portfolio/status` sem token → 401.
5. Confirme que `https://…:11434` **não** abre de fora.

## Fluxo de atendimento

| Situação | WhatsApp | Portfólio |
|----------|----------|-----------|
| Conversa em `human`, `waiting` ou `closed` | Não | Não |
| IA global desligada (`ai_enabled`) | Não | `503 offline` |
| Atendente humano online ou horário comercial | Não | Sim (status inicial sempre `ai`) |
| IA ligada + status `ai` + Ollama no ar | Sim | Sim |
| Laravel no ar + Ollama inacessível | fallback no Job | `202 queued` (mensagem do lead é gravada) |

Estados da conversa: `ai`, `human`, `waiting`, `closed`. Canais: `whatsapp`, `portfolio`.

Configurações (`ai_enabled`, `human_online`, horários) ficam no banco (`settings`, `business_hours`) e podem ser ajustadas diretamente no MySQL ou via seed.

## Estrutura principal

```text
app/
├── Http/Controllers/Api/WhatsAppWebhookController.php
├── Http/Controllers/Api/PortfolioChatController.php
├── Jobs/ProcessWhatsAppMessage.php
├── Models/
├── Services/
└── Support/SofiaPrompt.php

config/ai.php, config/whatsapp.php, config/sofia.php, config/portfolio.php
```

## Testes

```bash
sudo mysql -e "CREATE DATABASE IF NOT EXISTS sofia_ia_test; GRANT ALL ON sofia_ia_test.* TO 'sofia'@'localhost';"
php artisan test
```

## Troubleshooting

| Problema | Solução |
|----------|---------|
| `Class "Redis" not found` | `REDIS_CLIENT=predis` + `composer require predis/predis` |
| SOFIA não responde | Verifique Horizon, Redis, status da conversa e horário comercial |
| Ollama timeout | Aumente `OLLAMA_TIMEOUT` |
| Mensagem duplicada | Envie `messageId` único no webhook |
| Webhook 401 | Configure `WHATSAPP_VERIFY_TOKEN` e envie-o no header `X-Webhook-Token` (em ambiente local, a ausência do header é aceita) |
| Portfólio 401 | `Authorization: Bearer` = `PORTFOLIO_API_TOKEN` (token vazio sempre falha) |
| Portfólio 202 | Ollama inacessível; lead já foi gravado |

## Regras comerciais

- A SOFIA **nunca** informa preços.
- Coleta requisitos e gera resumo no banco para análise posterior.
- Transferência para humano quando o cliente solicitar.
