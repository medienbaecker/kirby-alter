<?php

declare(strict_types=1);

use Kirby\CLI\CLI;

return [
	'description' => 'Generate alt texts for images using Claude API',
	'args' => [
		'prompt' => [
			'prefix' => 'p',
			'longPrefix' => 'prompt',
			'description' => 'Custom prompt for generating alt texts',
		],
		'overwrite' => [
			'longPrefix' => 'overwrite',
			'description' => 'Overwrite existing alt texts',
			'defaultValue' => false,
			'noValue' => true,
		],
		'dry-run' => [
			'longPrefix' => 'dry-run',
			'description' => 'Preview changes without updating files',
			'defaultValue' => false,
			'noValue' => true,
		],
		'verbose' => [
			'longPrefix' => 'verbose',
			'description' => 'Show detailed progress information',
			'defaultValue' => false,
			'noValue' => true,
		],
		'page' => [
			'longPrefix' => 'page',
			'description' => 'Start from specific page URI (e.g. "blog" or "blog/some-article")',
			'defaultValue' => null,
		],
	],
	'command' => static function (CLI $cli): void {
		$generator = new AltTextGenerator($cli);
		$generator->run();
	}
];

class AltTextGenerator
{
	private CLI $cli;
	private array $config;
	private array $altTextCache = [];
	private int $apiCallCount = 0;
	private int $processed = 0;
	private int $errors = 0;
	private int $uniqueImagesProcessed = 0;

	public function __construct(CLI $cli)
	{
		$this->cli = $cli;

		// Use CLI prompt if provided, otherwise use config option
		$prompt = $cli->arg('prompt') ?: kirby()->option('medienbaecker.alter.prompt');

		$this->config = [
			'prompt' => $prompt,
			'model' => kirby()->option('medienbaecker.alter.api.model', kirby()->option('medienbaecker.alter.model', 'claude-haiku-4-5')),
			'overwrite' => $cli->arg('overwrite'),
			'dryRun' => $cli->arg('dry-run'),
			'verbose' => $cli->arg('verbose'),
			'apiKey' => kirby()->option('medienbaecker.alter.api.key', kirby()->option('medienbaecker.alter.apiKey')),
			'pageFilter' => $cli->arg('page'),
		];
	}

	public function run(): void
	{
		$this->validateConfig();
		$this->showRunMode();

		$languages = $this->selectLanguages();
		$imagesByHash = $this->collectAllImages($languages);

		if (empty($imagesByHash)) {
			$this->cli->bold()->green()->out('No images need alt text generation!');
			return;
		}

		$this->processImages($imagesByHash, $languages);
		$this->showSummary();
	}

	private function validateConfig(): void
	{
		if (!$this->config['apiKey']) {
			throw new \Exception('Claude API key is required');
		}
	}

	private function showRunMode(): void
	{
		if ($this->config['dryRun']) {
			$this->cli->bold()->blue()->out('DRY RUN MODE - no changes will be written');
		} else {
			$this->cli->bold()->yellow()->out('Generated alt texts will be stored as unsaved changes');
		}
	}

