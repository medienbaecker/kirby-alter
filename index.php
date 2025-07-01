<?php

use Kirby\Cms\App as Kirby;

Kirby::plugin('medienbaecker/alt-text-review', [
	'assets' => [
		'index.js' => __DIR__ . '/index.js',
		'index.css' => __DIR__ . '/index.css'
	],
	'translations' => [
		'de' => require_once __DIR__ . '/languages/de.php',
		'en' => require_once __DIR__ . '/languages/en.php',
	],
	'areas' => [
		'alt-text-review' => function ($kirby) {
			return [
				'label' => t('medienbaecker.alt-text-review.title'),
				'icon' => 'image',
				'menu' => true,
				'link' => 'alt-text-review',
				'views' => [
					[
						'pattern' => 'alt-text-review/(:num?)',
						'action' => function ($page = 1) {
							return [
								'component' => 'k-alt-text-review-view',
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
				'pattern' => 'alt-text-review/images',
				'method' => 'GET',
				'action' => function () {
					$request = kirby()->request();
					$page = (int)$request->get('page', 1);
					$limit = 100; // Fixed limit as requested
					
					// Get current language from panel
					$currentLanguage = kirby()->language();
					$languageCode = $currentLanguage ? $currentLanguage->code() : null;
					
					// Collect all images
					$allImages = [];
					$pages = site()->index();

					foreach ($pages as $sitePage) {
						if ($sitePage->hasImages()) {
							foreach ($sitePage->images() as $image) {
								// Get alt text for current language
								$altText = '';
								if ($languageCode) {
									$altText = $image->alt()->translation($languageCode)->value() ?? '';
								} else {
									$altText = $image->alt()->value() ?? '';
								}
								
								$allImages[] = [
									'id' => $image->id(),
									'url' => $image->url(),
									'filename' => $image->filename(),
									'alt' => $altText,
									'alt_reviewed' => $image->alt_reviewed()->toBool(),
									'panelUrl' => $image->panel()->url(),
									'pageUrl' => $image->page()->url(),
									'pageTitle' => $sitePage->title()->value(),
									'pageId' => $sitePage->id(),
									'pagePanelUrl' => $sitePage->panel()->url(),
									'language' => $languageCode,
								];
							}
						}
					}

					$totalImages = count($allImages);
					$totalPages = ceil($totalImages / $limit);
					$offset = ($page - 1) * $limit;
					$paginatedImages = array_slice($allImages, $offset, $limit);

					return [
						'images' => $paginatedImages,
						'pagination' => [
							'page' => (int)$page,
							'pages' => $totalPages,
							'total' => $totalImages,
							'limit' => $limit,
							'start' => $offset + 1,
							'end' => min($offset + $limit, $totalImages)
						]
					];
				}
			],
			[
				'pattern' => 'alt-text-review/update',
				'method' => 'POST',
				'action' => function () {
					$user = kirby()->user();
					if (!$user) {
						return ['error' => t('medienbaecker.alt-text-review.notAuthenticated')];
					}

					$request = kirby()->request();
					$imageId = $request->body()->get('imageId');
					$field = $request->body()->get('field');
					$value = $request->body()->get('value');

					if (!in_array($field, ['alt', 'alt_reviewed'])) {
						return ['error' => t('medienbaecker.alt-text-review.invalidField')];
					}

					try {
						$image = kirby()->file($imageId);
						if (!$image) {
							return ['error' => t('medienbaecker.alt-text-review.imageNotFound')];
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
						
						return ['success' => true, 'message' => t('medienbaecker.alt-text-review.success')];
					} catch (Exception $e) {
						return ['error' => $e->getMessage()];
					}
				}
			]
		]
	]
]);
