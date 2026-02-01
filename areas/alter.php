<?php

return [
	'alter' => function ($kirby) {
		$panelGeneration = option('medienbaecker.alter.panel.generation', option('medienbaecker.alter.panelGeneration', false)) === true;

		return [
			'label' => t('medienbaecker.alter.title'),
			'icon' => $panelGeneration ? 'imageAi' : 'image',
			'menu' => true,
			'link' => 'alter',
			'views' => [
				[
					'pattern' => 'alter/(:num?)',
					'action' => function ($page = 1) use ($panelGeneration) {
						return [
							'component' => 'k-alter-view',
							'props' => [
								'page' => (int)$page,
								'maxLength' => option('medienbaecker.alter.maxLength', false),
								'generation' => [
									'enabled' => $panelGeneration,
								],
							],
						];
					},
				],
			],
		];
	},
];
