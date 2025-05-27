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
 * @author thomas-topway-it <thomas.topway.it@mail.com>
 * @copyright Copyright © 2025, https://wikisphere.org
 */

$( () => {
	// eslint-disable-next-line no-unused-vars
	$( '#userverification-special-manage-toggle-delete-user' ).on( 'click', ( evt ) => {
		$( '.userverification-pager-button-delete-selected' ).toggle();
	} );

	// eslint-disable-next-line no-unused-vars
	$( '.userverification-pager-button-delete-selected' ).on( 'click', ( evt ) => {
		// eslint-disable-next-line no-alert
		if ( !confirm( mw.msg( 'userverification-module-delete-alert' ) ) ) {
			return false;
		}

		// eslint-disable-next-line no-alert
		if ( !confirm( mw.msg( 'userverification-module-delete-alert-b' ) ) ) {
			return false;
		}
	} );

} );
