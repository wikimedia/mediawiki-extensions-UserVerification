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
 * @copyright Copyright ©2023, https://wikisphere.org
 */

use MediaWiki\MediaWikiServices;

class SpecialUserVerificationList extends SpecialPage {

	/** @var Title */
	public $localTitle;

	/** @var int */
	public $userId;

	/** @var bool */
	public $wrongPassord;

	/**
	 * @inheritDoc
	 */
	public function __construct( $name = 'UserVerificationList' ) {
		$listed = true;
		parent::__construct( 'UserVerificationList', '', $listed );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ) {
		$this->requireLogin();

		$user = $this->getUser();
		if ( !\UserVerification::isAuthorizedGroup( $user )
			&& !$user->isAllowed( 'userverification-can-manage-verification' ) ) {
			$this->displayRestrictionError();
			return;
		}

		$this->userId = $par;

		$this->setHeaders();
		$this->outputHeader();

		$out = $this->getOutput();
		\UserVerification::disableClientCache( $out );
		\UserVerification::addJsConfigVars( $out, $user );

		$out->addModuleStyles( 'mediawiki.special' );
		$out->addModules( [ 'ext.UserVerification' ] );
		$this->addHelpLink( 'Extension:UserVerification' );

		$keys = \UserVerification::getKeys();

		if ( empty( $keys ) ) {
			$out->addHTML( Html::warningBox( $this->msg( 'userverification-special-manage-no-keys' )->parse(), [] ) );
			return;
		}

		$request = $this->getRequest();

		$this->request = $request;
		$this->user = $user;
		$this->localTitle = SpecialPage::getTitleFor( 'UserVerificationList' );

		$out->enableOOUI();

		$deleteUser = $request->getVal( 'delete' );
		if ( !empty( $deleteUser ) ) {
			$this->deleteUser( $deleteUser );
		}

		$filename = $request->getVal( 'file' );
		if ( !empty( $filename ) ) {
			$this->displayFile( $filename );
			// exit
		}

		if ( $this->userId ) {
			$dbr = \UserVerification::getDB( DB_REPLICA );
			$row = $dbr->selectRow( 'userverification_verification', '*', [ 'user_id' => $this->userId ], __METHOD__ );

			// if data aren't empty, ask for the site-level password
			if ( $row && !empty( $row->data ) && !UserVerification::getUserKey() ) {
				$out->addHTML( $this->passwordForm( $request, $row ) );
				return;
			}

			$data = ( $row ? \UserVerification::decryptData( $row->data ) : null );

			if ( !$row ) {
				$row = [
					'status' => 'none',
					'comments' => null
				];

			} else {
				$row = (array)$row;
			}

			$out->addWikiMsg(
				'userverification-special-manage-returnlink',
				$this->localTitle->getFullText()
			);

			$this->displayData( $data, $out );
			$out->addHTML( $this->manageVerificationForm( $request, $row ) );
			return;
		}

		$class = "MediaWiki\\Extension\\UserVerification\\Pagers\\UserPager";
		$pager = new $class(
			$this,
			$request,
			$this->getLinkRenderer()
		);

		$form = $this->showOptions( $request );
		$out->addHTML( $form );
		$out->addHTML( '<br />' );

		if ( \UserVerification::canDeleteUsers( $user ) ) {
			$out->addHTML( new OOUI\ButtonWidget(
				[
					'label' => $this->msg( 'userverification-special-manage-toggle-delete-user' )->text(),
					'infusable' => true,
					'flags' => [ 'progressive' ],
					'id' => 'userverification-special-manage-toggle-delete-user',
				]
			) );
			$out->addHTML( '<br />' );
			$out->addHTML( '<br />' );
		}

		if ( $pager->getNumRows() ) {
			$out->addParserOutputContent( $pager->getFullOutput() );
			// $out->addHTML(
			// 	$pager->getBody() .
			// 	$pager->getNavigationBar()
			// );

		} else {
			$out->addWikiMsg( 'userverification-special-browse-table-empty' );
		}
	}

	/**
	 * @param int $userId
	 * @return bool
	 */
	protected function deleteUser( $userId ) {
		return \UserVerification::deleteUsers( $this->getUser(), [ $userId ] );
	}