	private function selectLanguages(): array
	{
		if (!kirby()->multilang()) {
			// For single-language sites, ask for the language name
			$input = $this->cli->input('Language for alt text generation (English):');
			$input->defaultTo('English');
			$languageName = $input->prompt();

			$mockLanguage = new class($languageName) {
				private string $name;

				public function __construct(string $name)
				{
					$this->name = ucfirst(strtolower($name));
				}

				public function name(): string
				{
					return $this->name;
				}

				public function code(): ?string
				{
					return null; // Single language has no code
				}

				public function isDefault(): bool
				{
					return true;
				}
			};
			return [$mockLanguage];
		}

		$languages = kirby()->languages();
		$this->cli->out('');
		$this->cli->bold()->cyan()->out('Multi-language site detected with ' . count($languages) . ' languages:');
		$this->cli->dim()->out('   ' . implode(', ', $languages->pluck('name')));
		$this->cli->out('');
		$this->cli->bold()->yellow()->out('Generating for all languages will use ' . count($languages) . 'x more API tokens!');
		$this->cli->out('');

		$options = [
			'default' => 'Default language only (' . kirby()->defaultLanguage()->name() . ')',
		];

		foreach ($languages as $language) {
			if ($language->isDefault()) {
				continue;
			}
			$options['lang:' . $language->code()] = 'Only ' . $language->name();
		}

		$options['all'] = 'All ' . count($languages) . ' languages';

		$choice = $this->cli->radio('Choose your option:', $options)->prompt();

		if ($choice === 'all') {
			$selected = $languages->values();
			usort($selected, fn($a, $b) => $a->isDefault() ? -1 : ($b->isDefault() ? 1 : 0));
			$this->cli->green()->out('Will generate alt texts for all ' . count($selected) . ' languages');
			return $selected;
		}

		if ($choice === 'default') {
			$this->cli->green()->out('Will generate alt texts for default language only: ' . kirby()->defaultLanguage()->name());
			return [kirby()->defaultLanguage()];
		}

		if (str_starts_with($choice, 'lang:')) {
			$code = substr($choice, 5);
			$lang = $languages->find($code);
			if ($lang) {
				$this->cli->green()->out('Will generate alt texts for: ' . $lang->name());
				return [$lang];
			}
		}

		// Fallback to default if choice is unexpected
		$this->cli->green()->out('Will generate alt texts for default language only: ' . kirby()->defaultLanguage()->name());
		return [kirby()->defaultLanguage()];
	}

	private function collectAllImages(array $languages): array
	{
		$this->cli->dim()->out('Scanning site for images...');

		$pages = $this->getPages();
		$imagesByHash = [];
		$totalScanned = 0;
		$totalNeedingProcessing = 0;

		foreach ($pages as $page) {
			foreach ($page->images() as $image) {
				$hash = $this->getImageHash($image);
				$totalScanned++;

				// Group all instances by hash
				if (!isset($imagesByHash[$hash])) {
					$imagesByHash[$hash] = [
						'instances' => [],
						'needsProcessing' => false,
						'firstOrder' => $totalScanned, // Track order of first occurrence
					];
				}

				$imagesByHash[$hash]['instances'][] = [
					'image' => $image,
					'page' => $page,
				];

				// Check if ANY instance needs processing (check this specific instance)
				if (!$imagesByHash[$hash]['needsProcessing'] && $this->imageNeedsProcessing($image, $languages)) {
					$imagesByHash[$hash]['needsProcessing'] = true;
				}
			}
		}

		// Filter out hashes that don't need processing and count images that need work
		$imagesByHash = array_filter($imagesByHash, function ($data) use (&$totalNeedingProcessing) {
			if ($data['needsProcessing']) {
				$totalNeedingProcessing += count($data['instances']);
				return true;
			}
			return false;
		});

		$this->cli->dim()->out('Scanned ' . $totalScanned . ' images across site');

		if ($totalNeedingProcessing === 0) {
			$this->cli->bold()->green()->out('All images already have alt text!');
		} else {
			$uniqueFiles = count($imagesByHash);
			if ($uniqueFiles === 1) {
				$this->cli->bold()->green()->out('Found ' . $totalNeedingProcessing . ' images needing alt text (1 unique file)');
			} else {
				$this->cli->bold()->green()->out('Found ' . $totalNeedingProcessing . ' images needing alt text (' . $uniqueFiles . ' unique files)');
			}
		}

		return $imagesByHash;
	}

	private function getPages()
	{
		if ($this->config['pageFilter']) {
			$startPage = page($this->config['pageFilter']);
			if (!$startPage) {
				throw new \Exception('Page not found: ' . $this->config['pageFilter']);
			}
			$this->cli->info('Processing from page: ' . $startPage->title() . ' (' . $startPage->id() . ')');
			return $startPage->index(true)->prepend($startPage);
		}

		return site()->index(true);
	}

	private function getImageHash($image): string
	{
		return md5_file($image->root());
	}

	private function versionExists($version, ?string $languageCode): bool
	{
		return $languageCode === null
			? $version->exists()
			: $version->exists($languageCode);
	}

