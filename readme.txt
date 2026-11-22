=== Kilobyte Image Optimizer ===
Contributors: dunhakdis
Tags: image-optimization, compress-images, optimize-images, webp, avif
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Unlimited free image optimization. Compress, resize, and optimize images to WebP or AVIF in your browser or on your site, and regenerate thumbnails.

== Description ==

**Kilobyte Image Optimizer** makes smaller **WebP** and **AVIF** copies of your Media Library images. Processing runs in your browser or on your WordPress site.

= Why Optimize Your Images? =

Large photos can slow page loads and increase how much mobile data visitors use. Smaller WebP or AVIF copies can reduce transfer size and help your site load faster. Preview the results on your own photos to find a balance between quality and file size.

= Our Design Philosophy =

We care about your experience because we are WordPress users too. Kilobyte is designed to feel like a natural part of WordPress:

* **Ready right after activation** with no account, API key, or monthly quota. Processing runs in your browser or with your WordPress site's built-in image editor.
* **Calm admin experience** that keeps your dashboard focused on your media, with clean controls and no promotional banners or locked settings.
* **Native WordPress interface** uses standard WordPress components on the optimization screen, with **Preview**, **Selective optimize**, **Bulk optimize**, and **Preference** tabs.
* **Safe by default** means WordPress keeps your original Media Library files and existing image URLs. Restore switches delivery back to the original when WordPress can verify it.

= Key Features =

* **Unlimited free image optimization** across your entire Media Library with no account, API key, monthly quota, or thumbnail credit cap.
* **Automatic image compression on upload** makes smaller copies after WordPress saves the original. Scheduled tasks handle classic, mobile app, and server-managed REST uploads and can resume an interrupted browser job. Kilobyte queues native uploads after WordPress prepares the image.
* **Live quality preview & single optimize** lets you compare compression side by side on a supported photo from your Media Library or computer. Switch formats and presets live, then use **Optimize now** for a Media Library image or **Download copy** for a local file.
* **Selective optimize** lets you multi-select specific Media Library photos (paginated for large selections) to optimize a single post, landing page, or gallery without running a full bulk job.
* **Bulk image optimization** processes your Media Library with a live ETA, reload protection, per-reason skip totals, links to unavailable Media Library entries, and resume/retry for stopped or failed images while the tab stays open.
* **WebP & AVIF conversion + static GIF support** converts JPEG, PNG, BMP, and static GIF images to WebP or AVIF while automatically detecting and preserving animated GIFs, APNGs, and animated WebPs.
* **Browser-first processing + server fallback** runs compression off the main thread in a browser Web Worker when possible (no `exec()` required) and falls back to WordPress's built-in image editor (Imagick or GD) when needed.
* **Non-destructive originals & one-click restore** keep Media Library files and existing URLs in place, serve smaller copies via `<picture>` with fallback to the original, and let you restore a single image (from the Media Library list or media modal) or use **Restore all**.
* **Deferred cleanup after restore** keeps old copies available for at least 24 hours for pages already in cache. WordPress cleans up eligible copies in small batches and keeps cleanup records across reinstalls.
* **Resize optimized copies** to your maximum width and height.
* **Regenerate thumbnails** to rebuild registered WordPress image sizes. Identical thumbnails reuse the existing files, and previous thumbnail URLs stay available.
* **Copyable optimization summary** lets you copy a plain-text report of optimized counts, estimated transfer savings, and active format in one click.
* **Optional auto alt text & image decoding hints** can fill empty alt text from filenames on upload and hint to browsers how to decode photos.
* **Per-attachment permissions & multisite awareness** enforces WordPress `edit_post` permissions across REST routes and Media Library controls and scopes network lifecycle operations cleanly per site.
* **WP-CLI & developer hooks** support `wp kilobyte optimize`, `wp kilobyte restore`, `wp kilobyte stats` (`--reconcile`), and `wp kilobyte purge-backups`, plus `kilobyte_io_settings`, `kilobyte_io_should_optimize`, `kilobyte_io_after_optimize`, `kilobyte_io_variant_filename`, and `kilobyte_io_variant_changed`.

= How Originals & Delivery Work =

