/*
 * This file is part of the MediaWiki extension UploadWizard.
 *
 * UploadWizard is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * UploadWizard is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with UploadWizard.  If not, see <http://www.gnu.org/licenses/>.
 */

( function ( mw, $, uw, OO ) {
	/**
	 * Represents the UI for the wizard's Upload step.
	 *
	 * @class uw.ui.Upload
	 * @extends uw.ui.Step
	 * @constructor
	 * @param {Object} config UploadWizard config object.
	 */
	uw.ui.Upload = function UWUIUpload( config ) {
		var upload = this;

		this.config = config;

		uw.ui.Step.call(
			this,
			'file'
		);

		this.$addFileContainer = $( '<div>' )
			.attr( 'id', 'mwe-upwiz-add-file-container' )
			.addClass( 'mwe-upwiz-add-files-0' );

		this.$uploadCtrl = $( '<div>' )
			.attr( 'id', 'mwe-upwiz-upload-ctrls' )
			.addClass( 'mwe-upwiz-file ui-helper-clearfix' )
			.append( this.$addFileContainer );

		this.addFile = new OO.ui.ButtonWidget( {
			id: 'mwe-upwiz-add-file',
			label: mw.message( 'mwe-upwiz-add-file-0-free' ).text(),
			flags: [ 'constructive', 'primary' ]
		} );

		this.$addFileContainer.append( this.addFile.$element );

		if ( this.isFlickrImportEnabled() ) {
			this.$flickrAddFileContainer = $( '<div>' )
				.attr( 'id', 'mwe-upwiz-upload-ctrl-flickr-container' );

			this.$uploadCenterDivide = $( '<p>' )
				.attr( 'id', 'mwe-upwiz-upload-ctr-divide' )
				.text( mw.message( 'mwe-upwiz-add-flickr-or' ).text() );

			this.addFlickrFile = new OO.ui.ButtonWidget( {
				id: 'mwe-upwiz-add-flickr-file',
				label: mw.message( 'mwe-upwiz-add-file-flickr' ).text(),
				flags: 'constructive'
			} ).on( 'click', function () {
				upload.emit( 'flickr-ui-init' );
			} );

			this.$flickrAddFileContainer.append(
				this.$uploadCenterDivide,
				this.addFlickrFile.$element
			);

			this.$addFileContainer
				.append( this.$flickrAddFileContainer );

			this.$flickrSelectList = $( '<div>' )
				.attr( 'id', 'mwe-upwiz-flickr-select-list' );

			this.$flickrSelectListContainer = $( '<div>' )
				.attr( 'id', 'mwe-upwiz-flickr-select-list-container' )
				.addClass( 'ui-corner-all' )
				.append(
					$( '<div>' )
						.text( mw.message(
							'mwe-upwiz-multi-file-select2',
							config.maxUploads
						) ),
					this.$flickrSelectList
				);

			this.$flickrSelect = $( '#mwe-upwiz-select-flickr' );
		}

		this.nextStepButtonAllOk = new OO.ui.ButtonWidget( {
			label: mw.message( 'mwe-upwiz-next-file' ).text(),
			flags: [ 'progressive', 'primary' ]
		} ).on( 'click', function () {
			upload.emit( 'next-step' );
		} );

		this.retryButtonSomeFailed = new OO.ui.ButtonWidget( {
			label: mw.message( 'mwe-upwiz-file-retry' ).text(),
			flags: [ 'progressive' ]
		} ).on( 'click', function () {
			upload.hideEndButtons();
			upload.emit( 'retry' );
		} );

		this.nextStepButtonSomeFailed = new OO.ui.ButtonWidget( {
			label: mw.message( 'mwe-upwiz-next-file-despite-failures' ).text(),
			flags: [ 'progressive', 'primary' ]
		} ).on( 'click', function () {
			upload.emit( 'next-step' );
		} );

		this.retryButtonAllFailed = new OO.ui.ButtonWidget( {
			label: mw.message( 'mwe-upwiz-file-retry' ).text(),
			flags: [ 'progressive' ]
		} ).on( 'click', function () {
			upload.hideEndButtons();
			upload.emit( 'retry' );
		} );

		this.$fileList = $( '<div>' )
			.attr( 'id', 'mwe-upwiz-filelist' )
			.addClass( 'ui-corner-all' );

		this.$progress = $( '<div>' )
			.attr( 'id', 'mwe-upwiz-progress' )
			.addClass( 'ui-helper-clearfix' );

		this.addNextButton();
	};

	OO.inheritClass( uw.ui.Upload, uw.ui.Step );

	/**
	 * Set up the "Add files" button (#mwe-upwiz-add-file) to open a file selection dialog on click
	 * by means of a hidden `<input type="file">`.
	 *
	 * @param {jQuery} $element The element to append to
	 */
	uw.ui.Upload.prototype.setupFileInputCtrl = function ( $element ) {
		var $fileInputCtrl = $( '<input type="file" multiple name="file" class="mwe-upwiz-file-input" />' ),
			ui = this;

		$element.find( '.mwe-upwiz-file-input' ).remove();
		$element.append( $fileInputCtrl );

		$fileInputCtrl.on( 'change', function () {
			ui.emit( 'files-added', $fileInputCtrl[ 0 ].files );

			// We can't clear the value of a file input, so replace the whole
			// thing with a new one
			ui.setupFileInputCtrl( $element );
		} );
	};

	uw.ui.Upload.prototype.showProgressBar = function () {
		this.$progress.show();
	};

	/**
	 * Updates the interface based on the number of uploads.
	 *
	 * @param {boolean} haveUploads Whether there are any uploads at all.
	 * @param {boolean} fewerThanMax Whether we can add more uploads.
	 */
	uw.ui.Upload.prototype.updateFileCounts = function ( haveUploads, fewerThanMax ) {
		this.$fileList.toggleClass( 'mwe-upwiz-filled-filelist', haveUploads );
		this.$addFileContainer.toggleClass( 'mwe-upwiz-add-files-0', !haveUploads );

		this.setAddButtonText( haveUploads );

		if ( haveUploads ) {
			// we have uploads ready to go, so allow us to proceed
			this.$addFileContainer.add( this.$buttons ).show();

			if ( this.isFlickrImportEnabled() ) {
				this.$uploadCenterDivide.hide();
			}

			// fix the rounded corners on file elements.
			// we want them to be rounded only when their edge touched the top or bottom of the filelist.
			this.$fileListings = this.$fileList.find( '.filled' );

			this.$visibleFileListings = this.$fileListings.find( '.mwe-upwiz-visible-file' );
			this.$visibleFileListings.removeClass( 'ui-corner-top ui-corner-bottom' );
			this.$visibleFileListings.first().addClass( 'ui-corner-top' );
			this.$visibleFileListings.last().addClass( 'ui-corner-bottom' );

			this.$fileListings.filter( ':odd' ).addClass( 'odd' );
			this.$fileListings.filter( ':even' ).removeClass( 'odd' );
		} else {
			this.$buttons.hide();

			if ( this.isFlickrImportEnabled() ) {
				this.$uploadCenterDivide.show();
			}
		}

		this.addFile.setDisabled( !fewerThanMax );

		if ( this.isFlickrImportEnabled() ) {
			this.addFlickrFile.setDisabled( !fewerThanMax );
		}

		this.addFile.$element.find( '.mwe-upwiz-file-input' ).prop( 'disabled', !fewerThanMax );
	};

	/**
	 * Changes the initial centered invitation button to something like "add another file"
	 */
	uw.ui.Upload.prototype.setAddButtonText = function ( more ) {
		var msg = 'mwe-upwiz-add-file-',
			fmsg = 'mwe-upwiz-add-file-flickr';

		if ( more ) {
			msg += 'n';
			fmsg += '-n';
		} else {
			msg += '0-free';
		}

		this.addFile.setLabel( mw.message( msg ).text() );

		// if Flickr uploading is available to this user, show the "add more files from flickr" button
		if ( this.isFlickrImportEnabled() ) {
			// changes the flickr add button to "add more files from flickr" if necessary.
			this.addFlickrFile.setLabel( mw.message( fmsg ).text() );
			// jQuery likes to restore the wrong 'display' value when doing .show()
			this.$flickrAddFileContainer.css( 'display', '' );
		}
	};

	/**
	 * Empties the upload list.
	 */
	uw.ui.Upload.prototype.empty = function () {
		this.$addFileContainer
			.add( this.$buttons )
			.add( this.$progress )
			.hide();

		this.$addFileContainer
			.add( this.$uploadCenterDivide )
			.add( this.$uploadCtrl )
			.show();

		if ( this.isFlickrImportEnabled() ) {
			this.$flickrAddFileContainer
				.add( this.$uploadCenterDivide )
				.show();

			// changes the button back from "add more files from flickr" to the initial text
			this.addFlickrFile.setLabel( mw.message( 'mwe-upwiz-add-file-flickr' ).text() );

			this.emptyFlickrLists();
		}

		// changes the button back from "add another file" to the initial centered invitation button
		this.addFile.setLabel( mw.message( 'mwe-upwiz-add-file-0-free' ).text() );
	};

	/**
	 * Empties the Flickr selection lists.
	 */
	uw.ui.Upload.prototype.emptyFlickrLists = function () {
		// empty the flickr lists
		this.$flickrSelectList.empty();
		this.$flickrSelectListContainer.unbind();
		this.$flickrSelect.unbind();
	};

	uw.ui.Upload.prototype.moveTo = function () {
		uw.ui.Step.prototype.moveTo.call( this );

		this.$fileList.removeClass( 'mwe-upwiz-filled-filelist' );

		this.$div.prepend(
			$( '<div>' )
				.attr( 'id', 'mwe-upwiz-files' )
				.append(
					this.$flickrSelectListContainer,
					this.$fileList,
					this.$uploadCtrl
				)
		);

		// append <input type="file"> to button
		this.setupFileInputCtrl( this.addFile.$element.find( '.oo-ui-buttonElement-button' ) );

		// Show the upload button, and the add file button
		$( '#mwe-upwiz-upload-ctrls' ).show();
		$( '#mwe-upwiz-add-file' ).show();
	};

	uw.ui.Upload.prototype.addNextButton = function () {
		var ui = this;

		this.nextButtonPromise.done( function () {
			ui.$buttons.append(
				$( '<div>' )
					.addClass( 'mwe-upwiz-file-next-all-ok mwe-upwiz-file-endchoice' )
					.append(
						new OO.ui.HorizontalLayout( {
							items: [
								new OO.ui.LabelWidget( {
									label: mw.message( 'mwe-upwiz-file-all-ok' ).text()
								} ),
								ui.nextStepButtonAllOk
							]
						} ).$element
					)
			);

			ui.$buttons.append(
				$( '<div>' )
					.addClass( 'mwe-upwiz-file-next-some-failed mwe-upwiz-file-endchoice' )
					.append(
						new OO.ui.HorizontalLayout( {
							items: [
								new OO.ui.LabelWidget( {
									label: mw.message( 'mwe-upwiz-file-some-failed' ).text()
								} ),
								ui.retryButtonSomeFailed,
								ui.nextStepButtonSomeFailed
							]
						} ).$element
					)
			);

			ui.$buttons.append(
				$( '<div>' )
					.addClass( 'mwe-upwiz-file-next-all-failed mwe-upwiz-file-endchoice' )
					.append(
						new OO.ui.HorizontalLayout( {
							items: [
								new OO.ui.LabelWidget( {
									label: mw.message( 'mwe-upwiz-file-all-failed' ).text()
								} ),
								ui.retryButtonAllFailed
							]
						} ).$element
					)
			);

			ui.$buttons.append( ui.$progress );
		} );
	};

	/**
	 * Hide the buttons for moving to the next step.
	 */
	uw.ui.Upload.prototype.hideEndButtons = function () {
		this.$div
			.find( '.mwe-upwiz-buttons .mwe-upwiz-file-endchoice' )
			.hide();
	};

	/**
	 * Shows an error dialog informing the user that some uploads have been omitted
	 * since they went over the max files limit.
	 *
	 * @param {number} filesUploaded The number of files that have been attempted to upload
	 */
	uw.ui.Upload.prototype.showTooManyFilesWarning = function ( filesUploaded ) {
		mw.errorDialog(
			mw.message(
				'mwe-upwiz-too-many-files-text',
				this.config.maxUploads,
				filesUploaded
			).text(),
			mw.message( 'mwe-upwiz-too-many-files' ).text()
		);
	};

	/**
	 * Checks whether flickr import is enabled and the current user has the rights to use it
	 *
	 * @return {boolean}
	 */
	uw.ui.Upload.prototype.isFlickrImportEnabled = function () {
		return this.config.UploadFromUrl && this.config.flickrApiKey !== '';
	};

}( mediaWiki, jQuery, mediaWiki.uploadWizard, OO ) );
