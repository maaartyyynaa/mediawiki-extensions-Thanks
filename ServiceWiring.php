<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\Thanks\Services\ThanksLinkGenerator;
use MediaWiki\Extension\Thanks\Services\ThanksPermissionHelper;
use MediaWiki\Extension\Thanks\Services\ThanksQueryHelper;
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
	'ThanksPermissionHelper' => static function (
		MediaWikiServices $services
		): ThanksPermissionHelper {
			return new ThanksPermissionHelper(
				$services->getPermissionManager(),
				$services->getUserFactory(),
				new ServiceOptions(
					ThanksPermissionHelper::CONSTRUCTOR_OPTIONS,
					$services->getMainConfig()
				)
			);
	},
	'ThanksLinkGenerator' => static function (
		MediaWikiServices $services
		): ThanksLinkGenerator {
			return new ThanksLinkGenerator(
				$services->getUserFactory(),
				$services->getGenderCache(),
				$services->get( 'ThanksPermissionHelper' )
			);
	},
];
