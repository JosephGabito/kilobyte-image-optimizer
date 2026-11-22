interface MediaAttachmentData {
	id?: number;
	title?: string;
	filename?: string;
	url?: string;
	mime?: string;
	filesizeInBytes?: number;
	width?: number;
	height?: number;
	sizes?: { thumbnail?: { url?: string } };
}

interface MediaModel {
	toJSON: () => MediaAttachmentData;
}

interface MediaAttachment extends MediaModel {
	get: ( key: 'compat' ) => { item?: string } | undefined;
	set: ( key: 'compat', value: { item: string } ) => void;
	fetch: () => PromiseLike< unknown >;
}

interface MediaFactory {
	( options: Record< string, unknown > ): {
		on: ( event: string, callback: () => void ) => void;
		state: () => {
			get: ( key: 'selection' ) => {
				first: () => MediaModel | undefined;
				models: MediaModel[];
			};
		};
		open: () => void;
	};
	model?: { Attachments?: { all?: { get: ( id: number ) => MediaAttachment | undefined } } };
}

declare global {
	interface Window {
		wp?: { media?: MediaFactory };
	}
}

export function getWpMedia() {
	return window.wp?.media;
}

export function restoreControls( id: number ) {
	return document.querySelectorAll< HTMLButtonElement >( `.kilobyte-restore-btn[data-id="${ id }"]` );
}

export function retryControls( id: number ) {
	return document.querySelectorAll< HTMLButtonElement >( `.kilobyte-optimize-retry-btn[data-id="${ id }"]` );
}

export function updateRestoredAttachment( id: number, message: string, refresh = false ): void {
	for ( const button of restoreControls( id ) ) {
		const cell = button.closest( 'td' );
		if ( cell ) {
			cell.replaceChildren( message );
		} else {
			button.replaceWith( document.createTextNode( message ) );
		}
	}

	updateMediaCompat( id, ( root ) => {
		const button = root.querySelector( `.kilobyte-restore-btn[data-id="${ id }"]` );
		button?.replaceWith( document.createTextNode( message ) );
		return Boolean( button );
	}, refresh );
}

export function updateOptimizedAttachment( id: number, message: string, restoreLabel?: string ): void {
	const update = ( root: ParentNode ) => {
		const rows = root.querySelectorAll( `.kilobyte-upload-status[data-id="${ id }"]` );
		for ( const row of rows ) {
			const status = document.createElement( 'span' );
			status.setAttribute( 'role', 'status' );
			status.textContent = message;
			row.replaceChildren( status );
			if ( restoreLabel ) {
				const restore = document.createElement( 'button' );
				restore.type = 'button';
				restore.className = 'button-link kilobyte-restore-btn';
				restore.dataset.id = String( id );
				restore.textContent = restoreLabel;
				row.append( ' ', restore );
			}
		}
		return rows.length > 0;
	};

	update( document );
	updateMediaCompat( id, update, true );
}

function updateMediaCompat( id: number, update: ( root: ParentNode ) => boolean, refresh: boolean ): void {
	// The open picker may create another sidebar from this model before fetch returns.
	const attachment = getWpMedia()?.model?.Attachments?.all?.get( id );
	if ( ! attachment ) {
		return;
	}
	const compat = attachment.get( 'compat' );
	if ( compat?.item ) {
		const snapshot = document.createElement( 'template' );
		snapshot.innerHTML = compat.item;
		if ( update( snapshot.content ) ) {
			attachment.set( 'compat', { ...compat, item: snapshot.innerHTML } );
		}
	}
	if ( refresh ) {
		void Promise.resolve( attachment.fetch() ).catch( () => {} );
	}
}
