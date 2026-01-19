<?php

use Kirby\Cms\App as Kirby;

require_once __DIR__ . '/lib/AlterPanelGenerator.php';

Kirby::plugin('medienbaecker/alter', [
	'options' => [
		'api.key' => null,
		'api.model' => 'claude-haiku-4-5',
		'templates' => null,
		'maxLength' => false,
		'panel.generation' => false,
		'prompt' => function ($file) {
			$prompt = 'You are an accessibility expert writing alt text. Write a concise, short description in one to three sentences. Start directly with the subject - NO introductory phrases like "image of", "shows", "displays", "depicts", "contains", "features" etc.';
			$prompt .= ' The image is on a page called “' . $file->page()->title() . '”.';
			$prompt .= ' The site is called “' . $file->site()->title() . '”.';
			$prompt .= ' Return the alt text only, without any additional text or formatting.';

			return $prompt;
		},
	],
	'translations' => require __DIR__ . '/translations.php',
	'areas' => require __DIR__ . '/areas/alter.php',
	'api' => [
		'routes' => require __DIR__ . '/routes/api.php',
	],
	'commands' => [
		'alter:generate' => require_once __DIR__ . '/commands/generate.php',
	],
]);

