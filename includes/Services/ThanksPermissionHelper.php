<?php

namespace MediaWiki\Extension\Thanks\Services;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use User;

/**
 * Helper class for checking whether a user is allowed to give thanks.
 * Used in hooks and link generation.
 */
class ThanksPermissionHelper {
	private PermissionManager $permissionManager;
	private UserFactory $userFactory;
	private ServiceOptions $options;

	public const CONSTRUCTOR_OPTIONS = [
		'ThanksSendToBots',
	];

	public function __construct(
		PermissionManager $permissionManager,
		UserFactory $userFactory,
		ServiceOptions $options
	) {
		$this->permissionManager = $permissionManager;
		$this->userFactory = $userFactory;
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
	}

	/**
	 * Check whether the user is blocked from giving thanks.
	 *
	 * @param User $user
	 * @return bool
	 */
	public function isUserBlockedFromThanks( User $user ): bool {
		$block = $user->getBlock();
		return $block && ( $block->isSitewide() || $block->appliesToRight( 'thanks' ) );
	}

	/**
	 * Check whether the user is blocked from the title associated with the revision.
	 *
	 * This queries the replicas for a block; if 'no block' is incorrectly reported, it
	 * will be caught by ApiThank::dieOnUserBlockedFromTitle when the user attempts to thank.
	 *
	 * @param User $user
	 * @param LinkTarget $title
	 * @return bool
	 */
	public function isUserBlockedFromTitle( User $user, LinkTarget $title ): bool {
		return $this->permissionManager
			->isBlockedFrom( $user, $title, true );
	}

	/**
	 * Check whether a user is allowed to receive thanks or not
	 *
	 * @param UserIdentity $user Recipient
	 * @return bool true if allowed, false if not
	 */
	public function canReceiveThanks( UserIdentity $user ): bool {
		$legacyUser = $this->userFactory->newFromUserIdentity( $user );
		if ( !$user->isRegistered() || $legacyUser->isSystemUser() ) {
			return false;
		}

		if ( !$this->options->get( 'ThanksSendToBots' ) && $legacyUser->isBot() ) {
			return false;
		}

		return true;
	}
}
