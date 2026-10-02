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

use Hn\McpServer\Exception\McpException;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

/**
 * Every result of the add-on tools is a JSON object, errors included.
 *
 * Error texts never carry messages from the system (exceptions, database
 * errors): those go to the TYPO3 log, the client only gets a fixed hint.
 */
trait JsonResultTrait
{
    /**
     * Same flags as the MCP server's AbstractRecordTool::createJsonResult(),
     * which the add-on tools cannot reach because they are no record tools.
     *
     * @param array<string, mixed> $data
     */
    protected function createJsonResult(array $data, bool $isError = false): CallToolResult
    {
        $encoded = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return new CallToolResult([new TextContent($encoded !== false ? $encoded : '{}')], $isError);
    }

    /**
     * Also used by the MCP server's exception handler for uncaught exceptions.
     */
    protected function createErrorResult(string $message): CallToolResult
    {
        return $this->createJsonResult(['error' => $message], true);
    }

    /**
     * The MCP server passes the message of expected exceptions through
     * verbatim. Only McpException carries a message written for the client,
     * everything else is answered generically (the handler logs it anyway).
     */
    protected function getUserFriendlyMessage(\Throwable $e, string $operation = ''): string
    {
        if ($e instanceof McpException) {
            return $e->getUserMessage();
        }

        return 'An unexpected error occurred' . ($operation !== '' ? ' during ' . $operation : '')
            . '. Details are in the TYPO3 log.';
    }
}
