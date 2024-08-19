<?php
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
 * @ingroup extensions
 * @author thomas-topway-it <support@topway.it>
 * @copyright Copyright ©2024, https://wikisphere.org
 */

class UserVerificationHooks {

	/**
	 * @param DatabaseUpdater|null $updater
	 * @return void
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater = null ) {
		$base = __DIR__;
		$dbType = $updater->getDB()->getType();
		$array = [
			[
				'table' => 'userverification_verification',
				'filename' => '../' . $dbType . '/userverification_verification.sql'
			],
			[
				'table' => 'userverification_keys',
				'filename' => '../' . $dbType . '/userverification_keys.sql'
			]
		];
		foreach ( $array as $value ) {
			if ( file_exists( $base . '/' . $value['filename'] ) ) {
				$updater->addExtensionUpdate(
					[
						'addTable', $value['table'],
						$base . '/' . $value['filename'], true
					]
				);
			}
		}
	}

	/**
	 * @param array $credits
	 * @return void
	 */
	public static function initExtension( $credits = [] ) {
		// @see https://www.mediawiki.org/wiki/Manual:$wgAutopromote

		if ( $GLOBALS['wgUserVerificationEmailConfirmToEdit'] ) {
			$GLOBALS['wgAutopromote']['autoconfirmed'][] = APCOND_EMAILCONFIRMED;
		}

		// *** we cannot use the following
		//
		// $user = RequestContext::getMain()->getUser();
		// if ( !UserVerification::isAuthorized( $user ) ) {
		// 	$GLOBALS['wgEmailConfirmToEdit'] = true;
		// 	$GLOBALS['wgEmailAuthentication'] = true;
		// }

		// here, since getUserGroupManager triggers
		// a premature access error
	}

	/**
	 * @param Title &$title
	 * @param null $unused
	 * @param OutputPage $output
	 * @param User $user
	 * @param WebRequest $request
	 * @param MediaWiki|MediaWiki\Actions\ActionEntryPoint $mediaWiki $mediaWiki
	 * @return void
	 */
	public static function onBeforeInitialize( &$title, $unused, $output, $user, $request, $mediaWiki ) {
		// required confirm to edit and email authentication
		// only for non admins
		if ( !UserVerification::isAuthorizedGroup( $user ) ) {
			// we cannot use it here since it does not
			// prevent editing of the article at this point
			// $GLOBALS['wgEmailConfirmToEdit'] = true;

			// however we can use it to require
			// email address on CreateAccount
			if ( $GLOBALS['wgUserVerificationEmailConfirmToEdit'] ) {
				$GLOBALS['wgEmailConfirmToEdit'] = true;
			}
		}
	}

	/**
	 * @param Parser $parser
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setFunctionHook( 'userverificationquerylink', [ \UserVerification::class, 'parserFunctionQueryLink' ] );
	}

	/**
	 * @param Title $title
	 * @param User $user
	 * @param string $action
	 * @param array|string|MessageSpecifier &$result User
	 * @return bool
	 */
	public static function onGetUserPermissionsErrors( $title, $user, $action, &$result ) {
		// *** we cannot use the following

		// $userOptionsManager = MediaWikiServices::getInstance()->getUserOptionsManager();
		// $userOptionsManager->setOption( $user, MainConfigNames::EmailConfirmToEdit, false );

		// since PermissioManafer relies on
		// $this->options->get( MainConfigNames::EmailConfirmToEdit )
		// and $this->options are retrieved on initialization with
		// new ServiceOptions(
		// 		PermissionManager::CONSTRUCTOR_OPTIONS, $services->getMainConfig()
		// 	)

		// ignore on maintenance scripts
		if ( defined( 'MW_ENTRY_POINT' ) && MW_ENTRY_POINT === 'cli' ) {
			return;
		}

		// *** this seems the only way
		if ( $action === 'edit' ) {
			if ( $GLOBALS['wgUserVerificationEmailConfirmToEdit']
				&& !UserVerification::isAuthorizedGroup( $user )
				&& !$user->getEmailAuthenticationTimestamp()
			) {
				$result = [ 'confirmedittext' ];
				return false;
			}
		}

		if ( in_array( $action, $GLOBALS['wgUserVerificationRequireUserVerifiedActions'] )
			&& !UserVerification::isAuthorizedGroup( $user )
			&& !UserVerification::isVerified( $user )
		) {
			$status = UserVerification::getStatus( $user );
			$specialPage = SpecialPage::getTitleFor( 'UserVerification' );
			$url = wfAppendQuery( $specialPage->getLocalURL(),
				[ 'return' . $title->getFullText() ] );

			$result = [ 'userverifiedtoedittext', $status !== 'pending' ?
				'{{#querylink:Special:UserVerification|continue|return={{FULLPAGENAME}}}}'
				: wfMessage( 'userverifiedtoreadtext-pending' )->text()
				];
			return false;
		}
	}

	/**
	 * @param User &$user
	 * @param string &$injectHtml
	 * @param bool $direct
	 * @return void
	 */
	public static function onUserLoginComplete( User &$user, string &$injectHtml, bool $direct ) {
		// redirect to Special:ConfirmEmail
		if ( $GLOBALS['wgUserVerificationEmailConfirmToEdit']
			&& !$user->isEmailConfirmed() ) {
			$webRequest = RequestContext::getMain()->getRequest();
			$response = $webRequest->response();
			$title = SpecialPage::getTitleFor( 'Confirmemail' );
			$response->header( 'Location: ' . $title->getLocalURL() );
		}
	}

	/**
	 * @param User &$user
	 * @param string &$inject_html
	 * @param string $oldName
	 */
	public static function onUserLogoutComplete( &$user, &$inject_html, $oldName ) {
		UserVerification::deleteCookie();
	}

}
