<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 extension t3_mcp_addons.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace SvenJuergens\T3McpAddons\MCP\Tool;

use Hn\McpServer\MCP\Tool\AbstractTool;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Tells the model which backend user it is acting as.
 *
 * Read-only and limited to the authenticated user: there is no parameter, so
 * no other account can be looked up, and only a fixed allowlist of fields
 * leaves the record — never password hashes, sessions, TSconfig or
 * permissions. The MCP server keeps be_users itself closed to ReadTable.
 */
final class GetCurrentUserTool extends AbstractTool
{
    /**
     * Fields of be_users that may be returned, with their label.
     */
    private const FIELDS = [
        'uid' => 'UID',
        'username' => 'Username',
        'realName' => 'Name',
        'email' => 'Email',
    ];

    public function getSchema(): array
    {
        return [
            'description' => 'Get the backend user this MCP connection acts as: uid, username, name'
                . ' and email. Read-only, no parameters, and only ever about the current user -'
                . ' other accounts cannot be looked up. Use it to know whose changes you are'
                . ' making, e.g. before writing to a workspace.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new \stdClass(),
                'required' => [],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication || !is_array($backendUser->user) || (int)($backendUser->user['uid'] ?? 0) <= 0) {
            return $this->createErrorResult('No backend user is authenticated for this MCP connection.');
        }

        $lines = [];
        foreach (self::FIELDS as $field => $label) {
            $value = trim((string)($backendUser->user[$field] ?? ''));
            $lines[] = sprintf('%s: %s', $label, $value !== '' ? $value : '(not set)');
        }

        return new CallToolResult([new TextContent(implode("\n", $lines))]);
    }
}
