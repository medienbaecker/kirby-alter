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
						:class="{ 'has-changes': hasChanges(image.id) }" class="image-card">
						<k-image-frame :src="image.url" :alt="getImageData(image.id).alt" back="pattern" ratio="3/2" />

						<div class="image-info">
							<k-text class="image-header">
								<strong>{{ image.filename }}</strong>
							</k-text>

							<k-input v-model="currentImages[image.id].alt" label="Alt text" type="text"
								placeholder="No alt text" class="alt-text-input" />

							<div class="review-actions">
								<k-toggle-input v-model="getImageData(image.id).alt_reviewed"
									:text="['Pending', 'Reviewed']" />
								<k-button @click="saveImage(image.id)" :loading="saving[image.id]" icon="check"
									variant="filled" size="sm" :class="{ 'save-button-hidden': !hasChanges(image.id) }">
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

					// Debug logging for initialization
					console.log(`Initializing image ${image.id}:`, {
						alt: `"${image.alt}"`,
						alt_reviewed: image.alt_reviewed,
						altType: typeof image.alt,
						reviewedType: typeof image.alt_reviewed,
						filename: image.filename
					});

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

			const altChanged = current.alt !== original.alt;
			const reviewedChanged = current.alt_reviewed !== original.alt_reviewed;
			const hasChanges = altChanged || reviewedChanged;

			// Debug logging
			if (hasChanges) {
				console.log(`Changes detected for image ${imageId}:`, {
					current: {
						alt: `"${current.alt}"`,
						alt_reviewed: current.alt_reviewed,
						altType: typeof current.alt,
						reviewedType: typeof current.alt_reviewed
					},
					original: {
						alt: `"${original.alt}"`,
						alt_reviewed: original.alt_reviewed,
						altType: typeof original.alt,
						reviewedType: typeof original.alt_reviewed
					},
					altChanged,
					reviewedChanged
				});
			}

			return hasChanges;
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
.image-card {
	overflow: hidden;
	border-radius: var(--rounded);
	background: light-dark(var(--color-white), var(--color-gray-850));
	border: 1px solid var(--color-border);
}

.image-info {
	padding: 1rem;
}

.image-header {
	margin-bottom: 1rem;
}

.alt-text-input {
	margin-bottom: 1rem;
}

.review-actions {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 1rem;
}

.image-card.has-changes {
	outline: 2px solid var(--color-orange-400);
	outline-offset: -1px;
}

.save-button-hidden {
	visibility: hidden;
	pointer-events: none;
}
</style>