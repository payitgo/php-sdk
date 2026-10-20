<?php

declare(strict_types=1);

// A fake gateway for tests/run.php, served by PHP's built-in server on
// 127.0.0.1: it records each request and its reply in PAYITGO_GATEWAY_LOG,
// keeps the mode's webhook, the chosen coins, the payouts and their events in
// PAYITGO_GATEWAY_STATE and answers the routes the way the API does.

// The keys it knows besides the test key: a storefront's test key, and a live key.
const STORE_KEY = '7fd8a9991cc9aadccefc062f0467f180cb1ab0d388ff76cdaf0b1fbbd5c80296';
const LIVE_KEY = '9a6fd52f16ba8258c4b71ec3a7ffbe4e22ebc4ff99a1bc073fd414e0bbd4ee15';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = file_get_contents('php://input');
$headers = array_change_key_case(getallheaders(), CASE_LOWER);

$stateFile = (string) getenv('PAYITGO_GATEWAY_STATE');
$state = json_decode((string) @file_get_contents($stateFile), true) ?: [];
$webhook = $state['webhook'] ?? [
    'livemode' => false, 'url' => null, 'secret' => str_repeat('ab', 32),
    'created_at' => '2026-10-10T09:00:00Z', 'updated_at' => '2026-10-10T09:00:00Z',
];
// The payments whose coin has been chosen: choosing again is a 409.
$chosen = $state['chosen'] ?? [];
// The payments being simulated: simulating again is a 409.
$simulating = $state['simulating'] ?? [];
$body = json_decode($raw, true) ?? [];
// A storefront's key may read and test the webhook, not change it.
$storefront = ($headers['authorization'] ?? '') === 'Bearer ' . STORE_KEY;
// The live key reads a live webhook (livemode true); the state keeps the test one.
$live = ($headers['authorization'] ?? '') === 'Bearer ' . LIVE_KEY;
$forbidden = [403, ['error' => "a storefront's key cannot change the account's webhook: set webhook_url on its payments instead"]];
// pay_1 (order 'ORD 7/1') was created without a coin; once chosen, it has its
// address, amount, locked rate and 15 minutes.
$withCoin = fn (string $token): array => [
    'id' => 'pay_1', 'merchant_id' => 'mch_1', 'order_id' => 'ORD 7/1', 'status' => 'pending', 'livemode' => false,
    'token' => $token, 'chain' => 'xrp', 'deposit_address' => 'rPT1Sjq2YGrBMTttX4GZHjKu9dyfzbpAYe',
    'destination_tag' => 1042, 'crypto_amount' => '20', 'received_amount' => '0', 'usd_amount' => '10',
    'amount_due_usd' => '10', 'fees_paid_by_customer' => false, 'network_fee' => '0.000012', 'network_fee_usd' => '0.01',
    'settlement' => 'custodial', 'received_usd' => '0', 'rate_locked' => '0.5', 'deposits' => [],
    'expires_at' => '2026-10-10T12:15:00Z', 'created_at' => '2026-10-10T12:00:00Z',
    'network_fee_note' => 'Final in seconds. Include the destination tag: it is required.',
    'qr_payload' => 'rPT1Sjq2YGrBMTttX4GZHjKu9dyfzbpAYe',
    'checkout_token' => 'ct_abc', 'checkout_url' => 'https://pay.example/ct_abc', 'description' => 'Order 7',
    'metadata' => ['cart' => '42'],
];
// pay_2 (order 'ORD 8/1') is a test payment with its coin; its simulation has
// played a first deposit.
$simulated = fn (string $outcome): array => [
    'id' => 'pay_2', 'merchant_id' => 'mch_1', 'order_id' => 'ORD 8/1', 'status' => 'detecting', 'livemode' => false,
    'simulation' => ['outcome' => $outcome, 'state' => 'running'],
    'token' => 'USDT_TRC20', 'chain' => 'tron', 'deposit_address' => 'TPk2bpQqWRpXiaUHS9SXnZCDgvwrsuJ71o',
    'crypto_amount' => '20', 'received_amount' => '20', 'usd_amount' => '20', 'amount_due_usd' => '20',
    'fees_paid_by_customer' => false, 'network_fee' => '1', 'network_fee_usd' => '0.3', 'settlement' => 'custodial',
    'received_usd' => '20', 'rate_locked' => '1',
    'deposits' => [[
        'tx_id' => '8de0120d502929788a71a7b7cf60e5ef3c095cdbb05cd8a7ddabc0bc9ffa9582', 'token' => 'USDT_TRC20', 'chain' => 'tron', 'amount' => '20',
        'usd_value' => '20', 'counted' => true, 'status' => 'pending', 'seen_at' => '2026-10-10T12:00:05Z', 'simulated' => true,
    ]],
    'expires_at' => '2026-10-10T12:15:00Z', 'created_at' => '2026-10-10T12:00:00Z',
    'network_fee_note' => 'Final in about a minute.',
    'qr_payload' => 'TPk2bpQqWRpXiaUHS9SXnZCDgvwrsuJ71o',
];
// The dollar value of one unit of the currencies a price may be given in;
// null: a currency whose rate cannot be had right now.
$rates = ['EUR' => '1.082', 'XAF' => null];
// A created payment echoes the request; with simulate, it shows its
// simulation instead: running when it has its coin, else waiting for it. A
// price in another currency is converted into usd_amount, as the API does.
$created = function (array $body) use ($rates): array {
    $payment = array_merge($body, ['id' => 'pay_1', 'status' => 'pending']);
    if (isset($body['simulate'])) {
        unset($payment['simulate']);
        $payment['simulation'] = ['outcome' => $body['simulate'], 'state' => isset($body['token']) ? 'running' : 'requested'];
    }
    if (isset($body['price_currency'])) {
        $currency = strtoupper($body['price_currency']);
        $decimal = fn (string $v): string => str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v;
        $payment['usd_amount'] = $decimal(number_format((float) $body['price_amount'] * (float) $rates[$currency], 2, '.', ''));
        $payment['price_amount'] = $decimal($body['price_amount']);
        $payment['price_currency'] = $currency;
        $payment['price_rate'] = $rates[$currency];
    }
    return $payment;
};
$currency = strtoupper((string) ($body['price_currency'] ?? ''));

