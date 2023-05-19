<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\Thanks\Storage\LogStorage;
use MediaWiki\Extension\Thanks\ThanksQueryHelper;
use MediaWiki\MediaWikiServices;

return [
	'ThanksQueryHelper' => static function (
			MediaWikiServices $services
		): ThanksQueryHelper {
			return new ThanksQueryHelper(
				$services->getTitleFactory(),
				$services->getDBLoadBalancer()
			);
	},
	'LogStorage' => static function ( MediaWikiServices $services ): LogStorage {
		return new LogStorage(
			$services->getDBLoadBalancer(),
			$services->getActorNormalization(),
			new ServiceOptions(
				LogStorage::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			)
		);
	}
];
