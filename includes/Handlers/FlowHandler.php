<?php

namespace MediaWiki\Extension\Thanks\Handlers;

use ApiModuleManager;
use ExtensionRegistry;
use MediaWiki\Api\Hook\ApiMain__moduleManagerHook;
use MediaWiki\Extension\Thanks\ApiFlowThank;

class FlowHandler implements ApiMain__moduleManagerHook {

	/**
	 * Conditionally load API module 'flowthank' depending on whether
	 * Flow is installed.
	 *
	 * @param ApiModuleManager $moduleManager Module manager instance
	 */
	public function onApiMain__moduleManager( $moduleManager ) {
		if ( ExtensionRegistry::getInstance()->isLoaded( 'Flow' ) ) {
			$moduleManager->addModule(
				'flowthank',
				'action',
				ApiFlowThank::class
			);
		}
	}

}
