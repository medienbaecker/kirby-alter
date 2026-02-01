# Kirby Alter

Generate, edit and review alt texts for images in the [Kirby](https://getkirby.com/) Panel.

## Requirements

- Kirby 5+
- PHP 8.2+

## Installation

```bash
composer require medienbaecker/kirby-alter
```

> [!TIP]
> If you don’t use Composer, you can download this repository and copy it to `site/plugins/kirby-alter`.

## Features

### Panel view

The plugin provides a custom Panel view for reviewing and managing alt texts of images across all pages in your site.

- Filter modes to focus on **All / Saved / Unsaved / Missing**
- Shows the total number of drafts and saved alt texts
- Language toggle for switching between languages
- Sections for each page with breadcrumb, image count, and status
- Save or discard changes per image or in bulk
- Optional AI generation buttons to draft alt texts for the current list or a single image

> [!NOTE]
> On multilingual pages, the default alt text appears as a placeholder in non-default languages, making it easier to spot missing translations.

![Screenshot of the custom panel view with several cat images and alt texts](https://github.com/user-attachments/assets/070424ec-07b8-48ff-b781-c4dbd5236afe)

### CLI command

`kirby alter:generate` [CLI command](https://github.com/getkirby/cli) is included and uses the [Claude API](https://docs.anthropic.com/en/api/overview) to generate alt texts for images. Generated texts are stored as unsaved changes and need to be reviewed and published in the Panel.

- Supports multi-language installations (run for default only, a specific language, or all languages)
- Detects duplicate images and saves tokens by only uploading them once, updating all instances at once

![Screenshot of a terminal displaying output from "kirby alter:generate"](https://github.com/user-attachments/assets/b82e6e42-de36-4545-b484-240936b2fbeb)

#### CLI Arguments

- `--prompt` / `-p` - Custom prompt for generating alt texts (overrides the configured prompt)
- `--overwrite` - Overwrite existing alt texts (default: `false`)
- `--dry-run` - Preview changes without updating files (default: `false`)
- `--verbose` - Show detailed progress information (default: `false`)
- `--page` - Start from specific page URI, e.g. `"blog"` (optional)

> [!WARNING]
> `--dry-run` still uses the API (it only skips writing changes).

#### Usage Examples

```bash
# Generate alt texts for all images
kirby alter:generate

# Preview changes without updating files (still uses the API)
kirby alter:generate --dry-run

# Generate with custom prompt
kirby alter:generate --prompt "My custom prompt"

# Process only images from a specific page and overwrite existing alt texts
kirby alter:generate --page "blog/my-article" --overwrite
```

## Options

```php
// site/config/config.php
return [
  'medienbaecker.alter' => [
    'api.key' => 'claude-api-key', // Set your Claude API key here
    'api.model' => 'model-id',     // Optional: set a Claude model id/alias
    'templates' => null,           // Optional: restrict to specific file templates (string or array)
    'prompt' => 'Custom prompt',   // Optional: custom prompt for alt text generation
    'maxLength' => false,          // Optional: set a max length (e.g. 125) for alt texts in the panel counter
    'panel.generation' => false,   // Show AI generation buttons in the Panel (default: CLI-only)
  ]
];
```

Enable `panel.generation` to surface a “Generate” button in header and per image. A dropdown lets you choose whether to translate for the current language only or for all site languages. Panel generation never overwrites existing alt texts; it only fills missing ones as drafts.

> [!TIP]
> Get your Claude API key from the [Anthropic Console](https://console.anthropic.com/).

### Custom Prompt Configuration

The prompt option can be either a string or a callback function that receives the image file as a parameter. The default is:

```php
'prompt' => function ($file) {
  $prompt = 'You are an accessibility expert writing alt text. Write a concise, short description in one to three sentences. Start directly with the subject - NO introductory phrases like "image of", "shows", "displays", "depicts", "contains", "features" etc.';
  if ($file->parent() instanceof \Kirby\Cms\Page) {
    $prompt .= ' The image is on a page called "' . $file->parent()->title() . '".';
  }
  $prompt .= ' The site is called "' . $file->site()->title() . '".';
  $prompt .= ' Return the alt text only, without any additional text or formatting.';

  return $prompt;
}
```

You can override this with your own string or callback:

```php
// Simple string prompt
'prompt' => 'Describe this image concisely for accessibility purposes.'

// Custom callback with different context
'prompt' => function($file) {
  return 'Describe this image. Context: "' . $file->page()->text()->excerpt(100) . '"';
}
```
