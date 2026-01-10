<?php

use Kirby\Cms\App as Kirby;

Kirby::plugin('medienbaecker/alter', [
	'options' => [
		'apiKey' => null,
		'model' => 'claude-haiku-4-5',
		'templates' => null,
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
									'page' => (int)$page
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

								// Get alt text for current language
								$altText = '';
								if ($languageCode) {
									$altText = $image->alt()->translation($languageCode)->value() ?? '';
								} else {
									$altText = $image->alt()->value() ?? '';
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
									'alt' => $altText,
									'alt_reviewed' => $image->alt_reviewed()->toBool(),
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
					} elseif ($filter === 'reviewed') {
						$filteredImages = array_filter($allImages, function($imageData) {
							return $imageData['alt_reviewed'] === true;
						});
					} elseif ($filter === 'unreviewed') {
						$filteredImages = array_filter($allImages, function($imageData) {
							return $imageData['alt_reviewed'] === false;
						});
					}
					
					// Re-index filtered array
					$filteredImages = array_values($filteredImages);
					$filteredTotalImages = count($filteredImages);

					// Calculate totals for header badges (always based on all images)
					$totalWithAltText = 0;
					$totalReviewed = 0;
					foreach ($allImages as $imageData) {
						if (!empty($imageData['alt']) && trim($imageData['alt']) !== '') {
							$totalWithAltText++;
						}
						if ($imageData['alt_reviewed']) {
							$totalReviewed++;
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
							'withAltText' => $totalWithAltText,
							'reviewed' => $totalReviewed,
							'total' => $originalTotalImages
						]
					];
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

					if (!in_array($field, ['alt', 'alt_reviewed'])) {
						return ['error' => t('medienbaecker.alter.invalidField')];
					}

					try {
						$image = kirby()->file($imageId);
						if (!$image) {
							return ['error' => t('medienbaecker.alter.imageNotFound')];
						}

						// Get current language for saving
						$currentLanguage = kirby()->language();
						$languageCode = $currentLanguage ? $currentLanguage->code() : null;

						// Save to specific language if multilingual site
						if ($languageCode && $field === 'alt') {
							$image->update([$field => $value], $languageCode);
						} else {
							$image->update([$field => $value]);
						}

						return ['success' => true, 'message' => t('medienbaecker.alter.success')];
					} catch (Exception $e) {
						return ['error' => $e->getMessage()];
					}
				}
			]
		]
	],
	'commands' => [
		'alter:generate' => require_once __DIR__ . '/commands/generate.php',
	]
]);
