<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 extension t3_mcp_addons.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace SvenJuergens\T3McpAddons\EventListener;

use Hn\McpServer\Event\BeforeRecordWriteEvent;
use SvenJuergens\T3McpAddons\Workspace\McpWorkspaceResolver;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Safety net for the workspace XCLASS: WriteTable only writes when the user
 * is in the MCP workspace chosen in the user settings.
 *
 * Normally the XCLASS already guarantees that. Should it stop taking effect
 * (a changed MCP server, another XCLASS on the same class), writes would land
 * in whatever workspace the MCP server picks - this listener refuses them
 * instead.
 */
#[AsEventListener('t3-mcp-addons/mcp-workspace-write-guard')]
final class McpWorkspaceWriteGuard
{
    public function __invoke(BeforeRecordWriteEvent $event): void
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            $event->veto('No backend user is authenticated for this MCP connection.');
            return;
        }

        $resolver = new McpWorkspaceResolver();
        $workspaceId = $resolver->getSelectedWorkspaceId($backendUser);
        if ($workspaceId <= 0 || $resolver->getWritableWorkspace($backendUser, $workspaceId) === null) {
            $event->veto('No usable MCP workspace is chosen. Ask the user to choose one under User settings > MCP workspace.');
            return;
        }

        if ((int)$backendUser->workspace !== $workspaceId) {
            $event->veto('The MCP connection is not in the chosen MCP workspace. Nothing was written.');
        }
    }
}
