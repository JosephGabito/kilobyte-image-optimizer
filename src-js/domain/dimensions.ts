export function computeScaledDimensions(
	srcWidth: number,
	srcHeight: number,
	maxWidth: number,
	maxHeight: number
): { width: number; height: number; resized: boolean } {
	let width = srcWidth;
	let height = srcHeight;

	if ( maxWidth > 0 && width > maxWidth ) {
		height = Math.round( ( height * maxWidth ) / width );
		width = maxWidth;
	}

	if ( maxHeight > 0 && height > maxHeight ) {
		width = Math.round( ( width * maxHeight ) / height );
		height = maxHeight;
	}

	const finalWidth = Math.max( 1, width );
	const finalHeight = Math.max( 1, height );

	return {
		width: finalWidth,
		height: finalHeight,
		resized: finalWidth < srcWidth || finalHeight < srcHeight,
	};
}

export function clampQuality( quality: number ): number {
	return Math.max( 0.4, Math.min( 0.95, Number( quality || 82 ) / 100 ) );
}

export function clampConcurrency( concurrency: number ): number {
	return Math.max( 1, Math.min( 6, Number( concurrency || 3 ) ) );
}
