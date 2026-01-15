<?php

use Kirby\Cms\App as Kirby;
use Kirby\Exception\NotFoundException;
use Kirby\Exception\PermissionException;

Kirby::plugin('medienbaecker/alter', [
	'options' => [
		'apiKey' => null,
		'model' => 'claude-haiku-4-5',
		'templates' => null,
		'maxLength' => false,
		'prompt' => function ($file) {
			$prompt = 'You are an accessibility expert writing alt text. Write a concise, short description in one to three sentences. Start directly with the subject - NO introductory phrases like "image of", "shows", "displays", "depicts", "contains", "features" etc.';
			$prompt .= ' The image is on a page called “' . $file->page()->title() . '”.';
			$prompt .= ' The site is called “' . $file->site()->title() . '”.';
			$prompt .= ' Return the alt text only, without any additional text or formatting.';

			return $prompt;
		},
	],
	'translations' => [
		'de' => require_once __DIR__ . '/languages/de.php',
		'en' => require_once __DIR__ . '/languages/en.php',
	],
	'areas' => [
		'alter' => function ($kirby) {
			return [
				'label' => t('medienbaecker.alter.title'),
				'icon' => 'image',
				'menu' => true,
				'link' => 'alter',
				'views' => [
					[
						'pattern' => 'alter/(:num?)',
						'action' => function ($page = 1) {
							return [
								'component' => 'k-alter-view',
								'props' => [
									'page' => (int)$page,
									'maxLength' => option('medienbaecker.alter.maxLength', false)
								]
							];
						}
					]
				]
			];
		}
	],
	'api' => [
		'routes' => [
			[
				'pattern' => 'alter/images',
				'method' => 'GET',
				'action' => function () {
					$request = kirby()->request();
					$page = (int)$request->get('page', 1);
					$filter = $request->get('filter', 'all');
					$limit = 100;

					// Get current language from panel
					$currentLanguage = kirby()->language();
					$languageCode = $currentLanguage ? $currentLanguage->code() : null;

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

								$latestContent = $image->content($languageCode)->toArray();
								$latestAlt = (string)($latestContent['alt'] ?? '');
								$changes = $image->version('changes');
								$changesContent = $changes->exists($languageCode)
									? $changes->content($languageCode)->toArray()
									: [];

								$currentAlt = array_key_exists('alt', $changesContent)
									? (string)($changesContent['alt'] ?? '')
									: $latestAlt;
								$hasChanges = $currentAlt !== $latestAlt;

								$changesPath = $sitePage->panel()->path() . '/files/' . $image->filename();

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
								];
							}
						}
					}

					// Store original total before filtering
					$originalTotalImages = count($allImages);

					// Apply filter
					$filteredImages = $allImages;
					if ($filter === 'with_alt') {
						$filteredImages = array_filter($allImages, function($imageData) {
							return !empty($imageData['alt']) && trim($imageData['alt']) !== '';
						});
					} elseif ($filter === 'without_alt') {
						$filteredImages = array_filter($allImages, function($imageData) {
							return empty($imageData['alt']) || trim($imageData['alt']) === '';
						});
					} elseif ($filter === 'unsaved') {
						$filteredImages = array_filter($allImages, function($imageData) {
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
						'pagination' => [
							'page' => (int)$page,
							'pages' => $totalPages,
							'total' => $filteredTotalImages,
							'limit' => $limit,
							'start' => $offset + 1,
							'end' => min($offset + $limit, $filteredTotalImages)
						],
						'totals' => [
							'unsaved' => $totalUnsaved,
							'saved' => $totalSaved,
							'total' => $originalTotalImages
						]
					];
				}
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
					$currentLanguage = kirby()->language();
					$languageCode = $currentLanguage ? $currentLanguage->code() : null;

					// 1) Directly update the file with the alt text (publish it)
					$image = $image->update(['alt' => $alt], $languageCode);

					// 2) Remove alt from the changes version (if it exists) to clean up
					$changes = $image->version('changes');
					if ($changes->exists($languageCode)) {
						$changesContent = $changes->content($languageCode)->toArray();
						unset($changesContent['alt']);

						if (empty($changesContent)) {
							// No other draft fields, delete the changes version
							$changes->delete($languageCode);
						} else {
							// Keep other draft fields, just remove alt
							$changes->replace($changesContent, $languageCode);
                        // Now, restore alt in the draft if other fields exist
                        $changesContent['alt'] = $alt;
                        $changes->replace($changesContent, $languageCode);
						}
					}

					return ['success' => true];
				}
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
					$currentLanguage = kirby()->language();
					$languageCode = $currentLanguage ? $currentLanguage->code() : null;

					$changes = $image->version('changes');
					if ($changes->exists($languageCode) !== true) {
						return ['success' => true];
					}

					$changesContent = $changes->content($languageCode)->toArray();
					unset($changesContent['alt']);

					if (empty($changesContent) === true) {
						$changes->delete($languageCode);
					} else {
						$changes->replace($changesContent, $languageCode);
					}

					return ['success' => true];
				}
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
						$currentLanguage = kirby()->language();
						$languageCode = $currentLanguage ? $currentLanguage->code() : null;
						$changes = $image->version('changes');
						$changesContent = $changes->exists($languageCode)
							? $changes->content($languageCode)->toArray()
							: [];
						// If there is no changes version yet, initialize it from the
						// published/latest content so we don't lose other fields
						if ($changes->exists($languageCode) !== true) {
							$latestContent = $image->content($languageCode)->toArray();
							$changesContent = is_array($latestContent) ? $latestContent : [];
							$changesContent['alt'] = $value;
							$changes->create($changesContent, $languageCode);
						} else {
							$changesContent = $changes->content($languageCode)->toArray();
							$changesContent['alt'] = $value;
							$changes->replace($changesContent, $languageCode);
						}

						return ['success' => true, 'message' => t('medienbaecker.alter.success')];
					} catch (Exception $e) {
						return ['error' => $e->getMessage()];
					}
				}
			],
		]
	],
	'commands' => [
		'alter:generate' => require_once __DIR__ . '/commands/generate.php',
	]
]);
