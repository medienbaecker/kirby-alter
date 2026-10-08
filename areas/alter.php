<?php

use Kirby\Toolkit\I18n;
use Medienbaecker\Alter\ImageIndex;

return [
	'alter' => function ($kirby) {
		$panelGeneration = option('medienbaecker.alter.panel.generation', false) === true;
		$allowDecorative = option('medienbaecker.alter.panel.decorative', false) === true;
		$filters = ImageIndex::filters(option('medienbaecker.alter.filters'));

		return [
			'label' => t('medienbaecker.alter.title'),
			'icon' => $panelGeneration ? 'imageAi' : 'image',
			'menu' => true,
			'link' => 'alter',
			'views' => [
				[
					'pattern' => 'alter/(:num?)',
					'action' => function ($page = 1) use ($panelGeneration, $allowDecorative, $filters) {
						return [
							'component' => 'k-alter-view',
							'props' => [
								'page' => (int)$page,
								'maxLength' => option('medienbaecker.alter.maxLength', false),
								'generation' => [
									'enabled' => $panelGeneration,
								],
								'allowDecorative' => $allowDecorative,
								'filters' => array_map(
									fn($key, $filter) => [
										'value' => $key,
										'text' => I18n::translate($filter['label'] ?? $key, $filter['label'] ?? $key) ?? $key,
									],
									array_keys($filters),
									$filters
								),
							],
						];
					},
				],
			],
		];
	},
];
