<?php

use Kirby\Exception\NotFoundException;
use Kirby\Exception\PermissionException;
use Medienbaecker\Alter\Generator;
use Medienbaecker\Alter\PanelGenerator;

$versionExists = static function ($version, ?string $code): bool {
	return $code === null
		? $version->exists()
		: $version->exists($code);
};

$versionContentArray = static function ($version, ?string $code): array {
	try {
		$fields = $code === null ? $version->read() : $version->read($code);
		return $fields ?? [];
	} catch (\Throwable $e) {
		return [];
	}
};

return [
	[
		'pattern' => 'alter/images',
		'method' => 'GET',
		'action' => function () use ($versionExists, $versionContentArray) {
			$request = kirby()->request();
			$page = (int)$request->get('page', 1);
			$filter = $request->get('filter', 'all');
			$limit = 100;

			// Get current language from panel
			$languageCode = kirby()->multilang() ? kirby()->language()?->code() : null;
			$defaultLanguageCode = kirby()->multilang() ? kirby()->defaultLanguage()->code() : null;
			$siteLanguages = kirby()->multilang() ? kirby()->languages()->values() : [];
			$unsavedByLanguage = [];

			foreach ($siteLanguages as $language) {
				$unsavedByLanguage[$language->code()] = 0;
			}

			$latestContentArray = static function ($model, ?string $code): array {
				try {
					$latest = $model->version('latest');
					$fields = $code === null ? $latest->read() : $latest->read($code);
					return $fields ?? [];
				} catch (\Throwable $e) {
					return [];
				}
			};

			// Get template filter from options
			$allowedTemplates = kirby()->option('medienbaecker.alter.templates');
			if (is_string($allowedTemplates)) {
				$allowedTemplates = [$allowedTemplates];
			}

			// Collect all images
			$allImages = [];

			// Helper to process a single image and return its data array
			$processImage = function ($image, array $parent) use ($languageCode, $defaultLanguageCode, $siteLanguages, $allowedTemplates, $latestContentArray, $versionExists, $versionContentArray, &$unsavedByLanguage) {
				if ($allowedTemplates !== null) {
					$imageTemplate = $image->template();
					if (!in_array($imageTemplate, $allowedTemplates)) {
						return null;
					}
				}

				$latestContent = $latestContentArray($image, $languageCode);
				$latestAlt = (string)($latestContent['alt'] ?? '');
				$changes = $image->version('changes');
				$altByLanguage = [];

				if (!empty($siteLanguages)) {
					foreach ($siteLanguages as $lang) {
						$langCode = $lang->code();
						$langLatestContent = $latestContentArray($image, $langCode);
						$langLatestAlt = (string)($langLatestContent['alt'] ?? '');
						$langCurrentAlt = $langLatestAlt;

						if ($versionExists($changes, $langCode) === true) {
							$langChangesContent = $versionContentArray($changes, $langCode);
							if (array_key_exists('alt', $langChangesContent) === true) {
								$langCurrentAlt = (string)($langChangesContent['alt'] ?? '');
								if ($langCurrentAlt !== $langLatestAlt) {
									$unsavedByLanguage[$langCode] = ($unsavedByLanguage[$langCode] ?? 0) + 1;
								}
							}
						}

						$altByLanguage[$langCode] = trim((string)$langCurrentAlt) !== '';
					}
				}

				$changesContent = $versionExists($changes, $languageCode)
					? $versionContentArray($changes, $languageCode)
					: [];

				$currentAlt = array_key_exists('alt', $changesContent)
					? (string)($changesContent['alt'] ?? '')
					: $latestAlt;
				$hasChanges = $currentAlt !== $latestAlt;

				$changesPath = $parent['panelPath'] . '/files/' . $image->filename();

				$defaultAlt = $currentAlt;
				if ($defaultLanguageCode !== null && $defaultLanguageCode !== $languageCode) {
					$defaultLatest = $latestContentArray($image, $defaultLanguageCode);
					$defaultAlt = (string)($defaultLatest['alt'] ?? '');
					$defaultChangesContent = $versionExists($changes, $defaultLanguageCode)
						? $versionContentArray($changes, $defaultLanguageCode)
						: [];
					if (array_key_exists('alt', $defaultChangesContent)) {
						$defaultAlt = (string)($defaultChangesContent['alt'] ?? '');
					}
				}

				return [
					'id' => $image->id(),
					'url' => $image->url(),
					'thumbUrl' => $image->resize(500, 500)->url(),
					'filename' => $image->filename(),
					'alt' => $currentAlt,
					'altOriginal' => $latestAlt,
					'hasChanges' => $hasChanges,
					'altByLanguage' => $altByLanguage,
					'changesPath' => $changesPath,
					'panelUrl' => $image->panel()->url(),
					'pageUrl' => $parent['panelUrl'],
					'pageTitle' => $parent['title'],
					'pageId' => $parent['id'],
					'pagePanelUrl' => $parent['panelUrl'],
					'pageSort' => $parent['sort'],
					'pageStatus' => $parent['status'],
					'hasParentDrafts' => $parent['hasParentDrafts'],
					'breadcrumbs' => $parent['breadcrumbs'],
					'sortKey' => $parent['sortKey'],
					'language' => $languageCode,
					'altDefault' => $defaultAlt,
				];
			};

			// Collect site-level images
			$site = site();
			if ($site->hasImages()) {
				$siteLabel = t('view.site');
				$siteBreadcrumbs = [[
					'title' => $siteLabel,
					'label' => $siteLabel,
					'panelUrl' => '/site',
					'link' => '/site',
				]];

				foreach ($site->images() as $image) {
					$result = $processImage($image, [
						'id'              => 'site',
						'title'           => $siteLabel,
						'panelUrl'        => '/site',
						'panelPath'       => 'site',
						'sort'            => null,
						'status'          => null,
						'hasParentDrafts' => false,
						'breadcrumbs'     => $siteBreadcrumbs,
						'sortKey'         => '000000',
					]);
					if ($result !== null) {
						$allImages[] = $result;
					}
				}
			}

			// Collect page images
			$pages = site()->index(true);

			foreach ($pages as $sitePage) {
				if ($sitePage->hasImages()) {
					$hasParentDrafts = $sitePage->parents()->filter(fn($p) => $p->isDraft())->isNotEmpty();

					$parents = $sitePage->parents()->flip();
					$sortKey = '';
					foreach ($parents as $parent) {
						$sortKey .= sprintf('%06d-', $parent->num() ?? 999999);
					}
					$sortKey .= sprintf('%06d', $sitePage->num() ?? 999999);

					$breadcrumbs = [];
					foreach ($parents as $parent) {
						$breadcrumbs[] = [
							'title' => $parent->title()->value(),
							'label' => $parent->title()->value(),
							'panelUrl' => $parent->panel()->url(),
							'link' => $parent->panel()->url(),
						];
					}
					$breadcrumbs[] = [
						'title' => $sitePage->title()->value(),
						'label' => $sitePage->title()->value(),
						'panelUrl' => $sitePage->panel()->url(),
						'link' => $sitePage->panel()->url(),
					];

					foreach ($sitePage->images() as $image) {
						$result = $processImage($image, [
							'id'              => $sitePage->id(),
							'title'           => $sitePage->title()->value(),
							'panelUrl'        => $sitePage->panel()->url(),
							'panelPath'       => $sitePage->panel()->path(),
							'sort'            => $sitePage->num(),
							'status'          => $sitePage->status(),
							'hasParentDrafts' => $hasParentDrafts,
							'breadcrumbs'     => $breadcrumbs,
							'sortKey'         => $sortKey,
						]);
						if ($result !== null) {
							$allImages[] = $result;
						}
					}
				}
			}

			// Store original total before filtering
			$originalTotalImages = count($allImages);

			// Generation stats (always based on all images)
			$generationStats = [
				'missingCurrent' => 0,
				'missingAny' => 0,
			];

			foreach ($allImages as $imageData) {
				$currentAlt = trim((string)($imageData['alt'] ?? ''));
				if ($currentAlt === '') {
					$generationStats['missingCurrent']++;
				}

				$altByLang = $imageData['altByLanguage'] ?? [];
				$hasMissing = empty($altByLang)
					? $currentAlt === ''
					: in_array(false, $altByLang, true);
				if ($hasMissing) {
					$generationStats['missingAny']++;
				}
			}

			// Apply filter
			$filteredImages = $allImages;
			if ($filter === 'saved') {
				$filteredImages = array_filter($allImages, function ($imageData) {
					return !empty($imageData['alt']) && trim($imageData['alt']) !== '';
				});
			} elseif ($filter === 'missing') {
				$filteredImages = array_filter($allImages, function ($imageData) {
					return empty($imageData['alt']) || trim($imageData['alt']) === '';
				});
			} elseif ($filter === 'unsaved') {
				$filteredImages = array_filter($allImages, function ($imageData) {
					return $imageData['hasChanges'] === true;
				});
			}

			// Re-index filtered array
			$filteredImages = array_values($filteredImages);
			$filteredTotalImages = count($filteredImages);

			// Calculate totals for header badges (always based on all images)
			$totalUnsaved = 0;
			$totalSaved = 0;
			foreach ($allImages as $imageData) {
				if ($imageData['hasChanges']) {
					$totalUnsaved++;
				}
				if (!empty($imageData['altOriginal']) && trim($imageData['altOriginal']) !== '') {
					$totalSaved++;
				}
			}

			$totalPages = ceil($filteredTotalImages / $limit);
			$offset = ($page - 1) * $limit;
			$paginatedImages = array_slice($filteredImages, $offset, $limit);

			return [
				'images' => $paginatedImages,
				'defaultLanguage' => $defaultLanguageCode,
				'unsavedByLanguage' => $unsavedByLanguage,
				'pagination' => [
					'page' => (int)$page,
					'pages' => $totalPages,
					'total' => $filteredTotalImages,
					'limit' => $limit,
					'start' => $offset + 1,
					'end' => min($offset + $limit, $filteredTotalImages),
				],
				'totals' => [
					'unsaved' => $totalUnsaved,
					'saved' => $totalSaved,
					'total' => $originalTotalImages,
				],
				'generationStats' => $generationStats,
			];
		},
	],
	[
		'pattern' => 'alter/generate',
		'method' => 'POST',
		'action' => function () use ($versionExists, $versionContentArray) {
			$user = kirby()->user();
			if (!$user) {
				throw new PermissionException(t('medienbaecker.alter.notAuthenticated'));
			}

			if (option('medienbaecker.alter.panel.generation', false) !== true) {
				throw new PermissionException(t('medienbaecker.alter.generate.disabled'));
			}

			$apiKey = option('medienbaecker.alter.api.key', option('medienbaecker.alter.apiKey'));
			if (!$apiKey) {
				return ['error' => t('medienbaecker.alter.api.key.missing')];
			}

			$request = kirby()->request();
			$body = $request->body();

			$languageMode = $body->get('languageMode', 'current');
			if (!in_array($languageMode, ['all', 'current'], true)) {
				$languageMode = 'current';
			}
			$autoSelect = $body->get('autoSelect', false);
			$autoSelect = in_array($autoSelect, [true, 1, '1', 'true'], true);
			$imageIds = $body->get('imageIds', []);

			if (empty($imageIds)) {
				$imageIds = $body->get('imageId') ? [$body->get('imageId')] : [];
			}

			$imageIds = array_unique(array_filter((array)$imageIds));

			if (kirby()->multilang()) {
				$currentLanguage = kirby()->language();
				$languages = $languageMode === 'current'
					? [$currentLanguage ?? kirby()->defaultLanguage()]
					: kirby()->languages()->values();
				$defaultLanguage = kirby()->defaultLanguage();
			} else {
				$languages = [null];
				$defaultLanguage = null;
			}

			$images = [];

			if (empty($imageIds) === true) {
				if ($autoSelect !== true) {
					return ['error' => t('medienbaecker.alter.imageNotFound')];
				}

				$autoLimit = 100;

				$allowedTemplates = kirby()->option('medienbaecker.alter.templates');
				if (is_string($allowedTemplates)) {
					$allowedTemplates = [$allowedTemplates];
				}

				$currentAltForLanguage = static function ($image, ?string $code) use ($versionExists, $versionContentArray): string {
					$latest = $image->version('latest');
					$latestContent = $versionContentArray($latest, $code);
					$latestAlt = (string)($latestContent['alt'] ?? '');

					$changes = $image->version('changes');
					if ($versionExists($changes, $code) !== true) {
						return $latestAlt;
					}

					$changesContent = $versionContentArray($changes, $code);
					if (array_key_exists('alt', $changesContent) !== true) {
						return $latestAlt;
					}

					return (string)($changesContent['alt'] ?? '');
				};

				foreach (Generator::allImages() as $entry) {
					$image = $entry['image'];

					if (!Generator::isSupported($image)) {
						continue;
					}

					if ($allowedTemplates !== null) {
						$imageTemplate = $image->template();
						if (!in_array($imageTemplate, $allowedTemplates, true)) {
							continue;
						}
					}

					if ($image->permissions()->update() !== true) {
						continue;
					}

					if ($languageMode === 'current') {
						$target = $languages[0] ?? null;
						$code = $target?->code();
						$alt = trim((string)$currentAltForLanguage($image, $code));
						if ($alt === '') {
							$images[] = $image;
							if (count($images) >= $autoLimit) {
								break;
							}
						}
						continue;
					}

					// all languages
					foreach ($languages as $target) {
						$code = $target?->code();
						$alt = trim((string)$currentAltForLanguage($image, $code));
						if ($alt === '') {
							$images[] = $image;
							if (count($images) >= $autoLimit) {
								break 2;
							}
							break;
						}
					}
				}

				if (empty($images)) {
					return [
						'success' => true,
						'generated' => 0,
						'images' => [],
					];
				}
			} else {
				foreach ($imageIds as $imageId) {
					$image = kirby()->file($imageId);
					if (!$image) {
						throw new NotFoundException(t('medienbaecker.alter.imageNotFound'));
					}

					if ($image->permissions()->update() !== true) {
						throw new PermissionException(t('medienbaecker.alter.notAuthenticated'));
					}

					$images[] = $image;
				}
			}

			$generator = new PanelGenerator([
				'apiKey' => $apiKey,
				'model' => option('medienbaecker.alter.api.model', option('medienbaecker.alter.model', 'claude-haiku-4-5')),
				'prompt' => option('medienbaecker.alter.prompt'),
				'maxLength' => option('medienbaecker.alter.maxLength', false),
			]);

			try {
				$result = $generator->generateForImages($images, $languages, $defaultLanguage);
			} catch (\Throwable $e) {
				return ['error' => $e->getMessage()];
			}

			return [
				'success' => true,
				'generated' => $result['generated'],
				'images' => $result['images'],
			];
		},
	],
	[
		'pattern' => 'alter/publish',
		'method'  => 'POST',
		'action'  => function () {
			$user = kirby()->user();
			if (!$user) {
				throw new PermissionException(t('medienbaecker.alter.notAuthenticated'));
			}

			$request = kirby()->request();
			$imageId = $request->body()->get('imageId');
			$alt     = (string)($request->body()->get('alt') ?? '');

			$image = kirby()->file($imageId);
			if (!$image) {
				throw new NotFoundException(t('medienbaecker.alter.imageNotFound'));
			}

			if ($image->permissions()->update() !== true) {
				throw new PermissionException(t('medienbaecker.alter.notAuthenticated'));
			}

			// Get current language from panel
			$languageCode = kirby()->multilang() ? kirby()->language()?->code() : null;

			// 1) Directly update the file with the alt text (publish it)
			$image = $image->update(['alt' => $alt], $languageCode);

			// 2) Delete the changes version if it's now identical to latest
			$changes = $image->version('changes');
			$changesLang = $languageCode ?? 'default';

			if ($changes->exists($changesLang) && $changes->isIdentical('latest', $changesLang)) {
				$changes->delete($changesLang);
			}

			return ['success' => true];
		},
	],
	[
		'pattern' => 'alter/discard',
		'method'  => 'POST',
		'action'  => function () {
			$user = kirby()->user();
			if (!$user) {
				throw new PermissionException(t('medienbaecker.alter.notAuthenticated'));
			}

			$request = kirby()->request();
			$imageId = $request->body()->get('imageId');

			$image = kirby()->file($imageId);
			if (!$image) {
				throw new NotFoundException(t('medienbaecker.alter.imageNotFound'));
			}

			if ($image->permissions()->update() !== true) {
				throw new PermissionException(t('medienbaecker.alter.notAuthenticated'));
			}

			// Get current language from panel
			$languageCode = kirby()->multilang() ? kirby()->language()?->code() : null;

			$changes = $image->version('changes');
			$hasChanges = $languageCode === null
				? $changes->exists()
				: $changes->exists($languageCode);

			if ($hasChanges !== true) {
				return ['success' => true];
			}

			// Reset alt in changes to match latest
			$changesLang = $languageCode ?? 'default';
			$latestContent = $image->version('latest')->read($changesLang) ?? [];
			$changesContent = $changes->read($changesLang) ?? [];
			$changesContent['alt'] = $latestContent['alt'] ?? '';
			$changes->replace($changesContent, $changesLang);

			// Delete changes if now identical to latest
			if ($changes->isIdentical('latest', $changesLang)) {
				$changes->delete($changesLang);
			}

			return ['success' => true];
		},
	],
	[
		'pattern' => 'alter/update',
		'method' => 'POST',
		'action' => function () {
			$user = kirby()->user();
			if (!$user) {
				return ['error' => t('medienbaecker.alter.notAuthenticated')];
			}

			$request = kirby()->request();
			$imageId = $request->body()->get('imageId');
			$field = $request->body()->get('field');
			$value = $request->body()->get('value');

			if ($field !== 'alt') {
				return ['error' => t('medienbaecker.alter.invalidField')];
			}

			try {
				$image = kirby()->file($imageId);
				if (!$image) {
					return ['error' => t('medienbaecker.alter.imageNotFound')];
				}

				// Save alt to draft changes (always create or update changes version)
				$languageCode = kirby()->multilang() ? kirby()->language()?->code() : null;
				$changes = $image->version('changes');
				$hasChanges = $languageCode === null
					? $changes->exists()
					: $changes->exists($languageCode);
				$changesContent = $hasChanges
					? ($languageCode === null
						? ($changes->read() ?? [])
						: ($changes->read($languageCode) ?? []))
					: [];

				// If there is no changes version yet, initialize it from the
				// published/latest content so we don't lose other fields
				if ($hasChanges !== true) {
					$latestContent = $languageCode === null
						? ($image->version('latest')->read() ?? [])
						: ($image->version('latest')->read($languageCode) ?? []);
					$changesContent = is_array($latestContent) ? $latestContent : [];
					$changesContent['alt'] = $value;
					if ($languageCode === null) {
						$changes->create($changesContent);
					} else {
						$changes->create($changesContent, $languageCode);
					}
				} else {
					$changesContent = $languageCode === null
						? ($changes->read() ?? [])
						: ($changes->read($languageCode) ?? []);
					$changesContent['alt'] = $value;
					if ($languageCode === null) {
						$changes->replace($changesContent);
					} else {
						$changes->replace($changesContent, $languageCode);
					}
				}

				return ['success' => true, 'message' => t('medienbaecker.alter.success')];
			} catch (Exception $e) {
				return ['error' => $e->getMessage()];
			}
		},
	],
];
