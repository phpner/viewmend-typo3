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
use Throwable;
use ViewMend\Typo3SiteTracker\Application\EventRecorder;
use ViewMend\Typo3SiteTracker\Configuration\ConfigurationResolver;
use ViewMend\Typo3SiteTracker\Domain\EventType;
use ViewMend\Typo3SiteTracker\Domain\OutgoingEvent;
use ViewMend\Typo3SiteTracker\Site\CanonicalUrlPolicy;
use ViewMend\Typo3SiteTracker\Support\ClockInterface;
use ViewMend\Typo3SiteTracker\Support\ScalarValue;

final class DeploymentMarkerCommand extends Command
{
    public function __construct(
        private readonly ConfigurationResolver $configuration,
        private readonly EventRecorder $events,
        private readonly ClockInterface $clock,
        private readonly CanonicalUrlPolicy $urls,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Queue an explicit ViewMend deployment marker without waiting for the network.')
            ->addArgument(
                'event-id',
                InputArgument::REQUIRED,
                'Stable event ID supplied by the deployment system (max 160 characters).',
            )
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Marker title.', 'TYPO3 deployment')
            ->addOption('reference', null, InputOption::VALUE_REQUIRED, 'Absolute deployment or change reference URL.')
            ->addOption(
                'environment',
                null,
                InputOption::VALUE_REQUIRED,
                'Override the configured environment for this marker.',
            )
            ->addOption(
                'site',
                null,
                InputOption::VALUE_REQUIRED,
                'Expected TYPO3 site identifier; protects against a wrong target.',
            )
            ->addOption(
                'site-url',
                null,
                InputOption::VALUE_REQUIRED,
                'Expected configured public site URL; it cannot change the binding.',
            )
            ->addOption(
                'page-url',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Affected absolute public page URL (repeatable, max 20).',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $configuration = $this->configuration->resolve();
        if (!$configuration->enabled || $configuration->integrationId === '' || $configuration->siteIdentifier === '') {
            $io->error('ViewMend Site Tracker is not enabled and bound to a TYPO3 site.');
            return Command::INVALID;
        }

        $eventId = trim(ScalarValue::string($input->getArgument('event-id')));
        $title = trim(ScalarValue::string($input->getOption('title')));
        $reference = $this->nullableString($input->getOption('reference'));
        $environment = $this->nullableString($input->getOption('environment')) ?? $configuration->environment;
        $expectedSite = $this->nullableString($input->getOption('site'));
        $expectedSiteUrl = $this->nullableString($input->getOption('site-url'));
        $pageUrls = array_values(array_filter(
            (array) $input->getOption('page-url'),
            static fn (mixed $url): bool => is_string($url) && trim($url) !== '',
        ));

        if ($expectedSite !== null && $expectedSite !== $configuration->siteIdentifier) {
            $io->error('The --site value does not match the configured TYPO3 site binding.');
            return Command::INVALID;
        }
        if ($expectedSiteUrl !== null && !$this->urls->basesMatch($expectedSiteUrl, $configuration->siteUrl)) {
            $io->error('The --site-url value does not match the configured public site URL.');
            return Command::INVALID;
        }
        foreach ($pageUrls as $pageUrl) {
            if (!$this->urls->belongsToBase($pageUrl, $configuration->siteUrl)) {
                $io->error('Every --page-url value must belong to the configured TYPO3 site base.');
                return Command::INVALID;
            }
        }

        try {
            $deliveryUid = $this->events->record(new OutgoingEvent(
                eventId: $eventId,
                type: EventType::Deployment,
                title: $title,
                occurredAt: $this->clock->now(),
                siteUrl: $configuration->siteUrl,
                pageUrls: $pageUrls,
                environment: $environment,
                referenceUrl: $reference,
                changedFields: ['metadata'],
                metadata: [
                    'cms' => 'typo3',
                    'operation' => 'deployment',
                    'site_identifier' => $configuration->siteIdentifier,
                ],
            ));
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::INVALID;
        }
        if ($deliveryUid === null) {
            $io->error('The marker was not queued because Site Tracker is disabled or incomplete.');
            return Command::FAILURE;
        }

        $io->success(sprintf('Deployment marker queued locally as delivery %d.', $deliveryUid));
        return Command::SUCCESS;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
