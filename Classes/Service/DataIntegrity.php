<?php

namespace T3Monitor\T3monitoring\Service;

/*
 * This file is part of the t3monitoring extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\DatabaseConnection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class DataIntegrity
 */
class DataIntegrity
{

    /**
     * Invoke after core import
     */
    public function invokeAfterCoreImport()
    {
        $this->usedCore();
    }

    /**
     * Invoke after client import
     */
    public function invokeAfterClientImport()
    {
        $this->usedCore();
        $this->usedExtensions();
    }

    /**
     * Invoke after extension import
     */
    public function invokeAfterExtensionImport()
    {
        $this->getLatestExtensionVersion();
        $this->getNextSecureExtensionVersion();
        $this->usedExtensions();
    }

    /**
     * Get latest extension version
     */
    protected function getLatestExtensionVersion()
    {
        $table = 'tx_t3monitoring_domain_model_extension';

        // Patch release
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);
        $res = $queryBuilder
            ->select('name', 'major_version', 'minor_version')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('insecure', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $queryBuilder->expr()->gt('version_integer', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('is_official', $queryBuilder->createNamedParameter(1, \PDO::PARAM_INT))
            )
            ->groupBy('name', 'major_version', 'minor_version')
            ->execute();

        while ($row = $res->fetch()) {
            $queryBuilder2 = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable($table);
            $highestBugFixRelease = $queryBuilder2
                ->select('version')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->eq('name', $queryBuilder2->createNamedParameter($row['name'])),
                    $queryBuilder->expr()->eq('major_version', $queryBuilder2->createNamedParameter($row['major_version'], \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('minor_version', $queryBuilder2->createNamedParameter($row['minor_version'], \PDO::PARAM_INT))
                )
                ->orderBy('version_integer', 'desc')
                ->setMaxResults(1)
                ->execute()
                ->fetch();

            if (is_array($highestBugFixRelease)) {
                $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                    ->getConnectionForTable($table);
                $connection->update(
                    $table,
                    ['last_bugfix_release' => $highestBugFixRelease['version']],
                    [
                        'name' => $row['name'],
                        'major_version' => $row['major_version'],
                        'minor_version' => $row['minor_version'],
                    ]);
            }
        }

        // Minor release
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);
        $res = $queryBuilder
            ->select('name', 'major_version')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('insecure', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $queryBuilder->expr()->gt('version_integer', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('is_official', $queryBuilder->createNamedParameter(1, \PDO::PARAM_INT))
            )
            ->groupBy('name', 'major_version')
            ->execute();

        while ($row = $res->fetch()) {
            $queryBuilder2 = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable($table);
            $highestBugFixRelease = $queryBuilder2
                ->select('version')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->eq('name', $queryBuilder2->createNamedParameter($row['name'])),
                    $queryBuilder->expr()->eq('major_version', $queryBuilder2->createNamedParameter($row['major_version'], \PDO::PARAM_INT))
                )
                ->orderBy('version_integer', 'desc')
                ->setMaxResults(1)
                ->execute()
                ->fetch();

            if (is_array($highestBugFixRelease)) {
                $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                    ->getConnectionForTable($table);
                $connection->update(
                    $table,
                    ['last_minor_release' => $highestBugFixRelease['version']],
                    [
                        'name' => $row['name'],
                        'major_version' => $row['major_version']
                    ]);
            }
        }

        // Major release
        $queryResult = $this->getDatabaseConnection()->sql_query(
            'SELECT a.version,a.name ' .
            'FROM ' . $table . ' a ' .
            'LEFT JOIN ' . $table . ' b ON a.name = b.name AND a.version_integer < b.version_integer ' .
            'WHERE b.name IS NULL ' .
            'ORDER BY a.uid'
        );

        while ($row = $this->getDatabaseConnection()->sql_fetch_assoc($queryResult)) {
            $where = 'name=' . $this->getDatabaseConnection()->fullQuoteStr($row['name'], $table);
            $this->getDatabaseConnection()->exec_UPDATEquery($table, $where, [
                'last_major_release' => $row['version']
            ]);
        }

        // mark latest version
        $this->getDatabaseConnection()->sql_query('
            UPDATE ' . $table . '
            SET is_latest=1 WHERE version=last_major_release
        ');
    }

    /**
     * Get next secure extension version
     */
    protected function getNextSecureExtensionVersion()
    {
        $table = 'tx_t3monitoring_domain_model_extension';

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);
        $insecureExtensions = $queryBuilder
            ->select('uid', 'name', 'version_integer')
            ->from($table)
            ->where($queryBuilder->expr()->eq('insecure', $queryBuilder->createNamedParameter(1, \PDO::PARAM_INT)))
            ->execute()
            ->fetchAll();

        foreach ($insecureExtensions as $row) {
            $queryBuilder2 = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable($table);
            $nextSecureVersion = $queryBuilder2
                ->select('uid', 'version')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->eq('insecure', $queryBuilder2->createNamedParameter(0, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('name', $queryBuilder2->createNamedParameter($row['name'])),
                    $queryBuilder->expr()->gt('version_integer', $queryBuilder2->createNamedParameter($row['version_integer'], \PDO::PARAM_INT))
                )
                ->setMaxResults(1)
                ->execute()
                ->fetch();

            if (is_array($nextSecureVersion)) {
                $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                    ->getConnectionForTable($table);
                $connection->update($table, ['next_secure_version' => $nextSecureVersion['version']], ['uid' => $row['uid']]);
            }
        }
    }

    /**
     * Used core
     */
    protected function usedCore()
    {
        $table = 'tx_t3monitoring_domain_model_core';
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);
        $rows = $queryBuilder
            ->select('tx_t3monitoring_domain_model_core.uid')
            ->from($table)
            ->leftJoin(
                'tx_t3monitoring_domain_model_core',
                'tx_t3monitoring_domain_model_client',
                'tx_t3monitoring_domain_model_client',
                $queryBuilder->expr()->eq('tx_t3monitoring_domain_model_core.uid', $queryBuilder->quoteIdentifier('tx_t3monitoring_domain_model_client.core'))
            )
            ->execute()
            ->fetchAll();
        $coreRows = [];
        foreach ($rows as $row) {
            $coreRows[$row['uid']] = $row;
        }

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable($table);
        $connection->update($table, ['is_used' => 0], []);
        if (!empty($coreRows)) {
            $connection->update($table, ['is_used' => 1], []);
            foreach ($coreRows as $id => $row) {
                $connection->update($table, ['is_used' => 1], ['uid' => $id]);
            }
        }
    }

