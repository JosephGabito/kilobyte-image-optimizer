// Shared keyboard Escape handler + focus restoration for the mobile <details> navigation menu.
(() => {
	document.addEventListener( 'keydown', ( e ) => {
		if ( e.key !== 'Escape' ) {
			return;
		}
		const openMenus = document.querySelectorAll< HTMLDetailsElement >( '.kb-mobile-nav[open]' );
		openMenus.forEach( ( menu ) => {
			menu.removeAttribute( 'open' );
			const trigger = menu.querySelector< HTMLElement >( '.kb-mobile-nav-trigger' );
			trigger?.focus();
		} );
	} );
})();
