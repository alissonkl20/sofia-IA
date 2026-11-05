# Contrato: chat do portfólio (Vercel) → SOFIA IA

Este repositório **não** implementa o `chat.ts` do site. O portfólio na Vercel deve chamar a API abaixo **somente no servidor** (Route Handler / Server Action). O token nunca pode ir para o browser nem para `NEXT_PUBLIC_*`.

A SOFIA **nunca** informa preços. Ollama, Redis, MySQL e Horizon não são públicos: só o Laravel entra no túnel.

## Variáveis no servidor Vercel

| Variável | Valor |
|----------|--------|
| `SOFIA_URL` | URL HTTPS pública do túnel, sem barra no final. Ex.: `https://sofia.seudominio.com` |
| `SOFIA_TOKEN` | O mesmo valor de `PORTFOLIO_API_TOKEN` no `.env` do Laravel |

Timeout do `fetch` deve ser **alto** (dezenas de segundos). Se a Vercel estourar o limite do runtime, use o poll `GET`.

## Autenticação

```
Authorization: Bearer {SOFIA_TOKEN}
Accept: application/json
Content-Type: application/json
```

Sem token, token vazio ou token errado → `401` `{ "error": "Unauthorized" }`.

Throttle: 60 requisições/minuto por IP e por `session_id`.

## Rotas

Base: `{SOFIA_URL}`.

### `GET /api/portfolio/status`

Checagem rápida antes do chat.

**200**

```json
{ "online": true, "llm": true }
```

- `online`: Laravel no ar (se este endpoint responde `200`, é `true`).
- `llm`: Ollama alcançável em localhost (ping curto em `/api/tags`).

Se `llm` for `false`, o `POST /chat` tende a devolver `202` (lead mesmo assim é gravado).

**503** — IA global desligada (`ai_enabled`). Não pinga o Ollama.

```json
{ "status": "offline" }
```

### `POST /api/portfolio/chat`

Body:

```json
{
  "session_id": "550e8400-e29b-41d4-a716-446655440000",
  "name": "Ana",
  "email": "ana@example.com",
  "message": "Quero uma landing page"
}
```

| Campo | Obrigatório | Notas |
|-------|-------------|--------|
| `session_id` | sim | UUID estável por visitante (cookie / localStorage no site) |
| `message` | sim | Texto do usuário |
| `name` | não | Atualiza o cadastro se enviado |
| `email` | não | Atualiza o cadastro se enviado |

**200** — resposta da SOFIA (greeting na 1ª mensagem; depois o LLM)

```json
{
  "status": "ok",
  "reply": "Olá! Eu sou a SOFIA IA…",
  "stage": "greeting",
  "complete": false,
  "summary": null
}
```

Quando a triagem termina, `complete` é `true` e `summary` traz o resumo comercial (sem preço).

**202** — Laravel no ar, Ollama inacessível. A mensagem do lead **já foi gravada**.

```json
{
  "status": "queued",
  "reply": "Recebi sua mensagem e já registrei seu contato. Estou temporariamente indisponível; o Alisson retorna em breve."
}
```

Não invente preço no fallback do site. Use `reply` ou o bot estático do portfólio.

**401** — token inválido.

**422** — validação (`session_id` UUID, `message` obrigatória, `email` se presente).

**503** — IA global desligada (`ai_enabled`). A mensagem do lead ainda é gravada.

```json
{ "status": "offline" }
```

### `GET /api/portfolio/chat?session_id={uuid}`

Poll quando o `POST` estourar timeout na Vercel. Devolve a conversa ativa do canal `portfolio` (ou a mais recente, se estiver `closed`).

**200**

```json
{
  "status": "ok",
  "stage": "greeting",
  "complete": false,
  "summary": null,
  "messages": [
    { "role": "user", "content": "Olá" },
    { "role": "assistant", "content": "Olá! Eu sou a SOFIA IA…" }
  ]
}
```

Sem conversa: `messages` é `[]`, `stage`/`summary` são `null`.

## Fluxo recomendado no `chat.ts`

1. `GET {SOFIA_URL}/api/portfolio/status`
2. Se o `fetch` falhar (PC desligado, túnel caído) **ou** o status HTTP for `503` (IA global off): use o bot estático do portfólio e/ou salve o lead no fluxo já existente do site.
3. Se `200` com `online` e (opcionalmente) `llm`: `POST {SOFIA_URL}/api/portfolio/chat` com `session_id` + `message`.
4. `200`: mostre `reply`.
5. `202`: mostre `reply` (espera); o lead já está no MySQL da SOFIA.
6. Timeout / rede no `POST`: `GET {SOFIA_URL}/api/portfolio/chat?session_id=…` até aparecer mensagem `assistant` nova ou desistir para o fallback estático.

O canal portfólio **não** dispara WhatsApp. Horário comercial não bloqueia este chat.

## Túnel (operação na máquina da SOFIA)

Aponte Cloudflare Tunnel (preferido) ou ngrok **somente** para o Laravel (`http://127.0.0.1:8000`). Nunca para `11434`.

No Laravel:

```env
APP_URL=https://sofia.seudominio.com
PORTFOLIO_API_TOKEN=o-mesmo-SOFIA_TOKEN
```

Checklist: de um 4G, `GET {SOFIA_URL}/up` deve retornar 200. `GET {SOFIA_URL}/api/portfolio/status` sem Bearer deve retornar 401.
