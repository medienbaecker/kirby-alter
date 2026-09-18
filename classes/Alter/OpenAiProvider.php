<?php

namespace Medienbaecker\Alter;

class OpenAiProvider extends CustomProvider
{
	protected function defaultUrl(): string
	{
		return 'https://api.openai.com/v1';
	}

	protected function defaultModel(): string
	{
		return 'gpt-5.6-luna';
	}

	protected function tokenField(): string
	{
		return 'max_completion_tokens';
	}

	public function name(): string
	{
		return 'OpenAI';
	}

	public function body(string $prompt, ?array $image = null): array
	{
		$body = array_merge(['reasoning_effort' => 'none'], parent::body($prompt, $image));

		if (isset($body['max_tokens'])) {
			$body['max_completion_tokens'] = $body['max_tokens'];
			unset($body['max_tokens']);
		}

		return $body;
	}

	public function requiresKey(): bool
	{
		return true;
	}
}
