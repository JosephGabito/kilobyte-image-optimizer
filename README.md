![Kilobyte Image Optimizer](.wordpress-org/banner-1544x500.jpg)

# Kilobyte Image Optimizer

Unlimited free WordPress image optimization. Compress photos to **WebP** or **AVIF** in your browser (with automatic server fallback), keep existing media URLs intact, preview before/after quality before touching your library, and regenerate thumbnails with zero accounts, zero API keys, and zero credit limits.

## Why Kilobyte?

- **No accounts, no API keys, no credit paywalls.** Processing runs locally in your browser or on your own WordPress site.
- **Preview before you optimize.** Compare before/after quality and file size on any image from your Media Library or computer without modifying anything on your site.
- **Existing links stay safe.** Bulk optimization keeps the original Media Library file and saves a smaller WebP or AVIF copy beside it, serving modern formats with `<picture>` fallback to the original.
- **One-click restore.** Restore a single image from the Media Library or restore your entire library from **Media → Optimization**.
- **Native WordPress experience.** Built with `@wordpress/components` and zero third-party UI bloat.

## Features

- **Browser-first compression** using a dedicated Web Worker (`OffscreenCanvas` / Canvas), plus automatic server-side fallback via WordPress's `WP_Image_Editor` (Imagick / GD) for Safari, mobile apps, REST uploads, and unsupported formats.
- **WebP, AVIF, and Keep original** output modes with three presets (*High detail*, *Balanced*, *Smallest files*) and optional max-width resizing.
- **Bulk optimization** with resumable pagination, live ETA, per-item status filtering, and non-destructive sibling delivery.
- **Thumbnail regeneration**, automatic clean alt text from filenames, and image loading hints (`loading="lazy"` / `fetchpriority="high"`).
- **WP-CLI support**:
  - `wp kilobyte optimize`
  - `wp kilobyte restore`
  - `wp kilobyte stats`
  - `wp kilobyte purge-backups`
- **Cache-friendly invalidation**: fires `do_action( 'kilobyte_io_variant_changed', $attachment_id )` whenever a variant is created or restored, and retains restored copies for 24 hours so cached HTML never breaks.

## Requirements

- WordPress **6.6** or later (tested up to **7.1**)
- PHP **7.4** or later
- Node.js **24.15+** (for building frontend assets from source)

## Installation

1. Upload the `kilobyte-image-optimizer` folder to `/wp-content/plugins/` (or install the release ZIP via **Plugins → Add New → Upload Plugin**).
2. Activate **Kilobyte Image Optimizer**.
3. Open **Media → Optimization**, preview an image, and run **Optimize remaining images**.

## Development

The plugin uses Domain-Driven Design (DDD) across both PHP (`src/`) and TypeScript (`src-js/`), with zero runtime Composer dependencies.

```bash
# Install dev dependencies
composer install
npm ci

# Watch and rebuild frontend assets
npm run start

# Production build
npm run build
```

### Quality & Release Pipeline

```bash
# Auto-fix PHP formatting (WordPress-VIP-Go + WordPress-Extra)
npm run lint:php:fix

# Run PHPCS (uncached), TypeScript check, production build, and headless WordPress Plugin Check
npm run verify

# Build a clean WordPress.org release ZIP in dist/
npm run package
```

## License

Licensed under the [GNU General Public License v2.0 or later](LICENSE) (`GPL-2.0-or-later`), matching WordPress core and WordPress.org plugin directory requirements.
