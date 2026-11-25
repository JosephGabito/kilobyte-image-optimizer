=== Kilobyte Image Optimizer ===
Contributors: dunhakdis
Tags: image-optimization, compress-images, optimize-images, webp, avif
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Unlimited free WebP & AVIF optimization in your browser or on site. Keeps original files & links intact, with WordPress Abilities for AI agents.

== Description ==

**Kilobyte Image Optimizer** makes smaller **[WebP](https://developers.google.com/speed/webp)** and **[AVIF](https://developer.mozilla.org/en-US/docs/Web/Media/Guides/Formats/Image_types#avif_image)** copies of your WordPress images while *keeping your original Media Library files and existing links untouched*. **Preview the result on one photo** before optimizing your library. Processing runs right in your browser or on your WordPress site with *no account, no API key, and no monthly quota*.

Kilobyte also ships with native **[WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/)** support *(WordPress 6.9+)*.

WordPress Abilities let plugins expose specific functionality to an **[MCP](https://modelcontextprotocol.io/) server**, such as **[Uncanny Automator MCP](https://wordpress.org/plugins/uncanny-automator/)** or similar MCP plugins, so your AI assistant can automatically *inspect savings, optimize or restore images, regenerate thumbnails, and update preferences* out of the box.

= Key features =

* **Unlimited free image optimization** across your entire Media Library with *no account, API key, monthly quota, or per-thumbnail limits*.
* **Agent-ready WordPress Abilities API & MCP support** registers seven WordPress abilities (`kilobyte/get-stats`, `kilobyte/get-queue`, `kilobyte/get-settings`, `kilobyte/update-settings`, `kilobyte/optimize-image`, `kilobyte/restore-image`, `kilobyte/regenerate-thumbnails`) on WordPress 6.9+ with JSON schemas and per-attachment capability checks. Works automatically with **[Uncanny Automator MCP](https://wordpress.org/plugins/uncanny-automator/)** and similar WordPress MCP plugins.
* **Live quality preview & single optimize** lets you compare compression *side by side* on any photo from your Media Library or computer. Switch formats and presets live, then click **Optimize now** (Media Library) or **Download copy** (local file).
* **Selective optimize** lets you multi-select specific Media Library photos *(paginated for large selections)* to optimize a single post, landing page, or gallery without running a full bulk job.
* **Bulk image optimization** processes your entire Media Library with a *live ETA, reload protection, per-reason skip totals, links to unavailable entries, and resume/retry* for stopped or failed images.
* **Automatic compression on upload** creates smaller copies right after WordPress saves the original, using your browser or scheduled background tasks for classic, mobile app, and REST uploads.
* **WebP & AVIF conversion + static GIF support** converts JPEG, PNG, BMP, and static GIF images to WebP or AVIF while *automatically preserving animated GIFs, APNGs, and animated WebPs*.
* **Browser-first processing + server fallback** runs off the main thread in a browser Web Worker *(no `exec()` required)* and falls back to WordPress's built-in image editor (**Imagick** or **GD**) when needed.
* **Non-destructive originals & one-click restore** keep original files and existing URLs intact, serve smaller copies via [`<picture>`](https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/picture) with automatic fallback, and let you restore a single image or click **Restore all**.
* **Deferred 24-hour cleanup after restore** keeps retired copies available for *at least 24 hours* for pages already in cache, then cleans up eligible copies in small background batches.
* **Resize optimized copies** to your maximum width and height while leaving uploaded originals untouched.
* **Regenerate thumbnails** to rebuild registered WordPress image sizes without breaking existing thumbnail or original URLs.
* **Copyable optimization summary** copies a plain-text report of optimized counts, estimated transfer savings, and active format in one click.
* **Optional auto alt text & image decoding hints** fill empty alt text from filenames on upload and hint to browsers how to decode photos.
* **Per-attachment permissions & multisite awareness** enforce WordPress `edit_post` permissions across REST routes and Media Library controls and scope network lifecycle operations per site.
* **[WP-CLI](https://wordpress.org/cli/) & developer hooks** support `wp kilobyte optimize`, `wp kilobyte restore`, `wp kilobyte stats` (`--reconcile`), and `wp kilobyte purge-backups`, plus `kilobyte_io_settings`, `kilobyte_io_should_optimize`, `kilobyte_io_after_optimize`, `kilobyte_io_variant_filename`, and `kilobyte_io_variant_changed`.

= Our philosophy =

We care about your experience because *we are WordPress users too*. Kilobyte is designed to feel like a natural part of WordPress:

* **Ready right after activation.** Works out of the box with *no account, API key, or monthly quota*. Processing runs in your browser or with your WordPress site's built-in image editor.
* **Calm admin experience.** Keeps your WordPress dashboard clean and focused on your media, with simple controls and all settings available out of the box.
* **Native WordPress interface.** Uses standard [WordPress components](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-components/) across four focused tabs: **Preview**, **Selective optimize**, **Bulk optimize**, and **Preference**.
* **Safe by default.** WordPress keeps your original Media Library files and existing image URLs untouched. **Restore** switches delivery back to the original whenever WordPress can verify it.

= Why optimize your images? =

Large photos slow page loads and burn through mobile data. Smaller **WebP** or **AVIF** copies reduce transfer size and help your site load faster. *Preview the results on your own photos* to choose the right balance between visual quality and file size.

= How originals and delivery work =

Supported images in posts and featured images serve the smaller WebP or AVIF copy using `<picture>` fallback while the original remains available for older browsers and direct links. Transfer savings estimate how much smaller the generated copies are. Responsive markup uses the original files when Kilobyte cannot provide a suitable set of copies, and keeping originals uses additional storage. Restore preserves original backups from earlier builds. After you archive those backups, `wp kilobyte purge-backups` can clear their old records.

== Installation ==

1. Install and activate the plugin.
2. Open **Media > Optimization**.
3. Test a supported photo in **Preview**, optimize chosen files in **Selective optimize**, or run **Bulk optimize** when you are ready.

== Frequently Asked Questions ==

= Where are my images processed? =

Processing uses your browser or WordPress site. Kilobyte uses no compression service and requires no account or API key. When your site serves media through a CDN, the browser requests the configured media URLs.

= How does Kilobyte compress images, and which PHP extensions do I need? =

Kilobyte uses JavaScript and the browser's built-in [Canvas API](https://developer.mozilla.org/en-US/docs/Web/API/Canvas_API) to resize images and encode copies. When supported, it runs this in a background Web Worker using `OffscreenCanvas`. Files you pick from your computer in **Preview** stay on your device.

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

== Screenshots ==

1. Live side-by-side Quality preview comparing the original image and its WebP copy in your browser, with one-click Optimize now.
2. Selective optimize lets you pick specific Media Library photos to optimize without running a full bulk job.
3. Bulk optimization shows total transfer savings, per-image results, a copyable summary, thumbnail regeneration, and Restore all.
4. Preference tab with WebP, AVIF, and Keep original formats, compression presets, fine-tuned quality, max dimensions, auto alt text, and image decoding hints.
5. Media Library attachment details showing the untouched original file, optimization savings, and one-click Restore original.

== Development ==

Source code and issue tracking are hosted on [GitHub](https://github.com/JosephGabito/kilobyte-image-optimizer).

The editable JavaScript and TypeScript are in `src-js/`. With Node.js 24.15 or later in the Node.js 24 series, run `npm ci` and `npm run build` from the plugin directory. The build writes the browser scripts and dependency manifests to `build/`.

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
