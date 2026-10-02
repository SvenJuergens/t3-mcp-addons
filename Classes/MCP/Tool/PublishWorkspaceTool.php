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
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;
use TYPO3\CMS\Core\SysLog\Type as SystemLogType;
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
    use JsonResultTrait;

    /**
     * Fixed texts per failure reason - the logged error message itself never
     * leaves the system.
     */
    private const HINTS = [
        'denied' => 'Publishing was refused, check permissions and workspace stage.',
        'system' => 'System error, see the TYPO3 log.',
    ];

    public function __construct(
        private readonly WorkspaceService $workspaceService,
        private readonly WorkspaceContextService $workspaceContextService,
        private readonly ConnectionPool $connectionPool,
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
                . ' Returns a JSON object with the workspace, the record count and the records per'
                . ' table. Fails when the user is not in a workspace.',
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
        $dryRun = !empty($params['dryRun']);
        $result = [
            'workspace' => ['uid' => $workspaceId, 'title' => (string)$workspaceInfo['title']],
            'dryRun' => $dryRun,
            'published' => false,
            'count' => $this->countRecords($cmd),
            'records' => $this->listRecords($cmd),
        ];

        if ($result['count'] === 0 || $dryRun) {
            return $this->createJsonResult($result);
        }

        $lastLogUid = $this->getLastLogUid();
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmd);
        $dataHandler->process_cmdmap();

        if ($dataHandler->errorLog === []) {
            $result['published'] = true;
            return $this->createJsonResult($result);
        }

        // Error texts of DataHandler may quote database messages; only the
        // classification, table and uid of the logged errors go out.
        $failed = $this->collectFailedRecords($lastLogUid);
        unset($result['published']);

        return $this->createJsonResult([
            'error' => 'Publishing failed, other records of the workspace may have been published.'
                . ' Details are in the TYPO3 log.',
            ...$result,
            'failed' => $failed,
        ], true);
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
     * Records per table; an object even when empty, so the JSON shape stays
     * the same.
     *
     * @param array<string, array<int, mixed>> $cmd
     * @return array<string, int[]>|\stdClass
     */
    private function listRecords(array $cmd): array|\stdClass
    {
        $list = [];
        foreach ($cmd as $table => $records) {
            $list[$table] = array_map('intval', array_keys($records));
        }
        return $list !== [] ? $list : new \stdClass();
    }

    private function getLastLogUid(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_log');

        return (int)$queryBuilder
            ->selectLiteral('MAX(uid)')
            ->from('sys_log')
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Errors DataHandler logged for the current user since $lastLogUid, one
     * entry per record. Reads the classification only, never the message.
     *
     * @return list<array{table: ?string, uid: ?int, reason: string, hint: string}>
     */
    private function collectFailedRecords(int $lastLogUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_log');
        $rows = $queryBuilder
            ->select('error', 'tablename', 'recuid')
            ->from('sys_log')
            ->where(
                $queryBuilder->expr()->gt('uid', $queryBuilder->createNamedParameter($lastLogUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('userid', $queryBuilder->createNamedParameter((int)($GLOBALS['BE_USER']->user['uid'] ?? 0), Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('type', $queryBuilder->createNamedParameter(SystemLogType::DB, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('error', $queryBuilder->createNamedParameter(SystemLogErrorClassification::MESSAGE, Connection::PARAM_INT))
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        $failed = [];
        foreach ($rows as $row) {
            $table = (string)$row['tablename'] !== '' ? (string)$row['tablename'] : null;
            $uid = (int)$row['recuid'] > 0 ? (int)$row['recuid'] : null;
            $reason = (int)$row['error'] === SystemLogErrorClassification::USER_ERROR ? 'denied' : 'system';
            $key = $table . ':' . $uid;

            // A system error outweighs a refusal for the same record.
            if (isset($failed[$key]) && ($failed[$key]['reason'] === 'system' || $reason === 'denied')) {
                continue;
            }
            $failed[$key] = [
                'table' => $table,
                'uid' => $uid,
                'reason' => $reason,
                'hint' => self::HINTS[$reason],
            ];
        }

        return array_values($failed);
    }
}
