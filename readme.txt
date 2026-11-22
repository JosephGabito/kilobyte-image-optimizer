=== Kilobyte Image Optimizer ===
Contributors: josephgabito
Tags: image-optimization, compress-images, optimize-images, webp, avif
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Compress images to WebP or AVIF, keep existing media links, and regenerate thumbnails. Processing happens in your browser or on your WordPress site.

== Description ==

Preview compression on one of your images before processing the Media Library. Kilobyte keeps the file that WordPress receives and can create a smaller copy in supported browsers. Classic uploads, uploads from other clients, and bulk jobs can use the image editor on your WordPress site when needed. When WordPress processes uploads in the browser, Kilobyte waits for WordPress to finish before creating its copy.

Bulk optimization keeps the original Media Library file and saves a smaller copy beside it. Supported images in posts and featured images can use that copy. The original remains available as a fallback. To go back, choose **Restore all** in **Media > Optimization**, or restore an individual image from the Media Library. Transfer savings estimate how much smaller the generated copies are. Responsive markup uses the original files when Kilobyte cannot provide a suitable set of copies. Keeping the originals uses additional storage.

You can also regenerate thumbnails. Optional preferences fill empty image descriptions from filenames and add image decoding hints. WP-CLI supports `wp kilobyte optimize`, `wp kilobyte restore`, `wp kilobyte stats`, and `wp kilobyte purge-backups`. Restore keeps original backups from earlier builds in the uploads folder. The purge command clears references after that folder has been archived or emptied.

== Installation ==

1. Install and activate the plugin.
2. Open **Media > Optimization**.
3. Preview an image, then choose **Optimize remaining images** when you are ready.

== Frequently Asked Questions ==

= Where are my images processed? =

Processing uses your browser or WordPress site. Kilobyte uses no compression service and requires no account or API key. When your site serves media through a CDN, the browser requests the configured media URLs.

= What if my browser or site does not support AVIF? =

Kilobyte can fall back to WebP or use your WordPress site's image editor. Format availability depends on your browser, server, and site settings. Oversized, unreadable, or unsupported images may be skipped.

= Can I optimize GIFs? =

Kilobyte can make WebP or AVIF copies of still GIFs. Animated GIFs keep their animation. Choose Original in the format settings to keep GIF files as they are.

= What happens to images served from a CDN or offloaded storage? =

Kilobyte works with locally available uploads. When a CDN or offload plugin changes an attachment's URL, Kilobyte leaves its existing output in place. Browser bulk jobs can use the server fallback if an image is available locally.

= Do I need to clear my site cache after restoring images? =

Restore switches new page requests to the original files. Kilobyte keeps the old copies available for at least 24 hours so cached pages can still display them. WordPress removes the copies in the background once it can read the originals. Clear affected cached pages after restoring if your cache can keep them for more than a day. Cache integrations can listen to `kilobyte_io_variant_changed`, which passes the attachment ID.

= What happens when I uninstall Kilobyte? =

New pages load the files in your Media Library. Uninstall removes your Kilobyte preferences and pauses scheduled cleanup. Generated copies and their recovery records stay in place, so older cached pages can still use those copies. If you want WordPress to remove generated copies, use **Restore all** before uninstalling and leave Kilobyte active while WordPress runs its scheduled cleanup. If you reinstall Kilobyte, cleanup for restored images resumes as each site loads.

== Development ==

The editable JavaScript and TypeScript are in `src-js/`. With Node.js 24.15 or later in the Node.js 24 series, run `npm ci` and `npm run build` from the plugin directory. The build writes the browser scripts and dependency manifests to `build/`.

== Changelog ==

= 1.0.0 =
* Initial release with image previews, browser compression, server fallback, bulk optimization, restore, and thumbnail regeneration.
