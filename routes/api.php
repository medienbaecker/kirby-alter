<?php

use Kirby\Exception\NotFoundException;
use Kirby\Exception\PermissionException;
use Medienbaecker\Alter\Generator;
use Medienbaecker\Alter\ImageIndex;
use Medienbaecker\Alter\LanguageContext;
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

$toBool = static function ($value): bool {
	return in_array($value, [true, 1, '1', 'true'], true);
};

return [
	[
		'pattern' => 'alter/images',
		'method' => 'GET',
		'action' => function () {
			$kirby = kirby();
			$request = $kirby->request();
			$page = max(1, (int)$request->get('page', 1));
			$filter = $request->get('filter', 'all');
			$limit = 100;

			$allowedTemplates = $kirby->option('medienbaecker.alter.templates');
			if (is_string($allowedTemplates) === true) {
				$allowedTemplates = [$allowedTemplates];
			}

			$ignore = $kirby->option('medienbaecker.alter.ignore');
			if (is_callable($ignore) === false) {
				$ignore = null;
			}

			$language = LanguageContext::fromKirby($kirby);
			$index = ImageIndex::build($language, $allowedTemplates, $ignore, option('medienbaecker.alter.panel.decorative', false) === true);
			$aggregates = $index->aggregate();

			$filtered = array_values($index->filter($filter));
			$filteredTotal = count($filtered);

			$offset = ($page - 1) * $limit;
			$slice = array_slice($filtered, $offset, $limit);

			$images = array_values(array_filter(
				array_map(fn($light) => $index->hydrate($light), $slice)
			));

			return [
				'images' => $images,
				'defaultLanguage' => $language->default,
				'unsavedByLanguage' => $aggregates['unsavedByLanguage'],
				'pagination' => [
					'page' => $page,
					'pages' => (int)ceil($filteredTotal / $limit),
					'total' => $filteredTotal,
					'limit' => $limit,
					'start' => $offset + 1,
					'end' => min($offset + $limit, $filteredTotal),
				],
				'totals' => [
					'unsaved' => $aggregates['totalUnsaved'],
					'saved' => $aggregates['totalSaved'],
					'total' => count($index->entries()),
				],
				'generationStats' => [
					'missingCurrent' => $aggregates['missingCurrent'],
					'missingAny' => $aggregates['missingAny'],
				],
			];
		},
	],
	[
		'pattern' => 'alter/generate',
		'method' => 'POST',
		'action' => function () use ($versionExists, $versionContentArray, $toBool) {
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
			$autoSelect = $toBool($autoSelect);
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
		'action'  => function () use ($toBool) {
			$user = kirby()->user();
			if (!$user) {
				throw new PermissionException(t('medienbaecker.alter.notAuthenticated'));
			}

			$request = kirby()->request();
			$imageId = $request->body()->get('imageId');
			$alt     = (string)($request->body()->get('alt') ?? '');
			$decorative = $toBool($request->body()->get('decorative'));

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
			$image = $image->update([
				'alt' => $alt,
				'alt_decorative' => $decorative ? 'true' : '',
			], $languageCode);

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
			$changesContent['alt_decorative'] = $latestContent['alt_decorative'] ?? '';
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
		'action' => function () use ($toBool) {
			$user = kirby()->user();
			if (!$user) {
				return ['error' => t('medienbaecker.alter.notAuthenticated')];
			}

			$request = kirby()->request();
			$imageId = $request->body()->get('imageId');
			$field = $request->body()->get('field');
			$value = $request->body()->get('value');

			$contentKey = match ($field) {
				'alt' => 'alt',
				'decorative' => 'alt_decorative',
				default => null,
			};

			if ($contentKey === null) {
				return ['error' => t('medienbaecker.alter.invalidField')];
			}

			// Decorative is a boolean stored as 'true' / ''
			if ($field === 'decorative') {
				$value = $toBool($value) ? 'true' : '';
			}

			try {
				$image = kirby()->file($imageId);
				if (!$image) {
					return ['error' => t('medienbaecker.alter.imageNotFound')];
				}

				if ($image->permissions()->update() !== true) {
					throw new PermissionException(t('medienbaecker.alter.noPermission'));
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
					$changesContent[$contentKey] = $value;
					if ($languageCode === null) {
						$changes->create($changesContent);
					} else {
						$changes->create($changesContent, $languageCode);
					}
				} else {
					$changesContent = $languageCode === null
						? ($changes->read() ?? [])
						: ($changes->read($languageCode) ?? []);
					$changesContent[$contentKey] = $value;
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
