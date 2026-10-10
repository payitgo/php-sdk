<?php

declare(strict_types=1);

// A fake gateway for tests/run.php, served by PHP's built-in server on
// 127.0.0.1: it records each request and its reply in PAYITGO_GATEWAY_LOG,
// keeps the mode's webhook and the chosen coins in PAYITGO_GATEWAY_STATE and
// answers the routes the way the API does.

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = file_get_contents('php://input');
$headers = array_change_key_case(getallheaders(), CASE_LOWER);

$stateFile = (string) getenv('PAYITGO_GATEWAY_STATE');
$state = json_decode((string) @file_get_contents($stateFile), true) ?: [];
$webhook = $state['webhook'] ?? [
    'livemode' => false, 'url' => null, 'secret' => 'whsec_' . str_repeat('ab', 32),
    'created_at' => '2026-10-10T09:00:00Z', 'updated_at' => '2026-10-10T09:00:00Z',
];
// The payments whose coin has been chosen: choosing again is a 409.
$chosen = $state['chosen'] ?? [];
$body = json_decode($raw, true) ?? [];
// A storefront's key may read and test the webhook, not change it.
$storefront = ($headers['authorization'] ?? '') === 'Bearer sk_test_store';
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
    'network_fee_note' => 'XRP Ledger testnet: test XRP only. Include destination tag — required.',
    'qr_payload' => 'xrpl:rPT1Sjq2YGrBMTttX4GZHjKu9dyfzbpAYe?amount=20.000000&dt=1042',
    'checkout_token' => 'ct_abc', 'checkout_url' => 'https://pay.example/ct_abc', 'description' => 'Order 7',
    'metadata' => ['cart' => '42'],
];

[$status, $reply] = match (true) {
    $path === '/v1/webhook' && $method === 'GET' => [200, ['webhook' => $webhook]],
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
    $path === '/v1/payments' && $method === 'POST' => [201, array_merge($body, ['id' => 'pay_1', 'status' => 'pending'])],
    $path === '/v1/tokens' && $method === 'GET' => [200, ['tokens' => [[
        'token' => 'USDT_BEP20', 'chain' => 'bsc', 'min_usd' => '1', 'network_fee_usd' => '0.01', 'amount_due_usd' => '40.43', 'fee_note' => '',
    ]]]],
    $method === 'POST' && preg_match('#^/v1/payments/([^/]+)/token$#', $path, $m) === 1
        && in_array(rawurldecode($m[1]), ['pay_1', 'ORD 7/1'], true) => match (true) {
            ($body['token'] ?? '') === '' => [400, ['error' => 'token is required']],
            isset($chosen['pay_1']) => [409, ['error' => 'the coin for this payment has already been chosen']],
            default => [200, $chosen['pay_1'] = $withCoin($body['token'])],
        },
    default => [404, ['error' => 'not found']],
};
file_put_contents($stateFile, json_encode(['webhook' => $webhook, 'chosen' => $chosen]));
file_put_contents((string) getenv('PAYITGO_GATEWAY_LOG'), json_encode([
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
