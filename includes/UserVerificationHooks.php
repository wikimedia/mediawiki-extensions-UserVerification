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
	public static function onLoadExtensionSchemaUpdates( ?DatabaseUpdater $updater = null ) {
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
	 * @param Title|MediaWiki\Title\Title &$title
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
	 * @param User &$user
	 * @param string $action
	 * @param bool &$result
	 * @param int $incrBy
	 * @return bool|void
	 */
	public static function onPingLimiter( &$user, $action, &$result, $incrBy ) {
		// @FIXME use $incrBy if necessary
		// *** necessary to make the hook below work
		if ( $action === 'mailpassword' ) {
			$result = false;
			return false;
		}
	}

	/**
	 * @param array &$users
	 * @param array $data
	 * @param string|array|MessageSpecifier &$error
	 * @return bool|void
	 */
	public static function onSpecialPasswordResetOnSubmit( &$users, $data, &$error ) {
		foreach ( $users as $user ) {
			if ( $user->isRegistered() ) {
				return;
			}
		}
		$error = 'userverification-password-reset-user-does-not-exist';
		return false;
	}

	/**
	 * @param string &$siteNotice
	 * @param Skin $skin
	 * @return bool
	 */
	public static function onSiteNoticeBefore( &$siteNotice, $skin ) {
		$user = $skin->getUser();
		$title = $skin->getTitle();
		$whiteList = [ 'CreateAccount', 'Preferences', 'ChangeEmail', 'Userlogin', 'Confirmemail' ];
		$isWhiteList = static function () use ( $whiteList, $title ) {
			foreach ( $whiteList as $titletext_ ) {
				$specialTitle = SpecialPage::getTitleFor( $titletext_ );
				[ $text_ ] = explode( '/', $title->getFullText(), 2 );
				if ( $specialTitle->getFullText() === $text_ ) {
					return true;
				}
			}
			return false;
		};
		if ( $GLOBALS['wgUserVerificationEmailConfirmToEdit']
			&& $user->isRegistered()
			&& !UserVerification::isAuthorizedGroup( $user )
			&& !UserVerification::isVerified( $user )
			&& !$user->getEmailAuthenticationTimestamp()
			&& !$isWhiteList()
		) {
			$labelHtml = wfMessage( 'userverification-sitenotice-require-emailconfirmatio-link-text' )->text();

			if ( !Sanitizer::validateEmail( $user->getEmail() ) ) {
				$titleReturn = SpecialPage::getTitleFor( 'ConfirmEmail' );
				$query_ = [ 'return' => $titleReturn->getFullText() ];
				$title_ = SpecialPage::getTitleFor( 'ChangeEmail' );
				$link = Linker::link( $title_, $labelHtml, [], $query_ );
			} else {
				$query_ = [ 'return' => $title->getFullText() ];
				$title_ = SpecialPage::getTitleFor( 'ConfirmEmail' );
				$link = Linker::link( $title_, $labelHtml, [], $query_ );
			}
			$siteNotice = '<div class="userverification-sitenotice">'
				// UserVerification::addHeaditem is used to retrieve
				// the required styles before page loads
				. new \OOUI\MessageWidget( [
				'type' => 'warning',
				'icon' => 'info',
				'label' => new OOUI\HtmlSnippet(
					wfMessage( 'userverification-sitenotice-require-emailconfirmation', $link )->text()
				)
			] ) . '</div>';

			$out = $skin->getOutput();
			$out->addModules( [ 'ext.UserVerification' ] );

			return false;
		}
		return true;
	}

	/**
	 * @param OutputPage $outputPage
	 * @param Skin $skin
	 * @return void
	 */
	public static function onBeforePageDisplay( OutputPage $outputPage, Skin $skin ) {
		UserVerification::addHeaditem( $outputPage, [
			[ 'stylesheet', $GLOBALS['wgResourceBasePath'] . '/extensions/UserVerification/resources/style.css' ],

			// *** unfortunately we cannot use the following
			// since if coflicts with .less css files (i.e.
			// OOUI widgets do not show propertly)
			// [ 'stylesheet', $GLOBALS['wgResourceBasePath'] . '/resources/lib/ooui/oojs-ui-images-wikimediaui.css' ],
			// [ 'stylesheet', $GLOBALS['wgResourceBasePath'] . '/resources/lib/ooui/oojs-ui-core-wikimediaui.css' ],
		] );
	}

	/**
	 * @param Parser $parser
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setFunctionHook( 'userverificationquerylink', [ \UserVerification::class, 'parserFunctionQueryLink' ] );
	}

	/**
	 * @param Title|MediaWiki\Title\Title $title
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
			return true;
		}

		// *** this seems the only way
		if ( $action === 'edit' ) {
			if ( $GLOBALS['wgUserVerificationEmailConfirmToEdit']
				&& !UserVerification::isAuthorizedGroup( $user )
				&& !UserVerification::isVerified( $user )
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