	private function versionContent($version, ?string $languageCode)
	{
		return $languageCode === null
			? $version->content()
			: $version->content($languageCode);
	}

	/**
	 * Reads a version's content array safely. If the language doesn't exist yet,
	 * fall back to default language content (or empty array).
	 */
	private function contentArrayForVersion($image, string $versionId, ?string $languageCode): array
	{
		$version = $image->version($versionId);

		try {
			return $this->versionContent($version, $languageCode)->toArray();
		} catch (\Throwable $e) {
			// try default language (multilang), otherwise null
			$fallbackLanguage = kirby()->multilang() && kirby()->defaultLanguage()
				? kirby()->defaultLanguage()->code()
				: null;

			if ($fallbackLanguage !== null && $fallbackLanguage !== $languageCode) {
				try {
					return $version->content($fallbackLanguage)->toArray();
				} catch (\Throwable $e2) {
					// ignore
				}
			}

			try {
				return $this->versionContent($version, null)->toArray();
			} catch (\Throwable $e3) {
				return [];
			}
		}
	}

	private function latestAlt($image, ?string $languageCode): string
	{
		$data = $this->contentArrayForVersion($image, 'latest', $languageCode);
		return trim((string)($data['alt'] ?? ''));
	}

	private function draftAltInfo($image, ?string $languageCode): array
	{
		$changes = $image->version('changes');

		if ($this->versionExists($changes, $languageCode) !== true) {
			return ['exists' => false, 'hasKey' => false, 'value' => null];
		}

		$data = $this->contentArrayForVersion($image, 'changes', $languageCode);

		return [
			'exists' => true,
			'hasKey' => array_key_exists('alt', $data),
			'value' => trim((string)($data['alt'] ?? '')),
		];
	}

	/**
	* Auto-fill conditions:
	* - published/latest alt is empty
	* - draft alt is not modified (either missing or equal to latest alt)
	*
	* This permits filling alt even when other draft fields exist.
	 */
	private function shouldAutofillAlt($image, ?string $languageCode): bool
	{
		$latestAlt = $this->latestAlt($image, $languageCode);
		if ($latestAlt !== '') {
			return false;
		}

		$draft = $this->draftAltInfo($image, $languageCode);

		// no draft version -> safe to fill
		if ($draft['exists'] !== true) {
			return true;
		}

		// draft exists but alt not touched -> safe to fill
		if ($draft['hasKey'] !== true) {
			return true;
		}

		// alt key exists: only safe if it matches latest (i.e. not edited)
		return $draft['value'] === $latestAlt;
	}

	private function imageNeedsProcessing($image, array $languages): bool
	{
		if ($this->config['overwrite']) {
			return true;
		}

		foreach ($languages as $language) {
			$languageCode = $language?->code();

			// Allow processing even if other draft fields exist,
			// but only if alt itself is not edited in draft.
			if ($this->shouldAutofillAlt($image, $languageCode)) {
				return true;
			}
		}

		return false;
	}

