( function ( mw ) {

	/**
	 * Sort of an abstract class for deeds
	 *
	 * @class mw.UploadWizardDeed
	 * @abstract
	 */
	mw.UploadWizardDeed = function () {
		mw.UploadWizardDeed.prototype.instanceCount++;
	};

	mw.UploadWizardDeed.prototype = {
		instanceCount: 0,

		/**
		 * @return {uw.FieldLayout[]} Fields that need validation
		 */
		getFields: function () {
			return [];
		},

		getInstanceCount: function () {
			return this.instanceCount;
		},

		setFormFields: function () { },

		getSourceWikiText: function () {
			return this.sourceInput.getValue();
		},

		getAuthorWikiText: function () {
			return this.authorInput.getValue();
		},

		/**
		 * Get wikitext representing the licenses selected in the license object
		 *
		 * @return {string} wikitext of all applicable license templates.
		 */
		getLicenseWikiText: function () {
			return this.licenseInput.getWikiText();
		},

		/**
		 * @return {Object}
		 */
		getSerialized: function () {
			return {
				name: this.name
			};
		},

		/**
		 * @param {Object} serialized
		 */
		setSerialized: function ( serialized ) {
			if ( serialized.name ) {
				this.name = serialized.name;
			}
		}
	};

}( mediaWiki ) );
