import { __, sprintf } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';
import { formatBytes } from '../../../domain/format';
import type { LibraryStats } from '../../../domain/types';

interface GuidanceBannerProps {
	stats: LibraryStats | null;
	autoEnabled: boolean;
}

export function GuidanceBanner( { stats, autoEnabled }: GuidanceBannerProps ) {
	if ( ! stats ) {
		return null;
	}

	if ( stats.total_images === 0 ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ __(
					'No images in your Media Library yet.',
					'kilobyte-image-optimizer'
				) }
			</Notice>
		);
	}

	if ( stats.remaining_count > 0 && stats.optimized_count === 0 ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ sprintf(
					/* translators: %s: count of unoptimized images */
					__(
						'You have %s images ready to optimize. Try one in the Preview tab first to check the quality.',
						'kilobyte-image-optimizer'
					),
					stats.remaining_count.toLocaleString()
				) }
			</Notice>
		);
	}

	if ( stats.remaining_count === 0 ) {
		return (
			<Notice status="success" isDismissible={ false }>
				{ autoEnabled
					? sprintf(
							/* translators: 1: image count, 2: formatted bytes saved, 3: percent saved */
							__(
								'All %1$s images are processed (%2$s smaller to load, %3$s%% reduction). New uploads will be optimized when supported.',
								'kilobyte-image-optimizer'
							),
							stats.total_images.toLocaleString(),
							formatBytes( stats.total_saved ),
							String( stats.saved_percent )
					  )
					: sprintf(
							/* translators: 1: image count, 2: formatted bytes saved */
							__( 'All %1$s images are processed (%2$s smaller to load).', 'kilobyte-image-optimizer' ),
							stats.total_images.toLocaleString(),
							formatBytes( stats.total_saved )
					  ) }
			</Notice>
		);
	}

	return null;
}
