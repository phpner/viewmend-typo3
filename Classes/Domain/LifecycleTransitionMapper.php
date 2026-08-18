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

namespace ViewMend\Typo3SiteTracker\Domain;

final readonly class LifecycleTransitionMapper
{
    public function map(
        ?bool $wasPublic,
        bool $exists,
        bool $isPublic,
        ?string $beforeFingerprint,
        ?string $afterFingerprint,
    ): ?LifecycleTransition {
        if ($wasPublic === null) {
            return $isPublic ? LifecycleTransition::Publish : null;
        }
        if ($wasPublic && !$exists) {
            return LifecycleTransition::Delete;
        }
        if ($wasPublic && !$isPublic) {
            return LifecycleTransition::Unpublish;
        }
        if (!$wasPublic && $isPublic) {
            return LifecycleTransition::Publish;
        }
        if ($wasPublic && $isPublic && $beforeFingerprint !== $afterFingerprint) {
            return LifecycleTransition::Update;
        }

        return null;
    }
}
