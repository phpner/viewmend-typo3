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
use InvalidArgumentException;
use JsonException;
use ViewMend\Typo3SiteTracker\Support\ScalarValue;

final readonly class OutgoingEvent
{
    /**
     * @param list<string> $pageUrls
     * @param list<string> $changedFields
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        public string $eventId,
        public EventType $type,
        public string $title,
        public DateTimeImmutable $occurredAt,
        public string $siteUrl,
        public array $pageUrls,
        public string $environment,
        public ?string $description = null,
        public ?string $referenceUrl = null,
        public array $changedFields = [],
        public array $metadata = [],
    ) {
        if (trim($eventId) === '' || mb_strlen($eventId) > 160) {
            throw new InvalidArgumentException('The event ID must contain 1 to 160 characters.');
        }
        if (trim($title) === '' || mb_strlen($title) > 140) {
            throw new InvalidArgumentException('The event title must contain 1 to 140 characters.');
        }
        if (!self::isHttpUrl($siteUrl)) {
            throw new InvalidArgumentException('The site URL must be an absolute HTTP(S) URL.');
        }
        if (count($pageUrls) > 20 || count($changedFields) > 50) {
            throw new InvalidArgumentException('The event exceeds the ViewMend collection limits.');
        }
        foreach ($pageUrls as $url) {
            if (!self::isHttpUrl($url)) {
                throw new InvalidArgumentException('Every page URL must be an absolute HTTP(S) URL.');
            }
        }
        if ($referenceUrl !== null && !self::isHttpUrl($referenceUrl)) {
            throw new InvalidArgumentException('The reference URL must be an absolute HTTP(S) URL.');
        }
        if (mb_strlen($environment) > 60 || ($description !== null && mb_strlen($description) > 2000)) {
            throw new InvalidArgumentException('The event exceeds the ViewMend text limits.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_type' => $this->type->value,
            'title' => $this->title,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
            'site_url' => $this->siteUrl,
            'page_urls' => $this->pageUrls,
            'environment' => $this->environment,
            'description' => $this->description,
            'reference_url' => $this->referenceUrl,
            'changed_fields' => $this->changedFields,
            'metadata' => $this->metadata,
        ];
    }

    /** @throws JsonException */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $occurredAt = DateTimeImmutable::createFromFormat(
            DATE_ATOM,
            ScalarValue::string($data['occurred_at'] ?? null),
        );
        if ($occurredAt === false) {
            throw new InvalidArgumentException('The stored event timestamp is invalid.');
        }

        return new self(
            eventId: ScalarValue::string($data['event_id'] ?? null),
            type: EventType::from(ScalarValue::string($data['event_type'] ?? null)),
            title: ScalarValue::string($data['title'] ?? null),
            occurredAt: $occurredAt,
            siteUrl: ScalarValue::string($data['site_url'] ?? null),
            pageUrls: self::stringList($data['page_urls'] ?? []),
            environment: ScalarValue::string($data['environment'] ?? null),
            description: self::nullableString($data['description'] ?? null),
            referenceUrl: self::nullableString($data['reference_url'] ?? null),
            changedFields: self::stringList($data['changed_fields'] ?? []),
            metadata: self::scalarMap($data['metadata'] ?? []),
        );
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /** @return array<string, scalar|null> */
    private static function scalarMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && (is_scalar($item) || $item === null)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && is_string($parts['scheme'] ?? null)
            && in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }
}
