<?php

namespace MediaWiki\Extension\Thanks\Handlers;

use Article;
use Config;
use ConfigException;
use DatabaseLogEntry;
use DifferenceEngine;
use IContextSource;
use LogEventsList;
use LogPage;
use MediaWiki\Auth\Hook\LocalUserCreatedHook;
use MediaWiki\Block\Hook\GetAllBlockActionsHook;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Diff\Hook\DifferenceEngineViewHeaderHook;
use MediaWiki\Diff\Hook\DiffToolsHook;
use MediaWiki\Extension\Thanks\Services\ThanksLinkGenerator;
use MediaWiki\Extension\Thanks\Services\ThanksPermissionHelper;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\GetLogTypesOnUserHook;
use MediaWiki\Hook\HistoryToolsHook;
use MediaWiki\Hook\LogEventsListLineEndingHook;
use MediaWiki\Hook\PageHistoryBeforeListHook;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserOptionsManager;
use OutputPage;
use Skin;
use Title;
use User;

/**
 * Hooks for Thanks extension
 *
 * @file
 * @ingroup Extensions
 */
class MainHandler implements
	BeforePageDisplayHook,
	DiffToolsHook,
	DifferenceEngineViewHeaderHook,
	GetAllBlockActionsHook,
	GetLogTypesOnUserHook,
	HistoryToolsHook,
	LocalUserCreatedHook,
	LogEventsListLineEndingHook,
	PageHistoryBeforeListHook
{

	private RevisionLookup $revisionLookup;
	private UserOptionsManager $userOptionsManager;
	private ThanksPermissionHelper $thankPermissionHelper;
	private ThanksLinkGenerator $linkGenerator;
	private ServiceOptions $options;
	public const CONSTRUCTOR_OPTIONS = [
		'ThanksConfirmationRequired',
		'ThanksAllowedLogTypes'
	];

	public function __construct(
		RevisionLookup $revisionLookup,
		UserOptionsManager $userOptionsManager,
		Config $config,
		ThanksPermissionHelper $thankPermissionHelper,
		ThanksLinkGenerator $linkGenerator
	) {
		$this->revisionLookup = $revisionLookup;
		$this->userOptionsManager = $userOptionsManager;
		$this->thankPermissionHelper = $thankPermissionHelper;
		$this->linkGenerator = $linkGenerator;
		$options = new ServiceOptions( self::CONSTRUCTOR_OPTIONS, $config );
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
	}

	/**
	 * Handler for the HistoryTools hook
	 *
	 * @param RevisionRecord $revRecord
	 * @param array &$links
	 * @param RevisionRecord|null $prevRevRecord
	 * @param UserIdentity $userIdentity
	 */
	public function onHistoryTools( $revRecord, &$links, $prevRevRecord, $userIdentity ) {
		$this->linkGenerator->insertThankLink( $revRecord,
			$links, $userIdentity );
	}

	/**
	 * Handler for the DiffTools hook
	 *
	 * @param RevisionRecord $newRevRecord
	 * @param array &$links
	 * @param RevisionRecord|null $oldRevRecord
	 * @param UserIdentity $userIdentity
	 */
	public function onDiffTools( $newRevRecord, &$links, $oldRevRecord, $userIdentity ) {
		// Don't allow thanking for a diff that includes multiple revisions
		// This does a query that is too expensive for history rows (T284274)
		$previous = $this->revisionLookup
			->getPreviousRevision( $newRevRecord );
		if ( $oldRevRecord && $previous &&
			$previous->getId() !== $oldRevRecord->getId()
		) {
			return;
		}

		$this->linkGenerator->insertThankLink( $newRevRecord,
			$links, $userIdentity );
	}

	/**
	 * @param OutputPage $outputPage The OutputPage to add the module to.
	 */
	protected function addThanksModule( OutputPage $outputPage ) {
		$confirmationRequired = $this->options->get( 'ThanksConfirmationRequired' );
		$outputPage->addModules( [ 'ext.thanks.corethank' ] );
		$outputPage->addJsConfigVars( 'thanks-confirmation-required', $confirmationRequired );
	}

	/**
	 * Handler for PageHistoryBeforeList hook.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageHistoryBeforeList
	 *
	 * @param Article $article Not used
	 * @param IContextSource $context RequestContext object
	 */
	public function onPageHistoryBeforeList( $article, $context ) {
		if ( $context->getUser()->isRegistered() ) {
			$this->addThanksModule( $context->getOutput() );
		}
	}

	/**
	 * Handler for DifferenceEngineViewHeader hook.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/DifferenceEngineViewHeader
	 * @param DifferenceEngine $differenceEngine DifferenceEngine object that's calling.
	 */
	public function onDifferenceEngineViewHeader( $differenceEngine ) {
		if ( $differenceEngine->getUser()->isRegistered() ) {
			$this->addThanksModule( $differenceEngine->getOutput() );
		}
	}

	/**
	 * Handler for LocalUserCreated hook
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/LocalUserCreated
	 * @param User $user User object that was created.
	 * @param bool $autocreated True when account was auto-created
	 */
	public function onLocalUserCreated( $user, $autocreated ) {
		// New users get echo preferences set that are not the default settings for existing users.
		// Specifically, new users are opted into email notifications for thanks.
		if ( !$autocreated ) {
			$this->userOptionsManager->setOption( $user, 'echo-subscriptions-email-edit-thank', true );
		}
	}

	/**
	 * Handler for GetLogTypesOnUser.
	 * So users can just type in a username for target and it'll work.
	 * @link https://www.mediawiki.org/wiki/Manual:Hooks/GetLogTypesOnUser
	 * @param string[] &$types The list of log types, to add to.
	 */
	public function onGetLogTypesOnUser( &$types ) {
		$types[] = 'thanks';
	}

	public function onGetAllBlockActions( &$actions ) {
		$actions[ 'thanks' ] = 100;
	}

	/**
	 * Handler for BeforePageDisplay.  Inserts javascript to enhance thank
	 * links from static urls to in-page dialogs along with reloading
	 * the previously thanked state.
	 * @link https://www.mediawiki.org/wiki/Manual:Hooks/BeforePageDisplay
	 * @param OutputPage $out OutputPage object
	 * @param Skin $skin The skin in use.
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$title = $out->getTitle();
		// Add to Flow boards.
		if ( $title instanceof Title && $title->hasContentModel( 'flow-board' ) ) {
			$out->addModules( 'ext.thanks.flowthank' );
		}
		// Add to special pages where thank links appear
		if (
			$title->isSpecial( 'Log' ) ||
			$title->isSpecial( 'Contributions' ) ||
			$title->isSpecial( 'DeletedContributions' ) ||
			$title->isSpecial( 'Recentchanges' ) ||
			$title->isSpecial( 'Recentchangeslinked' ) ||
			$title->isSpecial( 'Watchlist' )
		) {
			$this->addThanksModule( $out );
		}
	}

	/**
	 * Insert a 'thank' link into the log interface, if the user is allowed to thank.
	 *
	 * @link https://www.mediawiki.org/wiki/Manual:Hooks/LogEventsListLineEnding
	 * @param LogEventsList $page The log events list.
	 * @param string &$ret The line ending HTML, to modify.
	 * @param DatabaseLogEntry $entry The log entry.
	 * @param string[] &$classes CSS classes to add to the line.
	 * @param string[] &$attribs HTML attributes to add to the line.
	 * @throws ConfigException
	 */
	public function onLogEventsListLineEnding( $page, &$ret, $entry, &$classes,
											   &$attribs
	) {
		$user = $page->getUser();

		// Don't thank if anonymous or blocked or if user is deleted from the log entry
		if (
			$user->isAnon()
			|| $entry->isDeleted( LogPage::DELETED_USER )
			|| $this->thankPermissionHelper->isUserBlockedFromTitle( $user, $entry->getTarget() )
			|| $this->thankPermissionHelper->isUserBlockedFromThanks( $user )
		) {
			return;
		}

		// Make sure this log type is allowed.
		$allowedLogTypes = $this->options->get( 'ThanksAllowedLogTypes' );
		if ( !in_array( $entry->getType(), $allowedLogTypes )
			&& !in_array( $entry->getType() . 'MainHandler.php/' . $entry->getSubtype(), $allowedLogTypes ) ) {
			return;
		}

		// Don't thank if no recipient,
		// or if recipient is the current user or unable to receive thanks.
		// Don't check for deleted revision (this avoids extraneous queries from Special:Log).

		$recipient = $entry->getPerformerIdentity();
		if (
			$recipient->getId() === $user->getId()
			|| !$this->thankPermissionHelper->canReceiveThanks( $recipient )
		) {
			return;
		}

		// Create thank link either for the revision (if there is an associated revision ID)
		// or the log entry.
		$type = $entry->getAssociatedRevId() ? 'revision' : 'log';
		$id = $entry->getAssociatedRevId() ?: $entry->getId();
		$thankLink = $this->linkGenerator->generateThankElement( $id, $user, $recipient, $type );

		// Add parentheses to match what's done with Thanks in revision lists and diff displays.
		$ret .= ' ' . wfMessage( 'parentheses' )->rawParams( $thankLink )->escaped();
	}
}