Supported images in posts and featured images serve the smaller WebP or AVIF copy using `<picture>` fallback while the original remains available for older browsers and direct links. Transfer savings estimate how much smaller the generated copies are. Responsive markup uses the original files when Kilobyte cannot provide a suitable set of copies, and keeping originals uses additional storage. Restore preserves original backups from earlier builds. After you archive those backups, `wp kilobyte purge-backups` can clear their old records.

== Installation ==

1. Install and activate the plugin.
2. Open **Media > Optimization**.
3. Test a supported photo in **Preview**, optimize chosen files in **Selective optimize**, or run **Bulk optimize** when you are ready.

== Frequently Asked Questions ==

= Where are my images processed? =

Processing uses your browser or WordPress site. Kilobyte uses no compression service and requires no account or API key. When your site serves media through a CDN, the browser requests the configured media URLs.

= How does Kilobyte compress images, and which PHP extensions do I need? =

Kilobyte uses JavaScript and the browser's built-in Canvas API to resize images and encode copies. When supported, it runs this in a background Web Worker using `OffscreenCanvas`. Files you pick from your computer in **Preview** stay on your device.

The plugin requires WordPress 6.6 or newer and PHP 7.4 or newer. Saving optimized copies to the Media Library, compressing on the server, and regenerating thumbnails require a working WordPress image editor. On most hosts, either the **GD** or **Imagick** PHP extension provides it. WebP and AVIF are available on your site when WordPress allows the format and your host's image editor supports it. The optional **EXIF** extension helps WordPress read camera metadata such as JPEG orientation.

Check **Tools > Site Health > Info > Media Handling** to see your site's active image editor and supported formats.

= What if my browser or site does not support AVIF? =

Kilobyte can fall back to WebP or use your WordPress site's image editor. Format availability depends on your browser, server, and site settings. Oversized, unreadable, or unsupported images may be skipped.

= Are there file size limits? =

Kilobyte can process images up to 24 MiB and 40 megapixels. Your host or browser may have lower upload or memory limits. If optimization skips a photo, the results explain why and what to try next.

= What if background optimization is overdue? =

Choose **Optimize now** on the image in the Media Library to process that file directly. If other uploads stay queued, ask your host to check that WordPress scheduled tasks are running.

= Can I optimize GIFs? =

Kilobyte can make WebP or AVIF copies of still GIFs. Animated GIFs keep their animation. Choose Original in the format settings to keep GIF files as they are.

= What happens to images served from a CDN or offloaded storage? =

Kilobyte works with locally available uploads. When a CDN or offload plugin changes an attachment's URL, Kilobyte leaves its existing output in place. Browser bulk jobs can use the server fallback if an image is available locally.

= Do I need to clear my site cache after restoring images? =

Restore switches new page requests to the original files. Kilobyte keeps the old copies available for at least 24 hours so cached pages can still display them. On single-site installs, WordPress can remove a copy in the background if the original still matches the file used to make it and no Media Library item uses the copy. Copies stay on disk when those checks are inconclusive, including on multisite installs with shared storage. Clear affected cached pages after restoring if your cache can keep them for more than a day. Cache integrations can listen to `kilobyte_io_variant_changed`, which passes the attachment ID.

= What happens when I uninstall Kilobyte? =

New pages load the files in your Media Library. Uninstall removes your Kilobyte preferences and pauses scheduled optimization and cleanup. Generated copies and recovery records stay in place, so older cached pages can still use those copies. On a single-site install, use **Restore all** and leave Kilobyte active while WordPress runs scheduled cleanup if you want it to remove eligible copies. Stored cleanup work resumes when you reinstall Kilobyte and WordPress can run its scheduled tasks and verify the file references. Very large libraries keep the files and recheck daily whether cleanup can run safely.

== Development ==

Source code and issue tracking are hosted on [GitHub](https://github.com/JosephGabito/kilobyte-image-optimizer).

The editable JavaScript and TypeScript are in `src-js/`. With Node.js 24.15 or later in the Node.js 24 series, run `npm ci` and `npm run build` from the plugin directory. The build writes the browser scripts and dependency manifests to `build/`.

== Changelog ==

= 1.0.0 =
* Initial release with image previews, browser compression, server fallback, bulk optimization, restore, and thumbnail regeneration.
