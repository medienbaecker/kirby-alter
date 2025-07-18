# Kirby Alter

A [Kirby](https://getkirby.com/) plugin for generating and reviewing alt texts for images in your sites.

> [!WARNING]  
> Use this plugin at your own risk. It was built primarily for my own projects and may not work in yours.

I use some Kirby 5 specific features, but it could potentially work Kirby 4 as well. Please report any issues you encounter.

## Features

### Panel view

The plugin provides a custom panel view for reviewing and managing alt texts of images across all pages in your site. Each image also gets a "Reviewed" checkbox that gets saved as `alt_reviewed` in the file's content file.

![Screenshot of the custom panel view with several cat images and alt texts](https://github.com/user-attachments/assets/6136bebe-ec70-4a33-ab61-80a994af237c)

### CLI command

I also included an `alter:generate` [CLI command](https://github.com/getkirby/cli) that uses the [Claude API](https://docs.anthropic.com/en/api/overview) to generate alt texts for your images.

- Supports multi-language installations and only uploads the image for the default language and then translates the generated alt text to the other languages
- Detects duplicate images and saves tokens by only uploading them once, updating all instances at once

![Screenshot of a terminal displaying output from "kirby alter:generate"](https://github.com/user-attachments/assets/b82e6e42-de36-4545-b484-240936b2fbeb)

#### CLI Arguments

- `--prompt` / `-p` - Custom prompt for generating alt texts (overrides the configured prompt)
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
		'prompt' => 'Your custom prompt', // Optional: Custom prompt for alt text generation
	]
];

```

Get your Claude API key from the [Anthropic Console](https://console.anthropic.com/).

### Custom Prompt Configuration

The prompt option can be either a string or a callback function that receives the image file as a parameter. The default is:

```php
'prompt' => function ($file) {
	$prompt = 'You are an accessibility expert writing alt text. Write a concise, short description in one to three sentences. Start directly with the subject - NO introductory phrases like "image of", "shows", "displays", "depicts", "contains", "features" etc.';
	$prompt .= ' The image is on a page called "' . $file->page()->title() . '".';
	$prompt .= ' The site is called "' . $file->site()->title() . '".';
	$prompt .= ' Return the alt text only, without any additional text or formatting.';

	return $prompt;
}
```

You can override this with your own string or callback:

```php
// Simple string prompt
'prompt' => 'Describe this image concisely for accessibility purposes.'

// Custom callback with different context
'prompt' => function($file) {
	return 'Describe this image. Context: "' . $file->page()->text()->excerpt(100) . '"';
}
```

## Installation

```
composer require medienbaecker/kirby-alter
```
