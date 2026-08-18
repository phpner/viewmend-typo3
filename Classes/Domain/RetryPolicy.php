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

use DateTimeImmutable;
use DateTimeZone;
use ViewMend\Typo3SiteTracker\Support\JitterInterface;

final readonly class RetryPolicy
{
    private const BACKOFF_SECONDS = [60, 300, 900, 3600, 10800, 21600, 43200, 86400];

    public function __construct(
        private JitterInterface $jitter,
        private int $maximumAttempts = 8,
        private int $maximumDelaySeconds = 86400,
    ) {
    }

    public function decide(
        int $attemptsMade,
        bool $retryable,
        ?string $retryAfter,
        DateTimeImmutable $now,
    ): RetryDecision {
        if (!$retryable) {
            return new RetryDecision(false, 0, 'permanent');
        }
        if ($attemptsMade >= $this->maximumAttempts) {
            return new RetryDecision(false, 0, 'attempt_limit');
        }

        $index = max(0, min($attemptsMade - 1, count(self::BACKOFF_SECONDS) - 1));
        $backoff = $this->jitter->apply(min(self::BACKOFF_SECONDS[$index], $this->maximumDelaySeconds));
        $serverDelay = $this->retryAfterSeconds($retryAfter, $now);

        return new RetryDecision(true, max($backoff, $serverDelay), 'transient');
    }

    private function retryAfterSeconds(?string $value, DateTimeImmutable $now): int
    {
        if ($value === null || trim($value) === '') {
            return 0;
        }
        $value = trim($value);
        if (ctype_digit($value)) {
            return min((int) $value, $this->maximumDelaySeconds);
        }
        $date = DateTimeImmutable::createFromFormat(
            'D, d M Y H:i:s \G\M\T',
            $value,
            new DateTimeZone('GMT'),
        );
        if ($date === false) {
            return 0;
        }

        return min(max(0, $date->getTimestamp() - $now->getTimestamp()), $this->maximumDelaySeconds);
    }
}
