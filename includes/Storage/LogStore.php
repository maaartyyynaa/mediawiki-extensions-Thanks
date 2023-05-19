<?php

namespace MediaWiki\Extension\Thanks\Storage;

use DatabaseLogEntry;
use ExtensionRegistry;
use ManualLogEntry;
use MediaWiki\CheckUser\Hooks;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\Thanks\Storage\Exceptions\InvalidLogType;
use MediaWiki\Extension\Thanks\Storage\Exceptions\LogDeleted;
use MediaWiki\User\ActorNormalization;
use User;
use Wikimedia\Rdbms\IConnectionProvider;

class LogStore {

	protected IConnectionProvider $conn;
	protected ActorNormalization $actorNormalization;
	public const CONSTRUCTOR_OPTIONS = [ 'ThanksLogging', 'ThanksAllowedLogTypes' ];
	protected ServiceOptions $serviceOptions;

	public function __construct(
		IConnectionProvider $conn,
		ActorNormalization $actorNormalization,
		ServiceOptions $serviceOptions
	) {
		$this->conn = $conn;
		$this->actorNormalization = $actorNormalization;
		$serviceOptions->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->serviceOptions = $serviceOptions;
	}

	/**
	 * @param User $user The user performing the thanks (and the log entry).
	 * @param User $recipient The target of the thanks (and the log entry).
	 * @param string $uniqueId A unique Id to identify the event being thanked for, to use
	 *                         when checking for duplicate thanks
	 */
	public function thank( User $user, User $recipient, string $uniqueId ): void {
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
		$dbw = $this->conn->getPrimaryDatabase();
		$thankerActor = $this->actorNormalization->acquireActorId( $thanker, $dbw );
		return (bool)$dbw->newSelectQueryBuilder()
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
	public function getLogEntryFromId( int $logId ): ?DatabaseLogEntry {
		$logEntry = DatabaseLogEntry::newFromId( $logId, $this->conn->getPrimaryDatabase() );

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
