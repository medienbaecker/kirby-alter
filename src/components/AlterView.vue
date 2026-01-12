<template>
  <k-panel-inside class="k-alter-view">
    <k-header>
      {{ $t('medienbaecker.alter.title') }}
      <template #buttons>
        <div class="k-alter-filter">
          <k-button
            :dropdown="true"
            icon="filter"
            variant="filled"
            size="sm"
            :text="currentFilterLabel"
            @click="$refs.filterDropdown.toggle()"
          />
          <k-dropdown-content ref="filterDropdown" align-x="end">
            <k-dropdown-item
              :current="filterMode === null"
              @click="onFilterChange(null)"
            >
              {{ $t('medienbaecker.alter.filter.all') }}
            </k-dropdown-item>
            <k-dropdown-item
              v-for="option in filterOptions"
              :key="option.value"
              :current="filterMode === option.value"
              @click="onFilterChange(option.value)"
            >
              {{ option.text }}
            </k-dropdown-item>
          </k-dropdown-content>
        </div>
        <k-button
          v-if="totalImagesCount > 0"
          element="span"
          variant="filled"
          size="sm"
          icon="edit"
        >
          {{ `${altTextImagesCount}/${totalImagesCount}` }}
        </k-button>
        <k-button
          v-if="totalImagesCount > 0"
          element="span"
          variant="filled"
          size="sm"
          icon="check"
          :theme="isComplete ? 'positive' : null"
        >
          {{ `${reviewedImagesCount}/${totalImagesCount}` }}
        </k-button>
        <div v-if="languages.length > 1" class="k-alter-language">
          <k-button
            :dropdown="true"
            icon="translate"
            variant="filled"
            size="sm"
            :text="currentLanguage ? currentLanguage.toUpperCase() : ''"
            @click="$refs.languageDropdown.toggle()"
          />
          <k-dropdown-content ref="languageDropdown" align-x="end">
            <k-dropdown-item
              v-for="lang in languages"
              :key="lang.code"
              :current="currentLanguage === lang.code"
              @click="onLanguageChange(lang.code)"
            >
              {{ lang.name }}
              <span class="k-alter-language-code">{{
                lang.code.toUpperCase()
              }}</span>
            </k-dropdown-item>
          </k-dropdown-content>
        </div>
        <div v-if="hasAnyChanges" class="k-form-controls">
          <div class="k-button-group" data-layout="collapsed">
            <k-button
              @click="discardChanges"
              icon="undo"
              variant="filled"
              theme="notice"
              size="sm"
            >
              {{ $t('discard') }}
            </k-button>
            <k-button
              @click="saveAllChanges"
              icon="check"
              theme="notice"
              variant="filled"
              size="sm"
            >
              {{ $t('medienbaecker.alter.save') }}
            </k-button>
          </div>
        </div>
      </template>
    </k-header>

    <div v-if="!hasLoadedOnce" class="k-loader-container">
      <k-loader />
    </div>

    <div v-else-if="images.length === 0" class="k-empty">
      <k-text>{{ emptyStateMessage }}</k-text>
    </div>

    <div v-else class="k-alter-content">
      <div
        v-for="pageGroup in groupedImages"
        :key="pageGroup.pageId"
        class="page-group"
      >
        <div class="page-group__header">
          <k-breadcrumb
            :crumbs="formatBreadcrumbs(pageGroup.breadcrumbs)"
            class="page-group__breadcrumb"
          />
          <div class="page-group__badges">
            <k-button
              v-if="
                pageGroup.pageStatus === 'draft' || pageGroup.hasParentDrafts
              "
              element="span"
              variant="filled"
              size="sm"
              theme="negative"
            >
              {{ $t('page.status.draft') }}
            </k-button>
            <k-button element="span" variant="filled" size="sm">
              {{ pageGroup.images.length }}
              {{
                pageGroup.images.length === 1
                  ? $t('medienbaecker.alter.image')
                  : $t('medienbaecker.alter.images')
              }}
            </k-button>
          </div>
        </div>

        <k-items
          :items="formatItems(pageGroup.images)"
          layout="cards"
          size="huge"
          :link="false"
        >
          <template #default="{ item }">
            <div
              :class="['k-alter-card', 'k-item', 'k-cards-item']"
              data-layout="cards"
              :data-theme="cardTheme(item.id)"
              data-has-image="true"
              :data-selecting="hasChanges(item.id)"
              data-selectable="true"
              :style="cardStyle(item.id)"
            >
              <k-link :to="item.panelUrl" class="k-alter-card__image-link">
                <k-image-frame
                  :src="item.thumbUrl"
                  :alt="getImageData(item.id).alt"
                  back="pattern"
                  ratio="3/2"
                />
              </k-link>

              <div class="k-alter-card__content k-item-content">
                <k-textarea-field
                  :label="item.filename"
                  :value="
                    currentImages[item.id] ? currentImages[item.id].alt : ''
                  "
                  @input="onAltTextInput(item.id, $event)"
                  @keydown.native="onAltTextKeydown"
                  :placeholder="$t('medienbaecker.alter.noAltText')"
                  :buttons="false"
                  :counter="true"
                  :maxlength="maxLength || null"
                  size="small"
                  class="k-alter-card__alt-input"
                />

                <div
                  v-if="hasChanges(item.id)"
                  class="k-alter-card__actions k-form-controls"
                >
                  <div class="k-button-group" data-layout="collapsed">
                    <k-button
                      icon="undo"
                      variant="filled"
                      theme="notice"
                      size="sm"
                      @click="discardImage(item.id)"
                    >
                      {{ $t('discard') }}
                    </k-button>
                    <k-button
                      icon="check"
                      variant="filled"
                      theme="notice"
                      size="sm"
                      @click="saveImage(item.id)"
                    >
                      {{ $t('save') }}
                    </k-button>
                  </div>
                </div>
              </div>
            </div>
          </template>
        </k-items>
      </div>

      <k-pagination
        v-if="pagination.total > pagination.limit"
        :page="pagination.page"
        :total="pagination.total"
        :limit="pagination.limit"
        :details="true"
        @paginate="onPageChange"
        style="margin-top: 2rem"
      />
    </div>
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
      filterMode: null,
      hasLoadedOnce: false,
    };
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
      return (
        this.totals.total > 0 && this.reviewedImagesCount === this.totals.total
      );
    },

    hasAnyChanges() {
      return Object.keys(this.currentImages).some((imageId) =>
        this.hasChanges(imageId),
      );
    },

    currentLanguage() {
      return this.$panel.language ? this.$panel.language.code : null;
    },

    languages() {
      return this.$panel.languages || [];
    },

    currentFilterLabel() {
      if (this.filterMode === null) {
        return this.$t('medienbaecker.alter.filter.all');
      }
      const option = this.filterOptions.find(
        (opt) => opt.value === this.filterMode,
      );
      return option ? option.text : this.$t('medienbaecker.alter.filter.all');
    },

    filterOptions() {
      return [
        {
          text: this.$t('medienbaecker.alter.filter.with_alt'),
          value: 'with_alt',
        },
        {
          text: this.$t('medienbaecker.alter.filter.without_alt'),
          value: 'without_alt',
        },
        {
          text: this.$t('medienbaecker.alter.filter.reviewed'),
          value: 'reviewed',
        },
        {
          text: this.$t('medienbaecker.alter.filter.unreviewed'),
          value: 'unreviewed',
        },
      ];
    },

    emptyStateMessage() {
      return this.$t('medienbaecker.alter.noImages');
    },

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
        a.sortKey.localeCompare(b.sortKey),
      );
    },
  },

  watch: {
    page(newPage) {
      this.loadImages(newPage);
    },

    currentLanguage(newLanguage, oldLanguage) {
      if (newLanguage !== oldLanguage && oldLanguage !== undefined) {
        if (this.hasAnyChanges) {
          if (!confirm(this.$t('medienbaecker.alter.unsavedChanges'))) {
            return;
          }
        }
        this.loadImages(this.page);
      }
    },
  },

  created() {
    const urlParams = new URLSearchParams(window.location.search);
    const filterParam = urlParams.get('filter');
    if (
      filterParam &&
      ['with_alt', 'without_alt', 'reviewed', 'unreviewed'].includes(
        filterParam,
      )
    ) {
      this.filterMode = filterParam;
    }

    this.loadImages(this.page);
  },

  mounted() {
    window.panel.events.on('keydown.cmd.s', this.saveAllChanges);
  },

  beforeDestroy() {
    window.panel.events.off('keydown.cmd.s', this.saveAllChanges);
  },

  methods: {
    hasChanges(imageId) {
      const current = this.currentImages[imageId];
      const original = this.originalImages[imageId];

      if (!current || !original) return false;

      return (
        current.alt !== original.alt ||
        current.alt_reviewed !== original.alt_reviewed
      );
    },

    getImageData(imageId) {
      return this.currentImages[imageId] || { alt: '', alt_reviewed: false };
    },

    cardTheme(imageId) {
      const imageData = this.getImageData(imageId);
      return this.hasChanges(imageId) ? 'info' : null;
    },

    cardStyle(imageId) {
      const theme = this.cardTheme(imageId);
      if (theme === 'info') {
        return {
          '--item-color-back':
            'light-dark(var(--color-orange-250), var(--color-orange-800))',
          '--item-color-icon': 'var(--color-orange-600)',
          'border-color': 'var(--color-orange-300)',
        };
      }
      return {};
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

    onFilterChange(value) {
      if (this.hasAnyChanges) {
        if (!confirm(this.$t('medienbaecker.alter.unsavedChanges'))) {
          return;
        }
      }

      this.filterMode = value;
      this.loadImages(1);
      const filterQuery = value ? `?filter=${value}` : '';
      this.$go(`/alter/1${filterQuery}`);
    },

    onLanguageChange(code) {
      if (this.hasAnyChanges) {
        if (!confirm(this.$t('medienbaecker.alter.unsavedChanges'))) {
          return;
        }
      }
      this.$panel.language = this.$panel.languages.find((l) => l.code === code);
      this.loadImages(this.page);
    },

    onAltTextInput(imageId, value) {
      const sanitizedValue = value.replace(/[\r\n]+/g, ' ').trim();
      if (this.currentImages[imageId]) {
        this.$set(this.currentImages[imageId], 'alt', sanitizedValue);
      }
    },

    onAltTextKeydown(event) {
      if (event.key === 'Enter') {
        event.preventDefault();
      }
    },

    onReviewChange(imageId, checked) {
      if (this.currentImages[imageId]) {
        this.$set(this.currentImages[imageId], 'alt_reviewed', checked);
      }
    },

    async loadImages(page = 1) {
      this.loading = true;
      try {
        const response = await this.$api.get('alter/images', {
          page: page,
          filter: this.filterMode || 'all',
        });

        this.images = response.images;
        this.pagination = response.pagination;
        this.totals = response.totals;

        this.initializeImageData();
        this.hasLoadedOnce = true;
      } catch (error) {
        console.error('Failed to load images:', error);
        this.$panel.notification.error('Failed to load images');
      } finally {
        this.loading = false;
      }
    },

    initializeImageData() {
      this.currentImages = {};
      this.originalImages = {};
      this.saving = {};

      this.images.forEach((image) => {
        const imageData = {
          alt: image.alt,
          alt_reviewed: image.alt_reviewed,
        };

        this.$set(this.originalImages, image.id, { ...imageData });
        this.$set(this.currentImages, image.id, { ...imageData });
        this.$set(this.saving, image.id, false);
      });
    },

    onPageChange(paginationData) {
      if (this.hasAnyChanges) {
        if (!confirm(this.$t('medienbaecker.alter.unsavedChanges'))) {
          return;
        }
      }

      const page = paginationData.page || paginationData;
      const filterQuery = this.filterMode ? `?filter=${this.filterMode}` : '';
      this.$go(`/alter/${page}${filterQuery}`);
    },

    async saveImage(imageId) {
      this.$set(this.saving, imageId, true);

      try {
        const current = this.currentImages[imageId];
        const original = this.originalImages[imageId];

        if (current.alt !== original.alt) {
          await this.updateField(imageId, 'alt', current.alt);
        }

        if (current.alt_reviewed !== original.alt_reviewed) {
          await this.updateField(
            imageId,
            'alt_reviewed',
            current.alt_reviewed ? 'true' : '',
          );
        }

        // Update totals based on changes
        if (current.alt !== original.alt) {
          const hadAltText = original.alt && original.alt.trim() !== '';
          const hasAltTextNow = current.alt && current.alt.trim() !== '';

          if (!hadAltText && hasAltTextNow) {
            this.totals.withAltText++;
          } else if (hadAltText && !hasAltTextNow) {
            this.totals.withAltText--;
          }
        }

        if (current.alt_reviewed !== original.alt_reviewed) {
          if (current.alt_reviewed) {
            this.totals.reviewed++;
          } else {
            this.totals.reviewed--;
          }
        }

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
        value: value,
      });

      if (response.error) {
        throw new Error(response.error);
      }

      return response;
    },

    async saveAllChanges(event) {
      if (event) {
        event.preventDefault();
      }

      const changedImages = Object.keys(this.currentImages).filter((imageId) =>
        this.hasChanges(imageId),
      );

      if (changedImages.length === 0) {
        return;
      }

      await Promise.all(
        changedImages.map((imageId) => this.saveImage(imageId)),
      );
    },

    discardImage(imageId) {
      const original = this.originalImages[imageId];
      if (original) {
        this.$set(this.currentImages, imageId, { ...original });
      }
    },

    discardChanges() {
      Object.keys(this.currentImages).forEach((imageId) => {
        const original = this.originalImages[imageId];
        if (original) {
          this.$set(this.currentImages, imageId, { ...original });
        }
      });
    },
  },
};
</script>

