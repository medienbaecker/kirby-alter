<template>
	<k-panel-inside class="k-alt-text-review-view">
		<k-header>
			{{ $t('medienbaecker.alt-text-review.title') }}
			<template #buttons>
				<k-button @click="saveAllChanges" icon="check" theme="orange" variant="filled" size="sm"
					:style="{ visibility: hasAnyChanges ? 'visible' : 'hidden' }">
					{{ $t('medienbaecker.alt-text-review.save') }}
				</k-button>
				<k-button v-if="!loading && totalImagesCount > 0" element="span" variant="filled" size="sm"
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
			<k-text>{{ $t('medienbaecker.alt-text-review.noImages') }}</k-text>
		</div>

		<div v-else>
			<k-grid style="--columns: 2; gap: 1rem;">
				<div v-for="image in images" :key="image.id"
					:class="{ 'alt-review-card--has-changes': currentImages[image.id] && hasChanges(image.id) }"
					class="alt-review-card">
					<k-link :to="image.panelUrl" class="alt-review-card__image-link">
						<k-image-frame :src="image.url" :alt="getImageData(image.id).alt" back="pattern" ratio="3/2" />
					</k-link>

					<div class="alt-review-card__content">
						<k-text class="alt-review-card__filename">
							<strong>{{ image.filename }}</strong>
						</k-text>

						<k-text-field :value="currentImages[image.id] ? currentImages[image.id].alt : ''"
							@input="currentImages[image.id] && (currentImages[image.id].alt = $event)"
							:placeholder="$t('medienbaecker.alt-text-review.noAltText')"
							class="alt-review-card__alt-input" />

						<label class="alt-review-card__checkbox">
							<input type="checkbox" :checked="getImageData(image.id).alt_reviewed"
								@change="$set(currentImages[image.id], 'alt_reviewed', $event.target.checked)"
								class="alt-review-card__checkbox-input" />
							<span class="alt-review-card__checkbox-label">{{
								$t('medienbaecker.alt-text-review.reviewed')
							}}</span>
						</label>
					</div>
				</div>
			</k-grid>

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
			loading: false,
		}
	},

	computed: {
		reviewedImagesCount() {
			return Object.values(this.currentImages).filter(img => img.alt_reviewed).length;
		},

		totalImagesCount() {
			return this.pagination.total;
		},

		isComplete() {
			return this.pagination.total > 0 && this.reviewedImagesCount === this.pagination.total;
		},

		hasAnyChanges() {
			return Object.keys(this.currentImages).some(imageId => this.hasChanges(imageId));
		},

		currentLanguage() {
			// Get current language from panel
			return this.$panel.language ? this.$panel.language.code : null;
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
					if (!confirm(this.$t('medienbaecker.alt-text-review.unsavedChanges'))) {
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
				const response = await this.$api.get('alt-text-review/images', {
					page: page
				});

				this.images = response.images;
				this.pagination = response.pagination;

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
				if (!confirm(this.$t('medienbaecker.alt-text-review.unsavedChanges'))) {
					return;
				}
			}

			// Extract the page number from the pagination object
			const page = paginationData.page || paginationData;

			// Update the URL to preserve pagination state
			this.$go(`/alt-text-review/${page}`);
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

				// Update original state to match current
				this.$set(this.originalImages, imageId, { ...current });

				this.$panel.notification.success();

			} catch (error) {
				this.$panel.notification.error(this.$t('medienbaecker.alt-text-review.error'));
				console.error(error);
			} finally {
				this.$set(this.saving, imageId, false);
			}
		},

		async updateField(imageId, field, value) {
			const response = await this.$api.post('alt-text-review/update', {
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



.alt-review-card__image-link {
	display: block;
}
</style>