<?php

use Kirby\Toolkit\Str;

/**
 * Lightweight generator for Panel-triggered requests
 * (keeps command-line generator untouched).
 */
class AlterPanelGenerator
{
	private string $apiKey;
	private string $model;
	private $prompt;

	public function __construct(array $config)
	{
		$this->apiKey = (string)($config['apiKey'] ?? '');
		$this->model = (string)($config['model'] ?? 'claude-haiku-4-5');
		$this->prompt = $config['prompt'] ?? null;
	}

	public function generateForImages(array $images, array $languages, $defaultLanguage): array
	{
		$results = [];
		$totalGenerated = 0;

		foreach ($images as $image) {
			$result = $this->generateForImage($image, $languages, $defaultLanguage);
			$totalGenerated += $result['generated'];
			$results[] = $result;
		}

		return [
			'generated' => $totalGenerated,
			'images' => $results,
		];
	}

	public function generateForImage($image, array $languages, $defaultLanguage): array
	{
		$defaultCode = $defaultLanguage?->code();
		$imagePayload = null; // base64 + mime; created lazily

		// Ensure default language goes first to provide translation source
		usort($languages, static function ($a, $b) use ($defaultCode) {
			if ($a?->code() === $defaultCode) return -1;
			if ($b?->code() === $defaultCode) return 1;
			return 0;
		});

		$baseAlt = null;
		$baseLanguageCode = null;

		if ($defaultCode !== null) {
			$defaultAlt = trim((string)$this->currentAlt($image, $defaultCode));
			if (Str::length($defaultAlt) > 0) {
				$baseAlt = $defaultAlt;
				$baseLanguageCode = $defaultCode;
			}
		}

		// If the default language has no alt text, use any existing language as source
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

		$perLanguage = [];
		$generatedCount = 0;

		foreach ($languages as $language) {
			$languageCode = $language?->code();

			$status = $this->altStatus($image, $languageCode);
			if ($this->shouldSkip($status)) {
				$perLanguage[] = [
					'language' => $languageCode,
					'status' => 'skipped',
					'reason' => 'existing',
				];
				continue;
			}

			$baseHasAlt = Str::length(trim((string)$baseAlt)) > 0;
			$shouldTranslate = $baseHasAlt && $baseLanguageCode !== $languageCode;

			if ($shouldTranslate) {
				$altText = $this->translateAltText($baseAlt, $language);
				$action = 'translated';
			} else {
				$imagePayload ??= $this->encodeImage($image);
				$altText = $this->generateAltText($imagePayload, $image, $language);
				$action = 'generated';
			}

			$this->storeDraftAlt($image, $altText, $languageCode);
			$generatedCount++;

			// Once the default language is generated/translated, use it as the source for further translations.
			if ($defaultCode !== null && $languageCode === $defaultCode) {
				$baseAlt = $altText;
				$baseLanguageCode = $defaultCode;
			} elseif ($baseHasAlt !== true) {
				$baseAlt = $altText;
				$baseLanguageCode = $languageCode;
			}

			$perLanguage[] = [
				'language' => $languageCode,
				'status' => $action,
				'text' => $altText,
			];
		}

		return [
			'imageId' => $image->id(),
			'generated' => $generatedCount,
			'languages' => $perLanguage,
		];
	}

	private function altStatus($image, ?string $languageCode): array
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

		return [
			'current' => $draftHasAlt ? $draftAlt : $publishedAlt,
			'published' => $publishedAlt,
			'draftHasAlt' => $draftHasAlt,
		];
	}

	private function shouldSkip(array $status): bool
	{
		return Str::length(trim($status['current'])) > 0;
	}

	private function currentAlt($image, ?string $languageCode): string
	{
		$status = $this->altStatus($image, $languageCode);
		return $status['current'] ?? '';
	}

	private function storeDraftAlt($image, string $altText, ?string $languageCode): void
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

	private function encodeImage($image): array
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

	private function generateAltText(array $imagePayload, $image, $language = null): string
	{
		$prompt = $this->prompt;

		if (is_callable($prompt)) {
			$prompt = $prompt($image);
		}

		if ($language) {
			$prompt .= ' Write the alt text in ' . $language->name() . '.';
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

	private function translateAltText(string $text, $targetLanguage): string
	{
		$targetName = $targetLanguage ? $targetLanguage->name() : 'default language';
		$prompt = 'Translate this alt text to ' . $targetName . '. Keep it concise and descriptive. Only return the translated alt text, nothing else: "' . addslashes($text) . '"';

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

	private function callClaude(array $requestData): string
	{
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

	private function versionExists($version, ?string $languageCode): bool
	{
		return $languageCode === null
			? $version->exists()
			: $version->exists($languageCode);
	}

	private function contentArrayForVersion($image, string $versionId, ?string $languageCode): array
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
}
