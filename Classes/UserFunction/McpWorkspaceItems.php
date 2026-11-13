<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 extension t3_mcp_addons.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace SvenJuergens\T3McpAddons\UserFunction;

use SvenJuergens\T3McpAddons\Workspace\McpWorkspaceResolver;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Options of the "MCP workspace" user setting: the workspaces the
 * current user may write to. Live is never offered.
 *
 * TYPO3 13 and 14 call the itemsProcFunc of a user setting differently, hence
 * two entry points on top of the same list.
 */
final class McpWorkspaceItems
{
    private const LLL = 'LLL:EXT:t3_mcp_addons/Resources/Private/Language/locallang.xlf:';

    /**
     * Regular TCA itemsProcFunc, used by the be_users form (TYPO3 13 and 14)
     * and the user settings of TYPO3 14. The "none" item comes from the TCA,
     * an invalid stored value is flagged by FormEngine itself.
     *
     * The options are the workspaces of the user being edited: the own ones
     * in the user settings, those of the edited user when an administrator
     * presets the choice. A user that is not saved yet has no groups to judge
     * by, so only "none" is offered until the record is saved.
     *
     * @param array<string, mixed> $params
     */
    public function addItems(array &$params): void
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return;
        }

        $resolver = new McpWorkspaceResolver();
        $editedUid = $params['row']['uid'] ?? null;
        if ($editedUid === null || (int)$editedUid === (int)($backendUser->user['uid'] ?? 0)) {
            $workspaces = $resolver->getWritableWorkspaces($backendUser);
        } elseif (MathUtility::canBeInterpretedAsInteger($editedUid)) {
            $workspaces = $resolver->getWritableWorkspacesForUserId((int)$editedUid);
        } else {
            $workspaces = [];
        }

        foreach ($workspaces as $uid => $title) {
            $params['items'][] = ['label' => $title, 'value' => $uid];
        }
    }

    /**
     * TYPO3 13 (EXT:setup): the itemsProcFunc of a select setting returns the
     * complete <select>. Fields stored in be_users are posted as
     * data[be_users][<field>].
     *
     * @param array<string, mixed> $config
     */
    public function renderSelect(array $config, object $parent): string
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return '';
        }

        $field = McpWorkspaceResolver::FIELD;
        $languageService = $this->getLanguageService();
        $current = (int)($backendUser->user[$field] ?? 0);

        $options = [0 => $languageService->sL(self::LLL . 'be_users.' . $field . '.none')];
        $options += (new McpWorkspaceResolver())->getWritableWorkspaces($backendUser);
        if ($current > 0 && !isset($options[$current])) {
            $options[$current] = sprintf($languageService->sL(self::LLL . 'be_users.' . $field . '.unavailable'), $current);
        }

        $html = '<select id="field_' . htmlspecialchars($field) . '" name="data[be_users][' . htmlspecialchars($field) . ']" class="form-select">';
        foreach ($options as $uid => $title) {
            $html .= '<option value="' . $uid . '"' . ($uid === $current ? ' selected="selected"' : '') . '>'
                . htmlspecialchars($title) . '</option>';
        }

        return $html . '</select>';
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
