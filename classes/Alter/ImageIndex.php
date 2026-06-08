<?php

namespace Medienbaecker\Alter;

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Content\Version;

/**
 * Lightweight per-image index used for aggregates, filtering, sorting,
 * and pagination. Heavy fields (URLs, breadcrumbs, permissions) are
 * built on demand for the paginated slice only.
 */
final class ImageIndex
{
	private function __construct(
		private readonly array $entries,
		private readonly array $parents,
		private readonly LanguageContext $language,
		private readonly bool $allowDecorative = false,
	) {}

	public static function build(LanguageContext $language, ?array $allowedTemplates, ?callable $ignore = null, bool $allowDecorative = false): self
	{
		$entries = [];
		$parents = ['site' => self::siteParent()];
		$site = App::instance()->site();
		$codes = $language->readableCodes();

		if ($site->hasImages() === true) {
			foreach ($site->images() as $image) {
				if (self::isAllowed($image, $allowedTemplates, $ignore) === false) {
					continue;
				}
				$entries[$image->id()] = self::lightEntry($image, 'site', $codes);
			}
		}

		foreach ($site->index(true) as $page) {
			if ($page->hasImages() === false) {
				continue;
			}

			$parentId = $page->id();
			if (isset($parents[$parentId]) === false) {
				$parents[$parentId] = self::pageParent($page);
			}

			foreach ($page->images() as $image) {
				if (self::isAllowed($image, $allowedTemplates, $ignore) === false) {
					continue;
				}
				$entries[$image->id()] = self::lightEntry($image, $parentId, $codes);
			}
		}

		return new self($entries, $parents, $language, $allowDecorative);
	}

	public function entries(): array
	{
		return $this->entries;
	}

	public function aggregate(): array
	{
		$unsavedByLanguage = array_fill_keys($this->language->multilangCodes, 0);
		$totalUnsaved = 0;
		$totalSaved = 0;
		$missingCurrent = 0;
		$missingAny = 0;
		$key = $this->language->key();

		foreach ($this->entries as $entry) {
			$isMissing = $this->isMissing($entry, $key);

			if ($entry['hasChangesByLang'][$key] ?? false) {
				$totalUnsaved++;
			}
			if (
				trim((string)($entry['latestAltByLang'][$key] ?? '')) !== ''
				|| ($this->allowDecorative && ($entry['latestDecorativeByLang'][$key] ?? false))
			) {
				$totalSaved++;
			}
			if ($isMissing) {
				$missingCurrent++;
			}

			if ($this->language->isMonolang() === true) {
				if ($isMissing) {
					$missingAny++;
				}
				continue;
			}

			$anyMissing = false;
			foreach ($this->language->multilangCodes as $code) {
				if ($this->isMissing($entry, $code)) {
					$anyMissing = true;
				}
				if ($entry['hasChangesByLang'][$code] ?? false) {
					$unsavedByLanguage[$code]++;
				}
			}
			if ($anyMissing) {
				$missingAny++;
			}
		}

		return compact(
			'totalUnsaved',
			'totalSaved',
			'missingCurrent',
			'missingAny',
			'unsavedByLanguage'
		);
	}

	/**
	 * An (entry, code) is "missing" when its effective alt is empty and
	 * it is not an enabled decorative image. Decorative counts as done.
	 */
	private function isMissing(array $entry, string $code): bool
	{
		$empty = $entry['effectiveEmptyByLang'][$code] ?? true;
		$decorative = $this->allowDecorative && ($entry['effectiveDecorativeByLang'][$code] ?? false);
		return $empty && !$decorative;
	}

	public function filter(string $name): array
	{
		$key = $this->language->key();

		return match ($name) {
			'saved'   => array_filter($this->entries, fn($entry) => !$this->isMissing($entry, $key)),
			'missing' => array_filter($this->entries, fn($entry) => $this->isMissing($entry, $key)),
			'unsaved' => array_filter($this->entries, fn($entry) => $entry['hasChangesByLang'][$key] ?? false),
			default   => $this->entries,
		};
	}

