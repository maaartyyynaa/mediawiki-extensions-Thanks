<?php

namespace MediaWiki\Extension\Thanks\Storage;

use DatabaseLogEntry;
use ExtensionRegistry;
use ManualLogEntry;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\Thanks\Hooks;
use MediaWiki\Extension\Thanks\Storage\Exceptions\InvalidLogType;
use MediaWiki\Extension\Thanks\Storage\Exceptions\LogDeleted;
use MediaWiki\User\ActorNormalization;
use User;
use Wikimedia\Rdbms\ILoadBalancer;

class LogStorage {

	protected ILoadBalancer $lb;
	protected ActorNormalization $actorNormalization;
	public const CONSTRUCTOR_OPTIONS = [ 'ThanksLogging', 'ThanksAllowedLogTypes' ];
	protected ServiceOptions $serviceOptions;

	public function __construct(
		ILoadBalancer $lb,
		ActorNormalization $actorNormalization,
		ServiceOptions $serviceOptions
	) {
		$this->lb = $lb;
		$this->actorNormalization = $actorNormalization;
		$this->serviceOptions = $serviceOptions;
	}

	/**
	 * @param User $user The user performing the thanks (and the log entry).
	 * @param User $recipient The target of the thanks (and the log entry).
	 * @param string $uniqueId A unique Id to identify the event being thanked for, to use
	 *                         when checking for duplicate thanks
	 */
	public function thank( User $user, User $recipient, $uniqueId ) {
		if ( !$this->serviceOptions->get( 'ThanksLogging' ) ) {
			return;
		}
		$logEntry = new ManualLogEntry( 'thanks', 'thank' );
		$logEntry->setPerformer( $user );
		$logEntry->setRelations( [ 'thankid' => $uniqueId ] );
		$target = $recipient->getUserPage();
		$logEntry->setTarget( $target );
		$logId = $logEntry->insert();
		$logEntry->publish( $logId, 'udp' );

		if ( ExtensionRegistry::getInstance()->isLoaded( 'CheckUser' ) ) {
			$recentChange = $logEntry->getRecentChange();
			// TODO: This should be done in a separate hook handler
			Hooks::updateCheckUserData( $recentChange );
		}
	}

	/**
	 * This checks the log_search data.
	 *
	 * @param User $thanker The user sending the thanks.
	 * @param string $uniqueId The identifier for the thanks.
	 * @return bool Whether thanks has already been sent
	 */
	public function haveThanked( User $thanker, string $uniqueId ): bool {
		// TODO: Figure out why it's not getting the data from a replica
		$dbw = $this->lb->getConnection( DB_PRIMARY );
		$thankerActor = $this->actorNormalization->acquireActorId( $thanker, $dbw );
		return $dbw->newSelectQueryBuilder()
			->select( 'ls_value' )
			->from( 'log_search' )
			->join( 'logging', null, [ 'ls_log_id=log_id' ] )
			->where(
				[
					'log_actor' => $thankerActor,
					'ls_field' => 'thankid',
					'ls_value' => $uniqueId,
				]
			)
			->caller( __METHOD__ )
			->fetchRow();
	}

	/**
	 * @throws InvalidLogType
	 * @throws LogDeleted
	 */
	public function getLogEntryFromId( int $id ): ?DatabaseLogEntry {
		$logEntry = DatabaseLogEntry::newFromId( $id, $this->lb->getConnection( DB_REPLICA ) );

		if ( !$logEntry ) {
			return null;
		}

		// Make sure this log type is allowed.
		$allowedLogTypes = $this->serviceOptions->get( 'ThanksAllowedLogTypes' );
		if ( !in_array( $logEntry->getType(), $allowedLogTypes )
			 && !in_array( $logEntry->getType() . '/' . $logEntry->getSubtype(), $allowedLogTypes ) ) {
			throw new InvalidLogType( $logEntry->getType() );
		}

		// Don't permit thanks if any part of the log entry is deleted.
		if ( $logEntry->getDeleted() ) {
			throw new LogDeleted();
		}
		return $logEntry;
	}
}
