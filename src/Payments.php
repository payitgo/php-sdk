<?php

declare(strict_types=1);

namespace Payitgo;

final class Payments
{
    /** The outcomes a test payment can be made to go through: see simulate(). */
    public const SIMULATION_OUTCOMES = [
        'confirmed', 'overpaid', 'partially_paid', 'underpaid', 'expired', 'failed', 'wrong_asset', 'paid_late', 'paid_twice',
    ];

    /** @param ?string $webhookUrl the client's default webhook_url */
    public function __construct(private readonly Client $client, private readonly ?string $webhookUrl = null)
    {
    }

    /**
     * Creates a payment. Required: order_id, and the price: amount_usd, or
     * price_amount in price_currency (an ISO 4217 code such as EUR, GBP or
     * NGN), which payitgo converts into dollars at the rate of the moment.
     * Optional: token (leave it out to let the customer choose on the checkout
     * page), tokens (the coins to offer), description, metadata,
     * customer_email, success_url, cancel_url, webhook_url (https: gets the
     * payment's events instead of the webhook's URL, signed with the same
     * secret; default: the client's webhook_url), simulate (test keys only:
     * the outcome to simulate, as simulate() does, once the payment has its
     * coin).
     * Safe to retry: an order with an open payment returns that payment.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        foreach (['amount_usd', 'price_amount'] as $amount) {
            if (isset($params[$amount])) {
                $params[$amount] = (string) $params[$amount];
            }
        }
        if (!isset($params['webhook_url']) && $this->webhookUrl !== null) {
            $params['webhook_url'] = $this->webhookUrl;
        }
        return $this->client->request('POST', '/v1/payments', $params);
    }

    /**
     * A payment by its id or by your order id (the order's open payment).
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->client->request('GET', '/v1/payments/' . rawurlencode($id));
    }

    /**
     * Sets the coin of a payment created without one, when the customer chose
     * it on your own page: one of its token_options. $id is the payment's id
     * or your order id. Returns the whole payment, now with its
     * address, amount, locked rate and a deadline 15 minutes away. Another coin
     * changes it while the payment still has token_options; once it can no
     * longer change, a PayitgoException with status 409.
     *
     * @return array<string, mixed>
     */
    public function selectToken(string $id, string $token): array
    {
        return $this->client->request('POST', '/v1/payments/' . rawurlencode($id) . '/token', ['token' => $token]);
    }

    /**
     * Plays an outcome (one of SIMULATION_OUTCOMES) on a test payment: payitgo
     * sends its deposits, status changes and webhooks a few seconds apart, with
     * no coins. $id is the payment's id or your order id. Returns the
     * whole payment, with its simulation. A live payment is a PayitgoException
     * with status 400; a simulation that is still running, 409.
     *
     * @return array<string, mixed>
     */
    public function simulate(string $id, string $outcome): array
    {
        return $this->client->request('POST', '/v1/payments/' . rawurlencode($id) . '/simulate', ['outcome' => $outcome]);
    }

    /**
     * Payments of the key's mode, newest first: ['payments' => [...], 'total' => …].
     *
     * @param array{status?: string, chain?: string, token?: string, limit?: int, offset?: int} $params
     * @return array<string, mixed>
     */
    public function list(array $params = []): array
    {
        $query = http_build_query(array_filter($params, fn ($v) => $v !== null && $v !== ''));
        return $this->client->request('GET', '/v1/payments' . ($query !== '' ? '?' . $query : ''));
    }
}
