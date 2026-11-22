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

export function updateRestoredAttachment( id: number, message: string, refresh = false ): void {
	for ( const button of restoreControls( id ) ) {
		const cell = button.closest( 'td' );
		if ( cell ) {
			cell.replaceChildren( message );
		} else {
			button.replaceWith( document.createTextNode( message ) );
		}
	}

	// The open picker may create another sidebar from this model before fetch returns.
	const attachment = getWpMedia()?.model?.Attachments?.all?.get( id );
	if ( ! attachment ) {
		return;
	}
	const compat = attachment.get( 'compat' );
	if ( compat?.item ) {
		const snapshot = document.createElement( 'template' );
		snapshot.innerHTML = compat.item;
		const restoreButton = snapshot.content.querySelector< HTMLButtonElement >( '.kilobyte-restore-btn' );
		if ( restoreButton && Number( restoreButton.dataset.id ) === id ) {
			restoreButton.replaceWith( document.createTextNode( message ) );
			attachment.set( 'compat', { ...compat, item: snapshot.innerHTML } );
		}
	}
	if ( refresh ) {
		void Promise.resolve( attachment.fetch() ).catch( () => {} );
	}
}
