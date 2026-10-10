<?php

declare(strict_types=1);

namespace Payitgo;

final class Checkout
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * The payer's view of a payment, by its checkout token. Public: sent without the API key.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $checkoutToken): array
    {
        return $this->client->request('GET', '/checkout/' . rawurlencode($checkoutToken), null, false);
    }

    /**
     * The customer's choice of coin for a payment created without one. Public.
     *
     * @return array<string, mixed>
     */
    public function selectToken(string $checkoutToken, string $token): array
    {
        return $this->client->request('POST', '/checkout/' . rawurlencode($checkoutToken) . '/token', ['token' => $token], false);
    }
}
