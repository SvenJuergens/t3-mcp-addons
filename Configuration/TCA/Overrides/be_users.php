<?php

declare(strict_types=1);

use SvenJuergens\T3McpAddons\UserFunction\McpWorkspaceItems;
use SvenJuergens\T3McpAddons\Workspace\McpWorkspaceResolver;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// The column is needed in both versions: the user settings save be_users
// fields through the DataHandler, which only writes columns known to the TCA.
// In the be_users form it lets administrators preset the choice; users can
// change it afterwards in their user settings.
// Not an exclude field: TYPO3 14 copies the column into the user settings
// (inheritFromParent), and FormEngine hides exclude fields from users without
// that permission - editors would not see their own setting. be_users itself
// is admin-only anyway.
$GLOBALS['TCA']['be_users']['columns'][McpWorkspaceResolver::FIELD] = [
    'label' => 'LLL:EXT:t3_mcp_addons/Resources/Private/Language/locallang.xlf:be_users.' . McpWorkspaceResolver::FIELD,
    'description' => 'LLL:EXT:t3_mcp_addons/Resources/Private/Language/locallang.xlf:be_users.' . McpWorkspaceResolver::FIELD . '.description',
    'config' => [
        'type' => 'select',
        'renderType' => 'selectSingle',
        'items' => [
            [
                'label' => 'LLL:EXT:t3_mcp_addons/Resources/Private/Language/locallang.xlf:be_users.' . McpWorkspaceResolver::FIELD . '.none',
                'value' => 0,
            ],
        ],
        'itemsProcFunc' => McpWorkspaceItems::class . '->addItems',
        'default' => 0,
    ],
];

// Editors: next to the workspace permissions. Administrators have no such
// field, their record gets it after the options.
ExtensionManagementUtility::addToAllTCAtypes('be_users', McpWorkspaceResolver::FIELD, '0', 'after:workspace_perms');
ExtensionManagementUtility::addToAllTCAtypes('be_users', McpWorkspaceResolver::FIELD, '1', 'after:tsconfig_includes');

// TYPO3 14.2+: user settings live in the TCA. TYPO3 13 registers the setting
// in ext_tables.php.
if (method_exists(ExtensionManagementUtility::class, 'addUserSetting')) {
    ExtensionManagementUtility::addUserSetting(
        McpWorkspaceResolver::FIELD,
        ['inheritFromParent' => true],
        'after:lang'
    );
}
