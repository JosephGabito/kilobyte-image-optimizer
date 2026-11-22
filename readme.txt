=== Kilobyte Image Optimizer ===
Contributors: josephgabito
Tags: image-optimization, compress-images, optimize-images, webp, avif
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Unlimited free image optimization. Compress, resize, and optimize images to WebP or AVIF in your browser or on your site, and regenerate thumbnails.

== Description ==

**Kilobyte Image Optimizer** is an unlimited, free image optimization plugin for WordPress with **no account, no API key, and no monthly credit limits**. Compress images, resize optimized copies, and convert photos to **WebP** and **AVIF** directly in your browser or on your WordPress site.

= Why Optimize Your Images? =

Large photos can slow page loads and increase how much mobile data visitors use. Smaller WebP or AVIF copies can reduce transfer size and help your site load faster. Preview the results on your own photos to find a balance between quality and file size.

= Our Design Philosophy =

We care about your experience because we are WordPress users too. Kilobyte is designed to feel like a natural part of WordPress:

* **Ready right after activation** with no account, API key, or monthly quota. Processing runs in your browser or with your WordPress site's built-in image editor.
* **Calm admin experience** that keeps your dashboard focused on your media, with clean controls and no promotional banners or locked settings.
* **Native WordPress interface** uses standard WordPress components on the optimization screen, with **Preview**, **Selective optimize**, **Bulk optimize**, and **Preference** tabs.
* **Safe by default** means WordPress keeps your original Media Library files and existing image URLs. Restore switches delivery back to the original when WordPress can verify it.

= Key Features =

* **Automatic image compression on upload** makes smaller copies of supported images after WordPress saves the original. Scheduled tasks handle classic, mobile app, and server-managed REST uploads. They can also finish an interrupted Kilobyte browser job after the original is saved. WordPress's native browser uploader queues optimization after it finishes preparing the media.
* **Unlimited Free Image Optimization:** Optimize images across your entire Media Library with zero monthly quotas or thumbnail credit caps.
* **Live quality preview & single optimize** lets you compare compression side by side on a supported photo from your Media Library or computer. Switch formats and presets live, then use **Optimize now** for a Media Library image or **Download copy** for a local file.
* **Selective optimize:** Pick specific Media Library photos to optimize a single post, landing page, or gallery without running a full bulk job.
* **Bulk image optimization** shows a live ETA and lets you resume stopped jobs or retry failed images while the tab stays open.
* **WebP converter & AVIF converter:** Convert JPEG, PNG, BMP, and static GIF images to WebP or AVIF while automatically detecting and leaving animated GIFs, APNGs, and animated WebPs untouched.
* **Browser-first processing + server fallback** uses your browser when possible and WordPress's image editor (Imagick or GD) when needed. Uploads that WordPress handles on its own can queue a copy for background optimization after saving the original.
* **Non-destructive originals and one-click restore** keep Media Library files in place and serve smaller copies with fallback to the original. Restore a single image or use **Restore all**.
* **Resize optimized copies** to your maximum width and height. WordPress keeps the uploaded original.
* **Regenerate thumbnails** to rebuild registered WordPress image sizes and keep existing thumbnail URLs working.
* **Optional auto alt text and image decoding hints** can fill empty alt text from filenames on upload and hint to browsers how to decode photos.
* **WP-CLI & developer hooks:** Supports `wp kilobyte optimize`, `wp kilobyte restore`, `wp kilobyte stats`, and `wp kilobyte purge-backups`, plus `kilobyte_io_variant_filename` for custom variant naming and `kilobyte_io_variant_changed` for page-cache invalidation.

= How Originals & Delivery Work =

Supported images in posts and featured images serve the smaller WebP or AVIF copy using `<picture>` fallback while the original remains available for older browsers and direct links. Transfer savings estimate how much smaller the generated copies are. Responsive markup uses the original files when Kilobyte cannot provide a suitable set of copies, and keeping originals uses additional storage. Restore preserves original backups from earlier builds. After you archive those backups, `wp kilobyte purge-backups` can clear their old records.

== Installation ==

1. Install and activate the plugin.
2. Open **Media > Optimization**.
3. Test a supported photo in **Preview**, optimize chosen files in **Selective optimize**, or run **Bulk optimize** when you are ready.

== Frequently Asked Questions ==

= Where are my images processed? =

Processing uses your browser or WordPress site. Kilobyte uses no compression service and requires no account or API key. When your site serves media through a CDN, the browser requests the configured media URLs.

= What if my browser or site does not support AVIF? =

Kilobyte can fall back to WebP or use your WordPress site's image editor. Format availability depends on your browser, server, and site settings. Oversized, unreadable, or unsupported images may be skipped.

= Are there file size limits? =

Kilobyte can process images up to 24 MiB and 40 megapixels. Your host or browser may have lower upload or memory limits. If optimization skips a photo, the results explain why and what to try next.

= Can I optimize GIFs? =

Kilobyte can make WebP or AVIF copies of still GIFs. Animated GIFs keep their animation. Choose Original in the format settings to keep GIF files as they are.

= What happens to images served from a CDN or offloaded storage? =

Kilobyte works with locally available uploads. When a CDN or offload plugin changes an attachment's URL, Kilobyte leaves its existing output in place. Browser bulk jobs can use the server fallback if an image is available locally.

= Do I need to clear my site cache after restoring images? =

Restore switches new page requests to the original files. Kilobyte keeps the old copies available for at least 24 hours so cached pages can still display them. On single-site installs, WordPress can remove a copy in the background if the original still matches the file used to make it and no Media Library item uses the copy. Copies stay on disk when those checks are inconclusive, including on multisite installs with shared storage. Clear affected cached pages after restoring if your cache can keep them for more than a day. Cache integrations can listen to `kilobyte_io_variant_changed`, which passes the attachment ID.

= What happens when I uninstall Kilobyte? =

New pages load the files in your Media Library. Uninstall removes your Kilobyte preferences and pauses scheduled optimization and cleanup. Generated copies and recovery records stay in place, so older cached pages can still use those copies. On a single-site install, use **Restore all** and leave Kilobyte active while WordPress runs scheduled cleanup if you want it to remove eligible copies. If you reinstall Kilobyte, cleanup for restored images resumes as each site loads.

== Development ==

The editable JavaScript and TypeScript are in `src-js/`. With Node.js 24.15 or later in the Node.js 24 series, run `npm ci` and `npm run build` from the plugin directory. The build writes the browser scripts and dependency manifests to `build/`.

== Changelog ==

= 1.0.0 =
* Initial release with image previews, browser compression, server fallback, bulk optimization, restore, and thumbnail regeneration.