	/**
	 * Returns null if the file or parent disappeared between build and
	 * hydrate (concurrent rename/delete).
	 */
	public function hydrate(array $light): ?array
	{
		$image = App::instance()->file($light['fileId']);
		$parent = $this->parents[$light['parentId']] ?? null;
		if ($image === null || $parent === null) {
			return null;
		}

		$key = $this->language->key();
		$latestAlt = $light['latestAltByLang'][$key] ?? '';
		$changesAlt = $light['changesAltByLang'][$key] ?? null;
		$currentAlt = $changesAlt ?? $latestAlt;

		return [
			'id' => $image->id(),
			'url' => $image->url(),
			'thumbUrl' => $image->resize(500, 500)->url(),
			'filename' => $image->filename(),
			'alt' => $currentAlt,
			'altOriginal' => $latestAlt,
			'decorative' => $light['effectiveDecorativeByLang'][$key] ?? false,
			'decorativeOriginal' => $light['latestDecorativeByLang'][$key] ?? false,
			'hasChanges' => $changesAlt !== null && $changesAlt !== $latestAlt,
			'altByLanguage' => $this->altByLanguageMap($light),
			'changesPath' => $parent['panelPath'] . '/files/' . $image->filename(),
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
			'language' => $this->language->current,
			'altDefault' => $this->defaultAlt($light, $currentAlt),
			'editable' => $image->permissions()->update() === true,
		];
	}

	private function altByLanguageMap(array $light): array
	{
		$map = [];
		foreach ($this->language->multilangCodes as $code) {
			$effective = ($light['changesAltByLang'][$code] ?? null) ?? ($light['latestAltByLang'][$code] ?? '');
			$map[$code] = trim((string)$effective) !== '';
		}
		return $map;
	}

	private function defaultAlt(array $light, string $currentAlt): string
	{
		$default = $this->language->default;
		if ($default === null || $default === $this->language->current) {
			return $currentAlt;
		}
		$changes = $light['changesAltByLang'][$default] ?? null;
		$latest = $light['latestAltByLang'][$default] ?? '';
		return $changes ?? $latest;
	}

	private static function isAllowed(File $image, ?array $allowedTemplates, ?callable $ignore): bool
	{
		if ($allowedTemplates !== null && in_array($image->template(), $allowedTemplates, true) === false) {
			return false;
		}
		if ($ignore !== null && $ignore($image) === false) {
			return false;
		}
		return true;
	}

	private static function siteParent(): array
	{
		$label = t('view.site');
		return [
			'id' => 'site',
			'title' => $label,
			'panelUrl' => '/site',
			'panelPath' => 'site',
			'sort' => null,
			'status' => null,
			'hasParentDrafts' => false,
			'breadcrumbs' => [self::breadcrumbCrumb($label, '/site')],
			'sortKey' => '000000',
		];
	}

	private static function pageParent(Page $page): array
	{
		$ancestors = $page->parents()->flip();
		$hasParentDrafts = $page->parents()->filter(fn(Page $parent) => $parent->isDraft())->isNotEmpty();

		$breadcrumbs = [];
		foreach ($ancestors as $ancestor) {
			$breadcrumbs[] = self::breadcrumbCrumb($ancestor->title()->value(), $ancestor->panel()->url());
		}
		$breadcrumbs[] = self::breadcrumbCrumb($page->title()->value(), $page->panel()->url());

		return [
			'id' => $page->id(),
			'title' => $page->title()->value(),
			'panelUrl' => $page->panel()->url(),
			'panelPath' => $page->panel()->path(),
			'sort' => $page->num(),
			'status' => $page->status(),
			'hasParentDrafts' => $hasParentDrafts,
			'breadcrumbs' => $breadcrumbs,
			'sortKey' => self::sortKeyFor($page, $ancestors),
		];
	}

