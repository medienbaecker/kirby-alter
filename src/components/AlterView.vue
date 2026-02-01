<template>
	<k-panel-inside>
		<k-header>
			{{ $t('medienbaecker.alter.title') }}
			<template #buttons>
				<k-button-group class="k-view-buttons">
					<div class="k-view-button">
						<k-button :dropdown="true" icon="filter" variant="filled" size="sm" :responsive="'text'"
							:text="currentFilterLabel" @click="toggleDropdown('filterDropdown')" />
						<k-dropdown-content ref="filterDropdown" align-x="end">
							<k-dropdown-item icon="filter" :current="filterMode === null" @click="onFilterChange(null)">
								{{ $t('medienbaecker.alter.filter.all') }}
							</k-dropdown-item>
							<k-dropdown-item v-for="option in filterOptions" :key="option.value" icon="filter"
								:current="filterMode === option.value" @click="onFilterChange(option.value)">
								{{ option.text }}
							</k-dropdown-item>
						</k-dropdown-content>
					</div>

					<div v-if="totalImagesCount > 0" class="k-view-button">
						<k-button variant="filled" size="sm" icon="check" element="span"
							:theme="isComplete ? 'positive' : null" :badge="unsavedImagesCount > 0
								? { theme: 'orange', text: unsavedImagesCount }
								: null
								">
							{{ `${savedImagesCount}/${totalImagesCount}` }}
						</k-button>
					</div>

					<div v-if="panelGenerationEnabled" class="k-view-button">
						<k-button :dropdown="languages.length > 1" :icon="generatingAll ? 'loader' : 'ai'"
							variant="filled" theme="orange-icon" size="sm" :responsive="'text'"
							:text="generationDropdownLabel" :title="generationDropdownLabel"
							:disabled="generationButtonDisabled"
							@click="languages.length > 1 ? toggleDropdown('scopeDropdown') : onGenerateAll('current')" />
						<k-dropdown-content ref="scopeDropdown" align-x="end">
							<k-dropdown-item v-for="scope in generationScopes" icon="aiGenerateText" :key="scope.value"
								:disabled="scope.disabled" @click="onGenerateAll(scope.value)">
								{{ scope.text }}
							</k-dropdown-item>
						</k-dropdown-content>
					</div>

					<div v-if="languages.length > 1" class="k-view-button k-languages-dropdown">
						<k-button :dropdown="true" icon="translate" variant="filled" size="sm" :responsive="'text'"
							:text="currentLanguage ? currentLanguage.toUpperCase() : ''"
							:aria-label="languageButtonLabel" :title="languageButtonLabel"
							:badge="hasAnyUnsavedLanguages ? { theme: 'orange' } : null"
							@click="toggleDropdown('languageDropdown')" />
						<k-dropdown-content ref="languageDropdown" align-x="end" align-y="bottom" theme="dark">
							<k-dropdown-item v-if="defaultLanguageItem" :key="defaultLanguageItem.code"
								class="k-languages-dropdown-item"
								:current="currentLanguage === defaultLanguageItem.code"
								:aria-label="defaultLanguageItem.name" :title="defaultLanguageItem.name"
								@click="onLanguageChange(defaultLanguageItem.code)">
								{{ defaultLanguageItem.name }}
								<span class="k-languages-dropdown-item-info">
									<k-icon v-if="languageHasUnsavedChanges(defaultLanguageItem.code)" type="edit-line"
										class="k-languages-dropdown-item-icon" role="img"
										:aria-label="$t('medienbaecker.alter.filter.unsaved')" />
									<span class="k-languages-dropdown-item-code">
										{{ defaultLanguageItem.code.toUpperCase() }}
									</span>
								</span>
							</k-dropdown-item>

							<hr v-if="defaultLanguageItem && secondaryLanguages.length > 0" />

							<k-dropdown-item v-for="lang in secondaryLanguages" :key="lang.code"
								class="k-languages-dropdown-item" :current="currentLanguage === lang.code"
								:aria-label="lang.name" :title="lang.name" @click="onLanguageChange(lang.code)">
								{{ lang.name }}
								<span class="k-languages-dropdown-item-info">
									<k-icon v-if="languageHasUnsavedChanges(lang.code)" type="edit-line"
										class="k-languages-dropdown-item-icon" role="img"
										:aria-label="$t('medienbaecker.alter.filter.unsaved')" />
									<span class="k-languages-dropdown-item-code">
										{{ lang.code.toUpperCase() }}
									</span>
								</span>
							</k-dropdown-item>
						</k-dropdown-content>
					</div>
				</k-button-group>

				<k-form-controls :has-diff="unsavedImagesCount > 0" :is-processing="isSaving" @discard="discardChanges"
					@submit="saveAllChanges" />
			</template>
		</k-header>

		<div v-if="images.length === 0" class="k-empty">
			<k-loader v-if="loading && hasLoadedOnce !== true" />
			<template v-else>
				<k-text>{{ emptyStateMessage }}</k-text>
				<k-loader v-if="loading" />
			</template>
		</div>

		<template v-else>
			<k-section v-for="pageGroup in groupedImages" :key="pageGroup.pageId">
				<header class="k-section-header">
					<k-breadcrumb :crumbs="formatBreadcrumbs(pageGroup.breadcrumbs)" class="alter-breadcrumb" />

					<k-button-group class="k-section-buttons">
						<k-button variant="filled" size="sm" element="span" :badge="badgeForPage(pageGroup.pageId)">
							{{ pageGroup.images.length }}
							{{
								pageGroup.images.length === 1
									? $t('medienbaecker.alter.image')
									: $t('medienbaecker.alter.images')
							}}
						</k-button>

						<k-button v-if="pageGroup.hasParentDrafts || pageGroup.pageStatus" element="span"
							variant="filled" size="sm" :icon="pageGroup.hasParentDrafts
								? 'status-draft'
								: pageStatusUi(pageGroup).icon
								" :theme="pageGroup.hasParentDrafts
					? 'negative-icon'
					: pageStatusUi(pageGroup).theme
					" :title="pageGroup.hasParentDrafts
					? $t('medienbaecker.alter.parentDraft')
					: pageStatusUi(pageGroup).title
					" :responsive="true">
							{{
								pageGroup.hasParentDrafts
									? $t('page.status.draft')
									: pageStatusUi(pageGroup).text
							}}
						</k-button>
					</k-button-group>
				</header>

				<k-items :items="formatItems(pageGroup.images)" layout="cards" size="huge" :link="false">
					<template #default="{ item }">
						<div class="k-item k-cards-item alter-card" :data-image-id="item.id">
							<k-link :to="item.panelUrl" class="alter-image-link">
								<k-image-frame :src="item.thumbUrl" :alt="getImageData(item.id).alt"
									class="alter-image-frame" back="pattern" ratio="3/2" />
							</k-link>

							<div class="k-item-content">
								<k-textarea-field :label="item.filename" name="alt" type="textarea" :value="currentImages[item.id] ? currentImages[item.id].alt : ''
									" @input="onAltTextInput(item.id, $event)" @focusin.native="setActiveImage(item.id)"
									@mousedown.native="setActiveImage(item.id)"
									@keydown.native="onAltTextKeydown(item.id, $event)"
									:placeholder="placeholderFor(item)" :buttons="false" :counter="true"
									:maxlength="maxLength || null" size="small" />
								<div v-if="
									shouldShowGenerateButton(item.id) || hasChanges(item.id)
								" class="k-form-controls">
									<k-button v-if="shouldShowGenerateButton(item.id)" :icon="generating[item.id]
										? 'loader'
										: item.hasAnyAlt && languages.length > 1
											? 'translate'
											: 'ai'
										" :text="hasChanges(item.id)
						? null
						: item.hasAnyAlt && languages.length > 1
							? $t('medienbaecker.alter.generate.translate')
							: $t('medienbaecker.alter.generate.label')
						" variant="filled" theme="orange-icon" size="sm" :disabled="generating[item.id]"
										@click="onGenerateImage(item.id)" />

									<k-button-group v-if="hasChanges(item.id)" layout="collapsed">
										<k-button icon="undo" variant="filled" theme="notice" size="sm"
											@click="discardImage(item.id)">
											{{ $t('discard') }}
										</k-button>
										<k-button icon="check" variant="filled" theme="notice" size="sm"
											@click="saveImage(item.id)">
											{{ $t('save') }}
										</k-button>
									</k-button-group>
								</div>
							</div>
						</div>
					</template>
				</k-items>
			</k-section>

			<k-pagination v-if="pagination.total > pagination.limit" :page="pagination.page" :total="pagination.total"
				:limit="pagination.limit" :details="true" @paginate="onPageChange" />
		</template>
	</k-panel-inside>