// Payouts: the payments (payout requests and batch rows) by id, the batches
// by id (the open list of requests is 'inbox'), and the test wallet's paper
// balances.
$payouts = $state['payouts'] ?? [];
$batches = $state['batches'] ?? [];
$balances = $state['balances'] ?? ['USDT_TRC20' => '0', 'USDT_BEP20' => '0'];
// The last id given: po_1, pb_2, …
$seq = $state['seq'] ?? 0;
$next = function (string $prefix) use (&$seq): string {
    return $prefix . ++$seq;
};
// The mode's events, oldest first, as GET /v1/events replays them; each
// change moves the clock (tick) a second on from 09:20:00.
$events = $state['events'] ?? [];
$tick = $state['tick'] ?? 0;
$at = function () use (&$tick): string {
    return gmdate('Y-m-d\TH:i:s\Z', strtotime('2026-10-20T09:20:00Z') + ++$tick);
};
$announce = function (string $type, array $data) use (&$events): void {
    $events[] = ['id' => 'evt_' . (count($events) + 1), 'type' => $type, 'livemode' => false, 'created_at' => $data['updated_at'], 'data' => $data];
};
// An RFC 3339 time, as the API reads updated_since and created_after.
$rfc3339 = fn (string $v): bool => preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d+)?(Z|[+-]\d\d:\d\d)$/', $v) === 1;
parse_str($_SERVER['QUERY_STRING'] ?? '', $query);
$noPayouts = [403, ['error' => 'this api key cannot make payouts: turn payouts on for it in the dashboard']];
$notFound = [404, ['error' => 'not found']];
// The payout coins: their chain, ticker and a made-up dollar price.
$coins = [
    'USDT_TRC20' => ['tron', 'USDT', 1.0], 'TRX' => ['tron', 'TRX', 0.3],
    'USDT_BEP20' => ['bsc', 'USDT', 1.0], 'USDC_BEP20' => ['bsc', 'USDC', 1.0], 'BNB' => ['bsc', 'BNB', 600.0],
];
$networks = ['tron' => 'Tron (TRC-20)', 'bsc' => 'BNB Smart Chain (BEP-20)'];
$dec = fn (float $v): string => rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
// A payment as payitgo checks and prices it: ready with its fees, or
// invalid (kept, with its errors) for an address it cannot pay. A claim
// link (claim, or claim_assets: never an email alone) has no address; with $rule's
// convert, a payment in another coin than convert_from is paid from it,
// converted on its way (a $1 + 0.5% conversion fee of $3 here).
$priced = function (array $in, string $id, string $batchId, int $row, array $rule = []) use ($coins, $networks, $dec): array {
    $asset = strtoupper((string) ($in['asset'] ?? ''));
    $address = (string) ($in['address'] ?? '');
    $claim = !empty($in['claim']) || !empty($in['claim_assets']);
    $to = !$claim && ($rule['convert'] ?? '') === 'convert' && isset($coins[$asset]) && $asset !== ($rule['convert_from'] ?? '') ? $asset : null;
    if ($to !== null) {
        $asset = $rule['convert_from'];
    }
    [$chain, $ticker] = $coins[$asset] ?? ['tron', 'USDT'];
    $amount = $to === null ? (string) ($in['amount'] ?? '0') : $dec((float) $in['amount'] * $coins[$to][2] / $coins[$asset][2]);
    $fee = $to === null ? 6.348213 : 9.348213;
    $item = [
        'id' => $id, 'merchant_id' => 'mch_1', 'livemode' => false, 'batch_id' => $batchId, 'reference' => $in['reference'] ?? '',
        'row' => $row, 'recipient' => array_filter(['name' => $in['name'] ?? '', 'email' => $in['email'] ?? '', 'note' => $in['note'] ?? '']),
        'asset' => $asset, 'ticker' => $ticker, 'chain' => $chain, 'address' => $address,
        'amount' => $amount, 'send_amount' => $amount, 'platform_fee' => '0.6',
        'network_fee' => '5.748213', 'fee' => $dec($fee), 'debit' => $dec((float) $amount + $fee),
        'usd_rate' => '1', 'amount_usd' => $amount, 'status' => 'ready', 'errors' => [], 'warnings' => [],
        'created_at' => '2026-10-20T09:15:01Z', 'updated_at' => '2026-10-20T09:15:01Z',
    ];
    if ($item['reference'] === '') {
        unset($item['reference']);
    }
    if (($in['webhook_url'] ?? '') !== '') {
        $item['webhook_url'] = $in['webhook_url']; // its events go there
    }
    $errors = [];
    if ($claim) {
        $item['claim'] = array_filter(['email' => (string) ($in['email'] ?? '')]) + [
            'assets' => array_values(array_diff(array_unique(array_map('strtoupper', $in['claim_assets'] ?? [])), [$asset])),
            'emailed' => 0, 'extended' => 0,
        ] + ($id !== '' ? ['url' => 'https://pay.example/claim/c' . $id] : []);
        if ($address !== '') {
            $errors[] = ['code' => 'invalid_address', 'field' => 'address', 'message' => 'a claim link has no address: its recipient chooses one',
                'hint' => 'leave the address empty, or pay the address without a claim link'];
        }
        if (isset($item['claim']['email']) && !str_contains($item['claim']['email'], '@')) {
            $errors[] = ['code' => 'invalid_email', 'field' => 'email', 'message' => 'this email address is not valid'];
        }
    } else {
        $network = $coins[$to ?? $asset][0] ?? 'tron';
        if (!str_starts_with($address, $network === 'tron' ? 'T' : '0x')) {
            $errors[] = ['code' => 'invalid_address', 'field' => 'address', 'hint' => 'check the address',
                'message' => 'this is not a ' . ($network === 'tron' ? 'Tron' : 'BNB Smart Chain') . ' address'];
        }
    }
    if ($to !== null) {
        $item['convert'] = ['to' => $to, 'ticker' => $coins[$to][1], 'network' => $networks[$coins[$to][0]],
            'to_amount' => (string) $in['amount'], 'estimate' => $dec((float) $in['amount'] * 0.995)];
        $item['conversion_fee'] = '3';
    }
    if ($errors !== []) {
        $item = array_merge($item, ['status' => 'invalid', 'send_amount' => '0', 'platform_fee' => '0', 'network_fee' => '0', 'fee' => '0',
            'debit' => '0', 'usd_rate' => '0', 'amount_usd' => '0', 'errors' => $errors]);
    }
    return $item;
};
// A batch's counts and totals, from its payments.
$batchOf = function (array $batch) use (&$payouts): array {
    $items = array_values(array_filter($payouts, fn ($it) => ($it['batch_id'] ?? '') === $batch['id']));
    $counts = ['total' => count($items), 'invalid' => 0, 'ready' => 0, 'cooling' => 0, 'queued' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'canceled' => 0,
        'claimable' => 0, 'expired' => 0];
    $debit = 0.0;
    foreach ($items as $it) {
        $counts[$it['status']]++;
        if ($it['status'] === 'ready') {
            $debit += (float) $it['debit'];
        }
    }
    $total = rtrim(rtrim(number_format($debit, 6, '.', ''), '0'), '.');
    return array_merge($batch, ['counts' => $counts, 'total_usd' => $total, 'totals' => $counts['ready'] === 0 ? [] : [[
        'asset' => 'USDT_TRC20', 'ticker' => 'USDT', 'chain' => 'tron', 'network' => 'Tron (TRC-20)', 'items' => $counts['ready'], 'debit' => $total,
    ]]]);
};
$newBatch = fn (string $id, array $fields): array => array_merge([
    'id' => $id, 'merchant_id' => 'mch_1', 'livemode' => false, 'name' => '', 'source' => 'api', 'inbox' => false, 'open' => false,
    'created_by' => 'key_1', 'by_api_key' => true, 'fee_paid_by' => 'merchant', 'convert' => 'same_network', 'status' => 'quoted',
    'approvals_needed' => 0, 'approvals' => [],
    'created_at' => '2026-10-20T09:15:01Z', 'updated_at' => '2026-10-20T09:15:01Z',
], $fields);
$batches['inbox'] ??= $newBatch('inbox', ['name' => 'Payout requests', 'source' => 'requests', 'created_by' => 'requests', 'by_api_key' => false,
    'inbox' => true, 'open' => true]);
