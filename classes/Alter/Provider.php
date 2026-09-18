<?php

namespace Medienbaecker\Alter;

/**
 * Base for the API dialects Alter can talk to.
 * Builds requests, reads answers and errors back out.
 */
abstract class Provider
{
	protected string $key;
	protected string $model;
	protected string $url;
	protected array $options;

	public function __construct(array $config)
	{
		$this->key = (string)($config['apiKey'] ?? '');
		$this->model = (string)($config['model'] ?? '') ?: $this->defaultModel();
		$this->url = rtrim((string)($config['url'] ?? '') ?: $this->defaultUrl(), '/');

		if ($this->url !== '' && in_array(parse_url($this->url, PHP_URL_SCHEME), ['http', 'https'], true) === false) {
			throw new \Exception('api.url must start with http:// or https://');
		}

		$options = $config['options'] ?? [];

		if (is_array($options) === false || ($options !== [] && array_is_list($options))) {
			throw new \Exception('api.options must be an array of field => value pairs');
		}

		$this->options = $options;
	}

	public static function create(array $config): Provider
	{
		return match (($config['provider'] ?? null) ?: 'anthropic') {
			'anthropic' => new AnthropicProvider($config),
			'openai' => new OpenAiProvider($config),
			'custom' => new CustomProvider($config),
			default => throw new \Exception('Unknown provider "' . $config['provider'] . '"'),
		};
	}

	protected function defaultUrl(): string
	{
		return '';
	}

	protected function defaultModel(): string
	{
		return '';
	}

	abstract public function name(): string;

	abstract public function endpoint(): string;

	/**
	 * Auth headers only, the Generator adds Content-Type.
	 */
	abstract public function headers(): array;

	/**
	 * @param array|null $image ['data' => base64, 'mime' => …] from Generator::encodeImage
	 */
	abstract public function body(string $prompt, ?array $image = null): array;

	abstract public function text(array $response): ?string;

	abstract public function error(array $response): string;

	public function requiresKey(): bool
	{
		return true;
	}

	public function hasKey(): bool
	{
		return $this->key !== '';
	}
}
