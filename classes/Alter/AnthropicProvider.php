<?php

namespace Medienbaecker\Alter;

class AnthropicProvider extends Provider
{
	protected function defaultUrl(): string
	{
		return 'https://api.anthropic.com/v1';
	}

	protected function defaultModel(): string
	{
		return 'claude-sonnet-5';
	}

	public function name(): string
	{
		return 'Anthropic';
	}

	public function endpoint(): string
	{
		return $this->url . '/messages';
	}

	public function headers(): array
	{
		return [
			'x-api-key: ' . $this->key,
			'anthropic-version: 2023-06-01',
		];
	}

	public function body(string $prompt, ?array $image = null): array
	{
		$content = [];

		if ($image !== null) {
			$content[] = [
				'type' => 'image',
				'source' => [
					'type' => 'base64',
					'media_type' => $image['mime'],
					'data' => $image['data'],
				],
			];
		}

		$content[] = [
			'type' => 'text',
			'text' => $prompt,
		];

		$body = [
			'model' => $this->model,
			'max_tokens' => 500,
			'messages' => [
				[
					'role' => 'user',
					'content' => $content,
				],
			],
		];

		return array_merge($body, $this->options);
	}

	public function text(array $response): ?string
	{
		foreach ($response['content'] ?? [] as $block) {
			if (($block['type'] ?? null) === 'text') {
				return $block['text'] ?? null;
			}
		}

		return null;
	}

	public function error(array $response): string
	{
		return $response['error']['message'] ?? 'Unknown error';
	}
}
