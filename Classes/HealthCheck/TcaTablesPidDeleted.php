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

use Lolli\Dbdoctor\Exception\NoSuchRecordException;
use Lolli\Dbdoctor\Helper\RecordsHelper;
use Lolli\Dbdoctor\Helper\TcaHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Not-deleted TCA records must point to not-deleted pages
 */
class TcaTablesPidDeleted extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for not-deleted records on pages set to deleted');
        $io->text([
            '[UPDATE] TCA records have a pid field set to a single page. This page must exist.',
            '         This scan finds deleted=0 records pointing to pages having deleted=1.',
            '         Affected records are set to deleted=1 if in live, or removed if in workspaces.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var TcaHelper $tcaHelper */
        $tcaHelper = $this->container->get(TcaHelper::class);
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);

        $affectedRows = [];
        // Iterate all TCA tables, but ignore pages table
        foreach ($tcaHelper->getNextTcaTable(['pages']) as $tableName) {
            $workspaceIdField = $tcaHelper->getWorkspaceIdField($tableName);
            $isTableWorkspaceAware = !empty($workspaceIdField);
            $selectFields = ['uid', 'pid'];
            if ($isTableWorkspaceAware) {
                $selectFields[] = $workspaceIdField;
            }

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $queryBuilder->select(...$selectFields)->from($tableName)->orderBy('uid');
            $queryBuilder->where($queryBuilder->expr()->gt('pid', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)));
            $tableDeleteField = $tcaHelper->getDeletedField($tableName);
            if ($tableDeleteField) {
                // Do not consider deleted records: Records pointing to a not-existing page have been
                // caught before, we want to find non-deleted records pointing to deleted pages.
                // Still, TCA tables without soft-delete, must point to not-deleted pages.
                $queryBuilder->andWhere($queryBuilder->expr()->eq($tableDeleteField, $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)));
            }
            $result = $queryBuilder->executeQuery();
            while ($row = $result->fetchAssociative()) {
                /** @var array<string, int|string> $row */
                // Records pointing to pid 0 are ok, check all others.
                try {
                    $pageRow = $recordsHelper->getRecord('pages', ['uid', 'deleted'], (int)$row['pid']);
                    if ((int)$pageRow['deleted'] === 1) {
                        $affectedRows[$tableName][] = $row;
                    }
                } catch (NoSuchRecordException $e) {
                    // Earlier test should have fixed this.
                    throw new \RuntimeException(
                        'Record with uid="' . $row['uid'] . '" on table "' . $tableName . '"'
                        . ' has pid="' . $row['pid'] . '", but that page does not exist. A previous check'
                        . ' should have found and fixed this. Please repeat.',
                        1647793650
                    );
                }
            }
        }
        return $affectedRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        foreach ($affectedRecords as $tableName => $tableRows) {
            $this->softOrHardDeleteRecords($io, $simulate, $tableName, $tableRows);
        }
    }
}
