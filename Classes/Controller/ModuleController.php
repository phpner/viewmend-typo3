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

namespace ViewMend\Typo3SiteTracker\Controller;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\SiteFinder;
use ViewMend\Typo3SiteTracker\Application\DeliveryWorker;
use ViewMend\Typo3SiteTracker\Application\EventRecorder;
use ViewMend\Typo3SiteTracker\Backend\Dashboard\DashboardClient;
use ViewMend\Typo3SiteTracker\Backend\Dashboard\DashboardView;
use ViewMend\Typo3SiteTracker\Configuration\ConfigurationResolver;
use ViewMend\Typo3SiteTracker\Configuration\SecretVault;
use ViewMend\Typo3SiteTracker\Configuration\SettingsRepositoryInterface;
use ViewMend\Typo3SiteTracker\Configuration\TrackerConfiguration;
use ViewMend\Typo3SiteTracker\Domain\DeliveryStatus;
use ViewMend\Typo3SiteTracker\Domain\EventType;
use ViewMend\Typo3SiteTracker\Domain\OutgoingEvent;
use ViewMend\Typo3SiteTracker\Persistence\DeliveryRepositoryInterface;
use ViewMend\Typo3SiteTracker\Support\ClockInterface;
use ViewMend\Typo3SiteTracker\Support\EventIdFactory;
use ViewMend\Typo3SiteTracker\Support\Redactor;
use ViewMend\Typo3SiteTracker\Support\ScalarValue;

