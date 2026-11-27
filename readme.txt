=== Kilobyte – Image Optimization, Compress Images & Optimize Images, AVIF & WebP Converter ===
Contributors: dunhakdis
Tags: compress-images, optimize-images, webp, avif
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free image optimizer, WebP converter & AVIF converter. Compress images and optimize images locally for fast image optimization.

== Description ==

**Smaller WebP and AVIF images. Your originals stay.**

Kilobyte is a free image optimizer, WebP converter, and AVIF converter for WordPress. Compress images and optimize images directly in your browser or on your server so visitors load lightweight WebP and AVIF files while your original Media Library files and links stay intact.

**No signup. No API key. No monthly quota.** Unlike cloud image optimization services, this free image optimizer converts JPEG, PNG, BMP, and still GIF photos to WebP and AVIF locally—with zero external uploads, no `exec()`, and no `gifsicle`, `jpegtran`, or `optipng`. Try the [interactive WebP and AVIF browser demo](https://kilobyte.gabi.to/).

= Features =

* **Visual Preview:** Compare original photos against WebP and AVIF quality and file size side by side before saving, or preview any local image from your computer.
* **Selective & Bulk Image Optimization:** Compress images for a single post, or bulk optimize images across your Media Library to WebP and AVIF with progress, ETA, and retry controls.
* **Automatic Upload Conversion:** Run WebP converter and AVIF converter jobs automatically on new uploads in your browser or via WordPress background tasks.
* **Native WordPress UI & Safe Restore:** Built with native WordPress components—no setup wizard, no upgrade banners, animated GIF/WebP/AVIF protection, and 1-click **Restore** or **Restore all**.
* **WordPress Power Tools:** Resize dimensions, regenerate thumbnails, auto-fill empty alt text, WP-CLI commands, Multisite support, and WordPress 6.9+ [Abilities API](https://developer.wordpress.org/apis/abilities-api/) for AI assistants and MCP.

== Installation ==

1. Install and activate **Kilobyte Image Optimizer**.
2. Open **Media > Optimization > Preview** and choose a supported photo.
3. Compare the result, adjust quality if needed, and choose **Optimize now**.
4. Set **Preference** for bulk and future uploads. Use **Selective optimize** or **Bulk optimize** to process more images.

== Frequently Asked Questions ==

= Is Kilobyte really free and unlimited? =

Yes. All features are included, with no compression subscription, monthly image allowance, or per-thumbnail quota. Unlimited refers to image count: individual files must be within 24 MiB and 40 megapixels. Your host or browser may impose lower limits.

= Where are my images processed? =

Browser compression uses Canvas and a Web Worker when supported. WordPress's image editor handles server compression and thumbnail copies.

Local files in **Preview** stay on your device. Media Library images are fetched from your configured media URLs, possibly through a CDN, and optimized copies are saved to WordPress. No external compression service is used.

= What does my site need? =

WordPress 6.6+, PHP 7.4+, and a working WordPress image editor (**GD** or **Imagick**) for saving Media Library copies, server compression, and thumbnail regeneration. Browser compression requires no `exec()` and no extra server binaries such as `gifsicle`, `jpegtran`, or `optipng`. The optional **EXIF** extension helps read camera metadata.

Check **Tools > Site Health > Info > Media Handling** for your editor and supported formats.

= Which images can I optimize? =

JPEG, PNG, BMP, still GIF, and supported WebP and AVIF files. Animated GIFs, APNGs, animated WebPs, and AVIF sequences stay unchanged. Unsupported, unreadable, or oversized files may be skipped; results explain why.

= What if AVIF is not supported? =

Kilobyte can fall back to WebP or use your site's image editor, which may retain the source format. Selecting AVIF does not guarantee AVIF output; support depends on your browser, host, and site settings.

= Will compression affect image quality? =

It can. Compare the result in **Preview**; savings vary by image, format, quality, and dimensions. Preview settings apply to that image, while **Preference** controls bulk and future uploads.

If WordPress cannot save the preview, **Optimize with site settings** may produce a different format or quality.

= Will my original files or image links change? =

Original files and URLs stay in place. Supported post, attachment, and featured images use copies through `<picture>`, with original fallback.

Existing `<picture>` markup, CSS backgrounds, changed CDN URLs, and unsupported output are left alone. Responsive images keep their original output if suitable copies are unavailable. Copies use additional disk space.

= What do the savings numbers mean? =

They compare original main image files with optimized copies, excluding thumbnails. This estimates potential transfer savings; it does not measure freed disk space or page speed.

= Does Kilobyte work with a CDN or offloaded media? =

Uploads must be available locally. Changed CDN or offload URLs keep their existing output. Browser jobs can use server fallback when the file remains on your site.

= Do I need to keep the browser tab open? =

Yes, for browser bulk jobs. Stopped runs can be continued and failed images retried. Background upload optimization relies on WordPress scheduled tasks.

For a queued upload, choose **Optimize now** in the Media Library. Ask your host to check scheduled tasks if jobs remain overdue.

= How does restore work with cached pages? =

Restore switches new page output to verified originals. Retired copies remain for at least 24 hours; clear affected pages if your cache retains them longer.

Single-site cleanup removes eligible copies in background batches after file checks pass. Inconclusive checks leave copies in place. Automatic retirement cleanup is disabled on multisite. Legacy original backups are preserved; after archiving them, `wp kilobyte purge-backups` clears their old records.

= What happens when I uninstall Kilobyte? =

New pages use original Media Library files. Preferences and scheduled work are removed; generated copies and recovery records remain for cached pages.

For cleanup on a single-site install, use **Restore all** and keep Kilobyte active while scheduled tasks remove eligible copies. Pending cleanup resumes after reinstalling and verifying file references. Very large libraries may defer cleanup and recheck daily.

= Can I connect an AI assistant or MCP server? =

Yes. On WordPress 6.9+, connect your assistant through [Uncanny Automator MCP](https://wordpress.org/plugins/uncanny-automator/) or another integration that supports the [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/).

Your assistant can check savings, find queued images, read or update preferences, optimize or restore individual images, and regenerate thumbnails—for example: *"Show me my image optimization stats"* or *"Optimize this image and show me the result."* Kilobyte runs compression on your WordPress server and checks the connected user's permissions. AI and MCP services may have separate accounts or usage limits.

= What developer tools (WP-CLI, Abilities, hooks, source) are included? =

Source code and issue tracking live on [GitHub](https://github.com/JosephGabito/kilobyte-image-optimizer).

* **WP-CLI:** `wp kilobyte optimize` defaults to 100 images per run (`--limit` to change), plus `wp kilobyte restore`, `wp kilobyte stats` (`--reconcile` optional), and `wp kilobyte purge-backups`.
* **WordPress Abilities:** `kilobyte/get-stats`, `kilobyte/get-queue`, `kilobyte/get-settings`, `kilobyte/update-settings`, `kilobyte/optimize-image`, `kilobyte/restore-image`, and `kilobyte/regenerate-thumbnails`. Abilities define JSON schemas and permission checks and operate on one attachment at a time.
* **Hooks:** `kilobyte_io_settings`, `kilobyte_io_should_optimize`, `kilobyte_io_after_optimize`, `kilobyte_io_variant_filename`, and `kilobyte_io_variant_changed` (passes the attachment ID for cache integrations).
* **Build from source:** Build the JavaScript and TypeScript in `src-js/` with Node.js 24.15+ (24.x). Run `npm ci` and `npm run build` in the plugin directory; compiled scripts and dependency manifests are written to `build/`.

== Screenshots ==

1. Preview: compare your original and optimized image, check file sizes, and choose Optimize now.
2. Selective optimize: choose the Media Library photos you want to process.
3. Bulk optimize: progress, results, estimated transfer savings, copyable summary, thumbnails, and Restore all.
4. Preference: format, quality, dimensions, filename-based alt text, and decoding hints.
5. Attachment details: original file, optimization savings, and Restore original.

== Changelog ==

= 1.1.0 =

* Added WordPress Abilities API support on WordPress 6.9 and newer. AI assistants and MCP servers can read optimization stats, list the images still waiting, read and update settings, optimize or restore an image, and regenerate thumbnails. Every ability checks the user's WordPress permissions.

= 1.0.1 =

* Fixed server-side optimization and thumbnail generation ignoring the quality preference when converting formats.
* Fixed Delete backups failing when legacy backup directories use numeric year or month names (such as `2024/01`).
* Fixed failed legacy backup restores leaving behind an uncleaned full-size copy when `_wp_attached_file` cannot be saved.
* Fixed failed attachment deletions holding image and stats locks for the rest of the request.
* Fixed auto alt text keeping a trailing `1` when WordPress appends `-1` to filenames ending in dimensions or `-scaled`.

= 1.0.0 =

* Initial release with image previews, browser compression, server fallback, bulk optimization, restore, and thumbnail regeneration.
