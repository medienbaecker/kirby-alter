<template>
	<k-panel-inside class="k-alter-view">
		<k-header>
			{{ $t('medienbaecker.alter.title') }}
			<template #buttons>
				<k-button @click="saveAllChanges" icon="check" theme="orange" variant="filled" size="sm"
					:style="{ visibility: hasAnyChanges ? 'visible' : 'hidden' }">
					{{ $t('medienbaecker.alter.save') }}
				</k-button>
				<k-button v-if="!loading && totalImagesCount > 0" element="span" variant="filled" size="sm" icon="edit">
					{{ `${altTextImagesCount}/${totalImagesCount}` }}
				</k-button>
				<k-button v-if="!loading && totalImagesCount > 0" element="span" variant="filled" size="sm" icon="check"
					:theme="isComplete ? 'positive' : null">
					{{ `${reviewedImagesCount}/${totalImagesCount}` }}
				</k-button>
				<k-button v-if="currentLanguage" element="span" variant="filled" size="sm">
					{{ currentLanguage.toUpperCase() }}
				</k-button>
			</template>
		</k-header>

		<div v-if="loading" class="k-loader">
			<k-loader />
		</div>

		<div v-else-if="images.length === 0" class="k-empty">
			<k-text>{{ $t('medienbaecker.alter.noImages') }}</k-text>
		</div>

		<div v-else>
			<div v-for="pageGroup in groupedImages" :key="pageGroup.pageId" class="page-group">
				<div class="page-group__header">
					<k-text>
						<k-link :to="pageGroup.pagePanelUrl" class="page-group__title">
							<strong>{{ pageGroup.pageTitle }}</strong>
						</k-link>
					</k-text>
					<div class="page-group__badges">
						<k-button v-if="pageGroup.pageStatus === 'draft'" element="span" variant="filled" size="sm"
							theme="negative">
							{{ $t('page.status.draft') }}
						</k-button>
						<k-button element="span" variant="filled" size="sm">
							{{ pageGroup.images.length }} {{ pageGroup.images.length === 1 ?
								$t('medienbaecker.alter.image')
								:
								$t('medienbaecker.alter.images') }}
						</k-button>
					</div>
				</div>

				<k-grid style="--columns: 2; gap: 1rem;" class="page-group__grid">
					<div v-for="image in pageGroup.images" :key="image.id"
						:class="{ 'alt-review-card--has-changes': currentImages[image.id] && hasChanges(image.id) }"
						class="alt-review-card">
						<k-image-frame :src="image.url" :alt="getImageData(image.id).alt" back="pattern" ratio="3/2" />

						<div class="alt-review-card__content">
							<k-text class="alt-review-card__filename">
								<k-link :to="image.panelUrl" class="alt-review-card__filename-link">
									<strong>{{ image.filename }}</strong>
								</k-link>
							</k-text>

							<k-text-field :value="currentImages[image.id] ? currentImages[image.id].alt : ''"
								@input="currentImages[image.id] && (currentImages[image.id].alt = $event)"
								:placeholder="$t('medienbaecker.alter.noAltText')" class="alt-review-card__alt-input" />

							<label class="alt-review-card__checkbox">
								<input type="checkbox" :checked="getImageData(image.id).alt_reviewed"
									@change="$set(currentImages[image.id], 'alt_reviewed', $event.target.checked)"
									class="alt-review-card__checkbox-input" />
								<span class="alt-review-card__checkbox-label">{{
									$t('medienbaecker.alter.reviewed')
								}}</span>
							</label>
						</div>
					</div>
				</k-grid>
			</div>

			<k-pagination v-if="pagination.total > pagination.limit" :page="pagination.page" :total="pagination.total"
				:limit="pagination.limit" :details="true" @paginate="onPageChange" style="margin-top: 2rem;" />
		</div>
	</k-panel-inside>
</template>

