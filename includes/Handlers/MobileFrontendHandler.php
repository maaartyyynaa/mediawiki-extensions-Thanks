<?php

namespace MediaWiki\Extension\Thanks\Handlers;

use ExtensionRegistry;
use MediaWiki\Extension\Thanks\Services\ThanksPermissionHelper;
use MobileContext;
use OutputPage;

class MobileFrontendHandler {
	private ThanksPermissionHelper $thankPermissionHelper;

	public function __construct( ThanksPermissionHelper $thankPermissionHelper ) {
		$this->thankPermissionHelper = $thankPermissionHelper;
	}

	/**
	 * Add thanks button to SpecialMobileDiff page
	 * @param OutputPage &$output OutputPage object
	 * @param MobileContext $ctx MobileContext object
	 * @param array $revisions Array with two elements, either nulls or RevisionRecord objects for
	 *     the two revisions that are being compared in the diff
	 */
	public function onBeforeSpecialMobileDiffDisplay(
		OutputPage &$output,
		MobileContext $ctx,
		array $revisions
	) {
		$rev = $revisions[1];

		// If the MobileFrontend extension is installed and the user is
		// logged in or recipient is not a bot if bots cannot receive thanks, show a 'Thank' link.
		if ( $rev
			&& ExtensionRegistry::getInstance()->isLoaded( 'MobileFrontend' )
			&& $rev->getUser()
			&& $this->thankPermissionHelper->canReceiveThanks( $rev->getUser() )
			&& $output->getUser()->isRegistered()
		) {
			$output->addModules( [ 'ext.thanks.mobilediff' ] );

			if ( $output->getRequest()->getSessionData( 'thanks-thanked-' . $rev->getId() ) ) {
				// User already sent thanks for this revision
				$output->addJsConfigVars( 'wgThanksAlreadySent', true );
			}
		}
	}
}
