<?php

declare(strict_types=1);

use SvenJuergens\T3McpAddons\UserFunction\McpWorkspaceItems;
use SvenJuergens\T3McpAddons\Workspace\McpWorkspaceResolver;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// TYPO3 13: user settings of EXT:setup. TYPO3 14 registers the setting in
// Configuration/TCA/Overrides/be_users.php instead - the legacy array would
// still be read there, but its itemsProcFunc is called the TCA way and the
// select would stay empty.
if (!method_exists(ExtensionManagementUtility::class, 'addUserSetting')) {
    $GLOBALS['TYPO3_USER_SETTINGS']['columns'][McpWorkspaceResolver::FIELD] = [
        'type' => 'select',
        'table' => 'be_users',
        'label' => 'LLL:EXT:t3_mcp_addons/Resources/Private/Language/locallang.xlf:be_users.' . McpWorkspaceResolver::FIELD,
        'itemsProcFunc' => McpWorkspaceItems::class . '->renderSelect',
    ];
    ExtensionManagementUtility::addFieldsToUserSettings(McpWorkspaceResolver::FIELD, 'after:lang');
}
