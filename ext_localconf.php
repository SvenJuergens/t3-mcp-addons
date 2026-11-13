<?php

declare(strict_types=1);

defined('TYPO3') or die();

// MCP works only in the MCP workspace chosen in the user settings, see the
// class comment for the details.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][\Hn\McpServer\Service\WorkspaceContextService::class] = [
    'className' => \SvenJuergens\T3McpAddons\Xclass\WorkspaceContextService::class,
];
