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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use ViewMend\Typo3SiteTracker\Configuration\ConfigurationResolver;
use ViewMend\Typo3SiteTracker\Persistence\DeliveryRepositoryInterface;

final class StatusCommand extends Command
{
    public function __construct(
        private readonly ConfigurationResolver $configuration,
        private readonly DeliveryRepositoryInterface $deliveries,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Show ViewMend Site Tracker readiness and local delivery counts.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $configuration = $this->configuration->resolve();
        $counts = $this->deliveries->counts($configuration->siteIdentifier ?: null);
        $io->definitionList(
            ['Ready' => $configuration->isReady() ? 'yes' : 'no'],
            ['Enabled' => $configuration->enabled ? 'yes' : 'no'],
            ['Site identifier' => $configuration->siteIdentifier ?: 'not configured'],
            ['Site URL' => $configuration->siteUrl ?: 'not configured'],
            ['Environment' => $configuration->environment],
            ['Token source' => $configuration->tokenSource],
        );
        $io->table(['Status', 'Count'], array_map(
            static fn (string $status, int $count): array => [$status, (string) $count],
            array_keys($counts),
            array_values($counts),
        ));

        return $configuration->isReady() ? Command::SUCCESS : Command::INVALID;
    }
}