	/**
	 * @param string $filename
	 */
	protected function displayFile( $filename ) {
		include_once __DIR__ . '/MimeTypes.php';
		$ext = pathinfo( $filename, PATHINFO_EXTENSION );
		$mime = null;

		if ( array_key_exists( $ext, $GLOBALS['MimeTypes'] ) ) {
			$mime = $GLOBALS['MimeTypes'][$ext];
			if ( is_array( $mime ) ) {
				$mime = $mime[0];
			}
		}
		$file = \UserVerification::getUploadDir( $this->userId ) . '/' . $filename;
		$contents = file_get_contents( $file );
		$contents = \UserVerification::decryptData( $contents );

		header( "Content-type: $mime" );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $contents ) );
		exit( $contents );
	}

	/**
	 * @param string $data
	 * @param OutputPage $out
	 */
	protected function displayData( $data, $out ) {
		$services = MediaWikiServices::getInstance();
		$userIdentityLookup = $services->getUserIdentityLookup();
		$user = $userIdentityLookup->getUserIdentityByUserId( $this->userId );

		if ( !$user ) {
			$out->addHTML( Html::Element( 'h3', [], $this->msg( 'userverification-pager-field-no-valid-user' )->text() ) );
			return;
		}

		if ( empty( $data ) ) {
			$out->addHTML( Html::Element( 'h3', [], $user->getName() ) );
			$out->addWikiMsg( 'userverification-special-manage-userverification-nodata' );

			return;
		}

		$table = '';
		$table = Html::Element( 'h3', [], $user->getName() );
		$table .= Html::openElement( 'table', [ 'style' => 'width:100%', 'class' => 'wikitable' ] );
		$table .= Html::Element( 'caption', [], $this->msg( 'userverification-special-manage-userverification-table-caption' )->text() );

		$data = json_decode( $data, true );
		foreach ( $data as $key => $value ) {
			[ $type, $value ] = $value;

			$table .= Html::openElement( 'tr' );
			$table .= Html::Element( 'th', [ 'style' => 'width:1%;text-align:left;white-space:nowrap' ],
				str_replace( '_', ' ', $key ) );

			if ( $type !== 'file' ) {
				$table .= Html::Element( 'td', [], $value );

			} else {
				// $link = '<span class="mw-ui-button mw-ui-progressive">view</span>';
				$title_ = SpecialPage::getTitleFor( 'UserVerificationList', $this->userId );
				// $query = [ 'file' => $value ];
				// $value = Linker::link( $title_, $link, [], $query );

				$url = wfAppendQuery( $title_->getLocalURL(),
					[ 'file' => $value ]
				);

				$value = new OOUI\ButtonWidget( [
					'icon' => 'eye',
					'label' => 'view',
					'href' => $url,
				] );

				$table .= Html::RawElement( 'td', [], $value );
			}
			$table .= Html::closeElement( 'tr' );
		}

		$table .= Html::closeElement( 'table' );
		// $out->addHTML( Html::noticeBox( $table, [] ) );
		$out->addHTML( $table );
	}

	/**
	 * @param Request $request
	 * @param array $row
	 */
	protected function passwordForm( $request, $row ) {
		$formDescriptor = [];

		// $sectionManage = 'userverification-special-manage-verification-form-section-name-manage';
		$sectionManage = '';

		$formDescriptor['password'] = [
			'section' => $sectionManage,
			'label-message' => 'userverification-special-manage-verification-form-password-label',
			'type' => 'password',
			'name' => 'password',
			'required' => true,
			'help-message' => 'userverification-special-manage-verification-form-password-help',
			'default' => '',
			'validation-callback' => function () {
				if ( $this->wrongPassord ) {
					// @see includes/htmlform/OOUIHTMLForm.php
					return $this->msg( 'userverification-special-manage-verification-form-wrong-password' )->text();
				}
				return true;
			},

		];
		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );

		$htmlForm->setMethod( 'post' );

		$htmlForm->setSubmitCallback( [ $this, 'onSubmitPassword' ] );

		$htmlForm
			->setWrapperLegendMsg( 'userverification-special-manage-userverification-form-manage-legend' )
			->setSubmitText( $this->msg( 'userverification-special-manage-userverification-form-manage-submit' )->text() )
			->addHeaderText( $this->msg( 'userverification-special-manage-userverification-form-manage-header' )->text() );

		$htmlForm->prepareForm();
		$result = $htmlForm->tryAuthorizedSubmit();

		$htmlForm->displayForm( $result );
		// return $htmlForm->getHTML( false );
	}

	/**
	 * @param Request $request
	 * @param array $row
	 * @return string
	 */
	protected function manageVerificationForm( $request, $row ) {
		$formDescriptor = [];

		// $sectionManage = 'userverification-special-manage-verification-form-section-name-manage';
		$sectionManage = '';

		$formDescriptor['status'] = [
			'section' => $sectionManage,
			'label-message' => 'userverification-special-manage-verification-form-status-label',
			'type' => 'select',
			'name' => 'status',
			'required' => true,
			'help-message' => 'userverification-special-manage-verification-form-status-help',
			'default' => $row['status'],
			'options' => [
				$this->msg( 'userverification-special-manage-verification-form-status-options-none' )->text() => 'none',
				$this->msg( 'userverification-special-manage-verification-form-status-options-pending' )->text() => 'pending',
				$this->msg( 'userverification-special-manage-verification-form-status-options-verified' )->text() => 'verified',
				$this->msg( 'userverification-special-manage-verification-form-status-options-not_required' )->text() => 'not_required'
			]
		];

		$formDescriptor['comments'] = [
			'section' => $sectionManage,
			'label-message' => 'userverification-special-manage-verification-form-comments-label',
			'type' => 'textarea',
			'rows' => 3,
			'name' => 'comments',
			'required' => false,
			'help-message' => 'userverification-special-manage-verification-form-comments-help',
			'default' => $row['comments'],
		];
		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );

		$htmlForm->setMethod( 'post' );

		$htmlForm->setSubmitCallback( [ $this, 'onSubmit' ] );

		$htmlForm
			->setWrapperLegendMsg( 'userverification-special-manage-userverification-form-manage-legend' )
			->setSubmitText( $this->msg( 'userverification-special-manage-userverification-form-manage-submit' )->text() )
			->addHeaderText( $this->msg( 'userverification-special-manage-userverification-form-manage-header' )->text() );

		$htmlForm->prepareForm();
		$result = $htmlForm->tryAuthorizedSubmit();

		// $htmlForm->displayForm( $result );

		return $htmlForm->getHTML( false );
	}

	/**
	 * @param Request $request
	 * @return string
	 */
	protected function showOptions( $request ) {
		$formDescriptor = [];
		$username = $request->getVal( 'username' );
		$formDescriptor['username'] = [
			'label-message' => 'userverification-special-manage-search-form-username-label',
			'type' => 'user',
			'name' => 'username',
			'required' => false,
			'help-message' => 'userverification-special-manage-search-form-username-help',
			'default' => $username ?? null,
		];

		$status = $request->getVal( 'status' );
		$formDescriptor['status'] = [
			'label-message' => 'userverification-special-manage-search-form-status-label',
			'type' => 'select',
			'name' => 'status',
			'required' => false,
			'help-message' => 'userverification-special-manage-search-form-status-help',
			'default' => $status ?? null,
			'options' => [
				// &nbsp;
				' ' => '',
				$this->msg( 'userverification-special-manage-verification-form-status-options-none' )->text() => 'none',
				$this->msg( 'userverification-special-manage-verification-form-status-options-pending' )->text() => 'pending',
				$this->msg( 'userverification-special-manage-verification-form-status-options-verified' )->text() => 'verified',
				$this->msg( 'userverification-special-manage-verification-form-status-options-not_required' )->text() => 'not_required'
			]
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );

		$htmlForm
			->setMethod( 'get' )
			->setWrapperLegendMsg( 'userverification-special-manage-search-form-legend' )
			->setSubmitText( $this->msg( 'userverification-special-manage-search-form-submit' )->text() );

		return $htmlForm->prepareForm()->getHTML( false );
	}

	/**
	 * @param array $data
	 * @return bool
	 */
	public function onSubmitPassword( $data ) {
		$keys = \UserVerification::getKeys();
		$message = null;
		$res = \UserVerification::setUserKey( $keys['protected_key'], $data['password'], $message );

		if ( $res === false ) {
			$this->wrongPassord = true;
			return Status::newFatal( 'formerror' );
		}

		$this->wrongPassord = false;
		$title_ = SpecialPage::getTitleFor( 'UserVerificationList', $this->userId );

		header( 'Location: ' . $title_->getLocalURL() );
		return $res;
	}

	/**
	 * @param array $data
	 * @return bool
	 */
	public function onSubmit( $data ) {
		\UserVerification::setManageVerification( (int)$this->userId, $data );
		return true;
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'userverification';
	}

}