$withStatus = fn (array $items): array => isset($query['status'])
    ? array_values(array_filter($items, fn ($it) => in_array($it['status'], explode(',', $query['status']), true)))
    : array_values($items);
// The payment carrying a reference; read by it, else the latest that gave it back.
$carrying = function (string $ref) use (&$payouts): ?array {
    return array_values(array_filter($payouts, fn ($it) => ($it['reference'] ?? '') === $ref))[0] ?? null;
};
$byReference = function (string $ref) use (&$payouts, $carrying): ?array {
    $released = array_values(array_filter($payouts, fn ($it) => ($it['released_reference'] ?? '') === $ref));
    return $carrying($ref) ?? ($released === [] ? null : end($released));
};
// A payment canceled by the key: a request still in the list is removed
// (its record kept, out of the list), any other canceled alone. Its
// reference is given back, and payout_item.canceled says so.
$cancel = function (string $id) use (&$payouts, $at, $announce): array {
    $it = $payouts[$id];
    $removed = ($it['batch_id'] ?? '') === 'inbox';
    $now = $at();
    $it = array_merge($it, ['status' => 'canceled', 'cancel_reason' => $removed ? 'removed' : 'canceled',
        'canceled_by' => 'key_1', 'canceled_at' => $now, 'updated_at' => $now]);
    if (isset($it['reference'])) {
        $it['released_reference'] = $it['reference'];
        unset($it['reference']);
    }
    if ($removed) {
        unset($it['batch_id']);
    }
    $announce('payout_item.canceled', $payouts[$id] = $it);
    return $it;
};
// GET /v1/payouts without a reference: the mode's payments, oldest change
// first; a cursor is the last change and id of the page before.
$listPayouts = function () use ($query, $withStatus, $rfc3339, &$payouts): array {
    $since = $query['updated_since'] ?? '';
    if ($since !== '' && !$rfc3339($since)) {
        return [400, ['error' => 'updated_since must be an RFC 3339 time, such as 2026-10-20T09:00:00Z']];
    }
    $limit = (int) ($query['limit'] ?? 0);
    if ($limit < 0 || $limit > 200) {
        return [400, ['error' => 'limit must be 1 to 200']];
    }
    $after = null;
    if (($query['cursor'] ?? '') !== '') {
        $after = explode('|', (string) base64_decode($query['cursor'], true), 2);
        if (count($after) !== 2 || strtotime($after[0]) === false) {
            return [400, ['error' => 'cursor is not one this API gave']];
        }
        $after[0] = strtotime($after[0]);
    }
    $key = fn (array $it): array => [strtotime($it['updated_at']), $it['id']];
    $items = $withStatus($payouts);
    usort($items, fn ($a, $b) => $key($a) <=> $key($b));
    $items = array_values(array_filter($items, fn ($it) => ($since === '' || strtotime($it['updated_at']) >= strtotime($since))
        && ($after === null || $key($it) > $after)));
    $listed = array_slice($items, 0, $limit ?: 50);
    $reply = ['items' => $listed, 'has_more' => count($items) > count($listed)];
    if ($listed !== []) {
        $last = $listed[count($listed) - 1];
        $reply['next_cursor'] = base64_encode($last['updated_at'] . '|' . $last['id']);
    }
    return [200, $reply];
};
$page = fn (array $list): array => [
    'list' => array_slice($list, (int) ($query['offset'] ?? 0), (int) ($query['limit'] ?? 50)),
    'limit' => (int) ($query['limit'] ?? 50), 'offset' => (int) ($query['offset'] ?? 0),
];
$wallets = function () use (&$balances): array {
    return [
    ['chain' => 'tron', 'network' => 'Tron', 'address' => 'TMkq7dZ3vN8xR2pB6cH9sJ4fL1wY5tGaQe', 'livemode' => false, 'assets' => [
        ['asset' => 'USDT_TRC20', 'ticker' => 'USDT', 'balance' => $balances['USDT_TRC20'], 'reserved' => '0', 'spendable' => $balances['USDT_TRC20'], 'needed' => '0'],
    ]],
    ['chain' => 'bsc', 'network' => 'BNB Smart Chain', 'address' => '0x8F3a6B2c9D1e4F7a0B5c8D2e6F9a1B4c7D0e3F6a', 'livemode' => false, 'assets' => [
        ['asset' => 'USDT_BEP20', 'ticker' => 'USDT', 'balance' => $balances['USDT_BEP20'], 'reserved' => '0', 'spendable' => $balances['USDT_BEP20'], 'needed' => '0'],
    ]],
    ];
};
// The conversion rule a batch or a quote asks for (the payout settings
// here: same_network, from USDT_TRC20), or a 400.
$ruleOf = function (array $body) use ($coins): array {
    $convert = $body['convert'] ?? 'same_network';
    $from = strtoupper((string) ($body['convert_from'] ?? 'USDT_TRC20'));
    return match (true) {
        !in_array($convert, ['same_network', 'convert'], true) => [400, ['error' => 'convert must be same_network or convert']],
        $convert === 'convert' && !isset($coins[$from]) => [400, ['error' => 'convert_from: payouts are not paid from ' . $from]],
        $convert === 'convert' => ['convert' => 'convert', 'convert_from' => $from],
        default => ['convert' => 'same_network'],
    };
};
$payoutRoute = function () use (
    $method, $path, $body, $query, $storefront, $live, $noPayouts, $notFound, $priced, $batchOf, $newBatch, $carrying, $byReference, $page,
    $withStatus, $wallets, $next, $ruleOf, $at, $announce, $cancel, $listPayouts, &$payouts, &$batches, &$balances,
): ?array {
    switch (true) {
        case $path === '/v1/payouts' && $method === 'POST':
            if ($storefront) {
                return $noPayouts;
            }
            if (($body['reference'] ?? '') !== '' && ($it = $carrying($body['reference'])) !== null) {
                return [200, ['item' => $it]];
            }
            $id = $next('po_');
            $rows = count(array_filter($payouts, fn ($it) => ($it['batch_id'] ?? '') === 'inbox'));
            $payouts[$id] = $priced($body, $id, 'inbox', $rows + 1);
            return [201, ['item' => $payouts[$id]]];
        case $path === '/v1/payouts' && $method === 'GET':
            if (($query['reference'] ?? '') === '') {
                return $listPayouts();
            }
            $it = $byReference($query['reference']);
            return $it === null ? $notFound : [200, ['item' => $it]];
        case $path === '/v1/payouts/remove' && $method === 'POST':
            if ($storefront) {
                return $noPayouts;
            }
            $ids = array_values(array_unique($body['ids'] ?? []));
            if (count($ids) < 1 || count($ids) > 1000) {
                return [400, ['error' => 'remove between 1 and 1000 payout requests at a time']];
            }
            $results = [];
            foreach ($ids as $id) {
                $it = $payouts[$id] ?? null;
                $results[] = ['id' => $id] + match (true) {
                    $it === null => ['error' => ['status' => 404, 'message' => 'not found']],
                    ($it['cancel_reason'] ?? '') === 'removed' => ['item' => $it], // removed already: no second event
                    in_array($it['status'], ['sent', 'failed', 'expired', 'canceled'], true) => ['error' => ['status' => 409, 'message' => 'the payment is ' . $it['status']]],
                    ($it['batch_id'] ?? '') !== 'inbox' => ['error' => ['status' => 409,
                        'message' => 'not in your list of payout requests (sent already): cancel it with DELETE /v1/payouts/' . $id]],
                    default => ['item' => $cancel($id)],
                };
            }
            return [200, ['results' => $results]];
        case $path === '/v1/payouts/inbox' && $method === 'GET':
            $inbox = $batchOf($batches['inbox']);
            $items = $withStatus(array_filter($payouts, fn ($it) => ($it['batch_id'] ?? '') === 'inbox'));
            $p = $page($items);
            $coverage = $inbox['totals'] === [] ? [] : [[
                'asset' => 'USDT_TRC20', 'ticker' => 'USDT', 'network' => 'Tron (TRC-20)', 'needed' => $inbox['total_usd'], 'spendable' => '0',
                'top_up' => $inbox['total_usd'], 'address' => 'TMkq7dZ3vN8xR2pB6cH9sJ4fL1wY5tGaQe',
            ]];
            return [200, ['inbox' => $inbox, 'coverage' => $coverage, 'items' => $p['list'], 'total' => count($items), 'limit' => $p['limit'], 'offset' => $p['offset']]];
        case $path === '/v1/payouts/send' && $method === 'POST':
            if ($storefront) {
                return $noPayouts;
            }
            $ready = array_keys(array_filter($payouts, fn ($it) => ($it['batch_id'] ?? '') === 'inbox' && $it['status'] === 'ready'));
            foreach ($body['ids'] ?? [] as $want) {
                if (!in_array($want, $ready, true)) {
                    return [400, ['error' => 'payout request ' . $want . ' is not a valid request waiting in your list']];
                }
            }
            $move = $body['ids'] ?? $ready;
            if ($move === []) {
                return [400, ['error' => 'no valid payout request to send']];
            }
            $id = $next('pb_');
            $now = $at();
            foreach ($move as $row => $item) {
                $payouts[$item] = array_merge($payouts[$item], ['batch_id' => $id, 'row' => $row + 1, 'updated_at' => $now]);
                $announce('payout_item.processing', $payouts[$item]); // out of the list, into its batch
            }
            $batches[$id] = $newBatch($id, ['name' => 'Payout requests of 20 Oct 2026 09:20 UTC', 'source' => 'requests', 'inbox' => true,
                'status' => 'awaiting_funds', 'submitted_by' => 'key_1', 'submitted_at' => '2026-10-20T09:20:00Z']);
            $batch = $batchOf($batches[$id]);
            $batch['waiting'] = ['reason' => 'funds', 'note' => 'top up the payout wallet', 'since' => '2026-10-20T09:20:00Z', 'shortfall' => [[
                'asset' => 'USDT_TRC20', 'network' => 'Tron (TRC-20)', 'amount' => $batch['total_usd'], 'address' => 'TMkq7dZ3vN8xR2pB6cH9sJ4fL1wY5tGaQe',
            ]]];
            return [200, ['batch' => $batches[$id] = $batch]];
        case $path === '/v1/payout-quotes' && $method === 'POST':
            if ($storefront) {
                return $noPayouts;
            }
            $items = $body['items'] ?? [];
            if (count($items) < 1 || count($items) > 100) {
                return [400, ['error' => 'quote between 1 and 100 payments']];
            }
            $rule = $ruleOf($body);
            if (isset($rule[0])) {
                return $rule;
            }
            $quoted = [];
            foreach (array_values($items) as $row => $in) {
                $quoted[] = array_diff_key($priced($in, '', '', $row + 1, $rule), array_flip(['id', 'merchant_id', 'batch_id', 'created_at', 'updated_at']));
            }
            $ready = array_values(array_filter($quoted, fn ($it) => $it['status'] === 'ready'));
            return [200, ['quote' => [
                'livemode' => false, 'fee_paid_by' => $body['fee_paid_by'] ?? 'merchant',
                'totals' => $ready === [] ? [] : [['asset' => 'USDT_TRC20', 'items' => count($ready)]],
                'total_usd' => (string) array_sum(array_map(fn ($it) => (float) $it['debit'], $ready)),
                'counts' => ['total' => count($quoted), 'invalid' => count($quoted) - count($ready), 'ready' => count($ready)],
            ], 'items' => $quoted]];
        case $path === '/v1/payout-batches' && $method === 'POST':
            if ($storefront) {
                return $noPayouts;
            }
            foreach ($batches as $b) {
                if (($body['reference'] ?? '') !== '' && ($b['reference'] ?? '') === $body['reference']) {
                    return [200, ['batch' => $batchOf($b)]];
                }
            }
            if (($body['items'] ?? []) === []) {
                return [400, ['error' => 'a batch needs at least one payment']];
            }
            $rule = $ruleOf($body);
            if (isset($rule[0])) {
                return $rule;
            }
            $id = $next('pb_');
            $batches[$id] = $newBatch($id, array_filter([
                'reference' => $body['reference'] ?? '', 'name' => $body['name'] ?? '', 'fee_paid_by' => $body['fee_paid_by'] ?? 'merchant',
                'webhook_url' => $body['webhook_url'] ?? '', 'quote_expires_at' => '2026-10-20T10:15:01Z',
            ]) + $rule + ['name' => '']);
            foreach (array_values($body['items']) as $row => $in) {
                $item = $next('po_');
                $payouts[$item] = $priced($in, $item, $id, $row + 1, $rule);
            }
            return [201, ['batch' => $batchOf($batches[$id])]];
        case $path === '/v1/payout-batches' && $method === 'GET':
            $list = array_values(array_map($batchOf, array_filter($batches, fn ($b) => !($b['open'] ?? false)
                && (!isset($query['status']) || $b['status'] === $query['status']))));
            $p = $page(array_reverse($list));
            return [200, ['batches' => $p['list'], 'total' => count($list), 'limit' => $p['limit'], 'offset' => $p['offset']]];
        case $path === '/v1/payout-wallets' && $method === 'GET':
            return [200, ['wallets' => $wallets()]];
    }
    if (preg_match('#^/v1/payout-wallets/([^/]+)/simulate-top-up$#', $path, $m) === 1 && $method === 'POST') {
        $asset = strtoupper(rawurldecode($m[1]));
        $refused = match (true) {
            $storefront => $noPayouts,
            $live => [400, ['error' => 'only a test wallet is topped up on paper: use a test key']],
            !isset($balances[$asset]) => [400, ['error' => 'payouts in ' . $asset . ' are not available yet']],
            (float) ($body['amount'] ?? 0) <= 0 => [400, ['error' => 'amount must be over 0 and at most 10000000']],
            default => null,
        };
        if ($refused !== null) {
            return $refused;
        }
        $balances[$asset] = (string) ((float) $balances[$asset] + (float) $body['amount']);
        return [200, ['wallets' => $wallets()]];
    }
    if (preg_match('#^/v1/payouts/([^/]+)$#', $path, $m) === 1) {
        $id = rawurldecode($m[1]);
        if (!isset($payouts[$id])) {
            return $notFound;
        }
        if ($method === 'GET') {
            return [200, ['item' => $payouts[$id]]];
        }
        if ($method === 'DELETE') {
            if ($storefront) {
                return $noPayouts;
            }
            return match ($payouts[$id]['status']) {
                'canceled' => [200, ['item' => $payouts[$id]]], // canceled already: answered as it is
                'sent', 'failed', 'expired' => [409, ['error' => 'the payment is ' . $payouts[$id]['status']]],
                default => [200, ['item' => $cancel($id)]],
            };
        }
    }
    if (preg_match('#^/v1/payout-batches/([^/]+)(/items|/quote|/submit|/cancel)?$#', $path, $m) === 1) {
        $id = rawurldecode($m[1]);
        $action = $m[2] ?? '';
        if (!isset($batches[$id]) || ($batches[$id]['open'] ?? false)) {
            return $notFound;
        }
        if ($method === 'POST' && $storefront) {
            return $noPayouts;
        }
        $batch = $batches[$id];
        $submitted = !in_array($batch['status'], ['validating', 'quoted'], true);
        switch ("$method $action") {
            case 'GET ':
                return [200, ['batch' => $batchOf($batch)]];
            case 'GET /items':
                $items = $withStatus(array_filter($payouts, fn ($it) => ($it['batch_id'] ?? '') === $id));
                $p = $page($items);
                return [200, ['items' => $p['list'], 'total' => count($items), 'limit' => $p['limit'], 'offset' => $p['offset']]];
            case 'POST /items':
                if ($submitted) {
                    return [409, ['error' => 'a submitted batch cannot change: cancel it and make another']];
                }
                $last = count(array_filter($payouts, fn ($it) => ($it['batch_id'] ?? '') === $id));
                foreach (array_values($body['items'] ?? []) as $row => $in) {
                    $item = $next('po_');
                    $payouts[$item] = $priced($in, $item, $id, $last + $row + 1, $batch);
                }
                return [200, ['batch' => $batchOf($batch)]];
            case 'POST /quote':
                if ($submitted) {
                    return [409, ['error' => 'a submitted batch cannot change: cancel it and make another']];
                }
                $batches[$id]['quote_expires_at'] = '2026-10-20T11:15:01Z';
                return [200, ['batch' => $batchOf($batches[$id])]];
            case 'POST /submit':
                if ($submitted) {
                    return [409, ['error' => 'the batch was submitted already']];
                }
                $batches[$id] = array_merge($batch, ['status' => 'awaiting_funds', 'submitted_by' => 'key_1', 'submitted_at' => '2026-10-20T09:16:40Z']);
                $out = $batchOf($batches[$id]);
                $out['waiting'] = ['reason' => 'funds', 'note' => 'top up the payout wallet', 'since' => '2026-10-20T09:16:40Z', 'shortfall' => [[
                    'asset' => 'USDT_TRC20', 'network' => 'Tron (TRC-20)', 'amount' => $out['total_usd'], 'address' => 'TMkq7dZ3vN8xR2pB6cH9sJ4fL1wY5tGaQe',
                ]]];
                return [200, ['batch' => $out]];
            case 'POST /cancel':
                if (in_array($batch['status'], ['completed', 'canceled', 'rejected'], true)) {
                    return [409, ['error' => 'the batch is ' . $batch['status']]];
                }
                $batches[$id] = array_merge($batch, ['status' => 'canceled', 'canceled_by' => 'key_1', 'canceled_at' => '2026-10-20T09:17:00Z']);
                return [200, ['batch' => $batchOf($batches[$id])]];
        }
    }
    return null;
};
// GET /v1/events: the mode's events, oldest first, of types (exact, or a
// family: payout_item.*), after an event or a time.
$replay = function () use ($query, $storefront, $rfc3339, &$events): array {
    if ($storefront) {
        return [403, ['error' => "a storefront's key cannot read the account's events: use an account key"]];
    }
    $types = ($query['type'] ?? '') === '' ? [] : array_map('trim', explode(',', $query['type']));
    foreach ($types as $type) {
        if (preg_match('/^[a-z_]+(\.[a-z_]+|\.\*)?$/', $type) !== 1) {
            return [400, ['error' => 'type must be event types, such as payout_item.sent or payout_item.*']];
        }
    }
    $list = $events;
    if (($query['after'] ?? '') !== '') {
        $pos = array_search($query['after'], array_column($events, 'id'), true);
        if ($pos === false) {
            return [400, ['error' => 'after is not an event of this mode']];
        }
        $list = array_slice($events, $pos + 1);
    } elseif (($query['created_after'] ?? '') !== '') {
        if (!$rfc3339($query['created_after'])) {
            return [400, ['error' => 'created_after must be an RFC 3339 time, such as 2026-10-20T09:00:00Z']];
        }
        $list = array_filter($list, fn ($e) => strtotime($e['created_at']) > strtotime($query['created_after']));
    }
    $limit = 100;
    if (($query['limit'] ?? '') !== '') {
        $limit = (int) $query['limit'];
        if ($limit < 1 || $limit > 200) {
            return [400, ['error' => 'limit must be 1 to 200']];
        }
    }
    $list = array_values(array_filter($list, fn ($e) => $types === [] || array_filter($types, fn ($t) => $t === $e['type']
        || (str_ends_with($t, '.*') && str_starts_with($e['type'], substr($t, 0, -1)))) !== []));
    return [200, ['events' => array_slice($list, 0, $limit), 'has_more' => count($list) > $limit]];
};

