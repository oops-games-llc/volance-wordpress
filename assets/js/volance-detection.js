/**
 * Volance Detection: consent-first loader.
 *
 * Nothing is collected and the Volance collector is not even downloaded until
 * the visitor allows it. The collector never reads typed characters, form values
 * or clipboard contents; this script only copies its snapshot into a hidden
 * field when a form is submitted, so the site's own server can score it.
 *
 * Public API for sites that bring their own consent tool:
 *   window.volanceDetection.setConsent( true | false )
 *   document.dispatchEvent( new CustomEvent( 'volance-detection:consent', { detail: { granted: true } } ) )
 */
const config = window.volanceDetectionConfig || null;

if ( config ) {
	let collector = null;
	let loading = false;
	const banner = { node: null };

	const readStored = () => {
		try {
			return window.localStorage.getItem( config.storageKey );
		} catch ( error ) {
			return null;
		}
	};

	const writeStored = ( value ) => {
		try {
			window.localStorage.setItem( config.storageKey, value );
		} catch ( error ) {
			// Private mode: the choice just lasts for this page.
		}
	};

	const removeBanner = () => {
		if ( banner.node && banner.node.parentNode ) {
			banner.node.parentNode.removeChild( banner.node );
		}
		banner.node = null;
	};

	const start = async () => {
		if ( collector || loading ) {
			return;
		}
		loading = true;
		try {
			const module = await import( config.collector );
			const created = module.createSiteCollector();
			created.start( { consent: true } );
			collector = created;
		} catch ( error ) {
			collector = null; // Fail open: forms keep working without evidence.
		}
		loading = false;
	};

	const stop = () => {
		if ( collector ) {
			try {
				collector.stop();
			} catch ( error ) {
				// Nothing to do.
			}
			collector = null;
		}
	};

	const setConsent = ( granted ) => {
		if ( granted === true ) {
			writeStored( 'granted' );
			removeBanner();
			start();
		} else {
			writeStored( 'declined' );
			removeBanner();
			stop();
		}
	};

	const link = ( url, label ) => {
		const anchor = document.createElement( 'a' );
		anchor.href = url;
		anchor.textContent = label;
		anchor.target = '_blank';
		anchor.rel = 'noopener noreferrer';
		return anchor;
	};

	const showBanner = () => {
		const node = document.createElement( 'div' );
		node.className = 'volance-detection-banner';
		node.setAttribute( 'role', 'dialog' );
		node.setAttribute( 'aria-label', config.strings.title );

		const title = document.createElement( 'strong' );
		title.textContent = config.strings.title;
		const text = document.createElement( 'p' );
		text.textContent = config.strings.text;

		const links = document.createElement( 'p' );
		if ( config.sitePrivacyUrl ) {
			links.appendChild( link( config.sitePrivacyUrl, config.strings.sitePrivacy ) );
			links.appendChild( document.createTextNode( ' \u00B7 ' ) );
		}
		links.appendChild( link( config.volanceUrl, config.strings.volancePolicy ) );

		const actions = document.createElement( 'div' );
		actions.className = 'volance-detection-actions';
		const allow = document.createElement( 'button' );
		allow.type = 'button';
		allow.textContent = config.strings.allow;
		allow.addEventListener( 'click', () => setConsent( true ) );
		const decline = document.createElement( 'button' );
		decline.type = 'button';
		decline.textContent = config.strings.decline;
		decline.addEventListener( 'click', () => setConsent( false ) );
		actions.appendChild( allow );
		actions.appendChild( decline );

		node.appendChild( title );
		node.appendChild( text );
		node.appendChild( links );
		node.appendChild( actions );
		document.body.appendChild( node );
		banner.node = node;
	};

	// Copy the snapshot into the form's hidden field just before it is sent.
	document.addEventListener(
		'submit',
		( event ) => {
			if ( ! collector || ! event.target || typeof event.target.querySelector !== 'function' ) {
				return;
			}
			const field = event.target.querySelector( 'input[name="' + config.field + '"]' );
			if ( ! field ) {
				return;
			}
			try {
				field.value = JSON.stringify( collector.snapshot() );
			} catch ( error ) {
				field.value = '';
			}
		},
		true
	);

	window.volanceDetection = { setConsent };

	if ( config.consentMode === 'custom' ) {
		document.addEventListener( 'volance-detection:consent', ( event ) => {
			setConsent( !! ( event.detail && event.detail.granted === true ) );
		} );
	} else if ( window.navigator.globalPrivacyControl === true ) {
		// Respect Global Privacy Control: no banner, no collection.
	} else {
		const stored = readStored();
		if ( stored === 'granted' ) {
			start();
		} else if ( stored !== 'declined' ) {
			showBanner();
		}
	}
}
