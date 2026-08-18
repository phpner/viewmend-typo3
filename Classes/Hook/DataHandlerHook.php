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

namespace ViewMend\Typo3SiteTracker\Hook;

use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use ViewMend\Typo3SiteTracker\Application\ContentReconciler;
use ViewMend\Typo3SiteTracker\Support\Redactor;
use ViewMend\Typo3SiteTracker\Support\ScalarValue;

final class DataHandlerHook
{
    /** @var array<string, array<int, true>> */
    private array $candidates = [];

    public function __construct(
        private readonly ContentReconciler $reconciler,
        private readonly Redactor $redactor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param array<string, mixed> $incomingFieldArray */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- TYPO3 DataHandler hook API method name.
    public function processDatamap_preProcessFieldArray(
        array &$incomingFieldArray,
        string $table,
        string|int $id,
        DataHandler $dataHandler,
    ): void {
        if (is_numeric($id)) {
            $this->safelyPrime($table, (int) $id);
        }
    }

    /** @param array<string, mixed> $fieldArray */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- TYPO3 DataHandler hook API method name.
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        string|int $id,
        array $fieldArray,
        DataHandler $dataHandler,
    ): void {
        $realId = is_numeric($id)
            ? (int) $id
            : ScalarValue::int($dataHandler->substNEWwithIDs[(string) $id] ?? null);
        $this->addCandidate($table, $realId);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- TYPO3 DataHandler hook API method name.
    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        $this->reconcileCandidates();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- TYPO3 DataHandler hook API method name.
    public function processCmdmap_preProcess(
        string $command,
        string $table,
        int $id,
        mixed $value,
        DataHandler $dataHandler,
        mixed $pasteUpdate,
    ): void {
        $this->safelyPrime($table, $id);
        $this->addCandidate($table, $id);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- TYPO3 DataHandler hook API method name.
    public function processCmdmap_postProcess(
        string $command,
        string $table,
        int $id,
        mixed $value,
        DataHandler $dataHandler,
        mixed $pasteUpdate = null,
        mixed $pasteDatamap = null,
    ): void {
        $this->addCandidate($table, $id);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- TYPO3 DataHandler hook API method name.
    public function processCmdmap_afterFinish(DataHandler $dataHandler): void
    {
        $this->reconcileCandidates();
    }

    private function addCandidate(string $table, int $uid): void
    {
        if ($uid > 0 && in_array($table, ['pages', 'tt_content'], true)) {
            $this->candidates[$table][$uid] = true;
        }
    }

    private function safelyPrime(string $table, int $uid): void
    {
        if (!in_array($table, ['pages', 'tt_content'], true) || $uid <= 0) {
            return;
        }
        try {
            $this->reconciler->prime($table, $uid);
        } catch (Throwable $exception) {
            $this->logFailure('prime', $table, $uid, $exception);
        }
    }

    private function reconcileCandidates(): void
    {
        $candidates = $this->candidates;
        $this->candidates = [];
        foreach ($candidates as $table => $uids) {
            foreach (array_keys($uids) as $uid) {
                try {
                    $this->reconciler->reconcile($table, $uid);
                } catch (Throwable $exception) {
                    $this->logFailure('reconcile', $table, $uid, $exception);
                }
            }
        }
    }

    private function logFailure(string $operation, string $table, int $uid, Throwable $exception): void
    {
        $this->logger->error('ViewMend content lifecycle capture failed without interrupting the TYPO3 save.', [
            'operation' => $operation,
            'record' => $table . ':' . $uid,
            'reason' => $this->redactor->redact($exception->getMessage()),
        ]);
    }
}
