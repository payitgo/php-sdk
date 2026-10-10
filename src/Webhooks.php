<?php

declare(strict_types=1);

namespace Payitgo;

use Payitgo\Exception\SignatureVerificationException;

/**
 * The webhook (IPN) of the key's mode: a test key manages the test webhook, a
 * live key the live one. A storefront's key may read and test it, not change it.
 */
final class Webhooks
{
    /** The events payitgo sends. A test ($client->webhooks->test()) sends "ping". */
    public const EVENTS = [
        'payment.partially_paid', 'payment.detecting', 'payment.confirmed', 'payment.underpaid',
        'payment.expired', 'payment.failed', 'payment.wrong_asset', 'payment.late_deposit',
        'payout.approved', 'payout.rejected', 'payout.sent', 'settlement.completed', 'settlement.failed',
        'ping',
    ];

    /** @param ?string $secret the client's webhook_secret */
    public function __construct(private readonly Client $client, private readonly ?string $secret = null)
    {
    }

    /**
     * The webhook: its url (null when not set) and its signing secret.
     *
     * @return array{livemode: bool, url: ?string, secret: string, created_at: string, updated_at: string}
     */
    public function get(): array
    {
        return $this->client->request('GET', '/v1/webhook')['webhook'];
    }

    /**
     * Sets the URL every event of the mode is POSTed to: https, public host,
     * accepting POST requests. Safe to retry.
     *
     * @return array{livemode: bool, url: ?string, secret: string, created_at: string, updated_at: string}
     */
    public function set(string $url): array
    {
        return $this->client->request('PUT', '/v1/webhook', ['url' => $url])['webhook'];
    }

    /**
     * Stops POSTing to the URL (payments with their own webhook_url still get
     * theirs); the secret stays.
     *
     * @return array{livemode: bool, url: ?string, secret: string, created_at: string, updated_at: string}
     */
    public function remove(): array
    {
        return $this->client->request('DELETE', '/v1/webhook')['webhook'];
    }

    /**
     * Queues a signed "ping" event to the URL and returns its delivery.
     *
     * @return array<string, mixed>
     */
    public function test(): array
    {
        return $this->client->request('POST', '/v1/webhook/test')['delivery'];
    }

    /**
     * Verifies a webhook request and returns its event; see Webhook::constructEvent.
     * $secret defaults to the client's webhook_secret.
     *
     * @return array<string, mixed>
     */
    public function constructEvent(string $payload, ?string $signatureHeader, ?string $secret = null, int $tolerance = Webhook::DEFAULT_TOLERANCE): array
    {
        $secret = $secret ?: $this->secret;
        if ($secret === null || $secret === '') {
            throw new SignatureVerificationException("no webhook secret: pass one, or set the client's webhook_secret");
        }
        return Webhook::constructEvent($payload, $signatureHeader, $secret, $tolerance);
    }
}
