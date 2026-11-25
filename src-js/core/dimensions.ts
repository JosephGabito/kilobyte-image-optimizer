import type { CoreResizeOptions } from './types';

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

export function computeTargetDimensions(
	srcWidth: number,
	srcHeight: number,
	options?: CoreResizeOptions,
	fallbackMaxWidth = 0,
	fallbackMaxHeight = 0
): { width: number; height: number; resized: boolean } {
	if ( ! options ) {
		return computeScaledDimensions( srcWidth, srcHeight, fallbackMaxWidth, fallbackMaxHeight );
	}

	const preventUpscale = options.preventUpscale !== false;
	const keepAspect = options.maintainAspectRatio !== false;
	const reqWidth = typeof options.width === 'number' && Number.isFinite( options.width ) && options.width > 0
		? Math.round( options.width )
		: 0;
	const reqHeight = typeof options.height === 'number' && Number.isFinite( options.height ) && options.height > 0
		? Math.round( options.height )
		: 0;

	if ( ! reqWidth && ! reqHeight ) {
		const maxW = options.maxWidth ?? fallbackMaxWidth;
		const maxH = options.maxHeight ?? fallbackMaxHeight;
		return computeScaledDimensions( srcWidth, srcHeight, maxW, maxH );
	}

	let width = srcWidth;
	let height = srcHeight;

	if ( keepAspect ) {
		if ( reqWidth && reqHeight ) {
			const scale = Math.min( reqWidth / srcWidth, reqHeight / srcHeight );
			const boundedScale = preventUpscale ? Math.min( 1, scale ) : scale;
			width = Math.round( srcWidth * boundedScale );
			height = Math.round( srcHeight * boundedScale );
		} else if ( reqWidth ) {
			const scale = reqWidth / srcWidth;
			const boundedScale = preventUpscale ? Math.min( 1, scale ) : scale;
			width = Math.round( srcWidth * boundedScale );
			height = Math.round( srcHeight * boundedScale );
		} else if ( reqHeight ) {
			const scale = reqHeight / srcHeight;
			const boundedScale = preventUpscale ? Math.min( 1, scale ) : scale;
			width = Math.round( srcWidth * boundedScale );
			height = Math.round( srcHeight * boundedScale );
		}
	} else {
		width = reqWidth ? ( preventUpscale ? Math.min( srcWidth, reqWidth ) : reqWidth ) : srcWidth;
		height = reqHeight ? ( preventUpscale ? Math.min( srcHeight, reqHeight ) : reqHeight ) : srcHeight;
	}

	const finalWidth = Math.max( 1, width );
	const finalHeight = Math.max( 1, height );

	return {
		width: finalWidth,
		height: finalHeight,
		resized: finalWidth !== srcWidth || finalHeight !== srcHeight,
	};
}

export function clampQuality( quality: number ): number {
	return Math.max( 0.4, Math.min( 0.95, Number( quality || 82 ) / 100 ) );
}

export function clampConcurrency( concurrency: number ): number {
	return Math.max( 1, Math.min( 6, Number( concurrency || 3 ) ) );
}
