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

namespace ViewMend\Typo3SiteTracker\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use ViewMend\Typo3SiteTracker\Application\ContentReconciler;
use ViewMend\Typo3SiteTracker\Application\DeliveryWorker;

final class ProcessQueueCommand extends Command
{
    public function __construct(
        private readonly ContentReconciler $reconciler,
        private readonly DeliveryWorker $worker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Reconcile scheduled TYPO3 visibility changes and process queued ViewMend deliveries.')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum deliveries to process (1-100).', '20')
            ->addOption(
                'reconcile-limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum scheduled records to reconcile (1-500).',
                '100',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = filter_var(
            $input->getOption('limit'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 100]],
        );
        $reconcileLimit = filter_var(
            $input->getOption('reconcile-limit'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 500]],
        );
        if ($limit === false || $reconcileLimit === false) {
            $io->error('Limits must be integers in their documented ranges.');
            return Command::INVALID;
        }
        $queued = $this->reconciler->reconcileDue($reconcileLimit);
        $report = $this->worker->process($limit);
        $io->success(sprintf('scheduled_events=%d %s', $queued, $report->summary()));

        return $report->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
