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
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Records in localization tables must point to a sys_language_uid=0 record in their transOrigPointerField.
 *
 * @todo: needs update to skip tt_content?!
 */
final class TcaTablesTranslatedParentInvalidPointer extends AbstractHealthCheck implements HealthCheckInterface
{
    public function header(SymfonyStyle $io): void
    {
        $io->section('Scan for record translations pointing to non default language parent');
        $this->outputClass($io);
        $this->outputTags($io, self::TAG_SOFT_DELETE, self::TAG_REMOVE, self::TAG_WORKSPACE_REMOVE);
        $io->text([
            'Record translations ("translate" / "connected" mode, as opposed to "free" mode) use the',
            'database field "transOrigPointerField" (field name usually "l10n_parent" or "l18n_parent").',
            'This field points to the default language record. This health check verifies that target',
            'actually has sys_language_uid = 0. Violating localizations are soft deleted if possible,',
            'or removed.',
        ]);
    }

    protected function getAffectedRecords(): array
    {
        /** @var RecordsHelper $recordsHelper */
        $recordsHelper = $this->container->get(RecordsHelper::class);

        $affectedRows = [];
        foreach ($this->tcaHelper->getNextLanguageAwareTcaTable(['pages']) as $tableName) {
            /** @var string $languageField */
            $languageField = $this->tcaHelper->getLanguageField($tableName);
            /** @var string $translationParentField */
            $translationParentField = $this->tcaHelper->getTranslationParentField($tableName);
            $workspaceIdField = $this->tcaHelper->getWorkspaceIdField($tableName);
            $isTableWorkspaceAware = !empty($workspaceIdField);
            $selectFields = ['uid', 'pid', $translationParentField];
            if ($isTableWorkspaceAware) {
                $selectFields[] = $workspaceIdField;
            }
            $parentRowFields = [
                'uid',
                'pid',
                $languageField,
                $translationParentField,
            ];

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            // Query could be potentially optimized with a self-join, but well ...
            $result = $queryBuilder->select(...$selectFields)->from($tableName)
                ->where(
                    // localized records
                    $queryBuilder->expr()->gt($languageField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    // in 'connected' mode
                    $queryBuilder->expr()->gt($translationParentField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
                )
                ->orderBy('uid')
                ->executeQuery();

            while ($localizedRow = $result->fetchAssociative()) {
                /** @var array<string, int|string> $localizedRow */
                try {
                    $parentRow = $recordsHelper->getRecord($tableName, $parentRowFields, (int)$localizedRow[$translationParentField]);
                    if ((int)$parentRow[$languageField] !== 0) {
                        $affectedRows[$tableName][] = $localizedRow;
                    }
                } catch (NoSuchRecordException $e) {
                    // Ignore non-existing localization parent rows for now.
                    continue;
                }
            }
        }
        return $affectedRows;
    }

    protected function processRecords(SymfonyStyle $io, bool $simulate, array $affectedRecords): void
    {
        $this->softOrHardDeleteRecords($io, $simulate, $affectedRecords);
    }

    protected function recordDetails(SymfonyStyle $io, array $affectedRecords): void
    {
        $this->outputRecordDetails($io, $affectedRecords, '', ['languageField', 'transOrigPointerField']);
    }
}
