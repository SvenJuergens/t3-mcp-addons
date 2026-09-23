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
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;

/**
 * Publishes everything the MCP user currently holds in their workspace.
 *
 * It ignores publish_time: calling the tool is the deliberate act of
 * publishing, so no date switch is involved. Meant for setups where content is
 * written via MCP and has to reach the live site without a manual publish step
 * in the backend.
 */
final class PublishWorkspaceTool extends AbstractTool
{
    public function __construct(
        private readonly WorkspaceService $workspaceService,
        private readonly WorkspaceContextService $workspaceContextService,
    ) {}

    public function getSchema(): array
    {
        return [
            'description' => 'Publishes ALL pending changes of the current workspace to the live site immediately.'
                . ' Call this only when the user explicitly asks to publish.'
                . ' Use dryRun first to list what would go live.'
                . ' Publishing cannot be undone through this tool: once the records are live, only a'
                . ' manual rollback in the TYPO3 backend takes them back.'
                . ' It ignores publish_time and publishes everything the current MCP user holds in'
                . ' their workspace, across all pages.'
                . ' Returns the list of published records. Fails when the user is not in a workspace.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'dryRun' => [
                        'type' => 'boolean',
                        'description' => 'List the records that would be published without changing anything.'
                            . ' Defaults to false, which publishes.',
                    ],
                ],
                'required' => [],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => false,
            ],
        ];
    }

    /**
     * The MCP middleware does not put the user into their workspace, every tool
     * has to do that for itself.
     */
    protected function initialize(): void
    {
        parent::initialize();

        if (isset($GLOBALS['BE_USER'])) {
            $this->workspaceContextService->switchToOptimalWorkspace($GLOBALS['BE_USER']);
        }
    }

    protected function doExecute(array $params): CallToolResult
    {
        $workspaceId = $this->workspaceContextService->getCurrentWorkspace();
        if ($workspaceId <= 0) {
            return $this->createErrorResult(
                'Not in a workspace - there is nothing to publish. Live edits are already public.'
            );
        }

        $workspaceInfo = $this->workspaceContextService->getWorkspaceInfo();
        $cmd = $this->workspaceService->getCmdArrayForPublishWS($workspaceId);
        $recordCount = $this->countRecords($cmd);

        if ($recordCount === 0) {
            return $this->createResult(sprintf(
                'Workspace "%s" (%d) holds no pending changes, nothing to publish.',
                $workspaceInfo['title'],
                $workspaceId
            ));
        }

        $recordList = $this->describeRecords($cmd);

        if (!empty($params['dryRun'])) {
            return $this->createResult(sprintf(
                "Dry run - workspace \"%s\" (%d) would publish %d records:\n%s",
                $workspaceInfo['title'],
                $workspaceId,
                $recordCount,
                $recordList
            ));
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmd);
        $dataHandler->process_cmdmap();

        if ($dataHandler->errorLog !== []) {
            return $this->createErrorResult(sprintf(
                "Publishing workspace \"%s\" (%d) failed:\n%s",
                $workspaceInfo['title'],
                $workspaceId,
                implode("\n", $dataHandler->errorLog)
            ));
        }

        return $this->createResult(sprintf(
            "Published %d records from workspace \"%s\" (%d) to the live site:\n%s",
            $recordCount,
            $workspaceInfo['title'],
            $workspaceId,
            $recordList
        ));
    }

    private function createResult(string $message): CallToolResult
    {
        return new CallToolResult([new TextContent($message)]);
    }

    /**
     * @param array<string, array<int, mixed>> $cmd
     */
    private function countRecords(array $cmd): int
    {
        $count = 0;
        foreach ($cmd as $records) {
            $count += count($records);
        }
        return $count;
    }

    /**
     * @param array<string, array<int, mixed>> $cmd
     */
    private function describeRecords(array $cmd): string
    {
        $lines = [];
        foreach ($cmd as $table => $records) {
            $lines[] = sprintf('- %s: %s', $table, implode(', ', array_keys($records)));
        }
        return implode("\n", $lines);
    }
}
