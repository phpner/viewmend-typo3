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

namespace ViewMend\Typo3SiteTracker\EventListener;

use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Workspaces\Event\AfterRecordPublishedEvent;
use ViewMend\Typo3SiteTracker\Application\ContentReconciler;
use ViewMend\Typo3SiteTracker\Support\Redactor;

#[AsEventListener(identifier: 'viewmend-site-tracker/workspace-publication')]
final readonly class WorkspacePublicationListener
{
    public function __construct(
        private ContentReconciler $reconciler,
        private Redactor $redactor,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AfterRecordPublishedEvent $event): void
    {
        if (!in_array($event->getTable(), ['pages', 'tt_content'], true)) {
            return;
        }
        try {
            $this->reconciler->reconcileWorkspacePublication($event->getTable(), $event->getRecordId());
        } catch (Throwable $exception) {
            $this->logger->error('ViewMend could not reconcile a published workspace record.', [
                'record' => $event->getTable() . ':' . $event->getRecordId(),
                'workspace_id' => $event->getWorkspaceId(),
                'reason' => $this->redactor->redact($exception->getMessage()),
            ]);
        }
    }
}
