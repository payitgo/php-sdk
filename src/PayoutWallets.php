<?php

declare(strict_types=1);

namespace Payitgo;

/**
 * Your payout wallet of the key's mode: one address per network (tron, bsc,
 * ethereum, …) that you top up, and what it holds of each payout coin.
 */
final class PayoutWallets
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * The wallet's addresses, one per network, each with its assets'
     * balance, reserved, spendable and needed (the top-up to send now).
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return $this->client->request('GET', '/v1/payout-wallets')['wallets'] ?? [];
    }

    /**
     * Test keys only: adds $amount of $asset (such as 'USDT_TRC20') to the
     * test wallet's paper balance, and funds any test batch waiting for it.
     * Returns the wallets. A live key is a PayitgoException with status 400.
     *
     * @return list<array<string, mixed>>
     */
    public function simulateTopUp(string $asset, string|int|float $amount): array
    {
        return $this->client->request('POST', '/v1/payout-wallets/' . rawurlencode($asset) . '/simulate-top-up', ['amount' => (string) $amount])['wallets'] ?? [];
    }
}
