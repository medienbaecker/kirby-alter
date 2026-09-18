# Providers

Generation needs a provider. `api.provider` picks one: `anthropic`, `openai`, or `custom` for any OpenAI-compatible chat completions endpoint. Dotted keys such as `'api.key'` are also accepted.

## Anthropic

```php
'api' => [
  'provider' => 'anthropic',
  'key' => 'your-api-key',
  'model' => 'claude-sonnet-5', // default
],
```

## OpenAI

```php
'api' => [
  'provider' => 'openai',
  'key' => 'your-api-key',
  'model' => 'gpt-5.6-luna', // default
],
```

Set `api.url` only to point at a proxy.

Requests carry `reasoning_effort: none` by default, so reasoning models answer instead of spending the token budget on thinking. Override it with `api.options`.

## Custom

Any OpenAI-compatible chat completions endpoint. The `api.url` and `api.model` options are required. The `api.key` option is optional for local servers.

```php
'api' => [
  'provider' => 'custom',
  'url' => 'https://llm.aihosting.mittwald.de/v1',
  'key' => 'your-api-key',
  'model' => 'Ministral-3-14B-Instruct-2512',
],
```

> [!WARNING]
> Copy the base URL exactly as your provider documents it, including `/v1` or `/api/v1`. Only `/chat/completions` is appended.

| Provider | `api.url` | Notes |
| --- | --- | --- |
| Mittwald AI Hosting | `https://llm.aihosting.mittwald.de/v1` | Dedicated plans get their own hostname |
| Ollama | `http://localhost:11434/v1` | No API key needed |
| Mistral | `https://api.mistral.ai/v1` | |
| OpenRouter | `https://openrouter.ai/api/v1` | |

Images are sent as base64 data URLs, resized to 500px, so file size limits do not matter.

## Request body options

`api.options` is merged into the top level of every request body. Use it for provider quirks. Mittwald runs Qwen models with thinking mode on by default. Thinking mode spends the token budget before the answer arrives. Turn it off:

```php
'api' => ['options' => ['chat_template_kwargs' => ['enable_thinking' => false]]]
```

You can raise the token limit the same way. For `openai` the value is sent as `max_completion_tokens`:

```php
'api' => ['options' => ['max_tokens' => 1000]]
```