	private function processImages(array $imagesByHash, array $languages): void
	{
		$processedFiles = 0;
		$errors = 0;
		$uniqueImagesProcessed = 0;
		$allLanguages = kirby()->multilang() ? kirby()->languages()->values() : [];

		// Sort hash groups by first occurrence order to match panel view
		uasort($imagesByHash, function ($a, $b) {
			return $a['firstOrder'] - $b['firstOrder'];
		});

		// Process by language to avoid immutable object issues
		foreach ($languages as $language) {
			$languageCode = $language?->code();
			$languageName = $language?->name() ?? 'Default';

			$this->cli->out('');
			$this->cli->bold()->cyan()->out('Processing ' . $languageName . ' language...');

			foreach ($imagesByHash as $hash => $hashData) {
				$instances = $hashData['instances'];
				$firstInstance = $instances[0]['image'];

				// Track unique images processed (only count once per image, not per language)
				if ($language === reset($languages)) {
						$uniqueImagesProcessed++;
					}

					$this->cli->out('');
					$this->cli->bold()->out('  ' . $firstInstance->filename() . ' (' . count($instances) . ' pages)');

					try {
						$result = $this->generateOrGetAltText($firstInstance, $hash, $language, $instances, $allLanguages);

					if ($result) {
						$altText = $result['text'];
						$source = $result['source'];

						foreach ($instances as $key => $instanceData) {
							$image = $instanceData['image'];
							$page = $instanceData['page'];

							$needsUpdate = $this->config['overwrite'] || $this->shouldAutofillAlt($image, $languageCode);

							if ($needsUpdate) {
								$updatedImage = $this->updateImageAltText($image, $altText, $languageCode);
								if ($updatedImage) {
									$imagesByHash[$hash]['instances'][$key]['image'] = $updatedImage;
								}
								$action = $this->config['dryRun'] ? 'Would store changes for' : 'Stored changes for';
								$this->cli->green()->out('    ' . $action . ' ' . $image->filename() . ' (' . $page->id() . ')');
								$processedFiles++;
							} else {
								// Explain why the item was skipped (alt exists or alt edited in draft)
								$latestAlt = $this->latestAlt($image, $languageCode);
								$draft = $this->draftAltInfo($image, $languageCode);

								if ($latestAlt !== '') {
									$this->cli->dim()->out('    Skipped ' . $image->filename() . ' (' . $page->id() . ') - already has alt text');
								} elseif ($draft['exists'] === true && $draft['hasKey'] === true) {
									$this->cli->dim()->out('    Skipped ' . $image->filename() . ' (' . $page->id() . ') - alt edited in draft');
								} else {
									$this->cli->dim()->out('    Skipped ' . $image->filename() . ' (' . $page->id() . ')');
								}
							}
						}

						$this->cli->dim()->out('    ' . ucfirst($source) . ': "' . $altText . '"');
					} else {
						foreach ($instances as $instanceData) {
							$image = $instanceData['image'];
							$page = $instanceData['page'];
							$this->cli->dim()->out('    Skipped ' . $image->filename() . ' (' . $page->id() . ') - already has alt text');
						}
					}
				} catch (\Exception $e) {
					$this->cli->red()->out('    Failed: ' . $e->getMessage());
					$errors++;
				}
			}
		}

		$this->processed = $processedFiles;
		$this->errors = $errors;
		$this->uniqueImagesProcessed = $uniqueImagesProcessed;
	}

	private function generateOrGetAltText($image, string $hash, $language, array $instances, array $allLanguages): ?array
	{
		$languageCode = $language?->code();
		$cacheKey = $hash . '_' . ($languageCode ?? 'default');

		if (isset($this->altTextCache[$cacheKey])) {
			return $this->altTextCache[$cacheKey];
		}

		// First, search ALL instances for existing alt text in this language
		$existingAltText = null;
		foreach ($instances as $instanceData) {
			$existingAlt = $this->getAltTextForLanguage($instanceData['image'], $languageCode);
			if ($existingAlt && $existingAlt->isNotEmpty()) {
				$existingAltText = $existingAlt->value();
				break; // Found existing alt text, use it
			}
		}

		// If existing alt text is found and not overwriting, use it
		if ($existingAltText && !$this->config['overwrite']) {
			$result = ['text' => $existingAltText, 'source' => 'copied from existing'];
			$this->altTextCache[$cacheKey] = $result;
			return $result;
		}

		// Only generate if at least one instance should be autofilled
		$needsProcessing = false;
		foreach ($instances as $instanceData) {
			if ($this->config['overwrite'] || $this->shouldAutofillAlt($instanceData['image'], $languageCode)) {
				$needsProcessing = true;
				break;
			}
		}

		if (!$needsProcessing) {
			return null; // nothing to do for this language
		}

		// Generate or translate new alt text
		if ($language && !$language->isDefault()) {
			$altText = $this->translateAltText($image, $hash, $language, $instances);
			$result = ['text' => $altText, 'source' => 'translated from ' . kirby()->defaultLanguage()->name()];
		} else {
			// Default language path: if another language already has alt, prefer translating it
			$existingOtherAlt = $this->findAltInOtherLanguages($instances, $allLanguages, $language);
			if ($existingOtherAlt) {
				$altText = $this->translateAltTextFromExisting($existingOtherAlt, $language);
				$result = ['text' => $altText, 'source' => 'translated from existing alt'];
			} else {
				$altText = $this->generateAltText($image, $language);
				$result = ['text' => $altText, 'source' => 'generated from image'];
			}
		}

		$this->altTextCache[$cacheKey] = $result;
		return $result;
	}

