<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 extension t3_mcp_addons.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace SvenJuergens\T3McpAddons\Workspace;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\GroupResolver;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\RootLevelRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Knows which MCP workspace a backend user has chosen.
 *
 * The choice is a plain field on be_users, set by the user in the user
 * settings. Whether the user may still write to the chosen workspace is
 * checked on every call against the core workspace permissions, so removing
 * a user from a workspace takes effect immediately.
 *
 * Deliberately free of constructor dependencies: it is created through
 * makeInstance() from the XCLASS and from itemsProcFuncs.
 */
final class McpWorkspaceResolver
{
    public const FIELD = 'tx_t3mcpaddons_workspace';

    /**
     * Access levels of checkWorkspace() that allow writing. "online" is the
     * live workspace, which is never a valid choice.
     */
    private const WRITE_ACCESS = ['admin', 'owner', 'member'];

    /**
     * The chosen workspace uid, read from the database on every call so a
     * change in the user settings applies to the next request - also for the
     * long-running stdio server, whose user record is loaded only once.
     */
    public function getSelectedWorkspaceId(BackendUserAuthentication $backendUser): int
    {
        $userId = (int)($backendUser->user['uid'] ?? 0);
        if ($userId <= 0) {
            return 0;
        }

        $value = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('be_users')
            ->select([self::FIELD], 'be_users', ['uid' => $userId])
            ->fetchOne();

        return max(0, (int)$value);
    }

    /**
     * The workspace record when the user may write to it, null otherwise.
     *
     * @return array<string, mixed>|null
     */
    public function getWritableWorkspace(BackendUserAuthentication $backendUser, int $workspaceId): ?array
    {
        if ($workspaceId <= 0) {
            return null;
        }

        $record = $backendUser->checkWorkspace($workspaceId);
        if (!is_array($record) || !in_array($record['_ACCESS'] ?? '', self::WRITE_ACCESS, true)) {
            return null;
        }

        return $record;
    }

    /**
     * All workspaces the user may write to, sorted by title.
     *
     * @return array<int, string> uid => title
     */
    public function getWritableWorkspaces(BackendUserAuthentication $backendUser): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_workspace');
        $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(RootLevelRestriction::class));
        $rows = $queryBuilder
            ->select('*')
            ->from('sys_workspace')
            ->orderBy('title')
            ->executeQuery()
            ->fetchAllAssociative();

        $workspaces = [];
        foreach ($rows as $row) {
            $record = $backendUser->checkWorkspace($row);
            if (is_array($record) && in_array($record['_ACCESS'] ?? '', self::WRITE_ACCESS, true)) {
                $workspaces[(int)$row['uid']] = (string)$row['title'];
            }
        }

        return $workspaces;
    }

    /**
     * All workspaces another backend user may write to, for an administrator
     * who presets the choice in the be_users form.
     *
     * Uses a detached user object with just the record and the resolved
     * groups - enough for checkWorkspace(). fetchGroupData() is avoided on
     * purpose: it replaces the backend.user aspect of the Context, which
     * belongs to the administrator editing the form.
     *
     * @return array<int, string> uid => title
     */
    public function getWritableWorkspacesForUserId(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeByType(HiddenRestriction::class);
        $userRecord = $queryBuilder
            ->select('*')
            ->from('be_users')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($userId, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        if (!is_array($userRecord)) {
            return [];
        }

        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->user = $userRecord;
        $backendUser->userGroupsUID = array_map(
            static fn(array $group): int => (int)$group['uid'],
            GeneralUtility::makeInstance(GroupResolver::class)->resolveGroupsForUser($userRecord, 'be_groups')
        );

        return $this->getWritableWorkspaces($backendUser);
    }
}
