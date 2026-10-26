<?php

declare(strict_types=1);

namespace Payitgo;

final class Tokens
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * The tokens a customer can pay with in the key's mode, their figures
     * (minimum, network_fee, …) in $currency: an ISO 4217 code, US dollars
     * when left out. With an amount, only the ones a payment of that amount
     * offers (its token_options), each with amount_due when fees are passed
     * on.
     *
     * @return list<array{token: string, chain: string, minimum: string, network_fee: string, amount_due?: string, amount_extra?: string, fee_note: string}>
     */
    public function list(string|int|float|null $amount = null, ?string $currency = null): array
    {
        $params = array_filter(['amount' => $amount === null ? null : (string) $amount, 'currency' => $currency], fn ($v) => $v !== null);
        $query = $params === [] ? '' : '?' . http_build_query($params);
        return $this->client->request('GET', '/v1/tokens' . $query)['tokens'] ?? [];
    }
}
