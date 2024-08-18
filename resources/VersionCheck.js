/**
 * This file is part of the MediaWiki extension UserVerification.
 *
 * UserVerification is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * UserVerification is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with UserVerification.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @file
 * @author thomas-topway-it <support@topway.it>
 * @copyright Copyright © 2024, https://wikisphere.org
 */

$( function () {
	var Config = mw.config.get( 'userverification-config' );

	// display every 3 days
	if (
		Config.canManageVerification &&
		!mw.cookie.get( 'userverification-check-latest-version' )
	) {
		mw.loader.using( 'mediawiki.api', function () {
			var action = 'userverification-check-latest-version';
			new mw.Api()
				.postWithToken( 'csrf', {
					action: action
				} )
				.done( function ( res ) {
					if ( action in res ) {
						if ( res[ action ].result === 2 ) {
							var messageWidget = new OO.ui.MessageWidget( {
								type: 'warning',
								label: new OO.ui.HtmlSnippet(
									mw.msg(
										'userverification-jsmodule-outdated-version'
									)
								),
								// *** this does not work before ooui v0.43.0
								showClose: true
							} );
							var closeFunction = function () {
								var three_days = 3 * 86400;
								mw.cookie.set( 'userverification-check-latest-version', true, {
									path: '/',
									expires: three_days
								} );
								$( messageWidget.$element ).parent().remove();
							};
							messageWidget.on( 'close', closeFunction );

							$( '.mw-body-content' )
							// $( '.mw-htmlform-ooui-wrapper' )
								.first()
								.prepend(
									// eslint-disable-next-line no-jquery/no-parse-html-literal
									$( '<div><br/></div>' ).prepend( messageWidget.$element )
								);
							if (
								// eslint-disable-next-line no-jquery/no-class-state
								!messageWidget.$element.hasClass(
									'oo-ui-messageWidget-showClose'
								)
							) {
								messageWidget.$element.addClass(
									'oo-ui-messageWidget-showClose'
								);
								var closeButton = new OO.ui.ButtonWidget( {
									classes: [ 'oo-ui-messageWidget-close' ],
									framed: false,
									icon: 'close',
									label: OO.ui.msg(
										'ooui-popup-widget-close-button-aria-label'
									),
									invisibleLabel: true
								} );
								closeButton.on( 'click', closeFunction );
								messageWidget.$element.append( closeButton.$element );
							}
						}
					}
				} );
		} );
	}
} );
