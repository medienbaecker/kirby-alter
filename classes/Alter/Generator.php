<?php

namespace Medienbaecker\Alter;

use Kirby\Toolkit\Str;

/**
 * Shared base for Panel and CLI alt-text generation.
 * Contains the Claude API client, image encoding, prompt
 * construction, and Kirby version helpers.
 */
class Generator
{
	protected const SUPPORTED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

	protected string $apiKey;
	protected string $model;
	protected $prompt;
	protected $maxLength;
	protected int $activeConcurrency = 1;

	public function __construct(array $config)
	{
		$this->apiKey = (string)($config['apiKey'] ?? '');
		$this->model = (string)($config['model'] ?? 'claude-sonnet-5');
		$this->prompt = $config['prompt'] ?? null;
		$this->maxLength = $config['maxLength'] ?? false;
	}

	protected function curlOptions(array $requestData, int $timeout = 30): array
	{
		return [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => json_encode($requestData),
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'x-api-key: ' . $this->apiKey,
				'anthropic-version: 2023-06-01',
			],
			CURLOPT_TIMEOUT => $timeout,
		];
	}

	protected function callClaude(array $requestData): string
	{
		$results = $this->callClaudeMulti(['request' => fn () => $requestData], 1, null, 30);
		$result  = $results['request'];

		if ($result instanceof \Throwable) {
			throw $result;
		}

		return $result;
	}

	protected static function parseResponse(?string $response, int $httpCode): string
	{
		if ($httpCode !== 200) {
			$error = json_decode((string)$response, true);
			throw new \Exception('Claude API error (HTTP ' . $httpCode . '): ' . ($error['error']['message'] ?? 'Unknown error'));
		}

		$result = json_decode((string)$response, true);

		$text = null;

		foreach ($result['content'] ?? [] as $block) {
			if (($block['type'] ?? null) === 'text') {
				$text = $block['text'];
				break;
			}
		}

		if ($text === null) {
			throw new \Exception('Unexpected API response format');
		}

		return static::unwrapQuotes(trim($text));
	}

	/**
	 * @param array<string, callable> $buildersByKey Each builder returns the request body, and is
	 *        only called when its batch runs so payloads never all exist at once.
	 * @return array<string, string|\Throwable> Alt text per key, or that key's failure.
	 */
	protected function callClaudeMulti(array $buildersByKey, int $concurrency, ?callable $onResult = null, int $timeout = 120): array
	{
		$queue    = $buildersByKey;
		$attempts = [];
		$results  = [];

		$this->activeConcurrency = max(1, $concurrency);

		while ($queue !== []) {
			$batch = array_slice($queue, 0, $this->activeConcurrency, true);
			$queue = array_slice($queue, count($batch), null, true);

			$multi   = curl_multi_init();
			$handles = [];

			foreach ($batch as $key => $builder) {
				try {
					$requestData = $builder();
				} catch (\Throwable $e) {
					$results[$key] = $e;
					if ($onResult !== null) {
						$onResult($key, $e);
					}
					continue;
				}

				$this->onApiCall();

				$ch = curl_init('https://api.anthropic.com/v1/messages');
				curl_setopt_array($ch, $this->curlOptions($requestData, $timeout));
				curl_setopt($ch, CURLOPT_HEADER, true);
				curl_multi_add_handle($multi, $ch);
				$handles[$key] = $ch;
			}

			do {
				$status = curl_multi_exec($multi, $running);
				if ($running) {
					curl_multi_select($multi, 1.0);
				}
			} while ($running && $status === CURLM_OK);

			// curl_error() stays empty on a multi handle until the transfer's
			// result has been read off the queue.
			$transferErrors = [];
			while ($info = curl_multi_info_read($multi)) {
				if ($info['result'] !== CURLE_OK) {
					$transferErrors[(int)$info['handle']] = curl_strerror($info['result']);
				}
			}

			$retry = [];
			$wait  = 0;

			foreach ($handles as $key => $ch) {
				$raw        = curl_multi_getcontent($ch);
				$httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
				$curlError  = $transferErrors[(int)$ch] ?? curl_error($ch);

				$headers = substr((string)$raw, 0, $headerSize);
				$body    = substr((string)$raw, $headerSize);

				curl_multi_remove_handle($multi, $ch);
				curl_close($ch);

				$attempts[$key] = ($attempts[$key] ?? 0) + 1;

				if (($curlError || $httpCode === 0 || $httpCode === 429 || $httpCode >= 500) && $attempts[$key] < 4) {
					$retry[$key] = $buildersByKey[$key];
					$wait = max($wait, $this->retryDelay($headers, $attempts[$key]));
					continue;
				}

				try {
					if ($curlError) {
						throw new \Exception('cURL error: ' . $curlError);
					}
					$results[$key] = static::parseResponse($body, $httpCode);
				} catch (\Throwable $e) {
					$results[$key] = $e;
				}

				if ($onResult !== null) {
					$onResult($key, $results[$key]);
				}
			}

			curl_multi_close($multi);

			if ($retry !== [] && $wait > 0) {
				sleep($wait);
			}

			$queue = $retry + $queue;
		}

		$this->activeConcurrency = 1;

		return $results;
	}

	protected function retryDelay(string $headers, int $attempt): int
	{
		if (preg_match('/^retry-after:\s*(\d+)/mi', $headers, $m) === 1) {
			return min(30, (int)$m[1]);
		}

		return min(30, 2 ** $attempt);
	}

	protected static function unwrapQuotes(string $text): string
	{
		$pairs = ['"' => '"', "'" => "'", '„' => '“', '»' => '«', '“' => '”'];

		foreach ($pairs as $open => $close) {
			if (str_starts_with($text, $open) && str_ends_with($text, $close)) {
				return trim(mb_substr($text, mb_strlen($open), -mb_strlen($close)));
			}
		}

		// A quote at one end is only stray if it is the single quote in the whole
		// string. Alt text that transcribes a sign legitimately ends on a quote.
		foreach (['"', "'"] as $stray) {
			if (mb_substr_count($text, $stray) !== 1) {
				continue;
			}
			if (str_starts_with($text, $stray)) {
				return trim(mb_substr($text, 1));
			}
			if (str_ends_with($text, $stray)) {
				return trim(mb_substr($text, 0, -1));
			}
		}

		return $text;
	}

	/**
	 * Hook called before every API request.
	 * Override for rate-limiting, counting, etc.
	 */
	protected function onApiCall(): void
	{
	}

	protected function encodeImage($image): array
	{
		if (file_exists($image->root()) !== true) {
			throw new \Exception('Image file not found: ' . $image->root());
		}

		$resized = $image->thumb([
			'width' => 500,
			'height' => 500,
			'format' => null,
		]);
		$resized->publish();
		$content = $resized->read();

		if (!$content) {
			throw new \Exception('Failed to read image content');
		}

		return [
			'data' => base64_encode($content),
			'mime' => $resized->mime(),
		];
	}

	protected function buildAltPrompt($image, ?string $language = null): string
	{
		$prompt = $this->prompt;

		if (is_callable($prompt)) {
			$prompt = $prompt($image);
		}

		if ($language) {
			$prompt .= ' Write the alt text in ' . $language . '.';
		}

		if ($this->maxLength) {
			$prompt .= ' Keep the alt text under ' . (int)$this->maxLength . ' characters.';
		}

		return $prompt;
	}

	protected function generateAltText(array $imagePayload, $image, ?string $language = null): string
	{
		return $this->callClaude($this->buildAltRequest($imagePayload, $this->buildAltPrompt($image, $language)));
	}

	protected function buildAltRequest(array $imagePayload, string $prompt): array
	{
		$requestData = [
			'model' => $this->model,
			'max_tokens' => 500,
			'messages' => [
				[
					'role' => 'user',
					'content' => [
						[
							'type' => 'image',
							'source' => [
								'type' => 'base64',
								'media_type' => $imagePayload['mime'],
								'data' => $imagePayload['data'],
							],
						],
						[
							'type' => 'text',
							'text' => $prompt,
						],
					],
				],
			],
		];

		return $requestData;
	}

	public function generateAltTextBatch(array $imagesByKey, ?string $language = null, int $concurrency = 8, ?callable $onResult = null): array
	{
		$builders = [];

		foreach ($imagesByKey as $key => $image) {
			$builders[$key] = fn () => $this->buildAltRequest(
				$this->encodeImage($image),
				$this->buildAltPrompt($image, $language)
			);
		}

		return $this->callClaudeMulti($builders, $concurrency, $onResult);
	}

	protected function translateAltText(string $text, string $targetLanguage): string
	{
		$prompt = 'Translate this alt text to ' . $targetLanguage . '. Keep it concise and descriptive. Only return the translated alt text, nothing else: "' . addslashes($text) . '"';

		if ($this->maxLength) {
			$prompt .= ' Keep the translation under ' . (int)$this->maxLength . ' characters.';
		}

		$requestData = [
			'model' => $this->model,
			'max_tokens' => 500,
			'messages' => [
				[
					'role' => 'user',
					'content' => [
						[
							'type' => 'text',
							'text' => $prompt,
						],
					],
				],
			],
		];

		return $this->callClaude($requestData);
	}

	protected function versionExists($version, ?string $languageCode): bool
	{
		return $languageCode === null
			? $version->exists()
			: $version->exists($languageCode);
	}

	protected function contentArrayForVersion($image, string $versionId, ?string $languageCode): array
	{
		$version = $image->version($versionId);

		try {
			$fields = $languageCode === null
				? $version->read()
				: $version->read($languageCode);

			return $fields ?? [];
		} catch (\Throwable $e) {
			return [];
		}
	}

	protected function storeDraftAlt($image, string $altText, ?string $languageCode): void
	{
		$changes = $image->version('changes');

		$latestData = $this->contentArrayForVersion($image, 'latest', $languageCode);
		$draftData = $this->versionExists($changes, $languageCode)
			? $this->contentArrayForVersion($image, 'changes', $languageCode)
			: [];

		$merged = array_merge($latestData, $draftData);
		$merged['alt'] = $altText;

		$languageCode === null
			? $changes->save($merged)
			: $changes->save($merged, $languageCode);
	}

	protected function currentAlt($image, ?string $languageCode): string
	{
		$changes = $image->version('changes');
		$latestContent = $this->contentArrayForVersion($image, 'latest', $languageCode);
		$draftExists = $this->versionExists($changes, $languageCode);
		$draftContent = $draftExists
			? $this->contentArrayForVersion($image, 'changes', $languageCode)
			: [];

		$draftHasAlt = array_key_exists('alt', $draftContent);
		$draftAlt = trim((string)($draftContent['alt'] ?? ''));
		$publishedAlt = trim((string)($latestContent['alt'] ?? ''));

		return $draftHasAlt ? $draftAlt : $publishedAlt;
	}

	protected function findTranslationSource($image, array $languages, $defaultLanguage): array
	{
		$defaultCode = $defaultLanguage?->code();
		$baseAlt = null;
		$baseLanguageCode = null;

		if ($defaultCode !== null) {
			$defaultAlt = trim((string)$this->currentAlt($image, $defaultCode));
			if (Str::length($defaultAlt) > 0) {
				$baseAlt = $defaultAlt;
				$baseLanguageCode = $defaultCode;
			}
		}

		if (Str::length(trim((string)$baseAlt)) === 0 && function_exists('kirby') && kirby()->multilang()) {
			foreach (kirby()->languages() as $lang) {
				$code = $lang->code();
				$alt = trim((string)$this->currentAlt($image, $code));

				if (Str::length($alt) === 0) {
					continue;
				}

				$baseAlt = $alt;
				$baseLanguageCode = $code;
				break;
			}
		}

		return [$baseAlt, $baseLanguageCode];
	}

	public static function isSupported($image): bool
	{
		return in_array($image->mime(), static::SUPPORTED_MIME_TYPES, true);
	}

	/**
	 * Yields all images from site and pages.
	 * Pass $pages to limit scope (e.g. CLI --page filter).
	 */
	public static function allImages($pages = null): \Generator
	{
		foreach (site()->images() as $image) {
			yield ['image' => $image, 'parent' => 'site'];
		}

		$pages ??= site()->index(true);
		foreach ($pages as $page) {
			foreach ($page->images() as $image) {
				yield ['image' => $image, 'parent' => $page->id()];
			}
		}
	}
}
