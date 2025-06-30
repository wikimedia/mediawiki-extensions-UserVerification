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

include_once __DIR__ . '/Countries.php';

use MediaWiki\Extension\UserVerification\Aliases\Html as HtmlClass;

class SpecialUserVerification extends SpecialPage {

	/** @var User|MediaWiki\User */
	public $user;

	/** @var WebRequest */
	public $request;

	/** @var Title|MediaWiki\Title\Title */
	public $localTitle;

	/** @var array */
	public $formDescriptor;

	/**
	 * @inheritDoc
	 */
	public function __construct( $name = 'UserVerification' ) {
		$listed = false;
		parent::__construct( 'UserVerification', '', $listed );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ) {
		$this->requireLogin();
		$this->setHeaders();
		$this->outputHeader();

		$user = $this->getUser();
		$out = $this->getOutput();

		\UserVerification::disableClientCache( $out );
		\UserVerification::addJsConfigVars( $out, $user );

		$out->addModuleStyles( 'mediawiki.special' );
		$out->addModules( [ 'ext.UserVerification' ] );
		$this->addHelpLink( 'Extension:UserVerification' );
		$out->enableOOUI();

		$request = $this->getRequest();
		$this->request = $request;
		$this->user = $this->getUser();
		$this->localTitle = SpecialPage::getTitleFor( 'UserVerification' );

		\UserVerification::addHeaditem( $out, [
			[ 'stylesheet', $GLOBALS['wgResourceBasePath'] . '/extensions/UserVerification/resources/style.css' ],
		] );

		// \UserVerification::addIndicator( $out );

		$dbr = \UserVerification::getDB( DB_REPLICA );
		$row = $dbr->selectRow( 'userverification_verification', '*', [ 'user_id' => $this->user->getId() ], __METHOD__ );
		$data = ( $row ? json_decode( (string)$row->data, true ) : [] );

		if ( $request->getVal( 'return' ) ) {
			$out->addWikiMsg(
				'userverification-special-userverification-returnlink',
				$request->getVal( 'return' )
			);
		}

		$out->addHTML( HtmlClass::Element( 'h3', [], $this->user->getName() ) );

		if ( !$row || $row->status !== 'pending' ) {
			$out->addHTML( $this->userVerificationForm( $request, $data ) );

		} else {
			// $out->addWikiMsg( 'userverifiedtoreadtext-pending' );
			$out->addHTML( '<br />' );
			$out->addHTML( new \OOUI\MessageWidget( [
				'type' => 'notice',
				'icon' => 'info',
				'label' => $this->msg( 'userverifiedtoreadtext-pending' )->text()
			] ) );
		}
	}

