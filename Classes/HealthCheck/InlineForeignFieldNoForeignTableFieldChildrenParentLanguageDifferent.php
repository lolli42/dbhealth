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
use Lolli\Dbdoctor\Helper\TableHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Handle inline foreign field without TCA foreign_table_field children
 * that are set to a different sys_language_uid than their parent.
 */
final class InlineForeignFieldNoForeignTableFieldChildrenParentLanguageDifferent extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for inline foreign field records with different language than their parent');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_UPDATE, self::TAG_RISKY);
        $io->text([
            'TCA inline foreign field child records point to a parent record. This check finds',
            'child records that have a different language than the parent record.',
            'This check is for inline children defined *without* foreign_table_field in TCA.',
            'Language of affected children is set to same language as parent record.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        /** @var TableHelper $tableHelper */
        $tableHelper = $this->container->get(TableHelper::class);

        $affectedRows = [];
        foreach ($this->tcaHelper->getNextInlineForeignFieldNoForeignTableFieldChildTcaTable() as $inlineChild) {
            $childTableName = $inlineChild['tableName'];
            $childTableLanguageField = $this->tcaHelper->getLanguageField($childTableName);
            if (!$childTableLanguageField) {
                // Skip child table if it is not localizable
                continue;
            }
            $parentTableName = $inlineChild['parentTableName'];
            $parentTableLanguageField = $this->tcaHelper->getLanguageField($parentTableName);
            if (!$parentTableLanguageField) {
                // Skip if parent table is not localizable
                continue;
            }
            $fieldNameOfParentTableUid = $inlineChild['fieldNameOfParentTableUid'];
            if (!$tableHelper->tableExistsInDatabase($parentTableName)) {
                continue;
            }
            $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($childTableName);
            $isTableWorkspaceAware = !empty($workspaceIdField);
            $childTableTranslationParentField = $this->tcaHelper->getTranslationParentField($childTableName);

            $selectFields = [
                'uid',
                'pid',
                $fieldNameOfParentTableUid,
                $childTableLanguageField,
                $childTableTranslationParentField,
            ];
            if ($isTableWorkspaceAware) {
                $selectFields[] = $workspaceIdField;
            }

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($childTableName);
            // Do not consider deleted child records.
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $result = $queryBuilder->select(...$selectFields)
                ->from($childTableName)
                ->where(
                    $queryBuilder->expr()->gt($fieldNameOfParentTableUid, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                )
                ->orderBy('uid')
                ->executeQuery();
            while ($inlineChildRow = $result->fetchAssociative()) {
                /** @var array<string, int|string> $inlineChildRow */
                $childRowLanguage = (int)$inlineChildRow[$childTableLanguageField];
                if ($childRowLanguage < 0) {
                    // Skip parent check if child has language "-1"
                    continue;
                }
                $childRowTranslationParent = (int)($inlineChildRow[$childTableTranslationParentField] ?? 0);
                if ($childRowTranslationParent > 0) {
                    continue;
                }
                try {
                    $parentRow = $recordsHelper->getRecord($parentTableName, ['uid', $parentTableLanguageField], (int)$inlineChildRow[$fieldNameOfParentTableUid]);
                    $parentRowLanguage = (int)$parentRow[$parentTableLanguageField];
                    if ($parentRowLanguage >= 0 && $childRowLanguage !== $parentRowLanguage) {
                        $inlineChildRow['_reasonBroken'] = 'Parent record language ' . $parentRowLanguage;
                        $inlineChildRow['_parentTableName'] = $parentTableName;
                        $inlineChildRow['_fieldNameOfParentTableUid'] = $fieldNameOfParentTableUid;
                        $inlineChildRow['_parentRowLanguage'] = $parentRowLanguage;
                        $affectedRows[$childTableName][] = $inlineChildRow;
                    }
                } catch (NoSuchRecordException $e) {
                    // Record existence has been checked by InlineForeignFieldChildrenParentMissing already.
                    // This can only happen if such a broken record has been added meanwhile, ignore it now.
                    continue;
                }
            }
        }
        return $affectedRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);
        foreach ($affectedRecords as $tableName => $tableRows) {
            $languageField = $this->tcaHelper->getLanguageField($tableName);
            foreach ($tableRows as $inlineChildRow) {
                $updateFields = [
                    $languageField => [
                        'value' => $inlineChildRow['_parentRowLanguage'],
                        'type' => Connection::PARAM_INT,
                    ],
                ];
                $this->updateSingleTcaRecord($io, $simulate, $recordsHelper, $tableName, (int)$inlineChildRow['uid'], $updateFields);
            }
        }
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        foreach ($affectedRecords as $tableName => $rows) {
            $extraDbFields = [
                (string)$rows[0]['_fieldNameOfParentTableUid'],
            ];
            $this->outputRecordDetails($io, [$tableName => $rows], '_reasonBroken', [], $extraDbFields);
        }
    }
}
