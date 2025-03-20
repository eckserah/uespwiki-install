( function ( mw, $ ) {
	// Override for the default searchSuggest
	mw.searchSuggest.request = function ( api, query, response, maxRows, namespace ) {
		return api.get( {
			formatversion: 2,
			action: 'opensearch',
			search: query,
			namespace: namespace || '0|102|104|106|108|110|112|114|116|118|120|122|124|126|128|130|132|134|136|138|140|142|144|146|148|150|152|154|156|158|160|162|164|166|168|170|172|174|176|178|184',
			limit: maxRows,
			suggest: true
		} ).done( function ( data, jqXHR ) {
			response( data[ 1 ], {
				type: jqXHR.getResponseHeader( 'X-OpenSearch-Type' ),
				query: query
			} );
		} );
	};
	
}( mediaWiki, jQuery ) );