<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\Helper;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\VersionNumberUtility;
use TYPO3\CMS\Core\Versioning\VersionState;

final class PageRepositoryHelper
{
    private PageRepository $pageRepository;
    private Context $context;

    public function __construct(PageRepository $pageRepository, Context $context = null)
    {
        $this->pageRepository = $pageRepository;
        $this->context = $context ?? GeneralUtility::makeInstance(Context::class);
    }

    public function getPageIdsBySite(Site $site): array
    {
        // Todo: Remove if clause when support for TYPO3 11 is dropped
        if (version_compare(VersionNumberUtility::getCurrentTypo3Version(), '12', '<')) {
            $pageIds = $this->getDescendantPageIdsRecursive($site->getRootPageId(), 999, 0, [], true);
        } else {
            $pageIds = $this->pageRepository->getDescendantPageIdsRecursive($site->getRootPageId(), 999, 0, [], true);
        }
        $pageIds[] = $site->getRootPageId();
        return $pageIds;
    }

    /**
     * Copied and modified from TYPO3 Core (dev-main) to support TYPO3 11
     * @deprecated Remove support for TYPO3 11 is dropped
     */
    private function getDescendantPageIdsRecursive(int $startPageId, int $depth, int $begin = 0, array $excludePageIds = [], bool $bypassEnableFieldsCheck = false): array
    {
        if (!$startPageId) {
            return [];
        }
        if (!$this->pageRepository->getRawRecord('pages', $startPageId, 'uid')) {
            // Start page does not exist
            return [];
        }
        // Find mount point if any
        $mount_info = $this->pageRepository->getMountPointInfo($startPageId);
        $includePageId = false;
        if (is_array($mount_info)) {
            $startPageId = (int)$mount_info['mount_pid'];
            // In overlay mode, use the mounted page uid
            if ($mount_info['overlay']) {
                $includePageId = true;
            }
        }
        $descendantPageIds = $this->getSubpagesRecursive($startPageId, $depth, $begin, $excludePageIds, $bypassEnableFieldsCheck);
        if ($includePageId) {
            $descendantPageIds = array_merge([$startPageId], $descendantPageIds);
        }
        return $descendantPageIds;
    }

    /**
     * Copied and modified from TYPO3 Core (dev-main) to support TYPO3 11
     * @deprecated Remove support for TYPO3 11 is dropped
     */
    private function getSubpagesRecursive(int $pageId, int $depth, int $begin, array $excludePageIds, bool $bypassEnableFieldsCheck, array $prevId_array = []): array
    {
        $descendantPageIds = [];
        // if $depth is 0, then we do not fetch subpages
        if ($depth === 0) {
            return [];
        }
        // Add this ID to the array of IDs
        if ($begin <= 0) {
            $prevId_array[] = $pageId;
        }
        // Select subpages
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, (int)$this->context->getPropertyFromAspect('workspace', 'id')));
        $queryBuilder->select('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)
                ),
                // tree is only built by language=0 pages
                $queryBuilder->expr()->eq('sys_language_uid', 0)
            )
            ->orderBy('sorting');

        if ($excludePageIds !== []) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->notIn('uid', $queryBuilder->createNamedParameter($excludePageIds, Connection::PARAM_INT_ARRAY))
            );
        }

        $result = $queryBuilder->executeQuery();
        while ($row = $result->fetchAssociative()) {
            $versionState = VersionState::cast($row['t3ver_state'] ?? 0);
            $this->pageRepository->versionOL('pages', $row, false, $bypassEnableFieldsCheck);
            if ($row === false
                || (int)$row['doktype'] === 6
                || $versionState->indicatesPlaceholder()
            ) {
                // falsy row means Overlay prevents access to this page.
                // Doing this after the overlay to make sure changes
                // in the overlay are respected.
                // However, we do not process pages below of and
                // including of type BE user section
                continue;
            }
            // Find mount point if any:
            $next_id = (int)$row['uid'];
            $mount_info = $this->pageRepository->getMountPointInfo($next_id, $row);
            // Overlay mode:
            if (is_array($mount_info) && $mount_info['overlay']) {
                $next_id = (int)$mount_info['mount_pid'];
                // @todo: check if we could use $mount_info[mount_pid_rec] and check against $excludePageIds?
                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                    ->getQueryBuilderForTable('pages');
                $queryBuilder->getRestrictions()
                    ->removeAll()
                    ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
                    ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, (int)$this->context->getPropertyFromAspect('workspace', 'id')));
                $queryBuilder->select('*')
                    ->from('pages')
                    ->where(
                        $queryBuilder->expr()->eq(
                            'uid',
                            $queryBuilder->createNamedParameter($next_id, Connection::PARAM_INT)
                        )
                    )
                    ->orderBy('sorting')
                    ->setMaxResults(1);

                if ($excludePageIds !== []) {
                    $queryBuilder->andWhere(
                        $queryBuilder->expr()->notIn('uid', $queryBuilder->createNamedParameter($excludePageIds, Connection::PARAM_INT_ARRAY))
                    );
                }

                $row = $queryBuilder->executeQuery()->fetchAssociative();
                $this->pageRepository->versionOL('pages', $row);
                $versionState = VersionState::cast($row['t3ver_state'] ?? 0);
                if ($row === false
                    || (int)$row['doktype'] === self::DOKTYPE_BE_USER_SECTION
                    || $versionState->indicatesPlaceholder()
                ) {
                    // Doing this after the overlay to make sure
                    // changes in the overlay are respected.
                    // see above
                    continue;
                }
            }
            // Add record:
            // Add ID to list:
            if ($begin <= 0) {
                $descendantPageIds[] = $next_id;
            }
            // Next level
            if (!$row['php_tree_stop']) {
                // Normal mode:
                if (is_array($mount_info) && !$mount_info['overlay']) {
                    $next_id = (int)$mount_info['mount_pid'];
                }
                // Call recursively, if the id is not in prevID_array:
                if (!in_array($next_id, $prevId_array, true)) {
                    /** @noinspection SlowArrayOperationsInLoopInspection */
                    $descendantPageIds = array_merge(
                        $descendantPageIds,
                        $this->getSubpagesRecursive(
                            $next_id,
                            $depth - 1,
                            $begin - 1,
                            $excludePageIds,
                            $bypassEnableFieldsCheck,
                            $prevId_array
                        )
                    );
                }
            }
        }
        return $descendantPageIds;
    }
}
