<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 extension t3_mcp_addons.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace SvenJuergens\T3McpAddons\Xclass;

use Hn\McpServer\Exception\McpException;
use Hn\McpServer\Http\McpEndpoint;
use Hn\McpServer\Service\WorkspaceContextService as BaseWorkspaceContextService;
use SvenJuergens\T3McpAddons\Workspace\McpWorkspaceResolver;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * MCP works in exactly the MCP workspace the user has chosen in the user
 * settings - no guessing, no fallback.
 *
 * The MCP server would otherwise take the alphabetically first workspace the
 * user can write to, create a workspace of its own, or fall back to live.
 * This override replaces that: a valid choice is used, everything else blocks
 * MCP access. The workspace switcher of the backend has no influence.
 *
 * The MCP endpoint calls this method once while it sets up the request, every
 * tool calls it again before it runs. Without a valid choice the endpoint
 * call only puts the user into "no workspace" (-99, as the core does for
 * users without workspace access), so the connection itself still works;
 * the tool calls then fail with a message the model can relay. Should the
 * caller detection ever miss because the MCP server changed, the endpoint
 * call throws as well: the connection fails, but nothing is read or written.
 */
class WorkspaceContextService extends BaseWorkspaceContextService
{
    private const NO_WORKSPACE = -99;

    public function switchToOptimalWorkspace(BackendUserAuthentication $beUser): int
    {
        $resolver = GeneralUtility::makeInstance(McpWorkspaceResolver::class);
        $workspaceId = $resolver->getSelectedWorkspaceId($beUser);

        if ($resolver->getWritableWorkspace($beUser, $workspaceId) !== null) {
            $this->setWorkspaceContext($beUser, $workspaceId);
            return $workspaceId;
        }

        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['class'] ?? '';
        if ($caller !== '' && is_a($caller, McpEndpoint::class, true)) {
            $this->blockWorkspaceAccess($beUser);
            return self::NO_WORKSPACE;
        }

        throw new McpException(
            $workspaceId > 0
                ? sprintf('Backend user %d may not write to the chosen MCP workspace %d.', (int)($beUser->user['uid'] ?? 0), $workspaceId)
                : sprintf('Backend user %d has not chosen an MCP workspace.', (int)($beUser->user['uid'] ?? 0)),
            $workspaceId > 0
                ? 'The MCP workspace chosen by this user is no longer available. Ask the user to choose'
                    . ' another one under User settings > MCP workspace in the TYPO3 backend.'
                : 'No MCP workspace is chosen for this user, so MCP tools may neither read nor write. Ask the'
                    . ' user to choose one under User settings > MCP workspace in the TYPO3 backend.',
            403
        );
    }

    /**
     * setTemporaryWorkspace() refuses workspaces the user cannot access, so
     * the "no workspace" state is set directly.
     */
    private function blockWorkspaceAccess(BackendUserAuthentication $beUser): void
    {
        $beUser->workspace = self::NO_WORKSPACE;
        $beUser->workspaceRec = [];
        GeneralUtility::makeInstance(Context::class)
            ->setAspect('workspace', GeneralUtility::makeInstance(WorkspaceAspect::class, self::NO_WORKSPACE));
    }
}