final readonly class ModuleController
{
    private const MODULE = 'tools_viewmendtracker';

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private UriBuilder $uriBuilder,
        private SiteFinder $siteFinder,
        private ConfigurationResolver $configuration,
        private SettingsRepositoryInterface $settings,
        private SecretVault $vault,
        private DeliveryRepositoryInterface $deliveries,
        private DeliveryWorker $worker,
        private EventRecorder $events,
        private EventIdFactory $ids,
        private ClockInterface $clock,
        private Redactor $redactor,
        private DashboardClient $dashboard,
        private DashboardView $dashboardView,
        private PageRenderer $pageRenderer,
    ) {
    }

    public function overview(ServerRequestInterface $request): ResponseInterface
    {
        $configuration = $this->configuration->resolve();
        $raw = $this->settings->getRaw();
        $counts = $this->deliveries->counts($configuration->siteIdentifier ?: null);
        $waiting = $counts[DeliveryStatus::Queued->value]
            + $counts[DeliveryStatus::RetryScheduled->value]
            + $counts[DeliveryStatus::InProgress->value];
        $delivered = $counts[DeliveryStatus::Accepted->value] + $counts[DeliveryStatus::Duplicate->value];
        $attention = $counts[DeliveryStatus::PermanentlyFailed->value];
        $issues = $this->readinessIssues($configuration);

        $query = $request->getQueryParams();
        $result = $this->dashboard->dashboard(
            $this->stringInput($query['page_id'] ?? null),
            $this->stringInput($query['device'] ?? null, 'desktop'),
        );

        return $this->render($request, 'Overview', 'overview', [
            'dashboard' => $this->dashboardView->build($result),
            'scopeUri' => $this->uri(self::MODULE . '.overview-filter'),
            'refreshUri' => $this->uri(self::MODULE . '.refresh'),
            'ready' => $configuration->isReady(),
            'issues' => $issues,
            'waiting' => $waiting,
            'delivered' => $delivered,
            'attention' => $attention,
            'cancelled' => $counts[DeliveryStatus::Cancelled->value],
            'siteIdentifier' => $configuration->siteIdentifier,
            'siteUrl' => $configuration->siteUrl,
            'environment' => $configuration->environment,
            'tokenSource' => $configuration->tokenSource,
            'lastWorker' => $this->formatTimestamp(ScalarValue::int($raw['last_worker_at'] ?? null)),
            'lastWorkerSummary' => ScalarValue::string($raw['last_worker_summary'] ?? null),
            'lastTest' => $this->formatTimestamp(ScalarValue::int($raw['last_test_at'] ?? null)),
            'lastTestEventId' => ScalarValue::string($raw['last_test_event_id'] ?? null),
            'lastError' => ScalarValue::string($raw['last_error'] ?? null),
        ]);
    }

    public function filterOverview(ServerRequestInterface $request): ResponseInterface
    {
        return new RedirectResponse($this->uri(self::MODULE, $this->dashboardScope($request)));
    }

    public function refreshDashboard(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $this->dashboardScope($request);
        $this->dashboard->dashboard($scope['page_id'], $scope['device'], true);
        return new RedirectResponse($this->uri(self::MODULE, $scope));
    }

    public function resources(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $result = $this->dashboard->resources(
            $this->stringInput($query['run_id'] ?? null),
            $this->stringInput($query['type'] ?? null),
            $this->stringInput($query['device'] ?? null, 'desktop'),
        );
        return new JsonResponse($result, 200, ['Cache-Control' => 'private, no-store']);
    }

    /** @return array{page_id: string, device: string} */
    private function dashboardScope(ServerRequestInterface $request): array
    {
        $form = $this->form($request);
        return [
            'page_id' => $this->stringInput($form['page_id'] ?? null),
            'device' => $this->stringInput($form['device'] ?? null, 'desktop'),
        ];
    }

    public function connection(ServerRequestInterface $request): ResponseInterface
    {
        $configuration = $this->configuration->resolve();
        $raw = $this->settings->getRaw();
        $sites = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $sites[] = [
                'identifier' => $site->getIdentifier(),
                'base' => (string) $site->getBase(),
                'selected' => $site->getIdentifier() === $configuration->siteIdentifier,
            ];
        }
        if (count($sites) === 1 && $configuration->siteIdentifier === '') {
            $sites[0]['selected'] = true;
        }
        $selectedSite = null;
        foreach ($sites as $site) {
            if ($site['selected']) {
                $selectedSite = $site;
                break;
            }
        }
        $environmentToken = getenv('VIEWMEND_API_TOKEN');

        return $this->render($request, 'Connection', 'connection', [
            'authenticationFailure' => $this->dashboard->authenticationFailure(),
            'configuration' => $configuration,
            'sites' => $sites,
            'singleSite' => count($sites) === 1,
            'selectedSite' => $selectedSite,
            'environmentChoices' => $this->environmentChoices($configuration->environment),
            'hasStoredToken' => is_string($raw['token_ciphertext'] ?? null) && $raw['token_ciphertext'] !== '',
            'environmentToken' => is_string($environmentToken) && trim($environmentToken) !== '',
            'saveUri' => $this->uri(self::MODULE . '.connection-save'),
        ]);
    }

    public function saveConnection(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $form = $this->form($request);
            $integrationId = trim($this->stringInput($form['integration_id'] ?? null));
            $siteIdentifier = trim($this->stringInput($form['site_identifier'] ?? null));
            $environment = trim($this->stringInput($form['environment'] ?? null, 'production'));
            $token = trim($this->stringInput($form['api_token'] ?? null));
            $this->validateConnection($integrationId, $siteIdentifier, $environment, $token);
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
            $values = [
                'integration_id' => $integrationId,
                'site_identifier' => $siteIdentifier,
                'site_url' => rtrim((string) $site->getBase(), '/') . '/',
                'environment' => $environment,
                'last_error' => '',
            ];
            if ($token !== '') {
                $values['token_ciphertext'] = $this->vault->encrypt($token);
            }
            $this->settings->save($values);

            $action = $this->stringInput($form['submit_action'] ?? null, 'save');
            if ($action === 'save_test') {
                $this->queueConnectionTest();
                return $this->redirect('connection', 'Connection saved and a real test event was queued locally.');
            }

            return $this->redirect('connection', 'Connection settings saved.');
        } catch (Throwable $exception) {
            return $this->redirect('connection', $this->redactor->redact($exception->getMessage()), 'error');
        }
    }

    public function rules(ServerRequestInterface $request): ResponseInterface
    {
        $configuration = $this->configuration->resolve();
        return $this->render($request, 'Rules', 'rules', [
            'configuration' => $configuration,
            'saveUri' => $this->uri(self::MODULE . '.rules-save'),
        ]);
    }

    public function saveRules(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $form = $this->form($request);
            $retention = filter_var($form['retention_days'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 365],
            ]);
            if ($retention === false) {
                throw new InvalidArgumentException('Retention must contain a value from 1 to 365 days.');
            }
            $this->settings->save([
                'enabled' => isset($form['enabled']) ? 1 : 0,
                'record_pages' => isset($form['record_pages']) ? 1 : 0,
                'record_content' => isset($form['record_content']) ? 1 : 0,
                'record_publish' => isset($form['record_publish']) ? 1 : 0,
                'record_update' => isset($form['record_update']) ? 1 : 0,
                'record_unpublish' => isset($form['record_unpublish']) ? 1 : 0,
                'record_delete' => isset($form['record_delete']) ? 1 : 0,
                'record_cache_flush' => isset($form['record_cache_flush']) ? 1 : 0,
                'retention_days' => $retention,
            ]);

            return $this->redirect('rules', 'Event rules saved.');
        } catch (Throwable $exception) {
            return $this->redirect('rules', $this->redactor->redact($exception->getMessage()), 'error');
        }
    }

    public function activity(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $status = null;
        if (is_string($query['status'] ?? null) && $query['status'] !== '') {
            $status = DeliveryStatus::tryFrom($query['status']);
        }
        $configuration = $this->configuration->resolve();
        $search = mb_substr(trim($this->stringInput($query['search'] ?? null)), 0, 200);
        $site = $configuration->siteIdentifier;
        $total = $site !== '' ? $this->deliveries->countRecent($status, $site, $search) : 0;
        $pageCount = max(1, (int) ceil($total / 25));
        $page = min($pageCount, $this->positiveInt($query['page'] ?? null) ?? 1);
        $rows = $site !== '' ? $this->deliveries->findRecent($status, $site, 25, ($page - 1) * 25, $search) : [];
        foreach ($rows as &$row) {
            $deliveryStatus = DeliveryStatus::tryFrom(ScalarValue::string($row['status'] ?? null));
            $row['created_label'] = $this->formatTimestamp(ScalarValue::int($row['created_at'] ?? null));
            $row['updated_label'] = $this->formatTimestamp(ScalarValue::int($row['updated_at'] ?? null));
            $row['can_retry'] = $deliveryStatus?->canRetryManually() ?? false;
            $row['can_cancel'] = $deliveryStatus?->canCancel() ?? false;
            $row['can_run'] = in_array($deliveryStatus, [DeliveryStatus::Queued, DeliveryStatus::RetryScheduled], true);
            $row['detail_uri'] = $this->uri(self::MODULE . '.activity', [
                'delivery' => ScalarValue::int($row['uid'] ?? null),
                'search' => $search,
                'page' => $page,
                'status' => $status->value ?? '',
            ]);
        }
        unset($row);

        $detail = null;
        $attempts = [];
        $detailUid = $this->positiveInt($query['delivery'] ?? null);
        if ($detailUid !== null) {
            $detail = $this->deliveries->findRow($detailUid);
            if (
                $detail !== null
                && ScalarValue::string($detail['site_identifier'] ?? null) === $configuration->siteIdentifier
            ) {
                $payload = json_decode(ScalarValue::string($detail['payload'] ?? null), true);
                $urls = is_array($payload) && is_array($payload['page_urls'] ?? null) ? $payload['page_urls'] : [];
                $detail['page_urls'] = array_values(array_filter($urls, 'is_string'));
                $summary = ScalarValue::string($detail['response_summary'] ?? null);
                $metadata = json_decode($summary, true);
                if (!is_array($metadata)) {
                    $metadata = [];
                    if (preg_match('/^affected=(\d+) ignored=(\d+) checks=(\d+)$/D', $summary, $matches)) {
                        $metadata = [
                            'affected_pages' => (int) $matches[1],
                            'ignored_urls' => (int) $matches[2],
                            'checks_queued' => (int) $matches[3],
                        ];
                    }
                }
                $detail['metadata'] = [];
                $metadataLabels = [
                    'affected_pages' => 'Matched pages',
                    'ignored_urls' => 'Ignored URLs',
                    'checks_queued' => 'Checks queued',
                    'scheduled_for' => 'Scheduled for',
                ];
                foreach ($metadataLabels as $key => $label) {
                    $value = $metadata[$key] ?? null;
                    $detail['metadata'][] = [
                        'label' => $label,
                        'value' => is_int($value) || (is_string($value) && $value !== '')
                            ? (string) $value
                            : 'Unknown',
                    ];
                }
                $attempts = $this->deliveries->attempts($detailUid);
                foreach ($attempts as &$attempt) {
                    $attempt['started_label'] = $this->formatTimestamp(
                        ScalarValue::int($attempt['started_at'] ?? null),
                    );
                    $attempt['finished_label'] = $this->formatTimestamp(
                        ScalarValue::int($attempt['finished_at'] ?? null),
                    );
                }
                unset($attempt);
            } else {
                $detail = null;
            }
        }

        $filters = ['status' => $status->value ?? '', 'search' => $search];
        return $this->render($request, 'Activity', 'activity', [
            'rows' => $rows,
            'search' => $search,
            'total' => $total,
            'page' => $page,
            'pageCount' => $pageCount,
            'previousUri' => $page > 1
                ? $this->uri(self::MODULE . '.activity', $filters + ['page' => $page - 1])
                : '',
            'nextUri' => $page < $pageCount
                ? $this->uri(self::MODULE . '.activity', $filters + ['page' => $page + 1])
                : '',
            'statuses' => array_map(static fn (DeliveryStatus $item): array => [
                'value' => $item->value,
                'selected' => $status === $item,
            ], DeliveryStatus::cases()),
            'selectedStatus' => $status->value ?? '',
            'filterUri' => $this->uri(self::MODULE . '.activity-filter'),
            'actionUri' => $this->uri(self::MODULE . '.activity-action', $filters + ['page' => $page]),
            'detail' => $detail,
            'attempts' => $attempts,
        ]);
    }

    public function filterActivity(ServerRequestInterface $request): ResponseInterface
    {
        $form = $this->form($request);
        $status = DeliveryStatus::tryFrom($this->stringInput($form['status'] ?? null));

        return new RedirectResponse($this->uri(self::MODULE . '.activity', [
            'search' => mb_substr(trim($this->stringInput($form['search'] ?? null)), 0, 200),
            'status' => $status->value ?? '',
        ]));
    }

    public function activityAction(ServerRequestInterface $request): ResponseInterface
    {
        $form = $this->form($request);
        $query = $request->getQueryParams();
        $status = DeliveryStatus::tryFrom($this->stringInput($query['status'] ?? null));
        $filters = [
            'status' => $status->value ?? '',
            'search' => mb_substr(trim($this->stringInput($query['search'] ?? null)), 0, 200),
            'page' => $this->positiveInt($query['page'] ?? null) ?? 1,
        ];
        $uid = $this->positiveInt($form['delivery'] ?? null);
        $action = $this->stringInput($form['delivery_action'] ?? null);
        if ($uid === null) {
            return $this->redirect('activity', 'A valid delivery ID is required.', 'error', $filters);
        }
        try {
            $row = $this->deliveries->findRow($uid);
            $siteIdentifier = $this->configuration->resolve()->siteIdentifier;
            if (
                $row === null
                || $siteIdentifier === ''
                || ScalarValue::string($row['site_identifier'] ?? null) !== $siteIdentifier
            ) {
                throw new RuntimeException('The delivery does not belong to the configured TYPO3 site.');
            }
            $changed = match ($action) {
                'retry' => $this->deliveries->retry($uid, $this->clock->now()),
                'cancel' => $this->deliveries->cancel($uid, $this->clock->now()),
                'run' => $this->worker->processOne($uid) !== null,
                default => false,
            };
            if (!$changed) {
                throw new RuntimeException('The requested action is not valid for the current delivery state.');
            }
            return $this->redirect('activity', 'Delivery action completed.', 'success', $filters);
        } catch (Throwable $exception) {
            return $this->redirect('activity', $this->redactor->redact($exception->getMessage()), 'error', $filters);
        }
    }

    /** @param array<string, mixed> $variables */
    private function render(
        ServerRequestInterface $request,
        string $template,
        string $active,
        array $variables,
    ): ResponseInterface {
        $query = $request->getQueryParams();
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle('ViewMend Site Tracker');
        $docHeader = $moduleTemplate->getDocHeaderComponent();
        if ((new Typo3Version())->getMajorVersion() >= 14) {
            $docHeader->disableAutomaticReloadButton();
        }
        $this->pageRenderer->addCssFile('EXT:viewmend_site_tracker/Resources/Public/Css/dashboard.css');
        foreach (['dashboard', 'forms'] as $script) {
            $this->pageRenderer->getJavaScriptRenderer()->addJavaScriptModuleInstruction(
                JavaScriptModuleInstruction::create('@viewmend/site-tracker/' . $script . '.js'),
            );
        }
        $moduleTemplate->assignMultiple(array_merge($variables, [
            'active' => $active,
            'overviewUri' => $this->uri(self::MODULE),
            'connectionUri' => $this->uri(self::MODULE . '.connection'),
            'rulesUri' => $this->uri(self::MODULE . '.rules'),
            'activityUri' => $this->uri(self::MODULE . '.activity'),
            'notice' => is_string($query['notice'] ?? null) ? $query['notice'] : '',
            'noticeSeverity' => ($query['severity'] ?? '') === 'error' ? 'danger' : 'success',
            'navOverview' => $active === 'overview',
            'navConnection' => $active === 'connection',
            'navRules' => $active === 'rules',
            'navActivity' => $active === 'activity',
        ]));

        return $moduleTemplate->renderResponse('Module/' . $template);
    }

    /** @return array<string, mixed> */
    private function form(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        $form = is_array($body) ? ($body['viewmend'] ?? []) : [];
        if (!is_array($form)) {
            return [];
        }
        $result = [];
        foreach ($form as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function queueConnectionTest(): void
    {
        $configuration = $this->configuration->resolve();
        if (!$configuration->isConnectionReady()) {
            throw new RuntimeException('Save an integration ID, TYPO3 site and API token before queuing a test event.');
        }
        $eventId = $this->ids->random('connection-test');
        $delivery = $this->events->recordConnectionTest(new OutgoingEvent(
            eventId: $eventId,
            type: EventType::Custom,
            title: 'TYPO3 connection test',
            occurredAt: $this->clock->now(),
            siteUrl: $configuration->siteUrl,
            pageUrls: [],
            environment: $configuration->environment,
            changedFields: ['metadata'],
            metadata: [
                'cms' => 'typo3',
                'operation' => 'connection_test',
                'site_identifier' => $configuration->siteIdentifier,
            ],
        ));
        if ($delivery === null) {
            throw new RuntimeException('The test event could not be queued.');
        }
        $this->settings->updateOperationalState([
            'last_test_at' => $this->clock->now()->getTimestamp(),
            'last_test_event_id' => $eventId,
        ]);
    }

    private function validateConnection(
        string $integrationId,
        string $siteIdentifier,
        string $environment,
        #[\SensitiveParameter] string $token,
    ): void {
        if ($integrationId === '' || mb_strlen($integrationId) > 255) {
            throw new InvalidArgumentException('Integration ID must contain 1 to 255 characters.');
        }
        if ($siteIdentifier === '' || mb_strlen($siteIdentifier) > 255) {
            throw new InvalidArgumentException('Choose a configured TYPO3 site.');
        }
        if (
            $environment === ''
            || mb_strlen($environment) > 60
            || preg_match('/^[A-Za-z0-9._-]+$/D', $environment) !== 1
        ) {
            throw new InvalidArgumentException(
                'Environment must contain only letters, numbers, dots, underscores or hyphens.',
            );
        }
        if (strlen($token) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $token) === 1) {
            throw new InvalidArgumentException('The API token is invalid.');
        }
    }

    /** @return list<array{value: string, label: string, selected: bool}> */
    private function environmentChoices(string $selected): array
    {
        $choices = [
            'production' => 'Production',
            'staging' => 'Staging',
            'development' => 'Development',
        ];
        if ($selected !== '' && !isset($choices[$selected])) {
            $choices[$selected] = $selected;
        }

        $result = [];
        foreach ($choices as $value => $label) {
            $result[] = [
                'value' => $value,
                'label' => $label,
                'selected' => $value === $selected,
            ];
        }

        return $result;
    }

    /** @return list<string> */
    private function readinessIssues(TrackerConfiguration $configuration): array
    {
        $issues = [];
        if (!$configuration->enabled) {
            $issues[] = 'Event capture is disabled.';
        }
        if ($configuration->integrationId === '') {
            $issues[] = 'Integration ID is missing.';
        }
        if ($configuration->siteIdentifier === '' || $configuration->siteUrl === '') {
            $issues[] = 'A public TYPO3 site is not bound.';
        }
        if ($configuration->apiToken === null || $configuration->apiToken === '') {
            $issues[] = 'API token is missing.';
        }

        return $issues;
    }

    /** @param array<string, int|string> $parameters */
    private function uri(string $route, array $parameters = []): string
    {
        return (string) $this->uriBuilder->buildUriFromRoute($route, $parameters);
    }

    /** @param array<string, int|string> $parameters */
    private function redirect(
        string $section,
        string $notice,
        string $severity = 'success',
        array $parameters = [],
    ): ResponseInterface {
        $route = $section === 'overview' ? self::MODULE : self::MODULE . '.' . $section;

        return new RedirectResponse($this->uri($route, [
            'notice' => $notice,
            'severity' => $severity,
        ] + $parameters));
    }

    private function formatTimestamp(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return 'Never';
        }

        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s T');
    }

    private function stringInput(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    private function positiveInt(mixed $value): ?int
    {
        $filtered = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($filtered) ? $filtered : null;
    }
}
