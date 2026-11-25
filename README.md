![Kilobyte Image Optimizer](.wordpress-org/banner-1544x500.jpg)

# Kilobyte Image Optimizer

[![CI & Plugin Check](https://img.shields.io/github/actions/workflow/status/JosephGabito/kilobyte-image-optimizer/ci.yml?branch=main&style=flat-square&label=CI%20%26%20Plugin%20Check)](https://github.com/JosephGabito/kilobyte-image-optimizer/actions/workflows/ci.yml)
[![Website](https://img.shields.io/badge/Website-kilobyte.gabi.to-3858e9?style=flat-square)](https://kilobyte.gabi.to/)
[![WordPress.org](https://img.shields.io/wordpress/plugin/v/kilobyte-image-optimizer?style=flat-square&logo=wordpress&logoColor=white&label=WordPress.org)](https://wordpress.org/plugins/kilobyte-image-optimizer/)
[![Try Live Preview](https://img.shields.io/badge/Live%20Preview-WordPress%20Playground-3858e9?style=flat-square&logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/JosephGabito/kilobyte-image-optimizer/main/.wordpress-org/blueprints/blueprint.json)
[![Tested up to WordPress](https://img.shields.io/wordpress/plugin/tested/kilobyte-image-optimizer?style=flat-square&label=WordPress)](https://wordpress.org/plugins/kilobyte-image-optimizer/)
[![Required PHP](https://img.shields.io/wordpress/plugin/required-php/kilobyte-image-optimizer?style=flat-square&logo=php&logoColor=white&label=PHP)](https://wordpress.org/plugins/kilobyte-image-optimizer/)
[![Downloads](https://img.shields.io/wordpress/plugin/dt/kilobyte-image-optimizer?style=flat-square&label=Downloads)](https://wordpress.org/plugins/kilobyte-image-optimizer/)
[![License](https://img.shields.io/github/license/JosephGabito/kilobyte-image-optimizer?style=flat-square&label=License)](LICENSE)

Make smaller **[WebP](https://developers.google.com/speed/webp)** and **[AVIF](https://developer.mozilla.org/en-US/docs/Web/Media/Guides/Formats/Image_types#avif_image)** copies of your WordPress images while *keeping your original Media Library files and existing URLs untouched*. **Preview the result on one photo** in your browser before optimizing your library, or try the interactive demo at **[kilobyte.gabi.to](https://kilobyte.gabi.to/)**. Unlimited and free, with *no account, API key, or monthly credit limits*.

Kilobyte also ships with native **[WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/)** support *(WordPress 6.9+)*.

WordPress Abilities let plugins expose specific functionality to an **[MCP](https://modelcontextprotocol.io/) server**, such as **[Uncanny Automator MCP](https://wordpress.org/plugins/uncanny-automator/)** or similar MCP plugins, so your AI assistant can automatically *inspect savings, optimize or restore images, regenerate thumbnails, and update preferences* out of the box.

## Screenshots

<p align="center">
  <img src=".wordpress-org/screenshot-1.jpg" alt="Live side-by-side Quality preview comparing Original and WebP output" width="100%" />
  <br />
  <sub><strong>Quality preview</strong> · Compare WebP, AVIF, or original output side by side in your browser before saving.</sub>
</p>

<table>
  <tr>
    <td width="50%" valign="top">
      <img src=".wordpress-org/screenshot-2.jpg" alt="Selective optimize tab with chosen Media Library photos" width="100%" />
      <br />
      <sub><strong>Selective optimize</strong> · Pick specific Media Library photos without running a full bulk job.</sub>
    </td>
    <td width="50%" valign="top">
      <img src=".wordpress-org/screenshot-5.jpg" alt="Media Library attachment details with untouched original and one-click restore" width="100%" />
      <br />
      <sub><strong>Media Library integration</strong> · Keeps your original file intact with savings and one-click restore right in the modal.</sub>
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <img src=".wordpress-org/screenshot-3.jpg" alt="Bulk optimization summary with transfer savings and per-image breakdown" width="100%" />
      <br />
      <sub><strong>Bulk optimization</strong> · Track transfer savings, copy a summary, regenerate thumbnails, or restore all in one click.</sub>
    </td>
    <td width="50%" valign="top">
      <img src=".wordpress-org/screenshot-4.jpg" alt="Preference tab with format, compression presets, quality slider, and resize controls" width="100%" />
      <br />
      <sub><strong>Preference &amp; fine-tuning</strong> · Native WordPress controls for format, compression, resize limits, auto alt text, and decoding hints.</sub>
    </td>
  </tr>
</table>

## Key features

- **Unlimited free image optimization:** Optimize your entire Media Library with *no account, API key, monthly quota, or per-thumbnail limits*.
- **Agent-ready WordPress Abilities API & MCP support:** Registers seven WordPress abilities under the `kilobyte-image-optimizer` category (`kilobyte/get-stats`, `kilobyte/get-queue`, `kilobyte/get-settings`, `kilobyte/update-settings`, `kilobyte/optimize-image`, `kilobyte/restore-image`, `kilobyte/regenerate-thumbnails`) on WordPress 6.9+ / 7.x with JSON schemas, semantic annotations, and per-attachment capability checks. Works automatically with **[Uncanny Automator MCP](https://wordpress.org/plugins/uncanny-automator/)** and similar WordPress MCP plugins.
- **Live quality preview & single optimize:** Compare compression *side by side* on any photo from your Media Library or computer, switch formats (WebP, AVIF, Original) and compression presets (High, Balanced, Smallest) live, and save immediately with **Optimize now** (or **Download copy** for local files).
- **Selective optimize:** Multi-select specific Media Library photos *(paginated for large selections)* to optimize a single post, landing page, or gallery without running a full bulk job.
- **Bulk image optimizer:** Process your entire Media Library with *resumable pagination, live ETA, reload protection, per-reason skip totals, links to unavailable entries, and one-click retry* for failed items.
- **Automatic image compression on upload:** Creates smaller copies right after WordPress saves the original, using your browser for Gutenberg and Media Library uploads, or scheduled background tasks for classic, mobile app, and server-handled REST uploads.
- **WebP & AVIF converter + static GIF support:** Converts JPEG, PNG, BMP, and static GIF images to WebP or AVIF while *automatically preserving animated GIFs, APNGs, and animated WebPs*.
- **Browser Web Worker + automatic server fallback:** Runs compression off the main thread in your browser *(no `exec()` required)*, and falls back automatically to WordPress's built-in image editor (**Imagick** or **GD**) when needed.
- **Non-destructive originals & one-click restore:** Keeps original Media Library files and existing URLs intact, serves smaller copies via [`<picture>`](https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/picture) with automatic fallback, and lets you restore a single image or choose **Restore all**.
- **Cache-safe 24-hour deferred cleanup:** Keeps retired copies available for *at least 24 hours* before eligible copies are removed in bounded background batches while preserving recovery state across reinstalls.
- **Resize optimized copies:** Scale down oversized copies to your maximum width and height while leaving the uploaded original untouched.
- **Regenerate thumbnails:** Rebuild registered WordPress thumbnail sizes without breaking existing thumbnail or original URLs.
- **Copyable optimization summary:** Copy a plain-text summary of optimized counts, estimated transfer savings, and active format in one click.
- **Optional auto alt text & image decoding hints:** Fill empty alt text cleanly from attachment filenames on upload and add browser image decoding hints.
- **Per-attachment permissions & multisite awareness:** Enforces WordPress `edit_post` permissions across REST routes and Media Library controls and scopes network lifecycle operations per site.
- **[WP-CLI](https://wordpress.org/cli/) & developer hooks:**
  - `wp kilobyte optimize`, `wp kilobyte restore`, `wp kilobyte stats` (`--reconcile`), `wp kilobyte purge-backups`
  - `kilobyte_io_settings`: filter active plugin preferences
  - `kilobyte_io_should_optimize` / `kilobyte_io_after_optimize`: control and react to optimization
  - `kilobyte_io_variant_filename`: customize generated variant filenames
  - `kilobyte_io_variant_changed`: action fired on create/restore for page-cache invalidation

## Our philosophy

We care about your experience because *we are WordPress users too*. Kilobyte is designed to feel like a natural part of WordPress:

- **Ready right after activation:** Works out of the box with *no account, API key, or monthly quota*. Processing runs in your browser or with your WordPress site's built-in image editor.
- **Calm admin experience:** Keeps your WordPress dashboard clean and focused on your media, with simple controls and all settings available out of the box.
- **Native WordPress interface:** Built with [`@wordpress/components`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-components/) across four focused tabs: **Preview**, **Selective optimize**, **Bulk optimize**, and **Preference**.
- **Safe by default:** Leaves your original Media Library files and existing image URLs untouched, so you can test freely and restore anytime.

## Requirements

- WordPress **6.6** or later (tested up to **7.1**)
- PHP **7.4** or later
- Node.js **24.15+** (for building frontend assets from source)

## Installation

1. Upload the `kilobyte-image-optimizer` folder to `/wp-content/plugins/` (or install the release ZIP via **Plugins → Add New → Upload Plugin**).
2. Activate **Kilobyte Image Optimizer**.
3. Open **Media → Optimization**, test any photo in **Preview**, optimize chosen files in **Selective optimize**, or run **Bulk optimize**.

## Development and contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for architecture notes, coding standards, local setup, and pull request guidelines.

```bash
# Install dev dependencies
composer install
npm ci

# Watch and rebuild frontend assets
npm run start

# Auto-fix PHP formatting (WordPress-VIP-Go + WordPress-Extra)
npm run lint:php:fix

# Run the PHPUnit integration tests (see tests/README.md for the test database setup)
composer test
composer test:coverage

# Run PHPCS (uncached), TypeScript check, production build, and headless Plugin Check
npm run verify

# Build a clean WordPress.org release ZIP in dist/
npm run package
```

## License

Licensed under the [GNU General Public License v2.0 or later](LICENSE) (`GPL-2.0-or-later`).
