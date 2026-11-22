import { formatBytes } from './format';
import type { LibraryStats } from './types';

export type QualityPresetId = 'high' | 'balanced' | 'compact';

export interface QualityPreset {
	id: QualityPresetId;
	quality: number;
}

export const QUALITY_PRESETS: Record< QualityPresetId, QualityPreset > = {
	high: { id: 'high', quality: 90 },
	balanced: { id: 'balanced', quality: 82 },
	compact: { id: 'compact', quality: 72 },
};

export function inferPresetFromQuality( quality: number ): QualityPresetId {
	if ( quality >= 87 ) {
		return 'high';
	}
	if ( quality <= 76 ) {
		return 'compact';
	}
	return 'balanced';
}

export function buildClientSummary( stats: LibraryStats, format: string ): string {
	return [
		`Image optimization summary`,
		`- Optimized: ${ stats.optimized_count.toLocaleString() } of ${ stats.total_images.toLocaleString() } images`,
		`- Estimated transfer savings: ${ formatBytes( stats.total_saved ) } (${ stats.saved_percent }% smaller on average)`,
		`- Default format: ${ format.toUpperCase() } (original URLs preserved)`,
	].join( '\n' );
}