	private function findAltInOtherLanguages(array $instances, array $languages, $targetLanguage): ?string
	{
		$targetCode = $targetLanguage?->code();

		foreach ($languages as $language) {
			if ($language->code() === $targetCode) {
				continue;
			}

			$code = $language->code();

			foreach ($instances as $instanceData) {
				$existingAlt = $this->getAltTextForLanguage($instanceData['image'], $code);
				if ($existingAlt) {
					$value = method_exists($existingAlt, 'isNotEmpty')
						? ($existingAlt->isNotEmpty() ? $existingAlt->value() : '')
						: (string)$existingAlt;

					if (trim($value) !== '') {
						return $value;
					}
				}
			}
		}

		return null;
	}

	private function translateAltText($image, string $hash, $language, array $instances): string
	{
		$defaultCacheKey = $hash . '_default';
		$defaultAltText = $this->altTextCache[$defaultCacheKey] ?? null;

		if (!$defaultAltText) {
			// Search ALL instances for existing default language alt text
			$defaultLanguageCode = kirby()->defaultLanguage()->code();
			foreach ($instances as $instanceData) {
				$defaultAlt = $this->getAltTextForLanguage($instanceData['image'], $defaultLanguageCode);
				if ($defaultAlt && $defaultAlt->isNotEmpty()) {
					$defaultAltText = $defaultAlt->value();
					$this->altTextCache[$defaultCacheKey] = $defaultAltText;
					break;
				}
			}

			// If still no default alt text found, generate it
			if (!$defaultAltText) {
				$defaultAltText = $this->generateAltText($image, kirby()->defaultLanguage());
				$this->altTextCache[$defaultCacheKey] = $defaultAltText;

				// Store the default language as draft (alt-only smart write)
				$image = $this->updateImageAltText($image, $defaultAltText, $defaultLanguageCode);
			}
		}

		$prompt = 'Translate this alt text to ' . $language->name() . '. Keep it concise and descriptive. Only return the translated alt text, nothing else: "' . $defaultAltText . '"';

		return $this->callClaudeApi($prompt);
	}

	private function generateAltText($image, $language = null): string
	{
		if (!file_exists($image->root())) {
			throw new \Exception('Image file not found: ' . $image->root());
		}

		$resized = $image->thumb([
			'width' => 500,
			'height' => 500,
			'format' => false, // Keep original format
		]);
		$resized->publish();
		$imageContent = $resized->read();

		if (!$imageContent) {
			throw new \Exception('Failed to read image content');
		}

		$imageData = base64_encode($imageContent);
		$mimeType = $image->mime();

		// Get prompt - handle callback if provided
		$prompt = $this->config['prompt'];

		// If prompt is callable, invoke it with the image
		if (is_callable($prompt)) {
			$prompt = $prompt($image);
		}

		// Add language specification to prompt
		if ($language) {
			$prompt .= ' Write the alt text in ' . $language->name() . '.';
		}

		return $this->callClaudeApiWithImage($imageData, $mimeType, $prompt);
	}

	private function translateAltTextFromExisting(string $text, $targetLanguage): string
	{
		$targetName = $targetLanguage ? $targetLanguage->name() : (kirby()->multilang() ? kirby()->defaultLanguage()->name() : 'default language');
		$prompt = 'Translate this alt text to ' . $targetName . '. Keep it concise and descriptive. Only return the translated alt text, nothing else: "' . $text . '"';

		return $this->callClaudeApi($prompt);
	}

	private function callClaudeApi(string $prompt): string
	{
		$requestData = [
			'model' => $this->config['model'],
			'max_tokens' => 500,
			'messages' => [
				[
					'role' => 'user',
					'content' => $prompt
				]
			]
		];

		return $this->makeApiRequest($requestData);
	}