</template>

<script>
export default {
	props: {
		page: {
			type: Number,
			default: 1,
		},
		maxLength: {
			type: [Number, Boolean],
			default: false,
		},
		generation: {
			type: Object,
			default: () => ({
				enabled: false,
			}),
		},
	},

	data() {
		return {
			// API data + derived UI data
			images: [],
			defaultLanguage: null,
			pagination: { page: 1, pages: 1, total: 0, limit: 100 },
			totals: { unsaved: 0, saved: 0, total: 0 },
			generationStats: { missingCurrent: 0, missingAny: 0 },
			unsavedByLanguage: {},

			// Local edit state
			currentImages: {},
			originalImages: {},
			saving: {},
			generating: {},

			// Draft autosave (debounced) per image
			altSaveTimeouts: {},

			// UI state
			hasLoadedOnce: false,
			loading: false,
			filterMode: null,
			generationScope: 'current',
			generatingAll: false,
			// "active textarea" tracking for Cmd+S (save current item)
			activeImageId: null,
			lastCmdSHandledAt: 0,
		};
	},

	computed: {
		// Totals
		savedImagesCount() {
			return this.totals.saved;
		},
		unsavedImagesCount() {
			return this.totals.unsaved;
		},
		totalImagesCount() {
			return this.totals.total;
		},
		isComplete() {
			return (
				this.totals.total > 0 && this.savedImagesCount === this.totals.total
			);
		},

		// Changes
		hasAnyChanges() {
			return Object.keys(this.currentImages).some((imageId) =>
				this.hasChanges(imageId)
			);
		},

		// Languages
		currentLanguage() {
			return this.$panel.language ? this.$panel.language.code : null;
		},
		languages() {
			return this.$panel.languages || [];
		},
		defaultLanguageCode() {
			if (this.defaultLanguage) return this.defaultLanguage;

			const panelDefault = this.languages.find(
				(lang) => lang.default === true || lang.isDefault === true
			);
			if (panelDefault?.code) return panelDefault.code;

			return this.languages?.[0]?.code || null;
		},
		defaultLanguageItem() {
			const code = this.defaultLanguageCode;
			if (!code) return this.languages?.[0] || null;

			return (
				this.languages.find((lang) => lang.code === code) ||
				this.languages?.[0] ||
				null
			);
		},
		secondaryLanguages() {
			const defaultCode = this.defaultLanguageItem?.code;
			if (!defaultCode) return this.languages;

			return this.languages.filter((lang) => lang.code !== defaultCode);
		},
		languageButtonLabel() {
			const lang = this.languages.find((l) => l.code === this.currentLanguage);
			if (lang) {
				return `${lang.name} (${lang.code.toUpperCase()})`;
			}
			return this.$t('language');
		},
		hasAnyUnsavedLanguages() {
			const currentCode = this.currentLanguage;

			return Object.entries(this.unsavedByLanguage || {}).some(
				([code, count]) =>
					Number(count) > 0 && (!currentCode || code !== currentCode)
			);
		},
		isSaving() {
			return Object.values(this.saving || {}).some(Boolean);
		},
		panelGenerationEnabled() {
			return this.generation?.enabled === true;
		},
		generationScopes() {
			const scopes = [
				{
					value: 'current',
					text: this.$t('medienbaecker.alter.generate.scope.current'),
				},
			];

			if (this.languages.length > 1) {
				scopes.push({
					value: 'all',
					text: this.$t('medienbaecker.alter.generate.scope.all'),
				});
			}

			const resolved = scopes.map((scope) => ({
				...scope,
				disabled: this.canGenerateForScope(scope.value) !== true,
			}));

			if (resolved.length > 1 && resolved[0].disabled === true) {
				const firstEnabledIndex = resolved.findIndex(
					(scope) => scope.disabled !== true
				);
				if (firstEnabledIndex > 0) {
					const [firstEnabled] = resolved.splice(firstEnabledIndex, 1);
					resolved.unshift(firstEnabled);
				}
			}

			return resolved;
		},
		generationButtonDisabled() {
			if (this.generatingAll) return true;
			return this.generationScopes.every((scope) => scope.disabled === true);
		},
		generationDropdownLabel() {
			return this.$t('medienbaecker.alter.generate.label');
		},

		// Filter UI
		currentFilterLabel() {
			if (this.filterMode === null) {
				return this.$t('medienbaecker.alter.filter.all');
			}
			const option = this.filterOptions.find(
				(opt) => opt.value === this.filterMode
			);
			return option ? option.text : this.$t('medienbaecker.alter.filter.all');
		},
		filterOptions() {
			return [
				{
					text: this.$t('medienbaecker.alter.filter.saved'),
					value: 'saved',
				},
				{
					text: this.$t('medienbaecker.alter.filter.unsaved'),
					value: 'unsaved',
				},
				{
					text: this.$t('medienbaecker.alter.filter.missing'),
					value: 'missing',
				},
			];
		},

		emptyStateMessage() {
			return this.$t('medienbaecker.alter.noImages');
		},

		// Group by page (for section rendering)
		groupedImages() {
			const groups = {};

			this.images.forEach((image) => {
				const pageId = image.pageId;
				if (!groups[pageId]) {
					groups[pageId] = {
						pageTitle: image.pageTitle,
						pageId: image.pageId,
						pagePanelUrl: image.pagePanelUrl,
						pageSort: image.pageSort,
						pageStatus: image.pageStatus,
						hasParentDrafts: image.hasParentDrafts,
						breadcrumbs: image.breadcrumbs,
						sortKey: image.sortKey,
						images: [],
					};
				}
				groups[pageId].images.push(image);
			});

			return Object.values(groups).sort((a, b) =>
				a.sortKey.localeCompare(b.sortKey)
			);
		},

		// Badge count per page
		unsavedCountByPageId() {
			const map = Object.create(null);

			for (const img of this.images) {
				const pageId = img.pageId;
				if (map[pageId] == null) map[pageId] = 0;
				if (this.hasChanges(img.id)) map[pageId] += 1;
			}

			return map;
		},
	},

	watch: {
		page(newPage) {
			this.loading = true;
			// Flush pending draft saves before navigation/pagination changes
			this.flushAltDraftSaves().finally(() => this.loadImages(newPage));
		},

		currentLanguage(newLanguage, oldLanguage) {
			if (newLanguage !== oldLanguage && oldLanguage !== undefined) {
				this.loading = true;
				// No confirm dialog: drafts are persisted. Just flush and reload.
				this.flushAltDraftSaves().finally(() => this.loadImages(this.page));
			}
		},
	},

	created() {
		if (!this.ensurePanelStoresIntact()) {
			return;
		}

		const urlParams = new URLSearchParams(window.location.search);
		const filterParam = urlParams.get('filter');

		if (filterParam && ['saved', 'missing', 'unsaved'].includes(filterParam)) {
			this.filterMode = filterParam;
		}

		this.loadImages(this.page);
	},

	mounted() {
		// Global Cmd+S handler (Panel hotkey). We route to "active item" save if possible.
		window.panel.events.on('keydown.cmd.s', this.onCmdS);
	},

	beforeDestroy() {
		window.panel.events.off('keydown.cmd.s', this.onCmdS);
	},

	methods: {
		// ---------------------------------------------------------------------
		// Status UI helpers
		// ---------------------------------------------------------------------

		pageStatusUi(pageGroup) {
			const status = pageGroup.pageStatus;

			const map = {
				draft: {
					icon: 'status-draft',
					theme: 'negative-icon',
					text: this.$t('page.status.draft'),
				},
				unlisted: {
					icon: 'status-unlisted',
					theme: 'notice-icon', // fallback: "orange-icon"
					text: this.$t('page.status.unlisted'),
				},
				listed: {
					icon: 'status-listed',
					theme: 'positive-icon', // fallback: "green-icon"
					text: this.$t('page.status.listed'),
				},
			};

			const ui = map[status] ?? {
				icon: 'circle',
				theme: null,
				text: status,
			};

			ui.title = `Status: ${ui.text}`;
			return ui;
		},

		badgeForPage(pageId) {
			const count = this.unsavedCountByPageId[pageId] || 0;
			return count > 0 ? { theme: 'orange', text: count } : null;
		},

		// ---------------------------------------------------------------------
		// Active item tracking (for Cmd+S = save current textarea)
		// ---------------------------------------------------------------------

		setActiveImage(imageId) {
			this.activeImageId = imageId;
		},

		getActiveImageIdFromDom() {
			const el = document.activeElement;
			if (!el || !el.closest) return null;

			const card = el.closest('[data-image-id]');
			return card?.dataset?.imageId || null;
		},

		// ---------------------------------------------------------------------
		// Keyboard handlers
		// ---------------------------------------------------------------------

		async onCmdS(event) {
			// If textarea handler already intercepted Cmd+S, ignore the global one.
			if (Date.now() - this.lastCmdSHandledAt < 250) {
				event?.preventDefault?.();
				return;
			}

			event?.preventDefault?.();

			const activeId = this.activeImageId || this.getActiveImageIdFromDom();

			if (activeId && this.hasChanges(activeId)) {
				await this.saveImage(activeId);
				return;
			}

			await this.saveAllChanges(event);
		},

		onAltTextKeydown(imageId, event) {
			this.setActiveImage(imageId);

			const isSave =
				(event.metaKey || event.ctrlKey) &&
				String(event.key).toLowerCase() === 's';

			if (isSave) {
				event.preventDefault();
				event.stopPropagation();

				// Mark handled so the global Panel shortcut doesn't re-trigger.
				this.lastCmdSHandledAt = Date.now();

				this.saveImage(imageId);
				return;
			}

			if (event.key === 'Enter') {
				event.preventDefault();
			}
		},

		// ---------------------------------------------------------------------
		// Local change tracking
		// ---------------------------------------------------------------------

		hasChanges(imageId) {
			const current = this.currentImages[imageId];
			const original = this.originalImages[imageId];
			if (!current || !original) return false;

			return current.alt !== original.alt;
		},

		getImageData(imageId) {
			return this.currentImages[imageId] || { alt: '' };
		},

		shouldShowGenerateButton(imageId) {
			if (this.panelGenerationEnabled !== true) return false;
			const current = this.currentImages?.[imageId]?.alt ?? '';

			const currentEmpty = String(current).trim().length === 0;
			return currentEmpty;
		},

		canGenerateForScope(scope) {
			if (this.panelGenerationEnabled !== true) return false;

			if (!Array.isArray(this.images) || this.images.length === 0) {
				if (this.filterMode && this.filterMode !== 'missing') return false;

				const missingCurrent = Number(
					this.generationStats?.missingCurrent ?? 0
				);
				const missingAny = Number(this.generationStats?.missingAny ?? 0);

				if (scope === 'current') return missingCurrent > 0;
				if (scope === 'all') return missingAny > 0;
				return false;
			}

			if (scope === 'current') {
				return this.images.some((image) => {
					const localValue = this.currentImages?.[image.id]?.alt;
					const value = localValue !== undefined ? localValue : image.alt;
					return String(value ?? '').trim().length === 0;
				});
			}

			if (scope === 'all') {
				return this.images.some((image) => image.hasMissingAlt === true);
			}

			return false;
		},

		applyGeneratedAlt(imageId, value) {
			if (!this.currentImages?.[imageId]) return;

			const sanitizedValue = String(value ?? '')
				.replace(/[\r\n]+/g, ' ')
				.trim();

			const wasChanged = this.hasChanges(imageId);
			this.$set(this.currentImages[imageId], 'alt', sanitizedValue);
			const isChanged = this.hasChanges(imageId);
			this.updateUnsavedTotals(wasChanged, isChanged);
		},

		applyGenerationResponse(response) {
			const results = Array.isArray(response?.images) ? response.images : [];
			if (results.length === 0) return;

			const currentCode = this.currentLanguage ?? null;

			for (const result of results) {
				const imageId = result?.imageId;
				if (!imageId) continue;

				const languages = Array.isArray(result?.languages)
					? result.languages
					: [];

				const currentEntry =
					languages.find((entry) => entry?.language === currentCode) ||
					languages[0];

				if (!currentEntry) continue;

				if (!['generated', 'translated'].includes(currentEntry.status)) {
					continue;
				}

				this.applyGeneratedAlt(imageId, currentEntry.text ?? '');

				for (const entry of languages) {
					const code = entry?.language;
					if (!code || code === currentCode) continue;
					if (!['generated', 'translated'].includes(entry.status)) continue;
					const current = Number(this.unsavedByLanguage?.[code] ?? 0);
					this.$set(this.unsavedByLanguage, code, current + 1);
				}
			}
		},

		// ---------------------------------------------------------------------
		// Draft autosave (debounced) helpers
		// ---------------------------------------------------------------------

		async saveAltDraft(imageId) {
			const image = this.getImageById(imageId);
			if (!image) return;

			const value = (this.currentImages[imageId]?.alt ?? '').trim();

			try {
				const resp = await this.$api.post('alter/update', {
					imageId: image.id,
					field: 'alt',
					value,
				});

				if (resp && resp.error) {
					this.$panel.notification.error(
						resp.error || this.$t('medienbaecker.alter.error')
					);
				}
			} catch (e) {
				this.$panel.notification.error(this.$t('medienbaecker.alter.error'));
				console.error('Draft save failed', e);
			}
		},

		scheduleAltDraftSave(imageId) {
			if (this.altSaveTimeouts[imageId]) {
				clearTimeout(this.altSaveTimeouts[imageId]);
			}

			this.altSaveTimeouts[imageId] = setTimeout(async () => {
				try {
					await this.saveAltDraft(imageId);
				} finally {
					this.altSaveTimeouts[imageId] = null;
				}
			}, 200);
		},

		async flushAltDraftSaves() {
			const ids = Object.keys(this.altSaveTimeouts).filter(
				(id) => this.altSaveTimeouts[id]
			);

			if (ids.length === 0) return;

			// Cancel pending timeouts
			for (const id of ids) {
				clearTimeout(this.altSaveTimeouts[id]);
				this.altSaveTimeouts[id] = null;
			}

			// Persist immediately
			await Promise.all(ids.map((id) => this.saveAltDraft(id)));
		},

		async flushAltDraftSaveFor(imageId) {
			const timeout = this.altSaveTimeouts?.[imageId];
			if (!timeout) return;

			clearTimeout(timeout);
			this.altSaveTimeouts[imageId] = null;

			await this.saveAltDraft(imageId);
		},

		// ---------------------------------------------------------------------
		// AI generation
		// ---------------------------------------------------------------------

		setGenerationScope(scope) {
			const allowed = ['current', 'all'];
			this.generationScope = allowed.includes(scope) ? scope : 'current';
		},

		async onGenerateAll(scope = this.generationScope) {
			this.setGenerationScope(scope);
			if (this.canGenerateForScope(scope) !== true) return;
			await this.generateForAll(scope);
		},

		async generateForAll(scope = this.generationScope) {
			if (!this.panelGenerationEnabled) return;

			await this.flushAltDraftSaves();

			const imageIds = [...new Set(this.images.map((image) => image.id))];

			// autoSelect: server picks images, keep as single request
			if (imageIds.length === 0 && this.filterMode === 'missing') {
				this.generatingAll = true;
				try {
					const response = await this.$api.post('alter/generate', {
						imageIds: [],
						languageMode: scope,
						autoSelect: true,
					});
					if (response?.error) throw new Error(response.error);
					const generated = Number(response?.generated ?? 0);
					this.applyGenerationResponse(response);
					this.$panel.notification.success(
						generated > 0
							? this.$t('medienbaecker.alter.generate.success.all', { count: generated })
							: this.$t('medienbaecker.alter.generate.none')
					);
				} catch (error) {
					this.$panel.notification.error(
						error?.message || this.$t('medienbaecker.alter.generate.failed')
					);
					console.error(error);
				} finally {
					this.generatingAll = false;
				}
				return;
			}

			this.generatingAll = true;
			let totalGenerated = 0;

			try {
				for (const imageId of imageIds) {
					this.$set(this.generating, imageId, true);
					try {
						const response = await this.$api.post('alter/generate', {
							imageIds: [imageId],
							languageMode: scope,
						});
						if (response?.error) throw new Error(response.error);
						totalGenerated += Number(response?.generated ?? 0);
						this.applyGenerationResponse(response);
					} finally {
						this.$set(this.generating, imageId, false);
					}
				}

				this.$panel.notification.success(
					totalGenerated > 0
						? this.$t('medienbaecker.alter.generate.success.all', { count: totalGenerated })
						: this.$t('medienbaecker.alter.generate.none')
				);
			} catch (error) {
				this.$panel.notification.error(
					error?.message || this.$t('medienbaecker.alter.generate.failed')
				);
				console.error(error);
			} finally {
				this.generatingAll = false;
			}
		},

		async onGenerateImage(imageId) {
			await this.generateForImage(imageId, 'current');
		},

		async generateForImage(imageId, scope = this.generationScope) {
			if (!this.panelGenerationEnabled) return;

			await this.flushAltDraftSaveFor(imageId);

			this.$set(this.generating, imageId, true);

			try {
				const response = await this.$api.post('alter/generate', {
					imageIds: [imageId],
					languageMode: scope,
				});

				if (response?.error) {
					throw new Error(response.error);
				}

				const generated = Number(response?.generated ?? 0);
				const didTranslate = Array.isArray(response?.images)
					? response.images
						.find((img) => img.imageId === imageId)
						?.languages?.some((entry) => entry.status === 'translated')
					: false;
				const message =
					generated > 0
						? didTranslate
							? this.$t('medienbaecker.alter.generate.success.translated.one')
							: this.$t('medienbaecker.alter.generate.success.one')
						: this.$t('medienbaecker.alter.generate.none');

				this.applyGenerationResponse(response);
				this.$panel.notification.success(message);
			} catch (error) {
				this.$panel.notification.error(
					error?.message || this.$t('medienbaecker.alter.generate.failed')
				);
				console.error(error);
			} finally {
				this.$set(this.generating, imageId, false);
			}
		},

		toggleDropdown(refName) {
			if (refName === 'scopeDropdown') {
				this.ensureGenerationScope();
			}

			const ref = this.getDropdownRef(refName);
			if (ref && typeof ref.toggle === 'function') {
				ref.toggle();
			}
		},

		ensureGenerationScope() {
			const enabledScopes = this.generationScopes.filter(
				(scope) => scope.disabled !== true
			);

			if (enabledScopes.length === 0) return;

			const enabledValues = enabledScopes.map((scope) => scope.value);
			if (enabledValues.includes(this.generationScope)) return;

			this.generationScope = enabledScopes[0].value;
		},

		getDropdownRef(refName) {
			const ref = this.$refs?.[refName];
			if (Array.isArray(ref)) {
				return ref[0];
			}
			return ref || null;
		},

		ensurePanelStoresIntact() {
			const resetKey = 'medienbaecker.alter.panelStoreResetAttempted';
			const stores = [
				'dropdown',
				'language',
				'menu',
				'notification',
				'system',
				'translation',
				'user',
			];

			for (const storeName of stores) {
				const store = this.$panel?.[storeName];
				if (store && typeof store.set !== 'function') {
					try {
						if (!sessionStorage.getItem(resetKey)) {
							sessionStorage.setItem(resetKey, '1');
							window.location.reload();
						}
					} catch (e) {
						window.location.reload();
					}

					return false;
				}
			}

			try {
				sessionStorage.removeItem(resetKey);
			} catch (e) { }

			return true;
		},

		// ---------------------------------------------------------------------
		// View actions (filter / language / pagination)
		// ---------------------------------------------------------------------

		async onFilterChange(value) {
			await this.flushAltDraftSaves();

			if (!this.ensurePanelStoresIntact()) return;

			this.filterMode = value;
			if (this.page === 1) {
				await this.loadImages(1);
			}

			this.$go(this.buildRoute(1));
		},

		async onLanguageChange(code) {
			await this.flushAltDraftSaves();

			if (!this.ensurePanelStoresIntact()) return;

			if ((code ?? null) === (this.currentLanguage ?? null)) {
				return;
			}

			this.loading = true;
			this.$reload({
				query: {
					...(this.filterMode ? { filter: this.filterMode } : {}),
					language: code,
				},
			});
		},

		async onPageChange(paginationData) {
			await this.flushAltDraftSaves();

			const page = paginationData.page || paginationData;
			this.$go(this.buildRoute(page));
		},

		// ---------------------------------------------------------------------
		// Input handling
		// ---------------------------------------------------------------------

		onAltTextInput(imageId, value) {
			const sanitizedValue = value.replace(/[\r\n]+/g, ' ').trim();

			if (this.currentImages[imageId]) {
				const wasChanged = this.hasChanges(imageId);

				this.$set(this.currentImages[imageId], 'alt', sanitizedValue);

				const isChanged = this.hasChanges(imageId);
				this.updateUnsavedTotals(wasChanged, isChanged);

				// Debounced draft save
				this.scheduleAltDraftSave(imageId);
			}
		},

		// ---------------------------------------------------------------------
		// Data loading + formatting
		// ---------------------------------------------------------------------
		buildRoute(page, languageOverride = null) {
			const query = {
				...(this.filterMode ? { filter: this.filterMode } : {}),
			};

			const lang = languageOverride || this.currentLanguage;
			if (lang) query.language = lang;

			return this.$url(`alter/${page}`, query);
		},

		async loadImages(page = 1) {
			this.loading = true;

			try {
				const response = await this.$api.get('alter/images', {
					page: page,
					filter: this.filterMode || 'all',
				});

				this.images = response.images;
				this.defaultLanguage = response.defaultLanguage || null;
				this.pagination = response.pagination;
				this.totals = response.totals;
				this.unsavedByLanguage = response.unsavedByLanguage || {};
				this.generationStats = response.generationStats || {
					missingCurrent: 0,
					missingAny: 0,
				};

				this.initializeImageData();
			} catch (error) {
				console.error('Failed to load images:', error);
				this.$panel.notification.error('Failed to load images');
			} finally {
				this.hasLoadedOnce = true;
				this.loading = false;
			}
		},

		languageHasUnsavedChanges(code) {
			if (!code) return false;
			return Number(this.unsavedByLanguage?.[code] ?? 0) > 0;
		},

		initializeImageData() {
			this.currentImages = {};
			this.originalImages = {};
			this.saving = {};
			this.generating = {};
			this.generatingAll = false;

			this.images.forEach((image) => {
				const currentData = { alt: image.alt || '' };
				const originalData = { alt: image.altOriginal || '' };

				this.$set(this.originalImages, image.id, { ...originalData });
				this.$set(this.currentImages, image.id, { ...currentData });
				this.$set(this.saving, image.id, false);
			});
		},

		formatItems(pageImages) {
			return pageImages.map((image) => ({
				...image,
				text: image.filename,
				image: { src: image.thumbUrl, back: 'pattern', ratio: '3/2' },
			}));
		},

		formatBreadcrumbs(breadcrumbs) {
			return breadcrumbs.map((crumb) => ({
				text: crumb.title || crumb.label,
				label: crumb.label || crumb.title,
				link: crumb.panelUrl || crumb.link,
			}));
		},

		getImageById(imageId) {
			return this.images.find((image) => image.id === imageId);
		},

		// ---------------------------------------------------------------------
		// Totals updates
		// ---------------------------------------------------------------------

		updateUnsavedTotals(wasChanged, isChanged) {
			if (wasChanged === isChanged) return;

			const delta = isChanged ? 1 : -1;
			this.totals.unsaved += delta;

			const code = this.currentLanguage;
			if (code) {
				const current = Number(this.unsavedByLanguage?.[code] ?? 0);
				this.$set(this.unsavedByLanguage, code, Math.max(0, current + delta));
			}
		},

		updateSavedTotals(previousAlt, nextAlt) {
			const hadAlt = previousAlt && previousAlt.trim() !== '';
			const hasAlt = nextAlt && nextAlt.trim() !== '';

			if (hadAlt === hasAlt) return;
			this.totals.saved += hasAlt ? 1 : -1;
		},

		// ---------------------------------------------------------------------
		// Save/discard actions
		// ---------------------------------------------------------------------
		placeholderFor(item) {
			const currentCode = this.currentLanguage;
			const defaultCode = this.defaultLanguage;
			if (!currentCode || currentCode === defaultCode) {
				return this.$t('medienbaecker.alter.noAltText');
			}

			const defaultAlt = item.altDefault || '';
			return defaultAlt.trim() !== ''
				? defaultAlt
				: this.$t('medienbaecker.alter.noAltText');
		},

		async saveImage(imageId) {
			// Ensure the last keystrokes are persisted to the draft before publishing.
			await this.flushAltDraftSaveFor(imageId);

			this.$set(this.saving, imageId, true);

			try {
				const image = this.getImageById(imageId);
				const current = this.currentImages[imageId];
				const original = this.originalImages[imageId];
				const wasChanged = this.hasChanges(imageId);

				if (!image || !current) return;

				const response = await this.$api.post('alter/publish', {
					imageId: image.id,
					alt: current.alt,
				});

				if (response.error) {
					throw new Error(response.error);
				}

				if (original) {
					this.updateSavedTotals(original.alt, current.alt);
				}

				this.$set(this.originalImages, imageId, { alt: current.alt });
				this.updateUnsavedTotals(wasChanged, false);

				this.$panel.notification.success();
			} catch (error) {
				this.$panel.notification.error(this.$t('medienbaecker.alter.error'));
				console.error(error);
			} finally {
				this.$set(this.saving, imageId, false);
			}
		},

		async saveAllChanges(event) {
			if (event) event.preventDefault();

			// Persist debounced drafts first (so publish sees the latest value)
			await this.flushAltDraftSaves();

			const changedImages = Object.keys(this.currentImages).filter((imageId) =>
				this.hasChanges(imageId)
			);

			if (changedImages.length === 0) return;

			await Promise.all(
				changedImages.map((imageId) => this.saveImage(imageId))
			);
		},

		async discardImage(imageId) {
			const image = this.getImageById(imageId);
			const original = this.originalImages[imageId];
			const current = this.currentImages[imageId];

			if (!image || !original || !current) return;

			// Stop any pending draft save for this image
			await this.flushAltDraftSaveFor(imageId);

			const wasChanged = this.hasChanges(imageId);

			// Update local state first
			this.$set(this.currentImages, imageId, { ...original });
			this.updateUnsavedTotals(wasChanged, this.hasChanges(imageId));

			try {
				await this.$api.post('alter/discard', { imageId: image.id });
			} catch (error) {
				this.$panel.notification.error(this.$t('medienbaecker.alter.error'));
				console.error(error);
			}
		},

		async discardChanges() {
			this.$panel.dialog.close();
			await this.flushAltDraftSaves();

			const changedImages = Object.keys(this.currentImages).filter((imageId) =>
				this.hasChanges(imageId)
			);

			if (changedImages.length === 0) return;

			await Promise.all(
				changedImages.map((imageId) => this.discardImage(imageId))
			);
		},
	},
};
</script>

<style scoped>
.alter-breadcrumb {
	margin-inline-start: calc(var(--button-padding) * -1);
}

.alter-card {
	display: flex;
	flex-direction: column;

	/* Remove default .k-item outline as only image is a link */
	&:has(a:focus) {
		outline: none;
	}
}

.alter-image-link {
	display: block;
	flex: 0 0 auto;
	overflow: clip;
	border-top-left-radius: var(--rounded);
	border-top-right-radius: var(--rounded);
}

.k-item-content {
	display: flex;
	flex-direction: column;
	flex-grow: 1;
	gap: var(--spacing-2);
	padding: var(--spacing-3) var(--spacing-4) var(--spacing-4);
}

.k-item .k-form-controls {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	gap: var(--spacing-4);
}

.k-field-name-alt {
	flex-grow: 1;
}
</style>