<style scoped>
.k-alter-filter {
  position: relative;
  display: inline-flex;
}

.k-loader-container {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 200px;
}

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

.page-group__breadcrumb {
  flex-grow: 1;
  min-width: 0;
}

.page-group__badges {
  display: flex;
  gap: 0.5rem;
  align-items: center;
}

.k-alter-card {
  display: flex;
  flex-direction: column;
  overflow: hidden;
  border-radius: var(--rounded);
  height: 100%;
}

.k-alter-card[data-theme='info'] {
  --item-color-back: light-dark(
    var(--color-orange-200),
    var(--color-orange-1000, var(--color-gray-850))
  );
  --item-color-icon: var(--color-orange-600);
  border-color: var(--color-orange-300);
}

.k-alter-card__image-link {
  display: block;
}

.k-alter-card__content {
  display: flex;
  flex-direction: column;
  flex-grow: 1;
  padding: 1rem;
}

.k-alter-card__filename {
  flex-shrink: 0;
  margin-bottom: 0.75rem;
}

.k-alter-card__filename-link {
  display: block;
  text-decoration: none;
  color: inherit;
  white-space: nowrap;
  overflow-x: clip;
  text-overflow: ellipsis;
  min-width: 0;
}

.k-alter-card__alt-input {
  flex-grow: 1;
  margin-bottom: 0.75rem;
}

.k-alter-card__alt-input :deep(.k-textarea-input) {
  min-height: 4rem;
}

.k-alter-card__actions {
  margin-top: 0.75rem;
  display: flex;
  justify-content: flex-end;
}

/* Language dropdown */
.k-alter-language {
  position: relative;
}

.k-alter-language-code {
  font-size: var(--text-xs);
  color: var(--color-gray-500);
  margin-inline-start: var(--spacing-3);
}

:global(.k-alter-view .k-breadcrumb) {
  overflow-x: auto;
}

:global(.k-alter-view .k-breadcrumb ol) {
  display: flex;
}

:global(.k-alter-view .k-breadcrumb-dropdown) {
  display: none;
}

:global(.k-item[data-selecting='true'][data-selectable='true']) {
  cursor: pointer;
}
</style>
