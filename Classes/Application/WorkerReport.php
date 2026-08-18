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

final readonly class WorkerReport
{
    public function __construct(
        public int $recovered,
        public int $claimed,
        public int $accepted,
        public int $duplicates,
        public int $retried,
        public int $failed,
        public int $pruned,
    ) {
    }

    public function summary(): string
    {
        return sprintf(
            'recovered=%d claimed=%d accepted=%d duplicates=%d retried=%d failed=%d pruned=%d',
            $this->recovered,
            $this->claimed,
            $this->accepted,
            $this->duplicates,
            $this->retried,
            $this->failed,
            $this->pruned,
        );
    }
}
