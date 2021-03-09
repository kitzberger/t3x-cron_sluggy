<?php

namespace Cron\CronSluggy\Updates;

/*
 * This file is part of TYPO3 CMS-extension cron_sluggy.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Command for migrating fields from "pages.tx_realurl_exclude"
 * into "pages.exclude_slug_for_subpages".
 */
class MigrateRealUrlPathSegmentField implements UpgradeWizardInterface
{
    public function getIdentifier(): string
    {
        return 'cronSluggyMigrateRealUrlExclude';
    }

    public function getTitle(): string
    {
        return 'cron_sluggy - Migrate RealUrl path segment';
    }

    public function getDescription(): string
    {
        return 'cron_sluggy - Migrate RealUrl pages.tx_realurl_pathsegment field to cron_sluggy\'s tx_cronsluggy_pathsegment.';
    }

    /**
     * Execute an update
     *
     * @return bool
     */
    public function executeUpdate(): bool
    {
        $conn = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages');

        $queryBuilder = $conn->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        $queryBuilder
            ->update('pages')
            ->set('tx_cronsluggy_pathsegment', 'tx_realurl_pathsegment', false)
            ->execute();

        return true;
    }

    /**
     * Check if update is necessary
     *
     * @return bool
     */
    public function updateNecessary(): bool
    {
        $conn = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages');
        $columns = $conn->getSchemaManager()->listTableColumns('pages');
        foreach ($columns as $column) {
            if (strtolower($column->getName()) === 'tx_realurl_pathsegment') {
                return true;
            }
        }
        return false;
    }

    /**
     * We need a working database
     *
     * @return string[]
     */
    public function getPrerequisites(): array
    {
        return [
            DatabaseUpdatedPrerequisite::class
        ];
    }
}
