export function formatEta(
	completed: number,
	total: number,
	elapsedMs: number
): string {
	const remaining = Math.max( 0, total - completed );
	if ( remaining === 0 ) {
		return 'Finishing up';
	}
	if ( completed < 1 || elapsedMs < 200 ) {
		return 'Estimating time left';
	}

	const avgMs = elapsedMs / completed;
	const remainingSeconds = Math.ceil( ( remaining * avgMs ) / 1000 );

	if ( remainingSeconds <= 10 ) {
		return 'Less than 10 seconds left';
	}
	if ( remainingSeconds < 60 ) {
		const rounded = Math.ceil( remainingSeconds / 5 ) * 5;
		return `About ${ rounded } seconds left`;
	}

	const minutes = Math.ceil( remainingSeconds / 60 );
	return minutes === 1 ? 'About 1 minute left' : `About ${ minutes } minutes left`;
}
