<?php

declare(strict_types=1);

namespace Payitgo;

final class Tokens
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * The tokens a customer can pay with in the key's mode. With an amount,
     * only the ones a payment of that amount offers (its token_options), each
     * with amount_due_usd when fees are passed on.
     *
     * @return list<array{token: string, chain: string, min_usd: string, network_fee_usd: string, amount_due_usd?: string, fee_note: string}>
     */
    public function list(string|int|float|null $amountUsd = null): array
    {
        $query = $amountUsd !== null ? '?' . http_build_query(['amount_usd' => (string) $amountUsd]) : '';
        return $this->client->request('GET', '/v1/tokens' . $query)['tokens'] ?? [];
    }
}
