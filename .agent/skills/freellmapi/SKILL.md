---
name: freellmapi
description: Run, configure, and route requests through FreeLLMAPI - a local self-hosted API gateway aggregating 34+ free LLM providers and 635+ free model endpoints behind a single OpenAI-compatible /v1 endpoint.
---

# FreeLLMAPI Skill

Use this skill to operate, configure, or route requests through the local **FreeLLMAPI** gateway located at `freellmapi/`.

## Architecture Overview

FreeLLMAPI acts as an OpenAI-compatible reverse proxy (`http://localhost:3000/v1`) that:
- Aggregates free tiers from dozens of AI providers (Google, Groq, Cerebras, OpenRouter, Mistral, NVIDIA NIM, Cloudflare, Hugging Face, etc.).
- Performs **smart routing** and **automatic failover** when hitting rate limits (`429`) or errors (`5xx`).
- Manages encryption for API keys at rest (`AES-256-GCM`).
- Provides fallback chains across equivalent model classes.

## Quick Start / Running FreeLLMAPI

### Option 1: Native Node.js
```powershell
cd freellmapi
npm install
# Set encryption key
$env:ENCRYPTION_KEY = (node -e "console.log(require('crypto').randomBytes(32).toString('hex'))")
npm run dev
```

### Option 2: Docker Compose
```bash
cd freellmapi
docker compose up -d
```
The server will listen on `http://localhost:3000`.

## Unified Endpoints
- **Chat completions**: `POST http://localhost:3000/v1/chat/completions`
- **Models list**: `GET http://localhost:3000/v1/models`
- **Anthropic Messages**: `POST http://localhost:3000/v1/messages` (Claude Code / Agent compatible)
- **Embeddings**: `POST http://localhost:3000/v1/embeddings`
- **Web Search**: `POST http://localhost:3000/v1/search`

## Supported Model Families & Providers

### 1. Keyless Providers (No API Key Required)
- **Kilo Gateway**: `nvidia/nemotron-3-super-120b-a12b:free`, `poolside/laguna-m.1:free`, `stepfun/step-3.7-flash:free`
- **OVHcloud AI Endpoints**: Anonymous tier (2 req/min per model)
- **AI Horde**: Community volunteer GPUs (`0000000000` sentinel key)
- **Pollinations**: `openai-fast` (`gpt-oss-20b`)
- **LLM7**: `gpt-oss-20b`, `codestral-latest`, `meta-llama/Meta-Llama-3.1-8B-Instruct-Turbo`

### 2. High-Capacity Free-Tier Providers (Keyed)
- **Google AI Studio**: `gemini-2.5-pro`, `gemini-2.5-flash`, `gemini-2.5-flash-lite`, `gemini-3.5-flash`, `gemma-4-31b-it`
- **Groq**: `llama-3.3-70b-versatile`, `llama-4-scout-17b-16e-instruct`, `openai/gpt-oss-120b`, `qwen/qwen3-32b`, `llama-3.1-8b-instant`
- **Cerebras**: `qwen-3-coder-480b`, `qwen3-235b`, `llama-4-maverick-17b-128e-instruct`, `gpt-oss-120b`, `llama3.1-8b`
- **OpenRouter (`:free` routes)**: `deepseek/deepseek-v3.1:free`, `qwen/qwen3-coder:free`, `minimax/minimax-m2.5:free`, `nvidia/nemotron-3-super-120b-a12b:free`, `openai/gpt-oss-120b:free`, `meta-llama/llama-3.3-70b-instruct:free`
- **Mistral**: `mistral-large-latest`, `codestral-latest`, `devstral-latest`, `mistral-medium-latest`
- **Cloudflare Workers AI**: `@cf/meta/llama-3.3-70b-instruct-fp8-fast`, `@cf/openai/gpt-oss-120b`, `@cf/zai-org/glm-4.7-flash`, `@cf/deepseek-ai/deepseek-r1-distill-qwen-32b`
- **NVIDIA NIM**: `meta/llama-3.3-70b-instruct`, `deepseek-ai/deepseek-v4-pro`, `mistralai/mistral-large-3-675b-instruct-2512`, `qwen/qwen3-coder-480b-a35b-instruct`
- **Zhipu AI (Z.ai)**: `glm-4.5-flash`, `glm-4.7-flash`, `glm-4.6v-flash`
- **Cohere**: `command-r-plus-08-2024`, `command-a-03-2025`
