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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use ViewMend\Typo3SiteTracker\Application\DeliveryWorker;
use ViewMend\Typo3SiteTracker\Persistence\DeliveryRepositoryInterface;
use ViewMend\Typo3SiteTracker\Support\ClockInterface;

final class RetryDeliveryCommand extends Command
{
    public function __construct(
        private readonly DeliveryRepositoryInterface $deliveries,
        private readonly DeliveryWorker $worker,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Reset a permanently failed delivery for retry, optionally processing it immediately.')
            ->addArgument('delivery', InputArgument::REQUIRED, 'Local numeric delivery ID.')
            ->addOption('run-now', null, InputOption::VALUE_NONE, 'Claim and process the delivery after resetting it.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $uid = filter_var($input->getArgument('delivery'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($uid === false) {
            $io->error('Delivery must be a positive numeric ID.');
            return Command::INVALID;
        }
        if (!$this->deliveries->retry($uid, $this->clock->now())) {
            $io->error('Only an existing permanently failed delivery can be retried.');
            return Command::FAILURE;
        }
        if ((bool) $input->getOption('run-now')) {
            $outcome = $this->worker->processOne($uid);
            if ($outcome === null) {
                $io->error('The delivery could not be claimed.');
                return Command::FAILURE;
            }
            $io->success('Delivery processed with outcome: ' . $outcome);
            return $outcome === 'failed' ? Command::FAILURE : Command::SUCCESS;
        }
        $io->success('Delivery queued for retry.');

        return Command::SUCCESS;
    }
}