[$status, $reply] = match (true) {
    $path === '/v1/webhook' && $method === 'GET' => [200, ['webhook' => $live ? array_merge($webhook, ['livemode' => true, 'url' => null]) : $webhook]],
    $path === '/v1/webhook' && $method === 'PUT' => match (true) {
        $storefront => $forbidden,
        !str_starts_with($body['url'] ?? '', 'https://') => [400, ['error' => 'url must use https']],
        default => [200, ['webhook' => $webhook = array_merge($webhook, ['url' => $body['url']])]],
    },
    $path === '/v1/webhook' && $method === 'DELETE' => $storefront
        ? $forbidden
        : [200, ['webhook' => $webhook = array_merge($webhook, ['url' => null])]],
    $path === '/v1/webhook/test' && $method === 'POST' => $webhook['url'] === null
        ? [400, ['error' => 'no webhook url is set for this mode: set one first']]
        : [202, ['delivery' => [
            'id' => 'whd_1', 'merchant_id' => 'mch_1', 'target' => 'account', 'url' => $webhook['url'],
            'event_id' => 'evt_1', 'event_type' => 'ping', 'payload' => '{}', 'status' => 'pending',
            'attempts' => 0, 'max_attempts' => 7, 'next_attempt_at' => '2026-10-10T12:00:00Z',
            'created_at' => '2026-10-10T12:00:00Z',
        ]]],
    $path === '/v1/payments' && $method === 'POST' => match (true) {
        $currency !== '' && isset($body['amount_usd']) => [400, ['error' => 'give amount_usd, or price_amount and price_currency: not both']],
        $currency !== '' && !array_key_exists($currency, $rates) => [400, ['error' => 'price_currency must be the ISO 4217 code of a currency (EUR, GBP, NGN…)']],
        $currency !== '' && $rates[$currency] === null => [503, ['error' => 'exchange rate unavailable for price_currency: try again shortly']],
        default => [201, $created($body)],
    },
    $path === '/v1/tokens' && $method === 'GET' => [200, ['tokens' => [[
        'token' => 'USDT_BEP20', 'chain' => 'bsc', 'min_usd' => '1', 'network_fee_usd' => '0.01', 'amount_due_usd' => '40.43', 'fee_note' => '',
    ]]]],
    $method === 'POST' && preg_match('#^/v1/payments/([^/]+)/token$#', $path, $m) === 1
        && in_array(rawurldecode($m[1]), ['pay_1', 'ORD 7/1'], true) => match (true) {
            ($body['token'] ?? '') === '' => [400, ['error' => 'token is required']],
            isset($chosen['pay_1']) => [409, ['error' => 'the coin for this payment can no longer be changed']],
            default => [200, $chosen['pay_1'] = $withCoin($body['token'])],
        },
    $method === 'POST' && preg_match('#^/v1/payments/([^/]+)/simulate$#', $path, $m) === 1
        && in_array(rawurldecode($m[1]), ['pay_2', 'ORD 8/1'], true) => match (true) {
            ($body['outcome'] ?? '') === '' => [400, ['error' => 'outcome is required']],
            isset($simulating['pay_2']) => [409, ['error' => 'a simulation is already running for this payment']],
            default => [200, $simulating['pay_2'] = $simulated($body['outcome'])],
        },
    $path === '/v1/events' && $method === 'GET' => $replay(),
    default => $payoutRoute() ?? [404, ['error' => 'not found']],
};
file_put_contents($stateFile, json_encode(['webhook' => $webhook, 'chosen' => $chosen, 'simulating' => $simulating,
    'payouts' => $payouts, 'batches' => $batches, 'balances' => $balances, 'seq' => $seq, 'events' => $events, 'tick' => $tick]));
file_put_contents((string) getenv('PAYITGO_GATEWAY_LOG'), json_encode([
    'status' => $status,
    'method' => $method,
    'path' => $path,
    'query' => $_SERVER['QUERY_STRING'] ?? '',
    'headers' => $headers,
    'body' => $raw === '' ? null : json_decode($raw, true),
    'reply' => $reply,
]));

http_response_code($status);
header('Content-Type: application/json');
echo json_encode($reply);
