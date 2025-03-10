/**
 * @module title
 */

var mw = window.mediaWiki;

/**
 * Gets the title of a local page from an href given some configuration.
 *
 * @param {String} href
 * @param {mw.Map} config
 * @return {String|undefined}
 */
export function getTitle( href, config ) {
	var linkHref,
		matches,
		queryLength,
		titleRegex = new RegExp( mw.RegExp.escape( config.get( 'wgArticlePath' ) )
			.replace( '\\$1', '(.+)' ) );

	// Skip every URI that mw.Uri cannot parse
	try {
		linkHref = new mw.Uri( href );
	} catch ( e ) {
		return undefined;
	}

	// External links
	if ( linkHref.host !== location.hostname ) {
		return undefined;
	}

	queryLength = Object.keys( linkHref.query ).length;

	// No query params (pretty URL)
	if ( !queryLength ) {
		matches = titleRegex.exec( linkHref.path );
		return matches ? decodeURIComponent( matches[ 1 ] ) : undefined;
	} else if ( queryLength === 1 && linkHref.query.hasOwnProperty( 'title' ) ) {
		// URL is not pretty, but only has a `title` parameter
		return linkHref.query.title;
	}

	return undefined;
}

/**
 * Given a page title it will return the mediawiki.Title if it is an eligible
 * link for showing page previews, null otherwise
 *
 * @param {String} title page title to check if it should show preview
 * @param {Number[]} contentNamespaces contentNamespaces as specified in
 * wgContentNamespaces
 * @returns {mw.Title|null}
 */
export function isValid( title, contentNamespaces ) {
	var mwTitle;

	if ( !title ) {
		return null;
	}

	// Is title in a content namespace?
	mwTitle = mw.Title.newFromText( title );
	if ( mwTitle && ( $.inArray( mwTitle.namespace, contentNamespaces ) >= 0 ) ) {
		return mwTitle;
	}

	return null;
}

/**
 * Return a mw.Title from a HTMLElement if valid for hovercards. Convenience
 * method
 *
 * @param {Element} el
 * @param {mw.Map} config
 * @return {mw.Title|null}
 */
export function fromElement( el, config ) {
	return isValid( getTitle( el.href, config ), config.get( 'wgContentNamespaces' ) );
}
