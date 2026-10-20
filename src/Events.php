<?php

declare(strict_types=1);

namespace Payitgo;

/**
 * The key's mode's events, each as its webhook carried it: replay the ones
 * your system missed (after an outage, say). Any key of the account reads
 * them, read-only ones too; a storefront's key cannot.
 */
final class Events
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * The mode's events, oldest first: ['events' => [...], 'has_more' => …].
     * Each is the event its webhook carried (id, type, livemode, created_at,
     * data), as constructEvent() returns it; a test ping is left out. type
     * takes event types, exact ('payout_item.sent') or a family
     * ('payout_item.*'): one, a list, or comma-separated; after an event's
     * id (the events after it); created_after a \DateTimeInterface (sent as
     * RFC 3339, in UTC) or an RFC 3339 string (ignored with after). While
     * has_more, call again with after => the last event's id.
     *
     * @param array{type?: string|list<string>, after?: string, created_after?: \DateTimeInterface|string, limit?: int} $params
     * @return array<string, mixed>
     */
    public function list(array $params = []): array
    {
        return $this->client->request('GET', '/v1/events' . Payouts::query($params));
    }
}
