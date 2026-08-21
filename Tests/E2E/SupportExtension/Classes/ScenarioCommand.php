<?php

/*
 * This file is part of the ViewMend Site Tracker extension for TYPO3.
 *
 * Copyright (C) 2026 ViewMend
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. See the LICENSE file distributed with this
 * source code for the full license text.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace ViewMend\Typo3SiteTrackerE2E;

use DateTimeImmutable;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use ViewMend\Typo3SiteTracker\Application\DeliveryWorker;
use ViewMend\Typo3SiteTracker\Application\EventRecorder;
use ViewMend\Typo3SiteTracker\Configuration\SettingsRepositoryInterface;
use ViewMend\Typo3SiteTracker\Domain\DeliveryStatus;
use ViewMend\Typo3SiteTracker\Domain\EventType;
use ViewMend\Typo3SiteTracker\Domain\OutgoingEvent;
use ViewMend\Typo3SiteTracker\Persistence\DeliveryRepositoryInterface;
use ViewMend\Typo3SiteTracker\Support\ClockInterface;

final class ScenarioCommand extends Command
{
    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
        private readonly DeliveryRepositoryInterface $deliveries,
        private readonly DeliveryWorker $worker,
        private readonly EventRecorder $events,
        private readonly SiteFinder $siteFinder,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->initializeBackendUser();
        $sites = $this->siteFinder->getAllSites();
        $site = reset($sites);
        if ($site === false) {
            $io->error('The TYPO3 test site is missing.');
            return Command::FAILURE;
        }
        $this->settings->save([
            'integration_id' => 'local-test-integration',
            'site_identifier' => $site->getIdentifier(),
            'site_url' => rtrim((string) $site->getBase(), '/') . '/',
            'environment' => 'testing',
            'enabled' => 1,
            'record_pages' => 1,
            'record_content' => 1,
            'record_publish' => 1,
            'record_update' => 1,
            'record_unpublish' => 1,
            'record_delete' => 1,
            'record_cache_flush' => 1,
            'retention_days' => 30,
        ]);

        $this->queueOperationalEvent('e2e-accepted', EventType::Custom);
        $this->queueOperationalEvent('e2e-duplicate', EventType::Deployment);
        $this->queueOperationalEvent('e2e-permanent', EventType::Custom);
        $this->queueOperationalEvent('e2e-retry', EventType::Custom);
        $this->exercisePageLifecycle($site->getRootPageId());

        $report = $this->worker->process(100);
        $counts = $this->deliveries->counts($site->getIdentifier());
        $recent = $this->deliveries->findRecent(null, $site->getIdentifier(), 100);
        $titles = array_column($recent, 'title');
        $requiredTitles = [
            'TYPO3 page published',
            'TYPO3 page updated',
            'TYPO3 page unpublished',
            'TYPO3 page deleted',
        ];
        foreach ($requiredTitles as $title) {
            if (!in_array($title, $titles, true)) {
                $io->error('Missing lifecycle event: ' . $title);
                return Command::FAILURE;
            }
        }
        if (
            $counts[DeliveryStatus::Accepted->value] < 5
            || $counts[DeliveryStatus::Duplicate->value] < 1
            || $counts[DeliveryStatus::PermanentlyFailed->value] < 1
            || $counts[DeliveryStatus::RetryScheduled->value] < 1
        ) {
            $io->error('The expected delivery lifecycle states were not produced.');
            return Command::FAILURE;
        }

        $io->success('ViewMend E2E scenario passed: ' . $report->summary());
        return Command::SUCCESS;
    }

    private function queueOperationalEvent(string $eventId, EventType $type): void
    {
        $this->events->record(new OutgoingEvent(
            eventId: $eventId,
            type: $type,
            title: 'TYPO3 E2E operational event',
            occurredAt: $this->clock->now(),
            siteUrl: 'http://127.0.0.1:8088/',
            pageUrls: [],
            environment: 'testing',
            changedFields: ['metadata'],
            metadata: ['cms' => 'typo3', 'operation' => 'e2e'],
        ));
    }

    private function exercisePageLifecycle(int $rootPageId): void
    {
        $first = 'NEW-viewmend-page-one';
        $this->dataMap(['pages' => [
            $first => [
                'pid' => $rootPageId,
                'title' => 'Tracked page',
                'slug' => '/tracked-page',
                'doktype' => 1,
                'hidden' => 0,
            ],
        ]]);
        $firstUid = $this->newId($first);
        $this->dataMap(['pages' => [$firstUid => ['title' => 'Tracked page updated']]]);
        $this->dataMap(['pages' => [$firstUid => ['hidden' => 1]]]);

        $second = 'NEW-viewmend-page-two';
        $this->dataMap(['pages' => [
            $second => [
                'pid' => $rootPageId,
                'title' => 'Deleted tracked page',
                'slug' => '/deleted-tracked-page',
                'doktype' => 1,
                'hidden' => 0,
            ],
        ]]);
        $secondUid = $this->newId($second);
        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start([], ['pages' => [$secondUid => ['delete' => 1]]]);
        $handler->process_cmdmap();
        $this->assertDataHandlerSucceeded($handler);
    }

    /** @param array<string, array<int|string, array<string, int|string>>> $data */
    private function dataMap(array $data): void
    {
        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start($data, []);
        $handler->process_datamap();
        $this->assertDataHandlerSucceeded($handler);
        $GLOBALS['viewmend_e2e_new_ids'] = array_merge(
            is_array($GLOBALS['viewmend_e2e_new_ids'] ?? null) ? $GLOBALS['viewmend_e2e_new_ids'] : [],
            $handler->substNEWwithIDs,
        );
    }

    private function newId(string $placeholder): int
    {
        $ids = $GLOBALS['viewmend_e2e_new_ids'] ?? [];
        $uid = is_array($ids) ? ($ids[$placeholder] ?? null) : null;
        if (!is_int($uid) && !is_numeric($uid)) {
            throw new \RuntimeException('TYPO3 did not create the E2E page.');
        }

        return (int) $uid;
    }

    private function initializeBackendUser(): void
    {
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->setBeUserByName('admin');
        if (!is_array($backendUser->user) || (int) ($backendUser->user['uid'] ?? 0) === 0) {
            throw new \RuntimeException('The TYPO3 E2E administrator is missing.');
        }
        $backendUser->fetchGroupData();
        $GLOBALS['BE_USER'] = $backendUser;
    }

    private function assertDataHandlerSucceeded(DataHandler $handler): void
    {
        if ($handler->errorLog !== []) {
            throw new \RuntimeException('TYPO3 DataHandler failed: ' . implode(' | ', $handler->errorLog));
        }
    }
}
