import { __ } from '@wordpress/i18n';

export function optimizationSkip( status?: string ): { subtitle: string; action?: string } {
	switch ( status ) {
		case 'already_optimal':
			return {
				subtitle: __( 'The new copy was no smaller than the original.', 'kilobyte-image-optimizer' ),
				action: __( 'Try a different format or quality in Preview if you want a smaller copy.', 'kilobyte-image-optimizer' ),
			};
		case 'already_processed':
		case 'preserved_existing':
			return {
				subtitle: __( 'This image already has an optimized copy.', 'kilobyte-image-optimizer' ),
				action: __( 'Restore the original if you want to make a new copy.', 'kilobyte-image-optimizer' ),
			};
		case 'animated':
			return { subtitle: __( 'The original was kept to preserve its animation.', 'kilobyte-image-optimizer' ) };
		case 'original_format_kept':
			return {
				subtitle: __( 'The Original format preference keeps this GIF unchanged.', 'kilobyte-image-optimizer' ),
				action: __( 'Choose WebP or AVIF to make a smaller copy of a still GIF.', 'kilobyte-image-optimizer' ),
			};
		case 'gif_canvas_kept':
			return {
				subtitle: __( 'Your site kept this GIF because it could not preserve the full image in a smaller copy.', 'kilobyte-image-optimizer' ),
				action: __( 'Try the image in Preview to see if your browser can make a copy.', 'kilobyte-image-optimizer' ),
			};
		case 'excluded':
			return {
				subtitle: __( 'A rule on this site excludes this image from optimization.', 'kilobyte-image-optimizer' ),
				action: __( 'Ask your site administrator if this image should be included.', 'kilobyte-image-optimizer' ),
			};
		case 'unavailable':
			return {
				subtitle: __( 'WordPress could not open these files.', 'kilobyte-image-optimizer' ),
				action: __( 'Check your media storage or recover the files, then try again.', 'kilobyte-image-optimizer' ),
			};
		default:
			return { subtitle: __( 'WordPress kept the original and did not return a reason.', 'kilobyte-image-optimizer' ) };
	}
}
