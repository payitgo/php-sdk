<?php

declare(strict_types=1);

namespace Payitgo;

/**
 * Payout batches: a whole list of payments at once (payroll, prizes), from
 * your payout wallet. payitgo checks and prices every row; you submit the
 * batch, and it is sent once approved (when your rules ask) and funded.
 * Writes need a key with payouts on.
 */
final class PayoutBatches
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Makes a batch of 1 to 1000 payments, checked and priced: 'quoted'
     * (or 'validating' past 100 payments, payout_batch.quoted following).
     * Each item: asset, address, amount (a decimal string; a number is sent
     * as a string), and optionally reference, tag, name, email, note,
     * webhook_url; or a claim link (claim => true or claim_assets, and no
     * address), as payouts->request() takes them. Optional: reference (your
     * id for the batch: send one), name, fee_paid_by ('merchant' or
     * 'recipient'), webhook_url (the batch's events, and its payments' that
     * have none of their own, go there instead of your webhook's URL), convert
     * ('convert': every payment in another coin than convert_from is paid
     * from convert_from, converted on its way; 'same_network': each from
     * the wallet's own coin) and convert_from (default: your payout
     * settings).
     * Safe to retry: a reference already used returns that batch.
     *
     * @param array{reference?: string, name?: string, fee_paid_by?: string, webhook_url?: string, convert?: string, convert_from?: string, items: list<array<string, mixed>>} $params
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        return $this->client->request('POST', '/v1/payout-batches', Payouts::items($params))['batch'];
    }

    /**
     * Batches of the key's mode, newest first: ['batches' => [...], 'total' => …].
     *
     * @param array{status?: string, limit?: int, offset?: int} $params
     * @return array<string, mixed>
     */
    public function list(array $params = []): array
    {
        return $this->client->request('GET', '/v1/payout-batches' . Payouts::query($params));
    }

    /**
     * A batch by its id.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id): array
    {
        return $this->client->request('GET', '/v1/payout-batches/' . rawurlencode($id))['batch'];
    }

    /**
     * A batch's payments, in row order: ['items' => [...], 'total' => …].
     * status takes one status or several (a list, or comma-separated).
     *
     * @param array{status?: string|list<string>, limit?: int, offset?: int} $params
     * @return array<string, mixed>
     */
    public function items(string $id, array $params = []): array
    {
        return $this->client->request('GET', '/v1/payout-batches/' . rawurlencode($id) . '/items' . Payouts::query($params));
    }

    /**
     * Adds 1 to 1000 payments to a batch not submitted yet; the whole batch
     * is checked and priced again. Returns the batch.
     *
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function addItems(string $id, array $items): array
    {
        return $this->client->request('POST', '/v1/payout-batches/' . rawurlencode($id) . '/items', Payouts::items(['items' => $items]))['batch'];
    }

    /**
     * Checks and prices a batch not submitted yet again, at today's prices
     * (they hold for an hour). Returns the batch.
     *
     * @return array<string, mixed>
     */
    public function quote(string $id): array
    {
        return $this->client->request('POST', '/v1/payout-batches/' . rawurlencode($id) . '/quote')['batch'];
    }

    /**
     * Submits a quoted batch: it waits for its approvals and/or funds
     * ('waiting' says what to send where), or starts. Returns the batch.
     *
     * @return array<string, mixed>
     */
    public function submit(string $id): array
    {
        return $this->client->request('POST', '/v1/payout-batches/' . rawurlencode($id) . '/submit')['batch'];
    }

    /**
     * Cancels a batch before it is funded, or its payments not sent yet once
     * it is processing. Returns the batch.
     *
     * @return array<string, mixed>
     */
    public function cancel(string $id): array
    {
        return $this->client->request('POST', '/v1/payout-batches/' . rawurlencode($id) . '/cancel')['batch'];
    }
}
