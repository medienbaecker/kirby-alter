<template>
	<k-panel-inside class="k-alt-text-review-view">
		<k-header>Alt Text Review</k-header>

		<div v-if="pageImages.length === 0" class="k-empty">
			<k-text>No images found</k-text>
		</div>

		<div v-else>
			<k-section v-for="pageData in pageImages" :key="pageData.pageId" :label="pageData.pageTitle">
				<k-grid style="--columns: 2; gap: 1rem;">
					<div v-for="image in pageData.images" :key="image.id"
						:class="{ 'alt-review-card--has-changes': hasChanges(image.id) }" class="alt-review-card">
						<k-image-frame :src="image.url" :alt="getImageData(image.id).alt" back="pattern" ratio="3/2" />

						<div class="alt-review-card__content">
							<k-text class="alt-review-card__filename">
								<strong>{{ image.filename }}</strong>
							</k-text>

							<k-text-field
								:value="currentImages[image.id].alt"
								@input="currentImages[image.id].alt = $event"
								placeholder="No alt text"
								class="alt-review-card__alt-input" />

							<div class="alt-review-card__actions">
								<label class="alt-review-card__checkbox">
									<input
										type="checkbox"
										:checked="getImageData(image.id).alt_reviewed"
										@change="$set(currentImages[image.id], 'alt_reviewed', $event.target.checked)"
										class="alt-review-card__checkbox-input"
									/>
									<span class="alt-review-card__checkbox-label">Reviewed</span>
								</label>
								<k-button @click="saveImage(image.id)" :loading="saving[image.id]" icon="check"
									variant="filled" size="sm"
									:class="['alt-review-card__save-button', { 'alt-review-card__save-button--hidden': !hasChanges(image.id) }]">
									Save
								</k-button>
							</div>
						</div>
					</div>
				</k-grid>
			</k-section>
		</div>
	</k-panel-inside>
</template>

<script>
export default {
	props: {
		pageImages: {
			type: Array,
			default: () => []
		}
	},

	data() {
		return {
			currentImages: {},
			originalImages: {},
			saving: {}
		}
	},


	created() {
		this.initializeImageData();
	},

	mounted() {
		window.panel.events.on("keydown.cmd.s", this.saveAllChanges);
	},

	beforeDestroy() {
		window.panel.events.off("keydown.cmd.s", this.saveAllChanges);
	},

	methods: {
		initializeImageData() {
			// Flatten all images and store original + current state
			this.pageImages.forEach(pageData => {
				pageData.images.forEach(image => {
					const imageData = {
						alt: image.alt,
						alt_reviewed: image.alt_reviewed
					};

					this.$set(this.originalImages, image.id, { ...imageData });
					this.$set(this.currentImages, image.id, { ...imageData });
					this.$set(this.saving, image.id, false);
				});
			});
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
				this.$panel.notification.error('Failed to save changes');
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
		}
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

.alt-review-card__actions {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 1rem;
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

.alt-review-card__save-button--hidden {
	visibility: hidden;
	pointer-events: none;
}
</style>