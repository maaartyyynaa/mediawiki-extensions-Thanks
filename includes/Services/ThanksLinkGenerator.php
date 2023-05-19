<?php

namespace MediaWiki\Extension\Thanks\Services;

use GenderCache;
use MediaWiki\Html\Html;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use SpecialPage;
use User;

/**
 * A service for generating and inserting thank HTML elements.
 */
class ThanksLinkGenerator {
	private UserFactory $userFactory;
	private GenderCache $genderCache;
	private ThanksPermissionHelper $thankPermissionHelper;

	/**
	 * @param UserFactory $userFactory
	 * @param GenderCache $genderCache
	 * @param ThanksPermissionHelper $thankPermissionHelper
	 */
	public function __construct(
		UserFactory $userFactory,
		GenderCache $genderCache,
		ThanksPermissionHelper $thankPermissionHelper
	) {
		$this->userFactory = $userFactory;
		$this->genderCache = $genderCache;
		$this->thankPermissionHelper = $thankPermissionHelper;
	}

	/**
	 * Insert a 'thank' link into revision interface, if the user is allowed to thank.
	 *
	 * @param RevisionRecord $revisionRecord RevisionRecord object to add the thank link for
	 * @param array &$links Links to add to the revision interface
	 * @param UserIdentity $userIdentity The user performing the thanks.
	 */
	public function insertThankLink(
		RevisionRecord $revisionRecord,
		array &$links,
		UserIdentity $userIdentity
	) {
		$recipient = $revisionRecord->getUser();
		if ( $recipient === null ) {
			// Cannot see the user
			return;
		}

		$user = $this->userFactory->newFromUserIdentity( $userIdentity );

		// Don't let users thank themselves.
		// Exclude anonymous users.
		// Exclude users who are blocked.
		// Check whether bots are allowed to receive thanks.
		// Don't allow thanking for a diff that includes multiple revisions
		// Check whether we have a revision id to link to
		if ( $userIdentity->isRegistered()
			&& !$userIdentity->equals( $recipient )
			&& !$this->thankPermissionHelper->isUserBlockedFromTitle( $user, $revisionRecord->getPageAsLinkTarget() )
			&& !$this->thankPermissionHelper->isUserBlockedFromThanks( $user )
			&& $this->thankPermissionHelper->canReceiveThanks( $recipient )
			&& !$revisionRecord->isDeleted( RevisionRecord::DELETED_TEXT )
			&& $revisionRecord->getId() !== 0
		) {
			$links[] = self::generateThankElement(
				$revisionRecord->getId(),
				$user,
				$recipient
			);
		}
	}

	/**
	 * Helper for HooksHelper::insertThankLink
	 * Creates either a thank link or thanked span based on users session
	 * @param int $id Revision or log ID to generate the thank element for.
	 * @param User $sender User who sends thanks notification.
	 * @param UserIdentity $recipient User who receives thanks notification.
	 * @param string $type Either 'revision' or 'log'.
	 */
	public function generateThankElement(
		int $id,
		User $sender,
		UserIdentity $recipient,
		string $type = 'revision'
	): string {
		// Check if the user has already thanked for this revision or log entry.
		// Session keys are backwards-compatible, and are also used in the ApiCoreThank class.
		$sessionKey = ( $type === 'revision' ) ? $id : $type . $id;
		if ( $sender->getRequest()->getSessionData( "thanks-thanked-$sessionKey" ) ) {
			return Html::element(
				'span',
				[ 'class' => 'mw-thanks-thanked' ],
				wfMessage( 'thanks-thanked', $sender->getName(), $recipient->getName() )->text()
			);
		}

		// Add 'thank' link
		$tooltip = wfMessage( 'thanks-thank-tooltip' )
			->params( $sender->getName(), $recipient->getName() )
			->text();

		$subpage = ( $type === 'revision' ) ? '' : 'Log/';
		return Html::element(
			'a',
			[
				'class' => 'mw-thanks-thank-link',
				'href' => SpecialPage::getTitleFor( 'Thanks', $subpage . $id )->getFullURL(),
				'title' => $tooltip,
				'data-' . $type . '-id' => $id,
				'data-recipient-gender' => $this->genderCache->getGenderOf( $recipient->getName(), __METHOD__ ),
			],
			wfMessage( 'thanks-thank', $sender->getName(), $recipient->getName() )->text()
		);
	}
}
