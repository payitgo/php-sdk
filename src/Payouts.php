<?php

declare(strict_types=1);

namespace Payitgo;

/**
 * Payout requests: your clients' payouts, sent to payitgo one by one as they
 * come in (a withdrawal from your platform, a prize won). payitgo checks and
 * prices each at once and keeps it in your open list with its costs; you
 * send the list when you want (or let it go by itself with auto-send). Each
 * payment, request or batch row, is read here too. Writes need a key with
 * payouts on.
 */
final class Payouts
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Adds one of your clients' payout requests to your open list, checked
     * and priced. Required: asset (USDT_TRC20, TRX, USDT_BEP20, USDC_BEP20,
     * BNB), address, amount (a decimal string; a number is sent as a string).
     * Optional: reference (your id for it, unique in its mode: send one),
     * tag, name, email, note. A claim link instead (claim => true, or
     * claim_assets: the other coins the recipient may choose) has no
     * address: its recipient chooses the coin and the address. With an
     * email the link is emailed; without, you share ['claim']['url'], and
     * give ['claim']['pin'] another way (the recipient enters it). An
     * email alone makes no claim link: a missing address is invalid.
     * Returns the payment: 'ready' with its fees, or 'invalid' with
     * 'errors' saying why (kept, never sent).
     * Safe to retry: a reference already used returns that payment.
     *
     * @param array{reference?: string, asset: string, address?: string, tag?: int, amount: string|int|float, name?: string, email?: string, note?: string, claim?: bool, claim_assets?: list<string>} $params
     * @return array<string, mixed>
     */
    public function request(array $params): array
    {
        return $this->client->request('POST', '/v1/payouts', self::amount($params))['item'];
    }

    /**
     * A payment by its id: a payout request or a batch's row.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->client->request('GET', '/v1/payouts/' . rawurlencode($id))['item'];
    }

    /**
     * A payment by your reference.
     *
     * @return array<string, mixed>
     */
    public function retrieveByReference(string $reference): array
    {
        return $this->client->request('GET', '/v1/payouts?' . http_build_query(['reference' => $reference]))['item'];
    }

    /**
     * Your open payout requests: ['inbox' => batch, 'coverage' => [...],
     * 'items' => [...], 'total' => …, 'limit' => …, 'offset' => …].
     * coverage says, coin by coin, what sending the valid ones needs against
     * what the payout wallet can spend, and the top_up to send first.
     *
     * @param array{status?: string|list<string>, limit?: int, offset?: int} $params
     * @return array<string, mixed>
     */
    public function inbox(array $params = []): array
    {
        return $this->client->request('GET', '/v1/payouts/inbox' . self::query($params));
    }

    /**
     * Sends your valid open payout requests (all of them, or those of $ids)
     * as a batch of their own: priced again and submitted. Returns the batch;
     * it then waits for its approvals and/or funds, or starts.
     *
     * @param ?list<string> $ids null: every valid request
     * @return array<string, mixed>
     */
    public function send(?array $ids = null): array
    {
        if ($ids === []) {
            // The API reads no ids as every request: never send them all by mistake.
            throw new \InvalidArgumentException('payitgo: no ids given: pass null to send every valid request');
        }
        return $this->client->request('POST', '/v1/payouts/send', $ids === null ? null : ['ids' => array_values($ids)])['batch'];
    }

    /**
     * Takes a payout request out: one still in your open list is removed
     * (its reference free again); one sent but not on its way yet is
     * canceled. Returns it, 'status' => 'canceled'.
     *
     * @return array<string, mixed>
     */
    public function cancel(string $id): array
    {
        return $this->client->request('DELETE', '/v1/payouts/' . rawurlencode($id))['item'];
    }

    /**
     * What up to 100 payments would cost, checked and priced as in a batch;
     * nothing is kept. Optional: fee_paid_by, convert ('same_network' or
     * 'convert') and convert_from, as payoutBatches->create() takes them.
     * Returns ['quote' => [livemode, fee_paid_by, totals, total_usd,
     * counts], 'items' => [...]].
     *
     * @param array{fee_paid_by?: string, convert?: string, convert_from?: string, items: list<array<string, mixed>>} $params
     * @return array<string, mixed>
     */
    public function quote(array $params): array
    {
        return $this->client->request('POST', '/v1/payout-quotes', self::items($params));
    }

    /**
     * @internal $params with its amount as a string.
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function amount(array $params): array
    {
        if (isset($params['amount'])) {
            $params['amount'] = (string) $params['amount'];
        }
        return $params;
    }

    /**
     * @internal $params with the amounts of its items as strings.
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function items(array $params): array
    {
        if (isset($params['items']) && is_array($params['items'])) {
            $params['items'] = array_map(fn ($item) => is_array($item) ? self::amount($item) : $item, array_values($params['items']));
        }
        return $params;
    }

    /**
     * @internal A query string ('' when empty) of the keys that have a
     * value; a list of statuses is sent comma-separated.
     * @param array<string, mixed> $params
     */
    public static function query(array $params): string
    {
        if (isset($params['status']) && is_array($params['status'])) {
            $params['status'] = implode(',', $params['status']);
        }
        $query = http_build_query(array_filter($params, fn ($v) => $v !== null && $v !== ''));
        return $query !== '' ? '?' . $query : '';
    }
}
