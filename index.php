<?php

use Kirby\Cms\App as Kirby;

Kirby::plugin('medienbaecker/alt-text-review', [
	'assets' => [
		'index.js' => __DIR__ . '/index.js',
		'index.css' => __DIR__ . '/index.css'
	],
	'areas' => [
		'alt-text-review' => function ($kirby) {
			return [
				'label' => 'Alt Text Review',
				'icon' => 'image',
				'menu' => true,
				'link' => 'alt-text-review',
				'views' => [
					[
						'pattern' => 'alt-text-review',
						'action' => function () {
							// Collect images organized by page
							$pageImages = [];
							$pages = site()->index();

							foreach ($pages as $page) {
								if ($page->hasImages()) {
									$images = [];
									foreach ($page->images() as $image) {
										$images[] = [
											'id' => $image->id(),
											'url' => $image->url(),
											'filename' => $image->filename(),
											'alt' => $image->alt()->value() ?? '',
											'alt_reviewed' => $image->alt_reviewed()->toBool(),
											'panel' => [
												'url' => $image->panel()->url()
											],
											'pageUrl' => $image->page()->url()
										];
									}
									$pageImages[] = [
										'pageTitle' => $page->title()->value(),
										'pageId' => $page->id(),
										'pagePanelUrl' => $page->panel()->url(),
										'images' => $images
									];
								}
							}

							return [
								'component' => 'k-alt-text-review-view',
								'props' => [
									'pageImages' => $pageImages
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
				'pattern' => 'alt-text-review/update',
				'method' => 'POST',
				'action' => function () {
					$user = kirby()->user();
					if (!$user) {
						return ['error' => 'Not authenticated'];
					}

					$imageId = get('imageId');
					$field = get('field');
					$value = get('value');

					if (!in_array($field, ['alt', 'alt_reviewed'])) {
						return ['error' => 'Invalid field'];
					}

					try {
						$image = kirby()->file($imageId);
						if (!$image) {
							return ['error' => 'Image not found'];
						}

						$image->update([$field => $value]);
						return ['success' => true, 'message' => 'Updated successfully'];
					} catch (Exception $e) {
						return ['error' => $e->getMessage()];
					}
				}
			]
		]
	]
]);
