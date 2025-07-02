# Kirby Alter

A [Kirby](https://getkirby.com/) plugin for generating and reviewing alt texts for images in your sites.

> [!WARNING]  
> Use this plugin at your own risk. It was built primarily for my own projects and may not work in yours.

## Features

### Panel view

The plugin provides a panel area for reviewing and managing alt texts of images across all pages in your site. Each image also gets a "Reviewed" checkbox that gets saved as `alt_reviewed` in the file's content file.

![Panel view](https://github.com/user-attachments/assets/6136bebe-ec70-4a33-ab61-80a994af237c)

### CLI command

I also included an `alter:generate` [CLI command](https://github.com/getkirby/cli) that uses the [Claude API](https://docs.anthropic.com/en/api/overview) to generate alt texts for the image.

- Supports multi-language installations and only uploads the image for the default language and then translates the generated alt text to the other languages
- Detects duplicate images and saves tokens by only uploading them once, updating all instances at once

![CLI command](https://github.com/user-attachments/assets/b82e6e42-de36-4545-b484-240936b2fbeb)

#### CLI Arguments

- `--prompt` / `-p` - Custom prompt for generating alt texts (default: `"You are an accessibility expert writing alt text. Write a concise, short description in one to three sentences. Start directly with the subject - NO introductory phrases like "image of", "shows", "displays", "depicts", "contains", "features" etc. Return the alt text only, without any additional text or formatting."`)
- `--overwrite` - Overwrite existing alt texts (default: `false`)
- `--dry-run` - Preview changes without updating files (default: `false`)
- `--verbose` - Show detailed progress information (default: `false`)
- `--page` - Start from specific page URI, e.g. `"blog"` (optional)

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
<?php

return [
	'medienbaecker.alter' => [
		'apiKey' => 'your-claude-api-key', // Set your Claude API key here
	]
];

Get your Claude API key from the [Anthropic Console](https://console.anthropic.com/).

```

## Installation

```
composer require medienbaecker/kirby-alter
```