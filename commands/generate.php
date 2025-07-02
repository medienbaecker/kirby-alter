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
			'defaultValue' => 'You are an accessibility expert writing alt text. Write a concise, short description in one to three sentences. Start directly with the subject - NO introductory phrases like "image of", "shows", "displays", "depicts", "contains", "features" etc. Return the alt text only, without any additional text or formatting.',
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
		$this->config = [
			'prompt' => $cli->arg('prompt'),
			'overwrite' => $cli->arg('overwrite'),
			'dryRun' => $cli->arg('dry-run'),
			'verbose' => $cli->arg('verbose'),
			'apiKey' => kirby()->option('medienbaecker.alter.apiKey'),
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

		if (strlen($this->config['prompt']) < 10) {
			throw new \Exception('Prompt must be at least 10 characters long');
		}
	}

	private function showRunMode(): void
	{
		if ($this->config['dryRun']) {
			$this->cli->bold()->blue()->out('DRY RUN MODE - no files will be updated');
		} else {
			$this->cli->bold()->yellow()->out('Files will be updated with generated alt texts');
		}
	}

	private function selectLanguages(): array
	{
		if (!kirby()->multilang()) {
			// For single-language sites, ask for the language name
			$languageName = $this->cli->input('Language for alt text generation:', 'English');

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
			'all' => 'All ' . count($languages) . ' languages'
		];

		$choice = $this->cli->radio('Choose your option:', $options)->prompt();

		if ($choice === 'all') {
			$selected = $languages->values();
			usort($selected, fn($a, $b) => $a->isDefault() ? -1 : ($b->isDefault() ? 1 : 0));
			$this->cli->green()->out('Will generate alt texts for all ' . count($selected) . ' languages');
			return $selected;
		}

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

	private function imageNeedsProcessing($image, array $languages): bool
	{
		if ($this->config['overwrite']) {
			return true;
		}

		foreach ($languages as $language) {
			$languageCode = $language?->code();
			$existingAlt = $this->getAltTextForLanguage($image, $languageCode);

			if (!$existingAlt || $existingAlt->isEmpty()) {
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
					$result = $this->generateOrGetAltText($firstInstance, $hash, $language, $instances);

					if ($result) {
						$altText = $result['text'];
						$source = $result['source'];

						foreach ($instances as $key => $instanceData) {
							$image = $instanceData['image'];
							$page = $instanceData['page'];

							$existingAlt = $this->getAltTextForLanguage($image, $languageCode);
							$needsUpdate = $this->config['overwrite'] || !$existingAlt || $existingAlt->isEmpty();

							if ($needsUpdate) {
								$updatedImage = $this->updateImageAltText($image, $altText, $languageCode);
								if ($updatedImage) {
									$imagesByHash[$hash]['instances'][$key]['image'] = $updatedImage;
								}
								$action = $this->config['dryRun'] ? 'Would update' : 'Updated';
								$this->cli->green()->out('    ' . $action . ' ' . $image->filename() . ' (' . $page->id() . ')');
								$processedFiles++;
							} else {
								$this->cli->dim()->out('    Skipped ' . $image->filename() . ' (' . $page->id() . ') - already has alt text');
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

	private function generateOrGetAltText($image, string $hash, $language, array $instances): ?array
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

		// If we found existing alt text and not overwriting, use it
		if ($existingAltText && !$this->config['overwrite']) {
			$result = ['text' => $existingAltText, 'source' => 'copied from existing'];
			$this->altTextCache[$cacheKey] = $result;
			return $result;
		}

		// Check if any instance needs processing (for when all instances already have alt text)
		$needsProcessing = false;
		foreach ($instances as $instanceData) {
			$existingAlt = $this->getAltTextForLanguage($instanceData['image'], $languageCode);
			if ($this->config['overwrite'] || !$existingAlt || $existingAlt->isEmpty()) {
				$needsProcessing = true;
				break;
			}
		}

		if (!$needsProcessing) {
			return null; // All instances already have alt text
		}

		// Generate or translate new alt text
		if ($language && !$language->isDefault()) {
			$altText = $this->translateAltText($image, $hash, $language, $instances);
			$result = ['text' => $altText, 'source' => 'translated from ' . kirby()->defaultLanguage()->name()];
		} else {
			$altText = $this->generateAltText($image, $language);
			$result = ['text' => $altText, 'source' => 'generated from image'];
		}

		$this->altTextCache[$cacheKey] = $result;
		return $result;
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

				// Update the default language file
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

		$resized = $image->resize(500);
		$resized->publish();
		$imageContent = $resized->read();

		if (!$imageContent) {
			throw new \Exception('Failed to read image content');
		}

		$imageData = base64_encode($imageContent);
		$mimeType = $image->mime();

		// Add language specification to prompt
		$prompt = $this->config['prompt'];
		if ($language) {
			$prompt .= ' Write the alt text in ' . $language->name() . '.';
		}

		return $this->callClaudeApiWithImage($imageData, $mimeType, $prompt);
	}

	private function callClaudeApi(string $prompt): string
	{
		$requestData = [
			'model' => 'claude-3-5-haiku-latest',
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
			'model' => 'claude-3-5-haiku-latest',
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
		curl_close($ch);

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

	private function updateImageAltText($image, string $altText, ?string $languageCode)
	{
		if ($this->config['dryRun']) {
			return $image;
		}

		return kirby()->impersonate('kirby', function () use ($image, $altText, $languageCode) {
			if ($languageCode) {
				return $image->update(['alt' => $altText], $languageCode);
			} else {
				return $image->update(['alt' => $altText]);
			}
		});
	}

	private function getAltTextForLanguage($image, ?string $languageCode)
	{
		if (!$languageCode) {
			return $image->alt();
		}

		if (!$image->translation($languageCode)->exists()) {
			return null;
		}

		return $image->content($languageCode)->get('alt');
	}

	private function showSummary(): void
	{
		$this->cli->out('');
		$this->cli->out('');

		$imageText = $this->uniqueImagesProcessed === 1 ? 'image' : 'images';
		$fileText = $this->processed === 1 ? 'file' : 'files';

		if ($this->config['dryRun']) {
			$this->cli->bold()->blue()->out('Dry run complete: ' . $this->uniqueImagesProcessed . ' ' . $imageText . ' (' . $this->processed . ' ' . $fileText . ') would be updated');
		} else {
			$this->cli->bold()->green()->out('Successfully processed ' . $this->uniqueImagesProcessed . ' ' . $imageText . ' (' . $this->processed . ' ' . $fileText . ' updated)');
		}

		if ($this->errors > 0) {
			$this->cli->bold()->red()->out($this->errors . ' file updates failed');
		}

		if ($this->config['verbose']) {
			$this->cli->dim()->out('Total API calls made: ' . $this->apiCallCount);
		}
	}
}
