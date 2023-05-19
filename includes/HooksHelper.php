<?php

namespace MediaWiki\Extension\Thanks;

use MediaWiki\Html\Html;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserIdentity;
use SpecialPage;
use User;

class HooksHelper {

    /**
     * Insert a 'thank' link into revision interface, if the user is allowed to thank.
     *
     * @param RevisionRecord $revisionRecord RevisionRecord object to add the thank link for
     * @param array &$links Links to add to the revision interface
     * @param UserIdentity $userIdentity The user performing the thanks.
     */
    public static function insertThankLink(
        RevisionRecord $revisionRecord,
        array &$links,
        UserIdentity $userIdentity
    ) {
        $recipient = $revisionRecord->getUser();
        if ( $recipient === null ) {
            // Cannot see the user
            return;
        }

        $user = MediaWikiServices::getInstance()->getUserFactory()->newFromUserIdentity( $userIdentity );

        // Don't let users thank themselves.
        // Exclude anonymous users.
        // Exclude users who are blocked.
        // Check whether bots are allowed to receive thanks.
        // Don't allow thanking for a diff that includes multiple revisions
        // Check whether we have a revision id to link to
        if ( $userIdentity->isRegistered()
            && !$userIdentity->equals( $recipient )
            && !self::isUserBlockedFromTitle( $user, $revisionRecord->getPageAsLinkTarget() )
            && !self::isUserBlockedFromThanks( $user )
            && self::canReceiveThanks( $recipient )
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
     * Check whether the user is blocked from the title associated with the revision.
     *
     * This queries the replicas for a block; if 'no block' is incorrectly reported, it
     * will be caught by ApiThank::dieOnUserBlockedFromTitle when the user attempts to thank.
     *
     * @param User $user
     * @param LinkTarget $title
     * @return bool
     */
    public static function isUserBlockedFromTitle( User $user, LinkTarget $title ) {
        return MediaWikiServices::getInstance()->getPermissionManager()
            ->isBlockedFrom( $user, $title, true );
    }

    /**
     * Check whether the user is blocked from giving thanks.
     *
     * @param User $user
     * @return bool
     */
    public static function isUserBlockedFromThanks( User $user ) {
        $block = $user->getBlock();
        return $block && ( $block->isSitewide() || $block->appliesToRight( 'thanks' ) );
    }

    /**
     * Check whether a user is allowed to receive thanks or not
     *
     * @param UserIdentity $user Recipient
     * @return bool true if allowed, false if not
     */
    public static function canReceiveThanks( UserIdentity $user ) {
        global $wgThanksSendToBots;

        $legacyUser = MediaWikiServices::getInstance()->getUserFactory()->newFromUserIdentity( $user );
        if ( !$user->isRegistered() || $legacyUser->isSystemUser() ) {
            return false;
        }

        if ( !$wgThanksSendToBots && $legacyUser->isBot() ) {
            return false;
        }

        return true;
    }

    /**
     * Helper for HooksHelper::insertThankLink
     * Creates either a thank link or thanked span based on users session
     * @param int $id Revision or log ID to generate the thank element for.
     * @param User $sender User who sends thanks notification.
     * @param UserIdentity $recipient User who receives thanks notification.
     * @param string $type Either 'revision' or 'log'.
     * @return string
     */
    public static function generateThankElement(
        $id, User $sender, UserIdentity $recipient, $type = 'revision'
    ) {
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

        $genderCache = MediaWikiServices::getInstance()->getGenderCache();
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
                'data-recipient-gender' => $genderCache->getGenderOf( $recipient->getName(), __METHOD__ ),
            ],
            wfMessage( 'thanks-thank', $sender->getName(), $recipient->getName() )->text()
        );
    }

}