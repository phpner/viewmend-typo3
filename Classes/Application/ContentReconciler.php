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

namespace ViewMend\Typo3SiteTracker\Application;

use ViewMend\Typo3SiteTracker\Configuration\ConfigurationResolver;
use ViewMend\Typo3SiteTracker\Configuration\TrackerConfiguration;
use ViewMend\Typo3SiteTracker\Domain\EventType;
use ViewMend\Typo3SiteTracker\Domain\LifecycleTransition;
use ViewMend\Typo3SiteTracker\Domain\LifecycleTransitionMapper;
use ViewMend\Typo3SiteTracker\Domain\OutgoingEvent;
use ViewMend\Typo3SiteTracker\Persistence\Observation;
use ViewMend\Typo3SiteTracker\Persistence\ObservationRepositoryInterface;
use ViewMend\Typo3SiteTracker\Site\PublicRecordResolverInterface;
use ViewMend\Typo3SiteTracker\Site\ResolvedPublicRecord;
use ViewMend\Typo3SiteTracker\Support\ClockInterface;
use ViewMend\Typo3SiteTracker\Support\EventIdFactory;

final readonly class ContentReconciler
{
    public function __construct(
        private ConfigurationResolver $configuration,
        private PublicRecordResolverInterface $records,
        private ObservationRepositoryInterface $observations,
        private LifecycleTransitionMapper $transitions,
        private EventRecorder $events,
        private EventIdFactory $ids,
        private ClockInterface $clock,
    ) {
    }

    public function prime(string $table, int $uid): void
    {
        $configuration = $this->configuration->resolve();
        if (!$this->tracksTable($table, $configuration)) {
            return;
        }
        $current = $this->records->resolve($table, $uid, $configuration);
        if ($current === null) {
            return;
        }
        if ($this->observations->find($current->siteIdentifier, $table, $uid, $current->languageId) !== null) {
            return;
        }
        $this->observations->save($this->observation($current, 0));
    }

    public function reconcile(string $table, int $uid): int
    {
        return $this->reconcileRecord($table, $uid, true);
    }

    public function reconcileWorkspacePublication(string $table, int $uid): int
    {
        return $this->reconcileRecord($table, $uid, false);
    }

    private function reconcileRecord(string $table, int $uid, bool $allowFirstPublicObservation): int
    {
        $configuration = $this->configuration->resolve();
        if (!$configuration->enabled || !$this->tracksTable($table, $configuration)) {
            return 0;
        }
        $current = $this->records->resolve($table, $uid, $configuration);
        if ($current === null) {
            if ($this->records->exists($table, $uid)) {
                return 0;
            }
            return $this->reconcileDeletion($table, $uid, $configuration);
        }

        $previous = $this->observations->find($current->siteIdentifier, $table, $uid, $current->languageId);
        if ($previous === null && !$allowFirstPublicObservation) {
            // The post-publish event has no reliable before-state. If the normal DataHandler
            // pre-hook did not capture one, establish a baseline instead of guessing.
            $this->observations->save($this->observation($current, 0));
            return 0;
        }
        $transition = $this->transitions->map(
            $previous?->public,
            true,
            $current->public,
            $previous?->fingerprint,
            $current->fingerprint,
        );
        $revision = ($previous->revision ?? 0) + 1;
        $queued = 0;
        if ($transition !== null && $this->ruleAllows($transition, $configuration)) {
            $event = $this->event($current, $transition, $revision, $configuration);
            $queued = $this->events->record($event) === null ? 0 : 1;
        }
        $this->observations->save($this->observation($current, $revision));

        return $queued;
    }

    public function reconcileDue(int $limit = 100): int
    {
        $queued = 0;
        foreach ($this->observations->findDue($this->clock->now()->getTimestamp(), $limit) as $observation) {
            $queued += $this->reconcile($observation->recordTable, $observation->recordUid);
        }

        return $queued;
    }

    private function reconcileDeletion(string $table, int $uid, TrackerConfiguration $configuration): int
    {
        $queued = 0;
        foreach ($this->observations->findByRecord($table, $uid) as $previous) {
            if ($previous->siteIdentifier !== $configuration->siteIdentifier) {
                continue;
            }
            $revision = $previous->revision + 1;
            if ($previous->public && $configuration->recordDelete) {
                $current = new ResolvedPublicRecord(
                    table: $table,
                    uid: $uid,
                    languageId: $previous->languageId,
                    siteIdentifier: $previous->siteIdentifier,
                    siteUrl: $previous->siteUrl,
                    pageUrl: $previous->pageUrl,
                    public: false,
                    fingerprint: '',
                    nextTransitionAt: 0,
                );
                $queued += $this->events->record(
                    $this->event($current, LifecycleTransition::Delete, $revision, $configuration),
                ) === null ? 0 : 1;
            }
            $this->observations->save(new Observation(
                siteIdentifier: $previous->siteIdentifier,
                recordTable: $table,
                recordUid: $uid,
                languageId: $previous->languageId,
                siteUrl: $previous->siteUrl,
                pageUrl: $previous->pageUrl,
                public: false,
                fingerprint: '',
                revision: $revision,
                nextTransitionAt: 0,
                lastSeenAt: $this->clock->now()->getTimestamp(),
            ));
        }

        return $queued;
    }

    private function event(
        ResolvedPublicRecord $record,
        LifecycleTransition $transition,
        int $revision,
        TrackerConfiguration $configuration,
    ): OutgoingEvent {
        $recordType = $record->table === 'pages' ? 'page' : 'content';
        $title = sprintf('TYPO3 %s %s', $recordType, $this->pastTense($transition));
        $changedField = in_array($transition, [LifecycleTransition::Publish, LifecycleTransition::Update], true)
            ? 'content'
            : 'metadata';

        return new OutgoingEvent(
            eventId: $this->ids->deterministic([
                $record->siteIdentifier,
                $record->table,
                $record->uid,
                $record->languageId,
                $transition->value,
                $revision,
            ]),
            type: EventType::ContentUpdate,
            title: $title,
            occurredAt: $this->clock->now(),
            siteUrl: $record->siteUrl,
            pageUrls: [$record->pageUrl],
            environment: $configuration->environment,
            changedFields: [$changedField],
            metadata: [
                'cms' => 'typo3',
                'operation' => $transition->value,
                'record_type' => $recordType,
                'record_uid' => $record->uid,
                'language_id' => $record->languageId,
                'site_identifier' => $record->siteIdentifier,
            ],
        );
    }

    private function observation(ResolvedPublicRecord $record, int $revision): Observation
    {
        return new Observation(
            siteIdentifier: $record->siteIdentifier,
            recordTable: $record->table,
            recordUid: $record->uid,
            languageId: $record->languageId,
            siteUrl: $record->siteUrl,
            pageUrl: $record->pageUrl,
            public: $record->public,
            fingerprint: $record->fingerprint,
            revision: $revision,
            nextTransitionAt: $record->nextTransitionAt,
            lastSeenAt: $this->clock->now()->getTimestamp(),
        );
    }

    private function tracksTable(string $table, TrackerConfiguration $configuration): bool
    {
        return ($table === 'pages' && $configuration->recordPages)
            || ($table === 'tt_content' && $configuration->recordContent);
    }

    private function ruleAllows(LifecycleTransition $transition, TrackerConfiguration $configuration): bool
    {
        return match ($transition) {
            LifecycleTransition::Publish => $configuration->recordPublish,
            LifecycleTransition::Update => $configuration->recordUpdate,
            LifecycleTransition::Unpublish => $configuration->recordUnpublish,
            LifecycleTransition::Delete => $configuration->recordDelete,
        };
    }

    private function pastTense(LifecycleTransition $transition): string
    {
        return match ($transition) {
            LifecycleTransition::Publish => 'published',
            LifecycleTransition::Update => 'updated',
            LifecycleTransition::Unpublish => 'unpublished',
            LifecycleTransition::Delete => 'deleted',
        };
    }
}
