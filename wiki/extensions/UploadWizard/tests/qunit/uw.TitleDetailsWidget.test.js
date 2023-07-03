( function ( mw, uw, $ ) {
	'use strict';

	var makeTitleInFileNSCases = [ {
		filename: 'foo.png',
		prefixedText: 'File:Foo.png',
		desc: 'filename without namespace starting with a lower case letter'
	}, {
		filename: 'foo_bar-baz.jpg',
		prefixedText: 'File:Foo bar-baz.jpg',
		desc: 'filename without namespace with space in it'
	}, {
		filename: 'MediaWiki:foo_bar.jpg',
		prefixedText: null,
		desc: 'filename starting with MediaWiki: (colons are disallowed)'
	}, {
		filename: 'File:foo_bar.jpg',
		prefixedText: 'File:Foo bar.jpg',
		desc: 'filename starting with File:'
	}, {
		filename: 'file:foo_bar.jpg',
		prefixedText: 'File:Foo bar.jpg',
		desc: 'filename starting with file:'
	}, {
		filename: 'Foo part 1/2.jpg',
		prefixedText: null,
		desc: 'filename with characters disallowed in file names'
	} ];

	QUnit.module( 'uw.TitleDetailsWidget', QUnit.newMwEnvironment() );

	QUnit.test( '.static.makeTitleInFileNS()', makeTitleInFileNSCases.length, function () {
		var makeTitleInFileNS = uw.TitleDetailsWidget.static.makeTitleInFileNS;

		$.each( makeTitleInFileNSCases, function ( i, test ) {
			var title = makeTitleInFileNS( test.filename );
			QUnit.equal(
				title ? title.getPrefixedText() : title,
				test.prefixedText,
				test.desc
			);
		} );
	} );
}( mediaWiki, mediaWiki.uploadWizard, jQuery ) );
