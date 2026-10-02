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
use Hn\McpServer\Service\LanguageService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Workspaces\Preview\PreviewUriBuilder;

/**
 * Hands out a workspace preview link so drafts written through MCP can be
 * looked at before they are published.
 *
 * The link carries an ADMCMD_prev token that unlocks the whole workspace, not
 * just the requested page - open it once and browse from there. Tokens are
 * stored in sys_preview and expire (48 hours unless the workspace record or
 * user TSconfig says otherwise).
 */
final class GetPreviewLinkTool extends AbstractTool
{
    use JsonResultTrait;

    public function __construct(
        private readonly PreviewUriBuilder $previewUriBuilder,
        private readonly WorkspaceContextService $workspaceContextService,
        private readonly LanguageService $languageService,
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function getSchema(): array
    {
        $schema = [
            'description' => 'Get a preview link for a page that shows the current workspace draft'
                . ' instead of the published version. Use this to check content written through MCP'
                . ' before publishing it. Returns a JSON object with the URL, the date it is valid'
                . ' until (validUntil) and a list of warnings; the link'
                . ' carries a token that unlocks the entire workspace, not just this page, so it can'
                . ' be opened once and browsed from there. The token expires after 48 hours unless'
                . ' the workspace record or user TSconfig says otherwise.'
                . ' Adds a warning when the preview will not show the page: hidden, not published'
                . ' yet (starttime), expired (endtime), not translated into the requested language,'
                . ' or the link does not resolve to this page.'
                . ' Fails when the page does not exist, and when the user is not in a workspace -'
                . ' there is no draft then, and the live URL already shows the current state.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'page' => [
                        'type' => 'integer',
                        'description' => 'The page ID to preview (the default language page, not the translation record).',
                    ],
                ],
                'required' => ['page'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'idempotentHint' => false,
            ],
        ];

        $availableLanguages = $this->languageService->getAvailableIsoCodes();
        if (count($availableLanguages) > 1) {
            $schema['inputSchema']['properties']['language'] = [
                'type' => 'string',
                'description' => 'Language ISO code of the version to preview (e.g. "de", "en").'
                    . ' Defaults to the default language.',
                'enum' => $availableLanguages,
            ];
        }

        return $schema;
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
        $pageId = (int)($params['page'] ?? 0);
        if ($pageId <= 0) {
            return $this->createErrorResult('Parameter "page" must be a positive page ID.');
        }

        $languageId = 0;
        if (isset($params['language']) && $params['language'] !== '') {
            $languageId = $this->languageService->getUidFromIsoCode((string)$params['language']);
            if ($languageId === null) {
                return $this->createErrorResult(sprintf('Unknown language code "%s".', $params['language']));
            }
        }

        $workspaceId = $this->workspaceContextService->getCurrentWorkspace();
        if ($workspaceId <= 0) {
            return $this->createErrorResult(
                'Not in a workspace - there is no draft to preview. The live URL already shows the current state.'
            );
        }

        $page = BackendUtility::getRecordWSOL('pages', $pageId);
        if (!is_array($page)) {
            return $this->createErrorResult(sprintf('Page %d does not exist.', $pageId));
        }
        if ((int)($page['sys_language_uid'] ?? 0) !== 0) {
            return $this->createErrorResult(sprintf(
                'Page %d is a translation record. Pass the default language page %d and the "language" parameter instead.',
                $pageId,
                (int)($page['l10n_parent'] ?? 0)
            ));
        }

        $translation = null;
        if ($languageId > 0) {
            $translation = $this->getPageTranslation($pageId, $languageId);
        }

        try {
            $uri = $this->previewUriBuilder->buildUriForPage($pageId, $languageId);
        } catch (\Throwable $e) {
            // The exception text stays in the log, the client gets a fixed hint.
            $this->logException($e, $this->getName());
            return $this->createErrorResult(sprintf(
                'Could not build a preview link for page %d. Details are in the TYPO3 log.',
                $pageId
            ));
        }