    /**
     * Used extensions
     */
    protected function usedExtensions()
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_t3monitoring_domain_model_client');
        $clients = $queryBuilder
            ->select('uid')
            ->from('tx_t3monitoring_domain_model_client')
            ->execute()
            ->fetchAll();

        foreach ($clients as $client) {
            $queryBuilder = $this->getQueryBuilderFor('tx_t3monitoring_client_extension_mm');

            $countInsecure = $queryBuilder
                ->count('uid')
                ->from('tx_t3monitoring_client_extension_mm')
                ->leftJoin(
                    'tx_t3monitoring_client_extension_mm',
                    'tx_t3monitoring_domain_model_extension',
                    'tx_t3monitoring_domain_model_extension',
                    $queryBuilder->expr()->eq('tx_t3monitoring_client_extension_mm.uid_foreign', $queryBuilder->quoteIdentifier('tx_t3monitoring_domain_model_extension.uid'))
                )
                ->where(
                    $queryBuilder->expr()->eq('is_official', $queryBuilder->createNamedParameter(1, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('insecure', $queryBuilder->createNamedParameter(1, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('tx_t3monitoring_client_extension_mm.uid_local', $queryBuilder->createNamedParameter($client['uid'], \PDO::PARAM_INT))
                )->execute()->fetchColumn(0);

            // count outdated extensions
            $queryBuilder = $this->getQueryBuilderFor('tx_t3monitoring_client_extension_mm');
            $countOutdated = $queryBuilder
                ->count('uid')
                ->from('tx_t3monitoring_client_extension_mm')
                ->leftJoin(
                    'tx_t3monitoring_client_extension_mm',
                    'tx_t3monitoring_domain_model_extension',
                    'tx_t3monitoring_domain_model_extension',
                    $queryBuilder->expr()->eq('tx_t3monitoring_client_extension_mm.uid_foreign', $queryBuilder->quoteIdentifier('tx_t3monitoring_domain_model_extension.uid'))
                )
                ->where(
                    $queryBuilder->expr()->eq('is_official', $queryBuilder->createNamedParameter(1, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('insecure', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('is_latest', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('tx_t3monitoring_client_extension_mm.uid_local', $queryBuilder->createNamedParameter($client['uid'], \PDO::PARAM_INT))
                )->execute()->fetchColumn(0);

            // update client
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('tx_t3monitoring_domain_model_client');
            $connection->update(
                'tx_t3monitoring_domain_model_client',
                [
                    'insecure_extensions' => $countInsecure,
                    'outdated_extensions' => $countOutdated
                ],
                [
                    'uid' => $client['uid']
                ]
            );
        }

        // Used extensions
        $this->getDatabaseConnection()->sql_query('
            UPDATE tx_t3monitoring_domain_model_extension
            SET is_used=1
            WHERE uid IN (
              SELECT uid_foreign FROM tx_t3monitoring_client_extension_mm
            );'
        );
    }

    protected function getQueryBuilderFor(string $table): QueryBuilder
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);
    }

    /**
     * @return DatabaseConnection
     */
    protected function getDatabaseConnection()
    {
        return $GLOBALS['TYPO3_DB'];
    }
}