	private function callClaudeApiWithImage(string $imageData, string $mimeType, string $prompt): string
	{
		$requestData = [
			'model' => $this->config['model'],
			'max_tokens' => 500,
			'messages' => [
				[
					'role' => 'user',
					'content' => [
						[
							'type' => 'image',
							'source' => [
								'type' => 'base64',
								'media_type' => $mimeType,
								'data' => $imageData
							]
						],
						[
							'type' => 'text',
							'text' => $prompt
						]
					]
				]
			]
		];

		return $this->makeApiRequest($requestData);
	}

	private function makeApiRequest(array $requestData): string
	{
		$this->apiCallCount++;

		$ch = curl_init('https://api.anthropic.com/v1/messages');
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => json_encode($requestData),
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'x-api-key: ' . $this->config['apiKey'],
				'anthropic-version: 2023-06-01'
			],
			CURLOPT_TIMEOUT => 30,
		]);

		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		unset($ch); // CurlHandle auto-closes when unset in PHP 8.0+

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

		usleep(500000);

		return trim($result['content'][0]['text'], '"\'');
	}

	/**
	 * Alt-only smart write:
	 * - preserves any other draft fields (merge latest + existing changes)
	 * - only touches alt
	 * - if alt was edited in draft and overwrite=false, does nothing
	 */
	private function updateImageAltText($image, string $altText, ?string $languageCode)
	{
		if ($this->config['dryRun']) {
			return $image;
		}

		return kirby()->impersonate('kirby', function () use ($image, $altText, $languageCode) {
				$changes = $image->version('changes');

				// If draft alt was modified and overwrite is false, do not modify it
				if ($this->config['overwrite'] !== true) {
					$latestAlt = $this->latestAlt($image, $languageCode);
				$draft = $this->draftAltInfo($image, $languageCode);

				if ($draft['exists'] === true && $draft['hasKey'] === true && $draft['value'] !== $latestAlt) {
					return $image;
					}
				}

				$latestData = $this->contentArrayForVersion($image, 'latest', $languageCode);
				$draftData  = $this->versionExists($changes, $languageCode)
					? $this->contentArrayForVersion($image, 'changes', $languageCode)
					: [];

				// Preserve other draft fields; overwrite alt
				$merged = array_merge($latestData, $draftData);
				$merged['alt'] = $altText;

				$languageCode === null
					? $changes->save($merged)
					: $changes->save($merged, $languageCode);

				return $image;
			});
	}

	private function getAltTextForLanguage($image, ?string $languageCode)
	{
		$changes = $image->version('changes');

		if ($this->versionExists($changes, $languageCode)) {
			// Use toArray + array_key_exists so an intentionally empty draft alt still counts as "present"
			$draftData = $this->contentArrayForVersion($image, 'changes', $languageCode);
			if (array_key_exists('alt', $draftData)) {
				return $this->versionContent($changes, $languageCode)->get('alt');
			}
		}

		try {
			// Explicitly read from latest to avoid ambiguity when a changes version exists
			return $this->versionContent($image->version('latest'), $languageCode)->get('alt');
		} catch (\Throwable $e) {
			// If translation doesn't exist yet, treat as missing
			return null;
		}
	}

	private function showSummary(): void
	{
		$this->cli->out('');
		$this->cli->out('');

		$imageText = $this->uniqueImagesProcessed === 1 ? 'image' : 'images';
		$fileText = $this->processed === 1 ? 'file' : 'files';

		if ($this->config['dryRun']) {
			$this->cli->bold()->blue()->out('Dry run complete: ' . $this->uniqueImagesProcessed . ' ' . $imageText . ' (' . $this->processed . ' ' . $fileText . ') would be stored as changes');
		} else {
			$this->cli->bold()->green()->out('Successfully processed ' . $this->uniqueImagesProcessed . ' ' . $imageText . ' (' . $this->processed . ' ' . $fileText . ' stored as changes)');
		}

		if ($this->errors > 0) {
			$this->cli->bold()->red()->out($this->errors . ' file updates failed');
		}

		if ($this->config['verbose']) {
			$this->cli->dim()->out('Total API calls made: ' . $this->apiCallCount);
		}
	}
}