<script>
export default {
	props: {
		page: {
			type: Number,
			default: 1
		}
	},

	data() {
		return {
			currentImages: {},
			originalImages: {},
			saving: {},
			images: [],
			pagination: { page: 1, pages: 1, total: 0, limit: 100 },
			totals: { withAltText: 0, reviewed: 0, total: 0 },
			loading: false,
		}
	},

	computed: {
		reviewedImagesCount() {
			return this.totals.reviewed;
		},

		altTextImagesCount() {
			return this.totals.withAltText;
		},

		totalImagesCount() {
			return this.totals.total;
		},

		isComplete() {
			return this.totals.total > 0 && this.reviewedImagesCount === this.totals.total;
		},

		hasAnyChanges() {
			return Object.keys(this.currentImages).some(imageId => this.hasChanges(imageId));
		},

		currentLanguage() {
			// Get current language from panel
			return this.$panel.language ? this.$panel.language.code : null;
		},

		groupedImages() {
			// Group images by their parent page
			const groups = {};

			this.images.forEach(image => {
				const pageId = image.pageId;
				if (!groups[pageId]) {
					groups[pageId] = {
						pageTitle: image.pageTitle,
						pageId: image.pageId,
						pagePanelUrl: image.pagePanelUrl,
						pageSort: image.pageSort,
						pageStatus: image.pageStatus,
						images: []
					};
				}
				groups[pageId].images.push(image);
			});

			// Convert to array and sort by page number, then by title
			return Object.values(groups).sort((a, b) => {
				// Sort by page number first (if both have numbers)
				if (a.pageSort !== null && b.pageSort !== null) {
					return a.pageSort - b.pageSort;
				}
				// If one has no number, put numbered pages first
				if (a.pageSort !== null && b.pageSort === null) return -1;
				if (a.pageSort === null && b.pageSort !== null) return 1;
				// If both have no numbers, sort alphabetically
				return a.pageTitle.localeCompare(b.pageTitle);
			});
		}
	},

	watch: {
		page(newPage) {
			// Load images when the page prop changes (from route)
			this.loadImages(newPage);
		},

		currentLanguage(newLanguage, oldLanguage) {
			// Reload images when language changes
			if (newLanguage !== oldLanguage && oldLanguage !== undefined) {
				const hasUnsaved = Object.keys(this.currentImages).some(imageId => this.hasChanges(imageId));

				if (hasUnsaved) {
					if (!confirm(this.$t('medienbaecker.alter.unsavedChanges'))) {
						return;
					}
				}

				this.loadImages(this.page);
			}
		}
	},

	created() {
		// Use the page prop from the route
		this.loadImages(this.page);
	},

	mounted() {
		window.panel.events.on("keydown.cmd.s", this.saveAllChanges);
	},

	beforeDestroy() {
		window.panel.events.off("keydown.cmd.s", this.saveAllChanges);
	},

	methods: {
		async loadImages(page = 1) {
			this.loading = true;
			try {
				const response = await this.$api.get('alter/images', {
					page: page
				});

				this.images = response.images;
				this.pagination = response.pagination;
				this.totals = response.totals;

				this.initializeImageData();
			} catch (error) {
				console.error('Failed to load images:', error);
				this.$panel.notification.error('Failed to load images');
			} finally {
				this.loading = false;
			}
		},

		initializeImageData() {
			// Clear previous page data
			this.currentImages = {};
			this.originalImages = {};
			this.saving = {};

			// Store original + current state for current page images
			this.images.forEach(image => {
				const imageData = {
					alt: image.alt,
					alt_reviewed: image.alt_reviewed
				};

				this.$set(this.originalImages, image.id, { ...imageData });
				this.$set(this.currentImages, image.id, { ...imageData });
				this.$set(this.saving, image.id, false);
			});
		},

		onPageChange(paginationData) {
			const hasUnsaved = Object.keys(this.currentImages).some(imageId => this.hasChanges(imageId));

			if (hasUnsaved) {
				if (!confirm(this.$t('medienbaecker.alter.unsavedChanges'))) {
					return;
				}
			}

			// Extract the page number from the pagination object
			const page = paginationData.page || paginationData;

			// Update the URL to preserve pagination state
			this.$go(`/alter/${page}`);
		},


		getImageData(imageId) {
			return this.currentImages[imageId] || { alt: '', alt_reviewed: false };
		},

		hasChanges(imageId) {
			const current = this.currentImages[imageId];
			const original = this.originalImages[imageId];

			if (!current || !original) return false;

			return current.alt !== original.alt ||
				current.alt_reviewed !== original.alt_reviewed;
		},

		async saveImage(imageId) {
			this.$set(this.saving, imageId, true);

			try {
				const current = this.currentImages[imageId];
				const original = this.originalImages[imageId];

				// Save alt text if changed
				if (current.alt !== original.alt) {
					await this.updateField(imageId, 'alt', current.alt);
				}

				// Save review status if changed
				if (current.alt_reviewed !== original.alt_reviewed) {
					await this.updateField(imageId, 'alt_reviewed', current.alt_reviewed ? 'true' : '');
				}

				// Update totals based on changes
				if (current.alt !== original.alt) {
					// Alt text changed - update alt text count
					const hadAltText = original.alt && original.alt.trim() !== '';
					const hasAltText = current.alt && current.alt.trim() !== '';
					
					if (!hadAltText && hasAltText) {
						this.totals.withAltText++;
					} else if (hadAltText && !hasAltText) {
						this.totals.withAltText--;
					}
				}
				
				if (current.alt_reviewed !== original.alt_reviewed) {
					// Review status changed - update reviewed count
					if (current.alt_reviewed) {
						this.totals.reviewed++;
					} else {
						this.totals.reviewed--;
					}
				}

				// Update original state to match current
				this.$set(this.originalImages, imageId, { ...current });

				this.$panel.notification.success();

			} catch (error) {
				this.$panel.notification.error(this.$t('medienbaecker.alter.error'));
				console.error(error);
			} finally {
				this.$set(this.saving, imageId, false);
			}
		},

		async updateField(imageId, field, value) {
			const response = await this.$api.post('alter/update', {
				imageId: imageId,
				field: field,
				value: value
			});

			if (response.error) {
				throw new Error(response.error);
			}

			return response;
		},




		saveAllChanges(event) {
			if (event) {
				event.preventDefault();
			}

			const changedImages = [];

			// Find all images with changes
			Object.keys(this.currentImages).forEach(imageId => {
				if (this.hasChanges(imageId)) {
					changedImages.push(imageId);
				}
			});

			if (changedImages.length === 0) {
				return;
			}

			// Trigger save for each changed image
			changedImages.forEach(imageId => {
				this.saveImage(imageId);
			});
		},


	}
}
</script>

