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
	protected string $apiKey;
	protected string $model;
	protected $prompt;
	protected $maxLength;

	public function __construct(array $config)
	{
		$this->apiKey = (string)($config['apiKey'] ?? '');
		$this->model = (string)($config['model'] ?? 'claude-haiku-4-5');
		$this->prompt = $config['prompt'] ?? null;
		$this->maxLength = $config['maxLength'] ?? false;
	}

	protected function callClaude(array $requestData): string
	{
		$this->onApiCall();

		$ch = curl_init('https://api.anthropic.com/v1/messages');
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => json_encode($requestData),
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'x-api-key: ' . $this->apiKey,
				'anthropic-version: 2023-06-01',
			],
			CURLOPT_TIMEOUT => 30,
		]);

		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		unset($ch);

		if ($curlError) {
			throw new \Exception('cURL error: ' . $curlError);
		}

		if ($httpCode !== 200) {
			$error = json_decode($response, true);
			throw new \Exception('Claude API error (HTTP ' . $httpCode . '): ' . ($error['error']['message'] ?? 'Unknown error'));
		}

		$result = json_decode($response, true);

		if (!isset($result['content'][0]['text'])) {
			throw new \Exception('Unexpected API response format');
		}

		return trim($result['content'][0]['text'], '"\'');
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
			'format' => false,
		]);
		$resized->publish();
		$content = $resized->read();

		if (!$content) {
			throw new \Exception('Failed to read image content');
		}

		return [
			'data' => base64_encode($content),
			'mime' => $image->mime(),
		];
	}

	protected function generateAltText(array $imagePayload, $image, $language = null): string
	{
		$prompt = $this->prompt;

		if (is_callable($prompt)) {
			$prompt = $prompt($image);
		}

		if ($language) {
			$prompt .= ' Write the alt text in ' . $language->name() . '.';
		}

		if ($this->maxLength) {
			$prompt .= ' Keep the alt text under ' . (int)$this->maxLength . ' characters.';
		}

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

		return $this->callClaude($requestData);
	}

	protected function translateAltText(string $text, $targetLanguage): string
	{
		$targetName = $targetLanguage ? $targetLanguage->name() : 'default language';
		$prompt = 'Translate this alt text to ' . $targetName . '. Keep it concise and descriptive. Only return the translated alt text, nothing else: "' . addslashes($text) . '"';

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