	/**
	 * @param Request $request
	 * @param array $data
	 * @return string
	 */
	protected function userVerificationForm( $request, $data ) {
		// phpcs:ignore MediaWiki.NamingConventions.ValidGlobalName.allowedPrefix, MediaWiki.Usage.ExtendClassUsage.FunctionConfigUsage
		global $Countries;

		$countries = [
			// &nbsp;
			' ' => ''
		];
		$countries += array_flip( (array)$Countries );

		$formDescriptor = [];

		// sectionUser = 'userverification-special-manage-verification-form-section-name-data';
		$sectionUser = '';
		$formDescriptor['info'] = [
			'section' => $sectionUser,
			'type' => 'info',
			'default' => new \OOUI\MessageWidget( [
				'type' => 'notice',
				'icon' => 'lock',
				'label' => $this->msg( 'userverification-special-manage-userverification-form-header' )->text()
			] ),
		];

		$formDescriptor['first_name'] = [
			'section' => $sectionUser,
			'label-message' => 'userverification-special-manage-verification-form-first_name-label',
			'type' => 'text',
			'name' => 'first_name',
			'required' => true,
			'help-message' => 'userverification-special-manage-verification-form-first_name-help',
			'default' => $data['first_name'] ?? null,
		];
		$formDescriptor['last_name'] = [
			'section' => $sectionUser,
			'label-message' => 'userverification-special-manage-verification-form-last_name-label',
			'type' => 'text',
			'name' => 'last_name',
			'required' => true,
			'help-message' => 'userverification-special-manage-verification-form-last_name-help',
			'default' => $data['last_name'] ?? null,
		];
		$formDescriptor['sex'] = [
			'section' => $sectionUser,
			'label-message' => 'userverification-special-manage-verification-form-sex-label',
			'type' => 'select',
			'name' => 'sex',
			'required' => true,
			'help-message' => 'userverification-special-manage-verification-form-sex-help',
			'default' => $data['sex'] ?? null,
			'options' => [
				// &nbsp;
				' ' => '',
				$this->msg( 'userverification-special-manage-verification-form-sex-options-male' )->text() => 'male',
				$this->msg( 'userverification-special-manage-verification-form-sex-options-female' )->text() => 'female',
				$this->msg( 'userverification-special-manage-verification-form-sex-options-decline' )->text() => 'decline'
			]
		];
		$formDescriptor['date_of_birth'] = [
			'section' => $sectionUser,
			'label-message' => 'userverification-special-manage-verification-form-date_of_birth-label',
			'type' => 'date',
			'name' => 'date_of_birth',
			'required' => true,
			'help-message' => 'userverification-special-manage-verification-form-date_of_birth-help',
			'default' => $data['date_of_birth'] ?? null,
		];
		$formDescriptor['place_of_birth'] = [
			'section' => $sectionUser,
			'label-message' => 'userverification-special-manage-verification-form-place_of_birth-label',
			'type' => 'text',
			'name' => 'place_of_birth',
			'required' => true,
			'help-message' => 'userverification-special-manage-verification-form-place_of_birth-help',
			'default' => $data['place_of_birth'] ?? null,
		];
		$formDescriptor['country_of_birth'] = [
			'section' => $sectionUser,
			'label-message' => 'userverification-special-manage-verification-form-country_of_birth-label',
			'type' => 'select',
			'name' => 'country_of_birth',
			'required' => true,
			'help-message' => 'userverification-special-manage-verification-form-country_of_birth-help',
			'default' => $data['country_of_birth'] ?? null,
			'options' => $countries
		];
		$formDescriptor['country_of_residence'] = [
			'section' => $sectionUser,
			'label-message' => 'userverification-special-manage-verification-form-country_of_residence-label',
			'type' => 'select',
			'name' => 'country_of_residence',
			'required' => true,
			'help-message' => 'userverification-special-manage-verification-form-country_of_residence-help',
			'default' => $data['country_of_residence'] ?? null,
			'options' => $countries
		];
		$formDescriptor['address_of_residence'] = [
			'section' => $sectionUser,
			'label-message' => 'userverification-special-manage-verification-form-address_of_residence-label',
			'type' => 'text',
			'name' => 'address_of_residence',
			'required' => true,
			'help-message' => 'userverification-special-manage-verification-form-address_of_residence-help',
			'default' => $data['address_of_residence'] ?? null,
		];
		$formDescriptor['email'] = [
			'section' => $sectionUser,
			'label-message' => 'userverification-special-manage-verification-form-email-label',
			'type' => 'email',
			'name' => 'email',
			'required' => true,
			'help-message' => 'userverification-special-manage-verification-form-email-help',
			'default' => $data['email'] ?? null,
		];
		$formDescriptor['phone_number'] = [
			'section' => $sectionUser,
			'label-message' => 'userverification-special-manage-verification-form-phone_number-label',
			'type' => 'text',
			'name' => 'phone_number',
			'required' => false,
			'help-message' => 'userverification-special-manage-verification-form-phone_number-help',
			'default' => $data['phone_number'] ?? null,
		];
		$formDescriptor['proof_of_identity'] = [
			'section' => $sectionUser,
			'label-message' => 'userverification-special-manage-verification-form-proof_of_identity-label',
			'type' => 'file',
			'accept' => [ 'application/pdf', 'image/png', 'image/jpeg' ],
			'name' => 'proof_of_identity',
			'required' => true,
			'help-message' => 'userverification-special-manage-verification-form-proof_of_identity-help',
			'default' => $data['proof_of_identity'] ?? null,
		];
		// $formDescriptor['identity_document_type'] = [
		// 	'label-message' => 'userverification-special-manage-verification-form-identity_document_type-label',
		// 	'type' => 'radio',
		// 	'name' => 'identity_document_type',
		// 	'required' => false,
		// 	'help-message' => 'userverification-special-manage-verification-form-identity_document_type-help',
		// 	'default' => $searchUsername ?? null,
		// ];
		$formDescriptor['proof_of_residence'] = [
			'section' => $sectionUser,
			'label-message' => 'userverification-special-manage-verification-form-proof_of_residence-label',
			'type' => 'file',
			'accept' => [ 'application/pdf', 'image/png', 'image/jpeg' ],
			'name' => 'proof_of_residence',
			'required' => false,
			'help-message' => 'userverification-special-manage-verification-form-proof_of_residence-help',
			'default' => $data['proof_of_residence'] ?? null,
		];

		// $formDescriptor['return'] = [
		// 	'section' => $sectionUser,
		// 	'type' => 'hidden',
		// 	'name' => 'return',
		// 	'default' => $request->getVal( 'return' ),
		// 	'value' => $request->getVal( 'return' )
		// ];

		$this->formDescriptor = $formDescriptor;

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setId( 'userverification-form-verification' );

		$htmlForm->setAction(
			wfAppendQuery( $this->localTitle->getLocalURL(),
				[ 'return' => $request->getVal( 'return' ) ]
			)
		);

		$htmlForm->setMethod( 'post' );
		$htmlForm->setSubmitCallback( [ $this, 'onSubmit' ] );

		$htmlForm
			->setMethod( 'post' )
			->setWrapperLegendMsg( 'userverification-special-manage-userverification-form-legend' )
			->setSubmitText( $this->msg( 'userverification-special-manage-userverification-form-submit' )->text() );
			// ->addHeaderHtml( $this->msg( 'userverification-special-manage-userverification-form-header' )->parse() );

		$htmlForm->prepareForm();
		$result = $htmlForm->tryAuthorizedSubmit();
		// $htmlForm->displayForm( $result );

		return $htmlForm->getHTML( false );
	}

	/**
	 * @param array $data
	 * @return bool
	 */
	public function onSubmit( $data ) {
		$ret = [];

		foreach ( $data as $key => $value ) {
			$ret[$key] = [
				$this->formDescriptor[$key]['type'],
				$value,
			];
		}

		\UserVerification::setVerificationData( $this->user, $ret );
		$title_ = SpecialPage::getTitleFor( 'UserVerification' );
		$request = $this->getRequest();
		$url = wfAppendQuery( $title_->getLocalURL(), [ 'return' => $request->getVal( 'return' ) ] );
		header( 'Location: ' . $url );
		return true;
	}
}
