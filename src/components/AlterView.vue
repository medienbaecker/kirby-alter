<template>
  <k-panel-inside>
    <k-header>
      {{ $t('medienbaecker.alter.title') }}
      <template #buttons>
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

        <k-button
          v-if="totalImagesCount > 0"
          variant="filled"
          size="sm"
          icon="edit"
          element="span"
        >
          {{ `${unsavedImagesCount}/${totalImagesCount}` }}
        </k-button>

        <k-button
          v-if="totalImagesCount > 0"
          variant="filled"
          size="sm"
          icon="check"
          element="span"
          :theme="isComplete ? 'positive' : null"
        >
          {{ `${savedImagesCount}/${totalImagesCount}` }}
        </k-button>

        <template v-if="languages.length > 1">
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
              <span>{{ lang.code.toUpperCase() }}</span>
            </k-dropdown-item>
          </k-dropdown-content>
        </template>

        <k-form-controls v-if="hasAnyChanges">
          <k-button-group layout="collapsed">
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
          </k-button-group>
        </k-form-controls>
      </template>
    </k-header>

    <div v-if="!hasLoadedOnce" class="k-loader-container">
      <k-loader />
    </div>

    <div v-else-if="images.length === 0" class="k-empty">
      <k-text>{{ emptyStateMessage }}</k-text>
    </div>

    <template v-else>
      <k-section v-for="pageGroup in groupedImages" :key="pageGroup.pageId">
        <header class="k-section-header">
          <k-breadcrumb :crumbs="formatBreadcrumbs(pageGroup.breadcrumbs)" />

          <k-button-group class="k-section-buttons">
            <k-button
              variant="filled"
              size="sm"
              element="span"
              :badge="badgeForPage(pageGroup.pageId)"
            >
              {{ pageGroup.images.length }}
              {{
                pageGroup.images.length === 1
                  ? $t('medienbaecker.alter.image')
                  : $t('medienbaecker.alter.images')
              }}
            </k-button>

            <k-button
              v-if="pageGroup.hasParentDrafts || pageGroup.pageStatus"
              element="span"
              variant="filled"
              size="sm"
              :icon="
                pageGroup.hasParentDrafts
                  ? 'status-draft'
                  : pageStatusUi(pageGroup).icon
              "
              :theme="
                pageGroup.hasParentDrafts
                  ? 'negative-icon'
                  : pageStatusUi(pageGroup).theme
              "
              :title="
                pageGroup.hasParentDrafts
                  ? $t('medienbaecker.alter.parentDraft')
                  : pageStatusUi(pageGroup).title
              "
              :responsive="true"
            >
              {{
                pageGroup.hasParentDrafts
                  ? $t('page.status.draft')
                  : pageStatusUi(pageGroup).text
              }}
            </k-button>
          </k-button-group>
        </header>

        <k-items
          :items="formatItems(pageGroup.images)"
          layout="cards"
          size="huge"
          :link="false"
        >
          <template #default="{ item }">
            <div class="k-item k-cards-item" :data-image-id="item.id">
              <k-link :to="item.panelUrl">
                <k-image-frame
                  :src="item.thumbUrl"
                  :alt="getImageData(item.id).alt"
                  back="pattern"
                  ratio="3/2"
                />
              </k-link>

              <div class="k-item-content">
                <k-textarea-field
                  :label="item.filename"
                  name="alt"
                  type="textarea"
                  :value="
                    currentImages[item.id] ? currentImages[item.id].alt : ''
                  "
                  @input="onAltTextInput(item.id, $event)"
                  @focusin.native="setActiveImage(item.id)"
                  @mousedown.native="setActiveImage(item.id)"
                  @keydown.native="onAltTextKeydown(item.id, $event)"
                  :placeholder="$t('medienbaecker.alter.noAltText')"
                  :buttons="false"
                  :counter="true"
                  :maxlength="maxLength || null"
                  size="small"
                />

                <div v-if="hasChanges(item.id)" class="k-form-controls">
                  <k-button-group layout="collapsed">
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
                  </k-button-group>
                </div>
              </div>
            </div>
          </template>
        </k-items>
      </k-section>

      <k-pagination
        v-if="pagination.total > pagination.limit"
        :page="pagination.page"
        :total="pagination.total"
        :limit="pagination.limit"
        :details="true"
        @paginate="onPageChange"
      />
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
  },

  data() {
    return {
      // API data + derived UI data
      images: [],
      pagination: { page: 1, pages: 1, total: 0, limit: 100 },
      totals: { unsaved: 0, saved: 0, total: 0 },

      // Local edit state
      currentImages: {},
      originalImages: {},
      saving: {},

      // Draft autosave (debounced) per image
      altSaveTimeouts: {},

      // UI state
      loading: false,
      filterMode: null,
      hasLoadedOnce: false,

      // “active textarea” tracking for Cmd+S (save current item)
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
        this.hasChanges(imageId),
      );
    },

    // Languages
    currentLanguage() {
      return this.$panel.language ? this.$panel.language.code : null;
    },
    languages() {
      return this.$panel.languages || [];
    },

    // Filter UI
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
          text: this.$t('medienbaecker.alter.filter.saved'),
          value: 'with_alt',
        },
        {
          text: this.$t('medienbaecker.alter.filter.unsaved'),
          value: 'unsaved',
        },
        {
          text: this.$t('medienbaecker.alter.filter.empty'),
          value: 'without_alt',
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
        a.sortKey.localeCompare(b.sortKey),
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
      // Flush pending draft saves before navigation/pagination changes
      this.flushAltDraftSaves().finally(() => this.loadImages(newPage));
    },

    currentLanguage(newLanguage, oldLanguage) {
      if (newLanguage !== oldLanguage && oldLanguage !== undefined) {
        // No confirm dialog: drafts are persisted. Just flush and reload.
        this.flushAltDraftSaves().finally(() => this.loadImages(this.page));
      }
    },
  },

  created() {
    const urlParams = new URLSearchParams(window.location.search);
    const filterParam = urlParams.get('filter');

    if (
      filterParam &&
      ['with_alt', 'without_alt', 'unsaved'].includes(filterParam)
    ) {
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
            resp.error || this.$t('medienbaecker.alter.error'),
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
        (id) => this.altSaveTimeouts[id],
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
    // View actions (filter / language / pagination)
    // ---------------------------------------------------------------------

    async onFilterChange(value) {
      await this.flushAltDraftSaves();

      this.filterMode = value;
      this.loadImages(1);

      const filterQuery = value ? `?filter=${value}` : '';
      this.$go(`/alter/1${filterQuery}`);
    },

    async onLanguageChange(code) {
      await this.flushAltDraftSaves();

      this.$panel.language = this.$panel.languages.find((l) => l.code === code);
      this.loadImages(this.page);
    },

    async onPageChange(paginationData) {
      await this.flushAltDraftSaves();

      const page = paginationData.page || paginationData;
      const filterQuery = this.filterMode ? `?filter=${this.filterMode}` : '';
      this.$go(`/alter/${page}${filterQuery}`);
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
      this.totals.unsaved += isChanged ? 1 : -1;
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
        this.hasChanges(imageId),
      );

      if (changedImages.length === 0) return;

      await Promise.all(
        changedImages.map((imageId) => this.saveImage(imageId)),
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

        // Reload only the affected image's data from backend
        const response = await this.$api.get('alter/images', {
          page: this.page,
          filter: this.filterMode || 'all',
        });

        const updatedImage = response.images.find((img) => img.id === imageId);

        if (updatedImage) {
          this.$set(this.originalImages, imageId, {
            alt: updatedImage.altOriginal || '',
          });
          this.$set(this.currentImages, imageId, {
            alt: updatedImage.alt || '',
          });
        }
      } catch (error) {
        this.$panel.notification.error(this.$t('medienbaecker.alter.error'));
        console.error(error);
      }
    },

    async discardChanges() {
      await this.flushAltDraftSaves();

      const changedImages = Object.keys(this.currentImages).filter((imageId) =>
        this.hasChanges(imageId),
      );

      if (changedImages.length === 0) return;

      await Promise.all(
        changedImages.map((imageId) => this.discardImage(imageId)),
      );
    },
  },
};
</script>

<style scoped>
:deep(.k-breadcrumb ol > li:first-child) .k-breadcrumb-link {
  padding-inline-start: 0;
}

.k-item {
  display: flex;
  flex-flow: column nowrap;
}

:where(.k-item) .k-link {
  display: block;
  flex: 0 0 auto;
}

.k-item-content {
  display: flex;
  flex-grow: 1;
  flex-flow: column nowrap;
  gap: var(--spacing-2);
}

.k-form-controls {
  justify-content: end;
  display: grid;
}

.k-field-name-alt {
  flex-grow: 1;
}
</style>
