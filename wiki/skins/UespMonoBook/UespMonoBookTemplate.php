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
 * @ingroup Skins
 */
require_once "skins/MonoBook/includes/MonoBookTemplate.php";

class UespMonoBookTemplate extends MonoBookTemplate {
	function customBox( $bar, $cont ) {
		$portletAttribs = array(
			'class' => 'generated-sidebar portlet',
			'id' => Sanitizer::escapeId( "p-$bar" ),
			'role' => 'navigation'
		);

		$tooltip = Linker::titleAttrib( "p-$bar" );
		if ( $tooltip !== false ) {
			$portletAttribs['title'] = $tooltip;
		}
		echo '	' . Html::openElement( 'div', $portletAttribs );
		$msgObj = wfMessage( $bar );
		?>

		<h3><?php echo htmlspecialchars( $msgObj->exists() ? $msgObj->text() : $bar ); ?></h3>
		<div class='pBody'>
			<?php
			if ( is_array( $cont ) ) {
				?>
				<ul>
					<?php
					$isindent = false;
					foreach ( $cont as $key => $val ) {
						if (substr($val['text'],0,1)=='*') {
							if (!$isindent)
								echo "\n<ul>\n";
							$isindent = true;
							$val['text'] = trim(substr($val['text'],1));
							$val['id'] = str_replace('.2A', '', $val['id']);
						}
						elseif ($isindent) {
							$isindent = false;
							echo "\n</ul>\n";
						}

						echo $this->makeListItem( $key, $val );
					}
					?>
				</ul>
			<?php
			} else {
				# allow raw HTML block to be defined by extensions
				print $cont;
			}

			$this->renderAfterPortlet( $bar );
			?>
		</div>
		</div>
	<?php
	}

	function execute() {
		$sn = $this->get('sitenotice');
		$this->set('sitenotice', "<div id='topad'><div class='center' id='uespTopBannerAd' style='margin:0 auto;'><div id='uesp_D_1'></div></div></div>$sn");
		$dac = $this->get('dataAfterContent');
		// Somewhat of a fugly hack to close divs we need to be outside of, but then re-open empty ones so as not to create incorrect code.
		$this->set('dataAfterContent', "$dac<div class='visualClear'></div></div></div><div style='width:300px; height:250px; margin:0 auto;'><div id='uesp_D_3'></div></div><div><div>");
		parent::execute();
	}
	
	function printTrail() {
		parent::printTrail();
	}

	function renderPortals( $sidebar ) {
		parent::renderPortals( $sidebar );
		?>
		<div class='portlet'>
			<div id='uesp_D_2'></div>
		</div><?php
	}

	function getSearchBox() {
	$html = '';
	
	$html .= Html::openElement( 'div', [ 'id' => 'p-search', 'class' => 'portlet', 'role' => 'search' ]);
	$html .= Html::rawElement( 'h3' , [], Html::rawElement( 'label' , [ 'for' => 'searchInput' ], $this->getMsg( 'search' )->parse() )); 
	$html .= Html::openElement( 'div', [ 'id' => 'searchBody', 'class' => 'pBody', 'style' => 'background-color: white; height: 12px;'] );
	$html .= Html::rawElement( 'form', [ 'action' => $this->get( 'wgScript' ), 'id' => 'searchform' ], 
		Html::hidden( 'title', $this->get( 'searchtitle' ) ) .
		Html::element( 'input', [ 'title' => 'Search UESPWiki [f]', 'accesskey' => 'f', 'id' => 'searchInput', 'style' => '-webkit-appearance: none; background-color: transparent; width: 90%; margin: 0; font-size: 13px; border: medium none; outline: medium none; direction: ltr; left: 1px; margin: 0; padding: .2em 0 .2em .2em; position: absolute; top: 18px; height: 16px;' ]) .
		Html::rawElement( 'button', [ 'id' => 'searchButton', 'title' => 'Search UESP for this text', 'name' => 'button', 'type' => 'submit', 'style' => 'background-color: transparent; background-image: none; border: medium none; cursor: pointer; margin: 0; padding: .2em .4em .2em 0; position: absolute; right: 0; top: 18px; width: 10%;' ] ,
			Html::element( 'img', [ 'width' => 12, 'height' => 13, 'alt' => 'Search', 'src' => '/w/skins/UespMonoBook/search-icon.png'])
		)
	);
	$html .= Html::closeElement( 'div' );
	$html .= Html::closeElement( 'div' );
	
	return $html;
	}
} // end of class
