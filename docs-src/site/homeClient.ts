import './toolClient';

const TRANSPARENT_PIXEL = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

// Homepage-only progressive enhancements: screen switcher, native <dialog> lightbox, accessible code tabs.
(() => {
	// 1. Plugin screens switcher + native <dialog> modal.
	const screenTabs = Array.from( document.querySelectorAll< HTMLButtonElement >( '[data-screen-tab]' ) );
	const inlineTriggers = Array.from( document.querySelectorAll< HTMLButtonElement >( '[data-open-modal]' ) );
	const frameBtn = document.getElementById( 'kb-screen-frame' ) as HTMLButtonElement | null;
	const frameImg = document.getElementById( 'kb-screen-frame-img' ) as HTMLImageElement | null;
	const dialog = document.getElementById( 'kb-screen-dialog' ) as HTMLDialogElement | null;
	const dialogTitle = document.getElementById( 'kb-dialog-title' );
	const dialogImg = document.getElementById( 'kb-dialog-img' ) as HTMLImageElement | null;
	const dialogCaption = document.getElementById( 'kb-dialog-caption' );
	const dialogClose = document.getElementById( 'kb-dialog-close' );
	const mobileMedia = window.matchMedia( '(max-width: 959px)' );

	let activeTab: HTMLButtonElement | null = screenTabs[ 0 ] ?? null;
	let lastDialogTrigger: HTMLElement | null = null;

	const selectScreen = ( tab: HTMLButtonElement ) => {
		activeTab = tab;
		screenTabs.forEach( ( t ) => {
			t.setAttribute( 'aria-pressed', String( t === tab ) );
		} );
		if ( frameImg && frameBtn ) {
			frameImg.src = tab.dataset.src || '';
			frameImg.alt = tab.dataset.alt || '';
			frameImg.width = Number( tab.dataset.width || 1600 );
			frameImg.height = Number( tab.dataset.height || 900 );
			frameBtn.setAttribute( 'aria-label', `View the ${ tab.dataset.title || '' } screen full size` );
		}
	};

	const finishDialogClose = () => {
		document.body.style.overflow = '';
		if ( dialogImg ) {
			dialogImg.src = TRANSPARENT_PIXEL;
			dialogImg.alt = '';
		}
		if ( lastDialogTrigger && typeof lastDialogTrigger.focus === 'function' ) {
			lastDialogTrigger.focus();
		}
		lastDialogTrigger = null;
	};

	const closeDialog = () => {
		if ( ! dialog ) {
			return;
		}
		if ( typeof dialog.close === 'function' && dialog.open ) {
			dialog.close();
		} else {
			dialog.removeAttribute( 'open' );
			finishDialogClose();
		}
	};

	const openModalForTab = ( tab: HTMLButtonElement, trigger: HTMLElement ) => {
		if ( ! dialog || ! dialogImg ) {
			return;
		}
		lastDialogTrigger = trigger;
		if ( dialogTitle ) {
			dialogTitle.textContent = `${ tab.dataset.title || '' } screen`;
		}
		dialogImg.src = tab.dataset.src || '';
		dialogImg.alt = tab.dataset.alt || '';
		if ( dialogCaption ) {
			dialogCaption.textContent = tab.dataset.body || '';
		}
		document.body.style.overflow = 'hidden';
		if ( typeof dialog.showModal === 'function' ) {
			dialog.showModal();
		} else {
			dialog.setAttribute( 'open', '' );
		}
		dialogClose?.focus();
	};

	screenTabs.forEach( ( tab ) => {
		tab.addEventListener( 'click', () => {
			selectScreen( tab );
			if ( mobileMedia.matches ) {
				openModalForTab( tab, tab );
			}
		} );
	} );

	frameBtn?.addEventListener( 'click', () => {
		if ( activeTab ) {
			openModalForTab( activeTab, frameBtn );
		}
	} );

	inlineTriggers.forEach( ( btn ) => {
		btn.addEventListener( 'click', () => {
			const id = btn.dataset.openModal;
			const matching = screenTabs.find( ( t ) => t.dataset.screenTab === id );
			if ( matching ) {
				openModalForTab( matching, btn );
			}
		} );
	} );

	dialogClose?.addEventListener( 'click', closeDialog );
	dialog?.addEventListener( 'click', ( e ) => {
		if ( e.target === dialog ) {
			closeDialog();
		}
	} );
	dialog?.addEventListener( 'close', finishDialogClose );

	// 2. Developer code tabs (APG roving tabindex + Arrow/Home/End keyboard navigation) + copy button.
	const tabList = document.querySelector< HTMLElement >( '#kb-code-tabs [role="tablist"]' );
	const codeTabBtns = Array.from( document.querySelectorAll< HTMLButtonElement >( '[data-code-tab]' ) );
	const codePanels = Array.from( document.querySelectorAll< HTMLElement >( '[data-code-panel]' ) );
	const copyBtns = Array.from( document.querySelectorAll< HTMLButtonElement >( '[data-copy-code]' ) );
	const copyAnnouncer = document.getElementById( 'kb-copy-status' );

	const activateCodeTab = ( btn: HTMLButtonElement, moveFocus = false ) => {
		const target = btn.dataset.codeTab;
		codeTabBtns.forEach( ( b ) => {
			const selected = b === btn;
			b.setAttribute( 'aria-selected', String( selected ) );
			b.tabIndex = selected ? 0 : -1;
		} );
		codePanels.forEach( ( p ) => {
			p.hidden = p.dataset.codePanel !== target;
		} );
		if ( moveFocus ) {
			btn.focus();
		}
	};

	codeTabBtns.forEach( ( btn ) => {
		btn.addEventListener( 'click', () => activateCodeTab( btn ) );
	} );

	tabList?.addEventListener( 'keydown', ( e ) => {
		const currentIndex = codeTabBtns.indexOf( document.activeElement as HTMLButtonElement );
		if ( currentIndex < 0 ) {
			return;
		}
		let nextIndex = -1;
		if ( e.key === 'ArrowRight' ) {
			nextIndex = ( currentIndex + 1 ) % codeTabBtns.length;
		} else if ( e.key === 'ArrowLeft' ) {
			nextIndex = ( currentIndex - 1 + codeTabBtns.length ) % codeTabBtns.length;
		} else if ( e.key === 'Home' ) {
			nextIndex = 0;
		} else if ( e.key === 'End' ) {
			nextIndex = codeTabBtns.length - 1;
		}
		if ( nextIndex >= 0 ) {
			e.preventDefault();
			activateCodeTab( codeTabBtns[ nextIndex ], true );
		}
	} );

	const copyTimers = new WeakMap< HTMLButtonElement, number >();

	copyBtns.forEach( ( btn ) => {
		btn.addEventListener( 'click', async () => {
			const name = btn.dataset.copyCode;
			const codeEl = name ? document.getElementById( `code-${ name }` ) : null;
			const text = codeEl?.textContent || '';
			if ( ! text ) {
				return;
			}
			const label = btn.querySelector( 'span' );
			const copyIcon = btn.querySelector< HTMLElement >( '[data-icon="copy"]' );
			const checkIcon = btn.querySelector< HTMLElement >( '[data-icon="check"]' );
			const prevTimer = copyTimers.get( btn );
			if ( prevTimer ) {
				window.clearTimeout( prevTimer );
			}

			try {
				if ( navigator.clipboard?.writeText ) {
					await navigator.clipboard.writeText( text );
				} else {
					const ta = document.createElement( 'textarea' );
					ta.value = text;
					ta.setAttribute( 'readonly', '' );
					ta.style.position = 'fixed';
					ta.style.opacity = '0';
					document.body.appendChild( ta );
					ta.select();
					document.execCommand( 'copy' );
					ta.remove();
				}
				if ( label ) {
					label.textContent = 'Copied';
				}
				if ( copyIcon && checkIcon ) {
					copyIcon.hidden = true;
					checkIcon.hidden = false;
				}
				if ( copyAnnouncer ) {
					copyAnnouncer.textContent = 'Code snippet copied to clipboard.';
				}
			} catch {
				if ( label ) {
					label.textContent = 'Copy failed';
				}
				if ( copyAnnouncer ) {
					copyAnnouncer.textContent = 'Could not copy code automatically. Select and copy manually.';
				}
			}

			const timer = window.setTimeout( () => {
				if ( label ) {
					label.textContent = 'Copy code';
				}
				if ( copyIcon && checkIcon ) {
					copyIcon.hidden = false;
					checkIcon.hidden = true;
				}
				if ( copyAnnouncer ) {
					copyAnnouncer.textContent = '';
				}
			}, 1800 );
			copyTimers.set( btn, timer );
		} );
	} );
})();
