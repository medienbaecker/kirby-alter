<?php

use Kirby\CLI\CLI;
use Medienbaecker\Alter\Generator;

return [
	'description' => 'Generate alt texts for images using Claude API',
	'args' => [
		'prompt' => [
			'prefix' => 'p',
			'longPrefix' => 'prompt',
			'description' => 'Custom prompt for alt text generation',
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

class AltTextGenerator extends Generator
{
	private CLI $cli;
	private array $cliConfig;
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

		parent::__construct([
			'prompt' => $prompt,
			'model' => kirby()->option('medienbaecker.alter.api.model', kirby()->option('medienbaecker.alter.model', 'claude-haiku-4-5')),
			'apiKey' => kirby()->option('medienbaecker.alter.api.key', kirby()->option('medienbaecker.alter.apiKey')),
			'maxLength' => kirby()->option('medienbaecker.alter.maxLength', false),
		]);

		$this->cliConfig = [
			'overwrite' => $cli->arg('overwrite'),
			'dryRun' => $cli->arg('dry-run'),
			'verbose' => $cli->arg('verbose'),
			'pageFilter' => $cli->arg('page'),
		];
	}

	protected function onApiCall(): void
	{
		$this->apiCallCount++;
		usleep(500000);
	}

	/**
	 * CLI uses ->content()->toArray() with fallback logic
	 * instead of the base's ->read().
	 */
	protected function contentArrayForVersion($image, string $versionId, ?string $languageCode): array
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
		if (!$this->apiKey) {
			throw new \Exception('Claude API key is required');
		}
	}

	private function showRunMode(): void
	{
		if ($this->cliConfig['dryRun']) {
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

		foreach (static::allImages($pages) as $entry) {
			$image = $entry['image'];
			$parent = $entry['parent'];
			$totalScanned++;

			if (!static::isSupported($image)) {
				if ($this->cliConfig['verbose']) {
					$this->cli->dim()->out('  Skipping ' . $image->filename() . ' (unsupported format: ' . $image->mime() . ')');
				}
				continue;
			}

			$hash = $this->getImageHash($image);

			if (!isset($imagesByHash[$hash])) {
				$imagesByHash[$hash] = [
					'instances' => [],
					'needsProcessing' => false,
					'firstOrder' => $totalScanned,
				];
			}

			$imagesByHash[$hash]['instances'][] = [
				'image' => $image,
				'parent' => $parent,
			];

			if (!$imagesByHash[$hash]['needsProcessing'] && $this->imageNeedsProcessing($image, $languages)) {
				$imagesByHash[$hash]['needsProcessing'] = true;
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
		if ($this->cliConfig['pageFilter']) {
			$startPage = page($this->cliConfig['pageFilter']);
			if (!$startPage) {
				throw new \Exception('Page not found: ' . $this->cliConfig['pageFilter']);
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

	private function versionContent($version, ?string $languageCode)
	{
		return $languageCode === null
			? $version->content()
			: $version->content($languageCode);
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
		if ($this->cliConfig['overwrite']) {
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
							$parent = $instanceData['parent'];

							$needsUpdate = $this->cliConfig['overwrite'] || $this->shouldAutofillAlt($image, $languageCode);

							if ($needsUpdate) {
								$updatedImage = $this->updateImageAltText($image, $altText, $languageCode);
								if ($updatedImage) {
									$imagesByHash[$hash]['instances'][$key]['image'] = $updatedImage;
								}
								$action = $this->cliConfig['dryRun'] ? 'Would store changes for' : 'Stored changes for';
								$this->cli->green()->out('    ' . $action . ' ' . $image->filename() . ' (' . $parent . ')');
								$processedFiles++;
							} else {
								// Explain why the item was skipped (alt exists or alt edited in draft)
								$latestAlt = $this->latestAlt($image, $languageCode);
								$draft = $this->draftAltInfo($image, $languageCode);

								if ($latestAlt !== '') {
									$this->cli->dim()->out('    Skipped ' . $image->filename() . ' (' . $parent . ') - already has alt text');
								} elseif ($draft['exists'] === true && $draft['hasKey'] === true) {
									$this->cli->dim()->out('    Skipped ' . $image->filename() . ' (' . $parent . ') - alt edited in draft');
								} else {
									$this->cli->dim()->out('    Skipped ' . $image->filename() . ' (' . $parent . ')');
								}
							}
						}

						$this->cli->dim()->out('    ' . ucfirst($source) . ': "' . $altText . '"');
					} else {
						foreach ($instances as $instanceData) {
							$image = $instanceData['image'];
							$parent = $instanceData['parent'];
							$this->cli->dim()->out('    Skipped ' . $image->filename() . ' (' . $parent . ') - already has alt text');
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
		if ($existingAltText && !$this->cliConfig['overwrite']) {
			$result = ['text' => $existingAltText, 'source' => 'copied from existing'];
			$this->altTextCache[$cacheKey] = $result;
			return $result;
		}

		// Only generate if at least one instance should be autofilled
		$needsProcessing = false;
		foreach ($instances as $instanceData) {
			if ($this->cliConfig['overwrite'] || $this->shouldAutofillAlt($instanceData['image'], $languageCode)) {
				$needsProcessing = true;
				break;
			}
		}

		if (!$needsProcessing) {
			return null; // nothing to do for this language
		}

		// Generate or translate new alt text
		if ($language && !$language->isDefault()) {
			$altText = $this->translateForLanguage($image, $hash, $language, $instances);
			$result = ['text' => $altText, 'source' => 'translated from ' . kirby()->defaultLanguage()->name()];
		} else {
			// Default language path: if another language already has alt, prefer translating it
			$existingOtherAlt = $this->findAltInOtherLanguages($instances, $allLanguages, $language);
			if ($existingOtherAlt) {
				$altText = $this->translateAltText($existingOtherAlt, $language);
				$result = ['text' => $altText, 'source' => 'translated from existing alt'];
			} else {
				$altText = $this->generateFromImage($image, $language);
				$result = ['text' => $altText, 'source' => 'generated from image'];
			}
		}

		$this->altTextCache[$cacheKey] = $result;
		return $result;
	}

	private function generateFromImage($image, $language = null): string
	{
		$imagePayload = $this->encodeImage($image);
		return $this->generateAltText($imagePayload, $image, $language);
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

	private function translateForLanguage($image, string $hash, $language, array $instances): string
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
				$defaultAltText = $this->generateFromImage($image, kirby()->defaultLanguage());
				$this->altTextCache[$defaultCacheKey] = $defaultAltText;

				// Store the default language as draft (alt-only smart write)
				$image = $this->updateImageAltText($image, $defaultAltText, $defaultLanguageCode);
			}
		}

		return $this->translateAltText($defaultAltText, $language);
	}

	/**
	 * Alt-only smart write:
	 * - preserves any other draft fields (merge latest + existing changes)
	 * - only touches alt
	 * - if alt was edited in draft and overwrite=false, does nothing
	 */
	private function updateImageAltText($image, string $altText, ?string $languageCode)
	{
		if ($this->cliConfig['dryRun']) {
			return $image;
		}

		return kirby()->impersonate('kirby', function () use ($image, $altText, $languageCode) {
			$changes = $image->version('changes');

			// If draft alt was modified and overwrite is false, do not modify it
			if ($this->cliConfig['overwrite'] !== true) {
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

		if ($this->cliConfig['dryRun']) {
			$this->cli->bold()->blue()->out('Dry run complete: ' . $this->uniqueImagesProcessed . ' ' . $imageText . ' (' . $this->processed . ' ' . $fileText . ') would be stored as changes');
		} else {
			$this->cli->bold()->green()->out('Successfully processed ' . $this->uniqueImagesProcessed . ' ' . $imageText . ' (' . $this->processed . ' ' . $fileText . ' stored as changes)');
		}

		if ($this->errors > 0) {
			$this->cli->bold()->red()->out($this->errors . ' file updates failed');
		}

		if ($this->cliConfig['verbose']) {
			$this->cli->dim()->out('Total API calls made: ' . $this->apiCallCount);
		}
	}
}
