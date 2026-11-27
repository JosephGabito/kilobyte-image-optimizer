=== Kilobyte Image Optimizer – WebP & AVIF Converter ===
Contributors: dunhakdis
Tags: image-optimization, compress-images, optimize-images, webp, avif
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free WebP & AVIF image optimizer and converter. Compress images locally with no account, API key, or monthly quota. Keep your originals.

== Description ==

**Smaller images. Your originals stay.**

Kilobyte is a free image optimizer for WordPress. Serve smaller WebP or AVIF copies so visitors download less image data. Your original Media Library files and existing links stay intact.

**No signup. No API key. No monthly quota.** Processing runs in your browser or on your WordPress site, without an external compression service.

**Start with one photo.** Open **Media > Optimization**, choose **Preview**, and compare the quality and file size side by side. Adjust the settings, then choose **Optimize now** when you like the result. You can also preview a file from your computer and download the optimized copy.

Want to see it before installing? [Try the interactive browser demo](https://kilobyte.gabi.to/).

= Optimize the images you choose =

* **A few images:** Use **Selective optimize** for the photos in a post, landing page, or gallery.
* **Your existing library:** **Bulk optimize** works through supported images with progress, estimated time remaining, and per-image results. Continue stopped runs or retry failures.
* **New uploads:** Create optimized copies automatically after WordPress saves the originals, using your browser or scheduled background processing.

= Choose the quality. Keep the original. =

Compare presets, fine-tune quality, and set maximum dimensions for optimized copies. Find the balance that looks right on your own photos.

Supported WordPress images use optimized copies with the original as fallback. Use **Restore** or **Restore all** to switch back to available originals. Keeping both versions uses additional storage.

= Work inside WordPress =

Built with native WordPress components, with no setup wizard and no upgrade banners. Use familiar WordPress controls to manage images, regenerate thumbnails, and copy an optimization summary. Optional settings fill empty alt text from filenames and add browser image-decoding hints.

= Give your AI assistant image tools =

On WordPress 6.9+, connect your assistant through [Uncanny Automator MCP](https://wordpress.org/plugins/uncanny-automator/) or another integration that supports the [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/).

Your assistant can check savings, find queued images, read or update preferences, optimize or restore individual images, and regenerate thumbnails.

For example, ask: **"Show me my image optimization stats"** or **"Optimize this image and show me the result."**

Kilobyte performs the compression on your WordPress server and checks the connected user's permissions. AI and MCP services may have separate accounts or usage limits.

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

== Screenshots ==

1. Preview: compare your original and optimized image, check file sizes, and choose Optimize now.
2. Selective optimize: choose the Media Library photos you want to process.
3. Bulk optimize: progress, results, estimated transfer savings, copyable summary, thumbnails, and Restore all.
4. Preference: format, quality, dimensions, filename-based alt text, and decoding hints.
5. Attachment details: original file, optimization savings, and Restore original.

== Development ==

Source code and issue tracking: [GitHub](https://github.com/JosephGabito/kilobyte-image-optimizer).

**WP-CLI:** `wp kilobyte optimize` defaults to 100 images per run (`--limit` to change), plus `wp kilobyte restore`, `wp kilobyte stats` (`--reconcile` optional), and `wp kilobyte purge-backups`.

**WordPress Abilities:** `kilobyte/get-stats`, `kilobyte/get-queue`, `kilobyte/get-settings`, `kilobyte/update-settings`, `kilobyte/optimize-image`, `kilobyte/restore-image`, and `kilobyte/regenerate-thumbnails`. Abilities define JSON schemas and permission checks. Image actions operate on one attachment at a time.

**Hooks:** `kilobyte_io_settings`, `kilobyte_io_should_optimize`, `kilobyte_io_after_optimize`, `kilobyte_io_variant_filename`, and `kilobyte_io_variant_changed`. The last passes the attachment ID for cache integrations.

Build the JavaScript and TypeScript in `src-js/` with Node.js 24.15+ (24.x). Run `npm ci` and `npm run build` in the plugin directory; scripts and dependency manifests go to `build/`.

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
