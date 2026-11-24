<?php
/**
 * What a visitor's browser receives for images in posts, featured images and theme templates.
 *
 * @package KilobyteImageOptimizer\Tests
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Tests fire WordPress core hooks the way WordPress does.
// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions -- Tests change files in the test uploads folder to simulate other tools.

namespace Kilobyte\ImageOptimizer\Tests\Integration\Presentation;

use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpAttachmentRepository;
use Kilobyte\ImageOptimizer\Presentation\Hooks\FrontendImageDelivery;
use Kilobyte\ImageOptimizer\Tests\Support\Images;
use Kilobyte\ImageOptimizer\Tests\Support\TestCase;

final class FrontendImageDeliveryTest extends TestCase {

	/**
	 * Changes to the uploads location made by a test.
	 *
	 * @var callable[]
	 */
	private array $upload_dir_filters = array();

	/**
	 * Symbolic links a test made in the uploads folder.
	 *
	 * @var string[]
	 */
	private array $links = array();

	public function set_up(): void {
		parent::set_up();
		$this->sign_in_as( 'administrator' );
		// The copy the plugin hooked in when it loaded remembers files for the whole PHP process.
		// Each test starts its own page views instead, so a change on disk is always seen.
		$this->remove_delivery_hooks();
	}

	public function tear_down(): void {
		// The base class empties the real uploads folder, so it must see the real location.
		foreach ( $this->upload_dir_filters as $filter ) {
			remove_filter( 'upload_dir', $filter );
		}
		// The base class cannot remove a link whose target it deleted first.
		foreach ( $this->links as $link ) {
			unlink( $link ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		parent::tear_down();
	}

	public function test_an_image_block_offers_webp_copies_at_each_width_and_keeps_the_original_as_fallback(): void {
		$id    = $this->optimized_jpeg();
		$block = $this->image_block( $id );

		$html = $this->render( $block );

		$offered = $this->offered( $html );
		$this->assertSame( array( '640w', '300w' ), array_values( $offered ) );
		foreach ( array_keys( $offered ) as $url ) {
			$this->assertSame( 'image/webp', wp_get_image_mime( $this->path_of( $url ) ) );
		}
		$this->assertMatchesRegularExpression( '~<picture><source type="image/webp" srcset="[^"]+" sizes="[^"]*640px"><img [^>]*src="' . preg_quote( (string) wp_get_attachment_url( $id ), '~' ) . '"[^>]*></picture>~', $html );
	}

	public function test_an_image_without_srcset_is_offered_one_copy(): void {
		$id  = $this->optimized_jpeg();
		$src = (string) wp_get_attachment_image_url( $id, 'thumbnail' );

		$html = $this->render( '<img src="' . $src . '" class="wp-image-' . $id . ' size-thumbnail" alt="">' );

		$offered = $this->offered( $html );
		$this->assertCount( 1, $offered );
		$this->assertSame( '', current( $offered ) );
		$this->assertSame( array( 150, 150 ), array_slice( (array) wp_getimagesize( $this->path_of( (string) key( $offered ) ) ), 0, 2 ) );
	}

	public function test_density_descriptors_are_kept_on_the_copies(): void {
		$id     = $this->optimized_jpeg();
		$medium = (string) wp_get_attachment_image_url( $id, 'medium' );
		$full   = (string) wp_get_attachment_url( $id );

		$html = $this->deliver( '<img src="' . $medium . '" srcset="' . $medium . ' 1x, ' . $full . ' 2x" class="wp-image-' . $id . '">' );

		$offered = $this->offered( $html );
		$this->assertSame( array( '1x', '2x' ), array_values( $offered ) );
		$this->assertSame( array( 300, 640 ), array_map( fn ( $url ) => wp_getimagesize( $this->path_of( $url ) )[0], array_keys( $offered ) ) );
	}

	public function test_two_srcset_entries_for_the_same_file_become_one_copy(): void {
		$id   = $this->optimized_jpeg();
		$full = (string) wp_get_attachment_url( $id );

		$html = $this->deliver( '<img src="' . $full . '" srcset="' . $full . ' 640w, ' . $full . ' 1280w" class="wp-image-' . $id . '">' );

		$this->assertSame( array( '640w' ), array_values( $this->offered( $html ) ) );
	}

	/**
	 * @dataProvider srcsets_without_a_full_set_of_copies
	 *
	 * @param string $srcset Image srcset, with {full} and {uploads} standing for the attachment URL and the uploads URL.
	 */
	public function test_an_image_is_left_alone_unless_every_srcset_entry_has_a_copy( string $srcset ): void {
		$id      = $this->optimized_jpeg();
		$full    = (string) wp_get_attachment_url( $id );
		$uploads = wp_get_upload_dir()['baseurl'];
		$img     = '<img src="' . $full . '" srcset="' . strtr(
			$srcset,
			array(
				'{full}'    => $full,
				'{uploads}' => $uploads,
			) 
		) . '" class="wp-image-' . $id . '">';

		$this->assertSame( $img, $this->deliver( $img ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function srcsets_without_a_full_set_of_copies(): array {
		return array(
			'an entry on another site'            => array( '{full} 640w, https://cdn.example.net/photo-1280.jpg 1280w' ),
			'an uploads file with no copy'        => array( '{full} 640w, {uploads}/2001/01/unrelated.jpg 1280w' ),
			'an entry with an unknown descriptor' => array( '{full} 640h' ),
		);
	}

	public function test_images_inside_pictures_scripts_templates_and_comments_are_left_alone(): void {
		$id    = $this->optimized_jpeg();
		$img   = '<img src="' . wp_get_attachment_url( $id ) . '" class="wp-image-' . $id . '" alt="">';
		$html  = "<p>{$img}</p>\n";
		$html .= "<picture><source srcset=\"/art.avif\" type=\"image/avif\">{$img}</picture>\n";
		$html .= "<script>document.write('{$img}');</script>\n";
		$html .= "<template>{$img}</template>\n";
		$html .= "<textarea>{$img}</textarea>\n";
		$html .= "<style>/* {$img} */</style>\n";
		$html .= "<!-- {$img} -->\n";
		$html .= "<div title='{$img}'>quoted</div>\n";
		$html .= "<!-- an unclosed comment {$img}";

		$delivered = $this->deliver( $html );

		$this->assertSame( 1, substr_count( $delivered, '<source type="image/webp"' ), 'Only the standalone image may get a copy.' );
		$this->assertStringStartsWith( '<p><picture><source type="image/webp" srcset="', $delivered );
		$this->assertSame( $html, preg_replace( '~<picture><source type="image/webp" srcset="[^"]+">(.*?)</picture>~', '$1', $delivered ) );
	}

	public function test_a_lightbox_image_with_a_load_handler_is_left_alone(): void {
		$id  = $this->optimized_jpeg();
		$img = '<img src="' . wp_get_attachment_url( $id ) . '" class="wp-image-' . $id . '" data-wp-on--load="actions.handleLoad">';

		$this->assertSame( $img, $this->deliver( $img ) );
	}

	public function test_an_image_hosted_on_another_site_is_left_alone(): void {
		$id  = $this->optimized_jpeg();
		$img = '<img src="https://other.example.net/wp-content/uploads/photo.jpg" class="wp-image-' . $id . '">';

		$this->assertSame( $img, $this->deliver( $img ) );
	}

	public function test_an_image_without_an_attachment_id_is_left_alone(): void {
		$id  = $this->optimized_jpeg();
		$img = '<img src="' . wp_get_attachment_url( $id ) . '" class="photo">';

		$this->assertSame( $img, $this->deliver( $img ) );
	}

	public function test_an_attachment_whose_address_points_away_from_uploads_is_left_alone(): void {
		$id  = $this->optimized_jpeg();
		$img = '<img src="' . wp_get_attachment_url( $id ) . '" class="wp-image-' . $id . '">';
		add_filter( 'wp_get_attachment_url', static fn () => 'https://cdn.example.net/photo.jpg' );

		$this->assertSame( $img, $this->deliver( $img ) );
	}

	public function test_an_image_without_a_copy_is_left_alone_while_other_images_have_one(): void {
		$this->optimized_jpeg();
		$plain = $this->upload( Images::jpeg( 640, 480, 'plain.jpg' ) );
		$img   = '<img src="' . wp_get_attachment_url( $plain ) . '" class="wp-image-' . $plain . '">';

		$this->assertSame( $img, $this->deliver( $img ) );
	}

	public function test_pages_are_unchanged_while_no_image_has_a_copy(): void {
		$id    = $this->upload( Images::jpeg() );
		$block = $this->image_block( $id );

		$this->assertSame( $block, $this->deliver( $block ) );
		$this->assertSame( 'No images here.', $this->deliver( 'No images here.' ) );
	}

	public function test_images_are_left_alone_when_uploads_are_served_from_another_host(): void {
		$id  = $this->optimized_jpeg();
		$img = '<img src="' . wp_get_attachment_url( $id ) . '" class="wp-image-' . $id . '">';
		$this->change_uploads_location(
			static function ( array $dir ): array {
				$dir['baseurl'] = 'https://media.example.net/uploads';
				return $dir;
			}
		);

		$this->assertSame( $img, $this->deliver( $img ) );
	}

	public function test_nothing_is_offered_when_the_uploads_folder_cannot_be_found(): void {
		$id = $this->optimized_jpeg();
		$this->change_uploads_location(
			static function ( array $dir ): array {
				$dir['basedir'] .= '-missing';
				return $dir;
			}
		);

		$this->assertSame( array(), $this->copies( $id ) );
	}

	public function test_theme_images_and_featured_images_are_offered_the_copy_once(): void {
		$id   = $this->optimized_jpeg();
		$post = self::factory()->post->create();
		set_post_thumbnail( $post, $id );
		$this->page_view();

		$theme_image    = wp_get_attachment_image( $id, 'thumbnail' );
		$featured_image = get_the_post_thumbnail( $post, 'thumbnail' );

		$this->assertCount( 1, $this->offered( $theme_image ) );
		$this->assertSame( 1, substr_count( $featured_image, '<picture>' ) );
		$this->assertCount( 1, $this->offered( $featured_image ) );
	}

	public function test_a_featured_image_from_a_theme_filter_is_offered_the_copy(): void {
		$id   = $this->optimized_jpeg();
		$post = self::factory()->post->create();
		$img  = '<img src="' . wp_get_attachment_image_url( $id, 'thumbnail' ) . '" alt="">';
		$this->page_view();

		$html = apply_filters( 'post_thumbnail_html', $img, $post, $id, 'thumbnail', '' );

		$this->assertCount( 1, $this->offered( $html ) );
	}

	public function test_a_size_whose_copy_was_deleted_is_no_longer_offered(): void {
		$id     = $this->optimized_jpeg();
		$medium = preg_grep( '/photo-300x225\./', $this->copies( $id, 'medium' ) );
		$this->assertCount( 1, $medium );

		unlink( $this->path_of( current( $medium ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertSame( array(), $this->copies( $id, 'medium' ) );
		$this->assertSame( array(), $this->copies( $id, 'full' ), 'The full size lists the medium file in its srcset.' );
		$this->assertCount( 1, $this->copies( $id, 'thumbnail' ) );
	}

	public function test_a_copy_replaced_by_a_symbolic_link_is_not_offered(): void {
		$id    = $this->optimized_jpeg();
		$thumb = $this->path_of( $this->copies( $id, 'thumbnail' )[0] );
		$moved = dirname( $thumb ) . '/moved.webp';
		rename( $thumb, $moved ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		symlink( $moved, $thumb );
		$this->links[] = $thumb;

		$this->assertSame( array(), $this->copies( $id, 'thumbnail' ) );
	}

	public function test_a_copy_is_still_offered_after_the_original_goes_missing(): void {
		$id = $this->optimized_jpeg();

		unlink( (string) get_attached_file( $id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertCount( 2, $this->copies( $id ) );
	}

	public function test_a_gif_copy_is_not_offered_once_the_original_goes_missing(): void {
		$id = $this->optimized_gif();
		$this->assertNotEmpty( $this->copies( $id, 'thumbnail' ) );

		unlink( (string) get_attached_file( $id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertSame( array(), $this->copies( $id, 'thumbnail' ) );
	}

	public function test_a_copy_is_not_offered_once_the_attachment_points_to_a_missing_file(): void {
		$id = $this->optimized_jpeg();

		update_attached_file( $id, dirname( (string) get_attached_file( $id ) ) . '/renamed.jpg' );

		$this->assertSame( array(), $this->copies( $id ) );
	}

	public function test_a_copy_is_not_offered_after_the_original_is_replaced(): void {
		$id       = $this->optimized_jpeg();
		$original = (string) get_attached_file( $id );

		copy( Images::jpeg( 640, 480, 'replacement.jpg' ), $original . '.new' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		rename( $original . '.new', $original ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename

		$this->assertSame( array(), $this->copies( $id ) );
		$this->assertSame( array(), $this->copies( $id, 'thumbnail' ), 'Thumbnails of a replaced original are no longer trusted either.' );
	}

	public function test_a_gif_copy_is_not_offered_when_the_gif_changed_within_the_same_second(): void {
		$id = $this->optimized_gif();
		$this->assertNotEmpty( $this->copies( $id, 'thumbnail' ) );
		// Another media tool rewrote the GIF without changing its size or timestamps.
		// Only the stored checksum can show that, so the record's checksum is changed instead.
		$record                    = get_post_meta( $id, WpAttachmentRepository::META_KEY, true );
		$record['source_revision'] = explode( '|', $record['source_revision'] )[0] . '|' . hash( 'sha256', 'another gif' );
		update_post_meta( $id, WpAttachmentRepository::META_KEY, $record );

		$this->assertSame( array(), $this->copies( $id, 'thumbnail' ) );
	}

	public function test_a_regenerated_thumbnail_stops_only_its_own_copy(): void {
		$id     = $this->optimized_jpeg();
		$medium = $this->path_of( (string) wp_get_attachment_image_url( $id, 'medium' ) );

		touch( $medium, time() + 120 );

		$this->assertSame( array(), $this->copies( $id, 'medium' ) );
		$this->assertCount( 1, $this->copies( $id, 'thumbnail' ) );
	}

	public function test_a_copy_recorded_by_an_older_version_is_offered_while_the_original_is_unchanged(): void {
		$id = $this->optimized_jpeg();
		$this->make_record_legacy( $id );

		$this->assertCount( 2, $this->copies( $id ) );
	}

	public function test_a_copy_recorded_by_an_older_version_is_not_offered_after_the_original_changes_size(): void {
		$id = $this->optimized_jpeg();
		$this->make_record_legacy( $id );

		copy( Images::jpeg( 320, 240, 'smaller.jpg' ), (string) get_attached_file( $id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy

		$this->assertSame( array(), $this->copies( $id ) );
	}

	public function test_a_copy_recorded_by_an_older_version_is_not_offered_after_the_original_is_saved_again(): void {
		$id = $this->optimized_jpeg();
		$this->make_record_legacy( $id );

		touch( (string) get_attached_file( $id ), time() + 120 );

		$this->assertSame( array(), $this->copies( $id ) );
	}

	public function test_a_copy_recorded_without_widths_is_offered_at_its_measured_width(): void {
		$id                       = $this->optimized_jpeg();
		$record                   = get_post_meta( $id, WpAttachmentRepository::META_KEY, true );
		$record['variant_widths'] = array();
		update_post_meta( $id, WpAttachmentRepository::META_KEY, $record );

		$html = $this->deliver( $this->plain_image( $id ) );

		$this->assertSame( array( '640w', '300w' ), array_values( $this->offered( $html ) ) );
	}

	public function test_a_copy_recorded_without_widths_is_not_offered_when_it_cannot_be_measured(): void {
		$id                       = $this->optimized_jpeg();
		$record                   = get_post_meta( $id, WpAttachmentRepository::META_KEY, true );
		$record['variant_widths'] = array();
		update_post_meta( $id, WpAttachmentRepository::META_KEY, $record );
		file_put_contents( $this->path_of( current( preg_grep( '/photo-300x225\./', $this->copies( $id, 'medium' ) ) ) ), 'not an image' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->assertSame( array(), $this->offered( $this->deliver( $this->plain_image( $id ) ) ) );
	}

	public function test_content_is_not_wrapped_in_wp_admin(): void {
		$id = $this->optimized_jpeg();
		$this->remove_delivery_hooks();
		set_current_screen( 'edit-post' );

		( new FrontendImageDelivery( new WpAttachmentRepository() ) )->register();

		$this->assertStringNotContainsString( '<picture>', apply_filters( 'the_content', $this->image_block( $id ) ) );
	}

	/**
	 * Changes where WordPress says uploads live, until the test ends.
	 *
	 * @param callable $filter `upload_dir` filter.
	 * @return void
	 */
	private function change_uploads_location( callable $filter ): void {
		$this->upload_dir_filters[] = $filter;
		add_filter( 'upload_dir', $filter );
	}

	/**
	 * Uploads a JPEG and makes its WebP copy on the server.
	 *
	 * @return int Attachment ID.
	 */
	private function optimized_jpeg(): int {
		$id = $this->upload( Images::jpeg() );
		$this->assertRestOk( $this->optimize_on_server( $id ) );
		return $id;
	}

	/**
	 * Uploads a still GIF and makes its WebP copy on the server.
	 *
	 * @return int Attachment ID.
	 */
	private function optimized_gif(): int {
		// At WordPress's default WebP quality, GD's copy of this small-palette fixture is a little larger
		// than the GIF. WordPress applies this filter when it converts formats, so it sets the quality here.
		add_filter( 'wp_editor_set_quality', static fn () => 50 );
		$id     = $this->upload( Images::still_gif() );
		$result = $this->assertRestOk( $this->optimize_on_server( $id ) );
		$this->assertFalse( $result['skipped'], 'The GIF fixture got no WebP copy: ' . wp_json_encode( $result ) );
		return $id;
	}

	/**
	 * Block editor markup for a full-size image.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	private function image_block( int $id ): string {
		$url = wp_get_attachment_url( $id );
		return "<!-- wp:image {\"id\":{$id},\"sizeSlug\":\"full\"} -->\n<figure class=\"wp-block-image size-full\"><img src=\"{$url}\" alt=\"\" class=\"wp-image-{$id}\"/></figure>\n<!-- /wp:image -->";
	}

	/**
	 * Runs post content through the plugin's delivery alone, in a fresh page view.
	 *
	 * Other content filters are removed so the result shows exactly what the plugin changed.
	 *
	 * @param string $html Post content.
	 * @return string
	 */
	private function deliver( string $html ): string {
		remove_all_filters( 'the_content' );
		$this->page_view();
		return apply_filters( 'the_content', $html );
	}

	/**
	 * Renders post content the way a theme does, in a fresh page view.
	 *
	 * @param string $html Post content.
	 * @return string
	 */
	private function render( string $html ): string {
		$this->page_view();
		return apply_filters( 'the_content', $html );
	}

	/**
	 * The copy URLs a theme image offers, in a fresh page view.
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $size Registered image size.
	 * @return string[]
	 */
	private function copies( int $id, string $size = 'full' ): array {
		$this->page_view();
		return array_keys( $this->offered( wp_get_attachment_image( $id, $size ) ) );
	}

	/**
	 * Theme markup for an image, with its srcset, as post content would hold it.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	private function plain_image( int $id ): string {
		$this->remove_delivery_hooks();
		return wp_get_attachment_image( $id, 'full', false, array( 'class' => "wp-image-{$id}" ) );
	}

	/**
	 * Starts a new front-end page view: delivery hooked in the way the plugin does it, with nothing remembered.
	 *
	 * @return void
	 */
	private function page_view(): void {
		$this->remove_delivery_hooks();
		( new FrontendImageDelivery( new WpAttachmentRepository() ) )->register();
	}

	/**
	 * The copies offered by the first `<picture>` source in the HTML.
	 *
	 * @param string $html Rendered HTML.
	 * @return array<string, string> Copy URL => descriptor ('' when there is none).
	 */
	private function offered( string $html ): array {
		if ( ! preg_match( '~<source type="image/webp" srcset="([^"]+)"~', $html, $match ) ) {
			return array();
		}
		$offered = array();
		foreach ( explode( ',', html_entity_decode( $match[1] ) ) as $candidate ) {
			$parts                = preg_split( '/\s+/', trim( $candidate ) );
			$offered[ $parts[0] ] = $parts[1] ?? '';
		}
		return $offered;
	}

	/**
	 * Rewrites the record as plugin versions before source fingerprints stored it.
	 *
	 * @param int $id Attachment ID.
	 * @return void
	 */
	private function make_record_legacy( int $id ): void {
		$record = get_post_meta( $id, WpAttachmentRepository::META_KEY, true );
		unset( $record['source_revision'], $record['thumbnail_stamps'] );
		update_post_meta( $id, WpAttachmentRepository::META_KEY, $record );
	}

	/**
	 * Removes the delivery hooks the plugin added when it loaded.
	 *
	 * @return void
	 */
	private function remove_delivery_hooks(): void {
		global $wp_filter;
		foreach ( array( 'the_content', 'wp_get_attachment_image', 'post_thumbnail_html' ) as $hook ) {
			foreach ( $wp_filter[ $hook ]->callbacks ?? array() as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof FrontendImageDelivery ) {
						remove_filter( $hook, $callback['function'], $priority );
					}
				}
			}
		}
	}
}
