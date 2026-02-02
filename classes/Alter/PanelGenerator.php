<?php

namespace Medienbaecker\Alter;

use Kirby\Toolkit\Str;

/**
 * Panel-triggered alt-text generation.
 * Orchestrates per-image / per-language generation and
 * stores results as draft changes.
 */
class PanelGenerator extends Generator
{
	public function generateForImages(array $images, array $languages, $defaultLanguage): array
	{
		$results = [];
		$totalGenerated = 0;

		foreach ($images as $image) {
			$result = $this->generateForImage($image, $languages, $defaultLanguage);
			$totalGenerated += $result['generated'];
			$results[] = $result;
		}

		return [
			'generated' => $totalGenerated,
			'images' => $results,
		];
	}

	public function generateForImage($image, array $languages, $defaultLanguage): array
	{
		if (!static::isSupported($image)) {
			return [
				'imageId' => $image->id(),
				'generated' => 0,
				'languages' => [],
				'skipped' => 'unsupported format',
			];
		}

		$defaultCode = $defaultLanguage?->code();
		$imagePayload = null;

		// Ensure default language goes first to provide translation source
		usort($languages, static function ($a, $b) use ($defaultCode) {
			if ($a?->code() === $defaultCode) return -1;
			if ($b?->code() === $defaultCode) return 1;
			return 0;
		});

		[$baseAlt, $baseLanguageCode] = $this->findTranslationSource($image, $languages, $defaultLanguage);

		$perLanguage = [];
		$generatedCount = 0;

		foreach ($languages as $language) {
			$languageCode = $language?->code();

			$current = $this->currentAlt($image, $languageCode);
			if (Str::length(trim($current)) > 0) {
				$perLanguage[] = [
					'language' => $languageCode,
					'status' => 'skipped',
					'reason' => 'existing',
				];
				continue;
			}

			$baseHasAlt = Str::length(trim((string)$baseAlt)) > 0;
			$shouldTranslate = $baseHasAlt && $baseLanguageCode !== $languageCode;

			$languageName = $language?->name() ?? option('medienbaecker.alter.language');

			if ($shouldTranslate) {
				$altText = $this->translateAltText($baseAlt, $languageName);
				$action = 'translated';
			} else {
				$imagePayload ??= $this->encodeImage($image);
				$altText = $this->generateAltText($imagePayload, $image, $languageName);
				$action = 'generated';
			}

			$this->storeDraftAlt($image, $altText, $languageCode);
			$generatedCount++;

			// Once the default language is generated/translated, use it as the source for further translations.
			if ($defaultCode !== null && $languageCode === $defaultCode) {
				$baseAlt = $altText;
				$baseLanguageCode = $defaultCode;
			} elseif ($baseHasAlt !== true) {
				$baseAlt = $altText;
				$baseLanguageCode = $languageCode;
			}

			$perLanguage[] = [
				'language' => $languageCode,
				'status' => $action,
				'text' => $altText,
			];
		}

		return [
			'imageId' => $image->id(),
			'generated' => $generatedCount,
			'languages' => $perLanguage,
		];
	}
}
