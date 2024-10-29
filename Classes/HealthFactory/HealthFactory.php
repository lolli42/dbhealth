<?php

declare(strict_types=1);

namespace Lolli\Dbdoctor\HealthFactory;

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

use Lolli\Dbdoctor\HealthCheck;
use Psr\Container\ContainerInterface;

final class HealthFactory implements HealthFactoryInterface
{
    /**
     * @var string[]
     */
    private array $healthClasses = [
        HealthCheck\SiteLanguageDeleted::class,
        HealthCheck\WorkspacesNotLoadedRecordsDangling::class,
        HealthCheck\WorkspacesRecordsOfDeletedWorkspaces::class,
        HealthCheck\TcaTablesDeleteFlagZeroOrOne::class,
        HealthCheck\WorkspacesSoftDeletedRecords::class,
        HealthCheck\WorkspacesPidNegative::class,
        HealthCheck\WorkspacesT3verStateNotZeroInLive::class,
        HealthCheck\WorkspacesT3verStateMinusOne::class,
        HealthCheck\WorkspacesT3verStateThree::class,
        // Note we have "move sys_redirects" *before* PagesBrokenTree for a better chance to move to correct pid
        HealthCheck\SysRedirectInvalidPid::class,
        HealthCheck\TcaTablesLanguageLessThanOneHasZeroLanguageParent::class,
        HealthCheck\TcaTablesLanguageLessThanOneHasZeroLanguageSource::class,
        HealthCheck\PagesBrokenTree::class,
        HealthCheck\PagesTranslatedLanguageParentMissing::class,
        HealthCheck\PagesTranslatedLanguageParentDeleted::class,
        HealthCheck\PagesTranslatedLanguageParentDifferentPid::class,
        // This one is relatively early since it is rather safe and prevents loops on checks below.
        HealthCheck\TcaTablesTranslatedParentInvalidPointer::class,
        HealthCheck\TtContentPidMissing::class,
        HealthCheck\TtContentPidDeleted::class,
        HealthCheck\TtContentDeletedLocalizedParentExists::class,
        HealthCheck\TtContentLocalizedParentExists::class,
        HealthCheck\TtContentLocalizedParentSoftDeleted::class,
        HealthCheck\TtContentDeletedLocalizedParentDifferentPid::class,
        HealthCheck\TtContentLocalizedParentDifferentPid::class,
        HealthCheck\TtContentLocalizedDuplicates::class,
        HealthCheck\TtContentLocalizationSourceExists::class,
        HealthCheck\TtContentLocalizationSourceSetWithParent::class,
        HealthCheck\TtContentLocalizationSourceLogicWithParent::class,
        // @todo: Next one is skipped in v12 and can be dropped when v11 compat is removed from extension.
        HealthCheck\SysFileReferenceInvalidTableLocal::class,
        HealthCheck\SysFileReferenceDangling::class,
        HealthCheck\SysFileReferenceDeletedLocalizedParentExists::class,
        HealthCheck\SysFileReferenceLocalizedParentExists::class,
        HealthCheck\SysFileReferenceLocalizedParentDeleted::class,
        HealthCheck\SysFileReferenceLocalizedFieldSync::class,
        HealthCheck\SysFileReferenceInvalidPid::class,
        HealthCheck\TcaTablesPidMissing::class,
        // @todo: Disabled for now, see the class comment
        // SysFileReferenceInvalidFieldname::class,
        HealthCheck\TcaTablesPidDeleted::class,
        HealthCheck\TcaTablesTranslatedLanguageParentMissing::class,
        // Check sys_file_reference pointing to not existing records *again*, TcaTablesTranslatedLanguageParentMissing may have deleted some.
        HealthCheck\SysFileReferenceDangling::class,
        HealthCheck\TcaTablesTranslatedLanguageParentDeleted::class,
        HealthCheck\TcaTablesTranslatedLanguageParentDifferentPid::class,
        // TcaTablesInvalidLanguageParent::class,
        HealthCheck\InlineForeignFieldChildrenParentMissing::class,
        HealthCheck\InlineForeignFieldNoForeignTableFieldChildrenParentMissing::class,
        HealthCheck\InlineForeignFieldChildrenParentDeleted::class,
        HealthCheck\InlineForeignFieldNoForeignTableFieldChildrenParentDeleted::class,
        // @todo: Maybe that's not a good position when we start scanning for records translated more than once (issue #9)?
        HealthCheck\InlineForeignFieldChildrenParentLanguageDifferent::class,
        HealthCheck\InlineForeignFieldNoForeignTableFieldChildrenParentLanguageDifferent::class,
    ];

    private ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function getNext(): iterable
    {
        foreach ($this->healthClasses as $class) {
            /** @var object $instance */
            $instance = $this->container->get($class);
            if (!$instance instanceof HealthCheck\HealthCheckInterface) {
                throw new \InvalidArgumentException(get_class($instance) . 'does not implement HealthInterface');
            }
            yield $instance;
        }
    }
}