        if ($languageId > 0 && $translation === null) {
            $warnings = [sprintf(
                'The page has no translation into "%s" - the preview shows the fallback, if any.',
                $params['language']
            )];
        } else {
            $warnings = $this->collectWarnings($translation ?? $page, $uri);
        }

        $workspaceInfo = $this->workspaceContextService->getWorkspaceInfo();

        return $this->createJsonResult([
            'page' => $pageId,
            'language' => $languageId > 0 ? (string)$params['language'] : null,
            'workspace' => ['uid' => $workspaceId, 'title' => (string)$workspaceInfo['title']],
            'url' => $uri,
            'validUntil' => $this->getExpiryDate($uri),
            'note' => 'The link unlocks the whole workspace, not just this page.',
            'warnings' => $warnings,
        ]);
    }

    /**
     * Translated page record of the given language with the workspace overlay applied.
     *
     * @return array<string, mixed>|null
     */
    private function getPageTranslation(int $pageId, int $languageId): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($this->workspaceContextService->getCurrentWorkspace()));

        $translation = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'l10n_parent',
                    $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)
                ),
                // Live rows and records created in the workspace; drafts of
                // live rows come in through the overlay below.
                $queryBuilder->expr()->eq('t3ver_oid', 0)
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($translation)) {
            return null;
        }

        // Replaces the live row with the draft version, or with false when
        // the translation is deleted in the workspace.
        BackendUtility::workspaceOL('pages', $translation);

        return is_array($translation) ? $translation : null;
    }

    /**
     * Two independent things can go wrong, so both are checked separately.
     *
     * The page router resolves the slug through PageRepository::getPage(),
     * which applies the enable fields - whether a hidden page still yields a
     * slug depends on the visibility aspect of the calling context, so the
     * link may or may not degrade to another page. The preview itself never
     * renders a disabled page.
     *
     * @param array<string, mixed> $page
     * @return string[]
     */
    private function collectWarnings(array $page, string $uri): array
    {
        $warnings = [];
        $now = (int)$GLOBALS['EXEC_TIME'];

        if ((int)($page['hidden'] ?? 0) === 1) {
            $warnings[] = 'The page is hidden - the preview will not render it. Set hidden to 0 first.';
        }

        $starttime = (int)($page['starttime'] ?? 0);
        if ($starttime > $now) {
            $warnings[] = sprintf(
                'The page is not published before %s - the preview will not render it.',
                date('Y-m-d H:i', $starttime)
            );
        }

        $endtime = (int)($page['endtime'] ?? 0);
        if ($endtime > 0 && $endtime <= $now) {
            $warnings[] = sprintf(
                'The page expired on %s - the preview will not render it.',
                date('Y-m-d H:i', $endtime)
            );
        }

        if (!$this->uriMatchesSlug($uri, (string)($page['slug'] ?? ''))) {
            $warnings[] = sprintf(
                'The link does not lead to this page - its slug is "%s". The router could not resolve'
                . ' the page and fell back to another one.',
                (string)($page['slug'] ?? '')
            );
        }

        return $warnings;
    }

    private function uriMatchesSlug(string $uri, string $slug): bool
    {
        $slug = rtrim($slug, '/');
        if ($slug === '') {
            // Root page - every path of this site is an acceptable result.
            return true;
        }

        $path = rtrim((string)parse_url($uri, PHP_URL_PATH), '/');

        return str_ends_with($path, $slug);
    }

    /**
     * Look up the expiry of the token that was just written to sys_preview,
     * as ISO 8601 date.
     */
    private function getExpiryDate(string $uri): ?string
    {
        parse_str((string)parse_url($uri, PHP_URL_QUERY), $query);
        $keyword = $query['ADMCMD_prev'] ?? null;
        if (!is_string($keyword) || $keyword === '') {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_preview');
        $endtime = $queryBuilder
            ->select('endtime')
            ->from('sys_preview')
            ->where(
                $queryBuilder->expr()->eq(
                    'keyword',
                    $queryBuilder->createNamedParameter($keyword, Connection::PARAM_STR)
                )
            )
            ->executeQuery()
            ->fetchOne();

        if (!$endtime) {
            return null;
        }

        return date('c', (int)$endtime);
    }
}
