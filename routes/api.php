<?php

use Kirby\Exception\NotFoundException;
use Kirby\Exception\PermissionException;

return [
	[
		'pattern' => 'alter/images',
		'method' => 'GET',
		'action' => function () {
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

			// Get template filter from options
			$allowedTemplates = kirby()->option('medienbaecker.alter.templates');
			if (is_string($allowedTemplates)) {
				$allowedTemplates = [$allowedTemplates];
			}

			// Collect all images
			$allImages = [];
			$pages = site()->index(true);

			foreach ($pages as $sitePage) {
				if ($sitePage->hasImages()) {
					foreach ($sitePage->images() as $image) {
						// Skip images with templates not in allowed list
						if ($allowedTemplates !== null) {
							$imageTemplate = $image->template();
							if (!in_array($imageTemplate, $allowedTemplates)) {
								continue;
							}
						}

								$latestContent = $latestContentArray($image, $languageCode);
								$latestAlt = (string)($latestContent['alt'] ?? '');
								$changes = $image->version('changes');
								$hasAnyAlt = false;
								$hasMissingAlt = false;

								// Track unsaved changes per language (for the language dropdown)
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

										if (
											$hasAnyAlt !== true &&
											trim((string)$langCurrentAlt) !== ''
										) {
											$hasAnyAlt = true;
										}

										if (
											$hasMissingAlt !== true &&
											trim((string)$langCurrentAlt) === ''
										) {
											$hasMissingAlt = true;
										}
									}
								}

						$changesContent = $versionExists($changes, $languageCode)
							? $versionContentArray($changes, $languageCode)
							: [];

							$currentAlt = array_key_exists('alt', $changesContent)
								? (string)($changesContent['alt'] ?? '')
								: $latestAlt;
							$hasChanges = $currentAlt !== $latestAlt;

								if (empty($siteLanguages) && trim((string)$currentAlt) !== '') {
									$hasAnyAlt = true;
								}

								if (empty($siteLanguages) && trim((string)$currentAlt) === '') {
									$hasMissingAlt = true;
								}

							$changesPath = $sitePage->panel()->path() . '/files/' . $image->filename();

							// Effective default-language alt (used as placeholder in panel)
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

						// Check if any parent pages are drafts
						$hasParentDrafts = $sitePage->parents()->filter(fn($p) => $p->isDraft())->isNotEmpty();

						// Build breadcrumbs and sort key
						$parents = $sitePage->parents()->flip();
						$sortKey = '';

						// Build hierarchical sort key with parent sort numbers
						foreach ($parents as $parent) {
							$sortKey .= sprintf('%06d-', $parent->num() ?? 999999);
						}
						$sortKey .= sprintf('%06d', $sitePage->num() ?? 999999);

						// Build breadcrumbs array for clickable navigation
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

								$allImages[] = [
									'id' => $image->id(),
									'url' => $image->url(),
									'thumbUrl' => $image->resize(500, 500)->url(),
							'filename' => $image->filename(),
							'alt' => $currentAlt,
									'altOriginal' => $latestAlt,
									'hasChanges' => $hasChanges,
									'hasAnyAlt' => $hasAnyAlt,
									'hasMissingAlt' => $hasMissingAlt,
									'changesPath' => $changesPath,
									'panelUrl' => $image->panel()->url(),
									'pageUrl' => $image->page()->url(),
									'pageTitle' => $sitePage->title()->value(),
							'pageId' => $sitePage->id(),
							'pagePanelUrl' => $sitePage->panel()->url(),
							'pageSort' => $sitePage->num(),
							'pageStatus' => $sitePage->status(),
							'hasParentDrafts' => $hasParentDrafts,
							'breadcrumbs' => $breadcrumbs,
							'sortKey' => $sortKey,
							'language' => $languageCode,
							'altDefault' => $defaultAlt,
						];
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

					if (($imageData['hasMissingAlt'] ?? false) === true) {
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
		'action' => function () {
			$user = kirby()->user();
			if (!$user) {
				throw new PermissionException(t('medienbaecker.alter.notAuthenticated'));
			}

			if (option('medienbaecker.alter.panel.generation', option('medienbaecker.alter.panelGeneration', false)) !== true) {
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

					$pages = site()->index(true);

					foreach ($pages as $sitePage) {
						if ($sitePage->hasImages() !== true) {
							continue;
						}

						foreach ($sitePage->images() as $image) {
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
										break 2;
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
										break 3;
									}
									break;
								}
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

				$generator = new AlterPanelGenerator([
					'apiKey' => $apiKey,
					'model' => option('medienbaecker.alter.api.model', option('medienbaecker.alter.model', 'claude-haiku-4-5')),
					'prompt' => option('medienbaecker.alter.prompt'),
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

			// 2) Remove alt from the changes version (if it exists) to clean up
			$changes = $image->version('changes');
			$hasChanges = $languageCode === null
				? $changes->exists()
				: $changes->exists($languageCode);

			if ($hasChanges) {
				$changesContent = $languageCode === null
					? ($changes->read() ?? [])
					: ($changes->read($languageCode) ?? []);
				unset($changesContent['alt']);

				if (empty($changesContent)) {
					// No other draft fields, delete the changes version
					$languageCode === null
						? $changes->delete()
						: $changes->delete($languageCode);
				} else {
					// Keep other draft fields, just remove alt
					if ($languageCode === null) {
						$changes->replace($changesContent);
					} else {
						$changes->replace($changesContent, $languageCode);
					}
					// Now, restore alt in the draft if other fields exist
					$changesContent['alt'] = $alt;
					if ($languageCode === null) {
						$changes->replace($changesContent);
					} else {
						$changes->replace($changesContent, $languageCode);
					}
				}
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

			$changesContent = $languageCode === null
				? ($changes->read() ?? [])
				: ($changes->read($languageCode) ?? []);
			unset($changesContent['alt']);

			if (empty($changesContent) === true) {
				$languageCode === null
					? $changes->delete()
					: $changes->delete($languageCode);
			} else {
				if ($languageCode === null) {
					$changes->replace($changesContent);
				} else {
					$changes->replace($changesContent, $languageCode);
				}
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
