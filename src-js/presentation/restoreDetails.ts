import { __ } from '@wordpress/i18n';
import type { RestoreResponse } from '../domain/types';

export function restoreDetails( result: RestoreResponse ): string | undefined {
	const details = [];
	if ( result.thumbnails_ready === false ) {
		details.push( __( 'Some thumbnails are missing. Try Regenerate thumbnails in Media → Optimization, or use the full image if your host cannot make smaller sizes.', 'kilobyte-image-optimizer' ) );
	}
	if ( result.backup_removed === false ) {
		details.push( __( 'The legacy backup is still in the kilobyte-backups folder under uploads.', 'kilobyte-image-optimizer' ) );
	}
	if ( result.copy_retained ) {
		details.push( __( 'The old optimized copy is still saved on your site.', 'kilobyte-image-optimizer' ) );
	}
	return details.join( ' ' ) || undefined;
}
