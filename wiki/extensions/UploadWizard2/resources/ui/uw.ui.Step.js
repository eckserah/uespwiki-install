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
	 * Represents a generic UI for a step.
	 *
	 * @class uw.ui.Step
	 * @mixins OO.EventEmitter
	 * @constructor
	 * @param {string} name The name of this step
	 */
	uw.ui.Step = function UWUIStep( name ) {
		OO.EventEmitter.call( this );

		this.name = name;

		this.$buttons = $( '<div>' ).addClass( 'mwe-upwiz-buttons' );

		this.$div = $( '<div>' )
			.attr( 'id', 'mwe-upwiz-stepdiv-' + this.name )
			.addClass( 'mwe-upwiz-stepdiv' )
			.hide();

		$( '#mwe-upwiz-content' ).append( this.$div );

		this.$arrow = $( '<li>' )
			.attr( 'id', 'mwe-upwiz-step-' + this.name )
			.append(
				$( '<div>' ).text( mw.message( 'mwe-upwiz-step-' + this.name ).text() )
			);

		$( '#mwe-upwiz-steps' ).append( this.$arrow );

		// this will make sure that button will only be added if it's been
		// set in the controller, otherwise there's nowhere to go...
		this.nextButtonPromise = $.Deferred();
	};

	OO.mixinClass( uw.ui.Step, OO.EventEmitter );

	/**
	 * Move to the step.
	 *
	 * @param {mw.UploadWizardUpload[]} uploads
	 */
	uw.ui.Step.prototype.moveTo = function ( uploads ) {
		var offset = $( 'h1:first' ).offset();

		this.uploads = uploads;
		this.$div.append( this.$buttons ).show();
		$( '#mwe-upwiz-steps' ).arrowStepsHighlight( this.$arrow );

		$( 'html, body' ).animate( {
			scrollTop: offset.top,
			scrollLeft: offset.left
		}, 'slow' );
	};

	/**
	 * Move out of the step.
	 */
	uw.ui.Step.prototype.moveFrom = function () {
		this.$div.children().detach();
	};

	/**
	 * Empty data from the step.
	 */
	uw.ui.Step.prototype.empty = function () {};

	uw.ui.Step.prototype.enableNextButton = function () {
		this.nextButtonPromise.resolve();
	};

	/**
	 * Add a 'next' button to the step's button container
	 */
	uw.ui.Step.prototype.addNextButton = function () {
		var ui = this;

		this.nextButton = new OO.ui.ButtonWidget( {
			classes: [ 'mwe-upwiz-button-next' ],
			label: mw.message( 'mwe-upwiz-next' ).text(),
			flags: [ 'progressive', 'primary' ]
		} ).on( 'click', function () {
			ui.emit( 'next-step' );
		} );

		this.nextButtonPromise.done( function () {
			ui.$buttons.append( ui.nextButton.$element );
		} );
	};

}( mediaWiki, jQuery, mediaWiki.uploadWizard, OO ) );