<style scoped>
.page-group {
	margin-bottom: 3rem;
}

.page-group__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 1.5rem;
	padding-bottom: 0.75rem;
	border-bottom: 1px solid var(--color-border);
}

.page-group__title {
	text-decoration: none;
	color: inherit;
}

.page-group__badges {
	display: flex;
	gap: 0.5rem;
	align-items: center;
}

.page-group__grid {
	margin-bottom: 1rem;
}

.alt-review-card {
	overflow: hidden;
	border-radius: var(--rounded);
	background: light-dark(var(--color-white), var(--color-gray-850));
	border: 1px solid var(--color-border);
}

.alt-review-card--has-changes {
	outline: 2px solid var(--color-orange-400);
	outline-offset: -1px;
}

.alt-review-card__content {
	padding: 1rem;
}

.alt-review-card__filename {
	margin-bottom: 1rem;
}

.alt-review-card__filename-link {
	text-decoration: none;
	color: inherit;
}

.alt-review-card__alt-input {
	margin-bottom: 1rem;
}

.alt-review-card__checkbox {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	cursor: pointer;
	user-select: none;
}

.alt-review-card__checkbox-input {
	width: 1rem;
	height: 1rem;
	margin: 0;
	cursor: pointer;
}

.alt-review-card__checkbox-label {
	font-size: 0.875rem;
	color: var(--color-text);
	cursor: pointer;
}
</style>