<?php

namespace Medienbaecker\Alter;

/**
 * Any OpenAI-compatible chat completions endpoint.
 */
class CustomProvider extends Provider
{
	public function __construct(array $config)
	{
		parent::__construct($config);

		if ($this->url === '' || $this->model === '') {
			throw new \Exception('The "custom" provider needs api.url and api.model');
		}
	}

	protected function tokenField(): string
	{
		return 'max_tokens';
	}

	public function name(): string
	{
		return 'Custom';
	}

	public function endpoint(): string
	{
		return $this->url . '/chat/completions';
	}

	public function headers(): array
	{
		return $this->hasKey() ? ['Authorization: Bearer ' . $this->key] : [];
	}

	public function body(string $prompt, ?array $image = null): array
	{
		$content = [];

		if ($image !== null) {
			$content[] = [
				'type' => 'image_url',
				'image_url' => [
					'url' => 'data:' . $image['mime'] . ';base64,' . $image['data'],
				],
			];
		}

		$content[] = [
			'type' => 'text',
			'text' => $prompt,
		];

		// vLLM rejects any key beyond role and content inside a message
		$body = [
			'model' => $this->model,
			$this->tokenField() => 500,
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
		$choice = $response['choices'][0] ?? [];
		$text = $choice['message']['content'] ?? null;

		if (($text === null || $text === '') && ($choice['finish_reason'] ?? null) === 'length') {
			throw new \Exception('The model used the whole token budget before answering. Raise the limit or disable thinking via api.options.');
		}

		return is_string($text) && $text !== '' ? $text : null;
	}

	public function error(array $response): string
	{
		$error = $response['error'] ?? null;

		if (is_array($error)) {
			return $error['message'] ?? 'Unknown error';
		}

		if (is_string($error) && $error !== '') {
			return $error;
		}

		return 'Unknown error';
	}

	public function requiresKey(): bool
	{
		return false;
	}
}
