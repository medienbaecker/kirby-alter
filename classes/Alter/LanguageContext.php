<?php

namespace Medienbaecker\Alter;

use Kirby\Cms\App;

/**
 * Snapshot of the request's language state, so callers don't redo
 * the multilang/monolang derivation. `current` and `default` are
 * null in monolang; `multilangCodes` is empty in monolang.
 */
final class LanguageContext
{
	public function __construct(
		public readonly ?string $current,
		public readonly ?string $default,
		public readonly array $multilangCodes,
	) {}

	public static function fromKirby(App $kirby): self
	{
		if ($kirby->multilang() === false) {
			return new self(null, null, []);
		}

		$codes = array_map(
			fn($language) => $language->code(),
			$kirby->languages()->values()
		);

		return new self(
			current: $kirby->language()?->code(),
			default: $kirby->defaultLanguage()->code(),
			multilangCodes: $codes,
		);
	}

	public function isMonolang(): bool
	{
		return empty($this->multilangCodes);
	}

	/**
	 * Stable string key for indexing into per-language maps.
	 * In monolang this is '', which lines up with how PHP coerces
	 * `null` array keys during storage.
	 */
	public function key(): string
	{
		return (string)$this->current;
	}

	/**
	 * The set of codes to iterate when reading content. Monolang
	 * collapses to `[null]` so the same loop reads the unsuffixed
	 * content file via `Version::read()` (no language argument).
	 */
	public function readableCodes(): array
	{
		return $this->isMonolang() ? [null] : $this->multilangCodes;
	}
}
