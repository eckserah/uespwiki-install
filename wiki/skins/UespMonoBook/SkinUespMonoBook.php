<?php
/**
 * MonoBook nouveau.
 *
 * Translated from gwicke's previous TAL template version to remove
 * dependency on PHPTAL.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Skins
 */

/**
 * Inherit main code from SkinTemplate, set the CSS and template filter.
 * @ingroup Skins
 */

require_once "skins/MonoBook/includes/SkinMonoBook.php";

class SkinUespMonoBook extends SkinMonoBook {
	/** Using MonoBook. */
	public $skinname = 'uespmonobook';
	public $stylename = 'UESP MonoBook';
	public $template = 'UespMonoBookTemplate';
	
	function setupSkinUserCss( OutputPage $out ) 
	{
		parent::setupSkinUserCss( $out );
		$out->addModuleStyles( array('mediawiki.skinning.elements', 'mediawiki.skinning.content', 'mediawiki.skinning.interface', 'skins.uespmonobook' ));

		// TODO: Migrate all of these
		$out->addStyle( 'uespmonobook/IE60Fixes.css', 'screen', 'IE 6' );
		$out->addStyle( 'uespmonobook/IE70Fixes.css', 'screen', 'IE 7' );

	}
}
