<?php

/**
 * Plugin translations for the Kirby Panel.
 *
 * Kirby will automatically fall back to other locales (e.g. `en`) if a key
 * isn't defined for the current translation.
 */
$translations = [];

foreach (glob(__DIR__ . '/translations/*.php') ?: [] as $file) {
	$locale = pathinfo($file, PATHINFO_FILENAME);
	$data = require $file;

	if (is_array($data)) {
		$translations[$locale] = $data;
	}
}

return $translations;

