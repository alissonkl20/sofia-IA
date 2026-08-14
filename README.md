# SOFIA IA

Sistema **backend** de autoatendimento inteligente para WhatsApp. A **SOFIA IA** conduz triagem comercial quando o atendimento humano está offline, organiza informações do projeto e prepara o contexto para orçamento do Alisson — **sem inventar preços**.

Todo o atendimento acontece pelo WhatsApp. Não há painel web.

## Arquitetura

```text
WhatsApp (wpp-server / API)
        │
        ▼
Webhook Laravel (/webhook/whatsapp)
        │
        ├── salva mensagem (MySQL)
        ├── verifica atendimento
        └── dispatch Job → HTTP 200
                │
                ▼
        Redis Queue (Horizon)
                │
                ▼
        ProcessWhatsAppMessage
                │
                ├── AIService → Ollama (qwen3:4b)
                ├── valida JSON
                └── WhatsAppService → envia resposta
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
CREATE USER IF NOT EXISTS 'sofia'@'localhost' IDENTIFIED BY 'sofia_secret';
GRANT ALL PRIVILEGES ON sofia_ia.* TO 'sofia'@'localhost';
FLUSH PRIVILEGES;
"
```

Configure no `.env`:

```env
DB_DATABASE=sofia_ia
DB_USERNAME=sofia
DB_PASSWORD=sofia_secret
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
WHATSAPP_VERIFY_TOKEN=sofia_webhook_secret
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

Health check: `GET http://localhost:8000/` retorna status JSON.

## Fluxo de atendimento

| Situação | SOFIA responde? |
|----------|-----------------|
| Conversa em `human`, `waiting` ou `closed` | Não |
| Atendente humano online (manual no banco) | Não |
| Dentro do horário comercial | Não |
| Fora do horário + IA habilitada + status `ai` | Sim |

Estados da conversa: `ai`, `human`, `waiting`, `closed`.

Configurações (`ai_enabled`, `human_online`, horários) ficam no banco (`settings`, `business_hours`) e podem ser ajustadas diretamente no MySQL ou via seed.

## Estrutura principal

```text
app/
├── Http/Controllers/Api/WhatsAppWebhookController.php
├── Jobs/ProcessWhatsAppMessage.php
├── Models/
├── Services/
└── Support/SofiaPrompt.php

config/ai.php, config/whatsapp.php, config/sofia.php
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
| Webhook 401 | Header `X-Webhook-Token` ou `APP_ENV=local` |

## Regras comerciais

- A SOFIA **nunca** informa preços.
- Coleta requisitos e gera resumo no banco para análise posterior.
- Transferência para humano quando o cliente solicitar.
