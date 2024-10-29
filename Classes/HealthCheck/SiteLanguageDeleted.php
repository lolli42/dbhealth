<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\HealthCheck;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */
use Lolli\Dbdoctor\Helper\PageRepositoryHelper;
use Lolli\Dbdoctor\Helper\RecordsHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Not-deleted TCA records must have an available site language
 */
final class SiteLanguageDeleted extends AbstractHealthCheck implements HealthCheckInterface
{
    private PageRepositoryHelper $pageRepositoryHelper;

    final public function injectPageRepositoryHelper(PageRepositoryHelper $pageRepositoryHelper): void
    {
        $this->pageRepositoryHelper = $pageRepositoryHelper;
    }

    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for not-deleted records in site on non-existing languages');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_SOFT_DELETE, self::TAG_REMOVE, self::TAG_WORKSPACE_REMOVE);
        $io->text([
            'TCA records have a sys_language_uid field set to a language id.',
            'The record language must be available in its parent site.',
            'Affected records are soft deleted if possible, or removed.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);

        $affectedRows = [];
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        $sites = $siteFinder->getAllSites();

        foreach ($sites as $site) {
            $sitePageIds = $this->pageRepositoryHelper->getPageIdsBySite($site);

            // Keep All Languages records
            $languageIds = [-1];
            foreach ($site->getAllLanguages() as $language) {
                $languageIds[] = $language->getLanguageId();
            }

            foreach ($this->tcaHelper->getNextTcaTable() as $tableName) {
                $languageField = $this->tcaHelper->getLanguageField($tableName);
                if (!$languageField) {
                    // Skip non translatable tables
                    continue;
                }
                $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($tableName);
                $isTableWorkspaceAware = !empty($workspaceIdField);
                $tableDeleteField = $this->tcaHelper->getDeletedField($tableName);
                $isTableSoftDeleteAware = !empty($tableDeleteField);
                $selectFields = ['uid', 'pid'];
                if ($isTableWorkspaceAware) {
                    $selectFields[] = $workspaceIdField;
                }

                $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
                $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                $queryBuilder->select(...$selectFields)->from($tableName)->orderBy('uid');
                $queryBuilder->where($queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($sitePageIds, Connection::PARAM_INT_ARRAY)));
                $queryBuilder->andWhere($queryBuilder->expr()->notIn($languageField, $queryBuilder->createNamedParameter($languageIds, Connection::PARAM_INT_ARRAY)));

                if ($isTableSoftDeleteAware) {
                    // Do not consider deleted records: Records pointing to a not-existing page have been
                    // caught before, we want to find non-deleted records pointing to deleted pages.
                    // Still, TCA tables without soft-delete, must point to not-deleted pages.
                    $queryBuilder->andWhere($queryBuilder->expr()->eq($tableDeleteField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)));
                }
                $result = $queryBuilder->executeQuery();
                while ($row = $result->fetchAssociative()) {
                    $affectedRows[$tableName][] = $row;
                }
            }
        }
        return $affectedRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->softOrHardDeleteRecords($io, $simulate, $affectedRecords);
    }
}