	private static function sortKeyFor(Page $page, Pages $ancestors): string
	{
		$key = '';
		foreach ($ancestors as $ancestor) {
			$key .= sprintf('%06d-', $ancestor->num() ?? 999999);
		}
		return $key . sprintf('%06d', $page->num() ?? 999999);
	}

	private static function breadcrumbCrumb(string $title, string $url): array
	{
		return [
			'title' => $title,
			'label' => $title,
			'panelUrl' => $url,
			'link' => $url,
		];
	}

	private static function lightEntry(File $image, string $parentId, array $codes): array
	{
		$latest = $image->version('latest');
		$changes = $image->version('changes');

		$latestAltByLang = [];
		$changesAltByLang = [];
		$hasChangesByLang = [];
		$effectiveEmptyByLang = [];
		$latestDecorativeByLang = [];
		$effectiveDecorativeByLang = [];

		foreach ($codes as $code) {
			$key = (string)$code;
			$latestAlt = self::readAlt($latest, $code);
			$changesAlt = self::readChangesAlt($changes, $code);
			$latestDecorative = self::readDecorative($latest, $code);
			$changesDecorative = self::readChangesDecorative($changes, $code);

			$altChanged = $changesAlt !== null && $changesAlt !== $latestAlt;
			$decorativeChanged = $changesDecorative !== null && $changesDecorative !== $latestDecorative;

			$latestAltByLang[$key] = $latestAlt;
			$changesAltByLang[$key] = $changesAlt;
			$hasChangesByLang[$key] = $altChanged || $decorativeChanged;
			$effectiveEmptyByLang[$key] = trim($changesAlt ?? $latestAlt) === '';
			$latestDecorativeByLang[$key] = $latestDecorative;
			$effectiveDecorativeByLang[$key] = $changesDecorative ?? $latestDecorative;
		}

		return [
			'fileId' => $image->id(),
			'parentId' => $parentId,
			'filename' => $image->filename(),
			'latestAltByLang' => $latestAltByLang,
			'changesAltByLang' => $changesAltByLang,
			'hasChangesByLang' => $hasChangesByLang,
			'effectiveEmptyByLang' => $effectiveEmptyByLang,
			'latestDecorativeByLang' => $latestDecorativeByLang,
			'effectiveDecorativeByLang' => $effectiveDecorativeByLang,
		];
	}

	private static function readAlt(Version $version, ?string $code): string
	{
		try {
			$content = $code === null ? $version->read() : $version->read($code);
			return (string)($content['alt'] ?? '');
		} catch (\Throwable) {
			return '';
		}
	}

	/**
	 * Null vs empty string is load-bearing: only an explicit `alt`
	 * key in the changes version counts as an unsaved edit.
	 */
	private static function readChangesAlt(Version $version, ?string $code): ?string
	{
		$exists = $code === null ? $version->exists() : $version->exists($code);
		if ($exists === false) {
			return null;
		}

		try {
			$content = $code === null ? $version->read() : $version->read($code);
		} catch (\Throwable) {
			return null;
		}

		if (is_array($content) === false || array_key_exists('alt', $content) === false) {
			return null;
		}
		return (string)($content['alt'] ?? '');
	}

	private static function readDecorative(Version $version, ?string $code): bool
	{
		try {
			$content = $code === null ? $version->read() : $version->read($code);
			return (string)($content['alt_decorative'] ?? '') === 'true';
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Null vs explicit key is load-bearing, same as readChangesAlt: only an
	 * explicit `alt_decorative` key in the changes version counts as an edit.
	 */
	private static function readChangesDecorative(Version $version, ?string $code): ?bool
	{
		$exists = $code === null ? $version->exists() : $version->exists($code);
		if ($exists === false) {
			return null;
		}

		try {
			$content = $code === null ? $version->read() : $version->read($code);
		} catch (\Throwable) {
			return null;
		}

		if (is_array($content) === false || array_key_exists('alt_decorative', $content) === false) {
			return null;
		}
		return (string)($content['alt_decorative'] ?? '') === 'true';
	}
}
