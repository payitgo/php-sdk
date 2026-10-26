<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/../src/' . str_replace(['Payitgo\\', '\\'], ['', '/'], $class) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use Payitgo\Client;
use Payitgo\Events;
use Payitgo\Exception\PayitgoException;
use Payitgo\Exception\SignatureVerificationException;
use Payitgo\Payments;
use Payitgo\Webhook;
use Payitgo\Webhooks;

// Every notice, warning and deprecation fails the run, whatever php.ini hides:
// merchants run the SDK with their own error reporting, and a new PHP
// deprecates functions (curl_close() in 8.5).
error_reporting(E_ALL);
$problems = [];
set_error_handler(function (int $level, string $message, string $file, int $line) use (&$problems): bool {
    if (error_reporting() & $level) { // not silenced with @
        $problems[] = $message . ' (' . basename($file) . ':' . $line . ')';
    }
    return true;
});

// The test key, a storefront's test key and a live key (gateway.php knows them).
const KEY = '8334dfeae25b9b200b75995e6e55cf8c3602120c1920bfef7a4ff8900b620881';
const STORE_KEY = '7fd8a9991cc9aadccefc062f0467f180cb1ab0d388ff76cdaf0b1fbbd5c80296';
const LIVE_KEY = '9a6fd52f16ba8258c4b71ec3a7ffbe4e22ebc4ff99a1bc073fd414e0bbd4ee15';

// Signed by the gateway's own signer.
const SECRET = '05bd6b428158318cc38756fa34aa60795af0fc5d9a316dcc9b060c8ca1863fc3';
const HEADER = 't=1760000000,v1=b61376838fdc09d9241eb407eda645be1e67a88fed148fc057c56a1b784e01e5';
const BODY = '{"id":"b8e1f80bd70ae0784c7855a4","type":"payment.confirmed","livemode":false,"created_at":"2026-10-09T12:00:00Z","data":{"id":"040ffd5925d40e11c67b7238","order_id":"ORD-1","status":"confirmed","currency":"USD","amount":"10"}}';
const OTHER_SECRET = 'bd4106123e659e259c401119d0c7ef0a221df306a5a414b6fb6a579499962d26';

$failures = 0;
function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}
function rejects(callable $fn): bool
{
    try {
        $fn();
        return false;
    } catch (SignatureVerificationException) {
        return true;
    }
}

$event = Webhook::constructEvent(BODY, HEADER, SECRET, 0);
check('accepts a delivery signed by the gateway', $event['type'] === 'payment.confirmed' && $event['data']['order_id'] === 'ORD-1' && $event['livemode'] === false);
check('rejects a changed body', rejects(fn () => Webhook::constructEvent(str_replace('"10"', '"1000"', BODY), HEADER, SECRET, 0)));
check('rejects the wrong secret', rejects(fn () => Webhook::constructEvent(BODY, HEADER, OTHER_SECRET, 0)));
check('rejects a missing header', rejects(fn () => Webhook::constructEvent(BODY, null, SECRET, 0)));
check('rejects an old timestamp', rejects(fn () => Webhook::constructEvent(BODY, HEADER, SECRET)));
$now = time();
$fresh = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . BODY, SECRET);
check('accepts a fresh delivery', Webhook::constructEvent(BODY, $fresh, SECRET)['id'] === 'b8e1f80bd70ae0784c7855a4');

putenv('PAYITGO_BASE_URL');
foreach (['', '  '] as $key) {
    try {
        new Client($key, ['base_url' => 'http://x']);
        check('requires a key', false);
    } catch (InvalidArgumentException $e) {
        check('requires a key', $e->getMessage() === 'payitgo: the API key is required');
    }
}
try {
    new Client(KEY, ['base_url' => '']);
    check('requires a base URL', false);
} catch (InvalidArgumentException) {
    check('requires a base URL', true);
}
check('no livemode property: isLive() asks payitgo', !property_exists(Client::class, 'livemode'));
check('the version is set', preg_match('/^\d+\.\d+\.\d+$/', Client::VERSION) === 1);
check('the events are listed, in the API\'s order', Webhooks::EVENTS === [
    'payment.partially_paid', 'payment.detecting', 'payment.confirmed', 'payment.underpaid', 'payment.expired', 'payment.failed',
    'payment.wrong_asset', 'payment.late_deposit', 'payout.approved', 'payout.rejected', 'payout.sent', 'settlement.completed',
    'settlement.failed', 'payout_batch.quoted', 'payout_batch.pending_approval', 'payout_batch.approved', 'payout_batch.rejected',
    'payout_batch.awaiting_funds', 'payout_batch.processing', 'payout_batch.completed', 'payout_batch.canceled',
    'payout_item.processing', 'payout_item.returned', 'payout_item.sent', 'payout_item.failed', 'payout_item.canceled',
    'payout_item.claimed', 'payout_item.claim_expired', 'payout_wallet.withdrawn', 'payout_wallet.withdrawal_failed',
    'payout_schedule.run', 'payout_schedule.funding_needed', 'ping',
]);
$signed = function (string $body): string {
    $t = time();
    return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, SECRET);
};
$body = '{"id":"7c1e5a9b3d2f4e6a8b0c1d2e","type":"payout_item.claimed","livemode":false,"created_at":"2026-10-20T10:00:00Z","data":{'
    . '"id":"5c7e9a1b3d5f7a9c1e3b5d7f","reference":"WD-90","status":"queued","asset":"USDT_BEP20","address":"0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed",'
    . '"amount":"50","claim":{"email":"ana@example.com","assets":["USDT_BEP20","BNB"],"expires_at":"2026-11-19T10:00:00Z",'
    . '"claimed_at":"2026-10-20T10:00:00Z","emailed":1,"extended":0,"funded_asset":"USDT_TRC20","funded_amount":"50"}}}';
$event = Webhook::constructEvent($body, $signed($body), SECRET);
check('a payout_item.claimed event decodes the payment and its claim', $event['type'] === 'payout_item.claimed'
    && in_array($event['type'], Webhooks::EVENTS, true) && $event['data']['status'] === 'queued'
    && $event['data']['claim']['claimed_at'] === '2026-10-20T10:00:00Z' && $event['data']['claim']['funded_asset'] === 'USDT_TRC20'
    && $event['data']['claim']['funded_amount'] === '50' && $event['data']['claim']['assets'] === ['USDT_BEP20', 'BNB']);
$body = '{"id":"8d2f6b0c4e3a5f7b9c1d2e3f","type":"payout_schedule.funding_needed","livemode":false,"created_at":"2026-10-29T09:00:00Z","data":{'
    . '"schedule":{"id":"2b4d6f8a0c1e3a5b7d9f1a2c","livemode":false,"name":"Monthly payroll","status":"active","cadence":"monthly","weekday":0,'
    . '"day":1,"at":"09:00","timezone":"UTC","fee_paid_by":"merchant","auto_submit":false,"remind_days":3,'
    . '"totals":[{"asset":"USDT_TRC20","ticker":"USDT","items":12,"amount":"1200"}],"next_run_at":"2026-11-01T09:00:00Z","last_run_at":null,'
    . '"last_batch_id":"","last_error":"","runs":0},'
    . '"shortfall":[{"asset":"USDT_TRC20","network":"Tron (TRC-20)","amount":"1200","address":"TMkq7dZ3vN8xR2pB6cH9sJ4fL1wY5tGaQe"}]}}';
$event = Webhook::constructEvent($body, $signed($body), SECRET);
check('a payout_schedule.funding_needed event decodes the schedule and its shortfall', in_array($event['type'], Webhooks::EVENTS, true)
    && $event['data']['schedule']['cadence'] === 'monthly' && $event['data']['schedule']['next_run_at'] === '2026-11-01T09:00:00Z'
    && $event['data']['schedule']['last_run_at'] === null && $event['data']['schedule']['totals'][0]['items'] === 12
    && !isset($event['data']['schedule']['items'])
    && $event['data']['shortfall'][0] === ['asset' => 'USDT_TRC20', 'network' => 'Tron (TRC-20)', 'amount' => '1200', 'address' => 'TMkq7dZ3vN8xR2pB6cH9sJ4fL1wY5tGaQe']);

$body = '{"id":"6e8a0c2e4b6d8f1a3c5e7b9d","type":"payout_item.returned","livemode":false,"created_at":"2026-10-20T10:05:00Z","data":{'
    . '"id":"5c7e9a1b3d5f7a9c1e3b5d7f","batch_id":"6a1f3c8e2d9b4a7051c3e8f2","reference":"WD-92","row":3,"status":"invalid","asset":"USDT_TRC20",'
    . '"address":"TR7mNkucnhLZoJQLLJ6DR88jpLQufWxUJi","amount":"5","errors":[{"code":"below_minimum","message":"below the minimum of 10 USDT"}],'
    . '"warnings":[],"webhook_url":"https://shop.example/withdrawals/ipn","updated_at":"2026-10-20T10:05:00Z"}}';
$event = Webhook::constructEvent($body, $signed($body), SECRET);
check('a payout_item.returned event decodes the payment back in the list, with its errors and webhook_url', in_array($event['type'], Webhooks::EVENTS, true)
    && $event['data']['status'] === 'invalid' && $event['data']['errors'][0]['code'] === 'below_minimum'
    && $event['data']['webhook_url'] === 'https://shop.example/withdrawals/ipn');
$body = '{"id":"9c3e5a7b1d2f4a6c8e0b2d4f","type":"payout_wallet.withdrawal_failed","livemode":false,"created_at":"2026-10-20T11:05:00Z","data":{'
    . '"id":"1d3f5a7c9e2b4d6f8a0c2e4b","merchant_id":"mch_1","livemode":false,"asset":"USDT_TRC20","chain":"tron","amount":"377.797583",'
    . '"fee":"3.71","send_amount":"374.087583","address_id":"3e7a1c9f5b2d4e8a6c0f2b1d","address":"TWd4Kp9mR2xH7sN3vB6cJ8fL5qY1tZgEaU",'
    . '"status":"failed","fail_reason":"the transfer could not be sent","requested_by":"9b2e4c71d8a05f3e6c1a7b24",'
    . '"members":{"9b2e4c71d8a05f3e6c1a7b24":"owner@acme.example"},"created_at":"2026-10-20T11:02:15Z","updated_at":"2026-10-20T11:05:00Z"}}';
$event = Webhook::constructEvent($body, $signed($body), SECRET);
check('a payout_wallet.withdrawal_failed event decodes the withdrawal and its fail_reason', in_array($event['type'], Webhooks::EVENTS, true)
    && $event['data']['status'] === 'failed' && $event['data']['fail_reason'] === 'the transfer could not be sent'
    && $event['data']['amount'] === '377.797583');

// The webhook (IPN), against the fake gateway in gateway.php on 127.0.0.1.
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$log = tempnam(sys_get_temp_dir(), 'payitgo-gateway');
$state = tempnam(sys_get_temp_dir(), 'payitgo-state');
$gateway = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/gateway.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    null,
    ['PAYITGO_GATEWAY_LOG' => $log, 'PAYITGO_GATEWAY_STATE' => $state],
);
for ($i = 0; $i < 100 && !($socket = @fsockopen('127.0.0.1', $port)); $i++) {
    usleep(50000);
}
if ($socket) {
    fclose($socket);
}
$last = fn (): array => json_decode((string) file_get_contents($log), true);
$client = fn (string $key = KEY, array $options = []): Client => new Client($key, ['base_url' => "http://127.0.0.1:$port/", 'max_retries' => 0] + $options);
function apiError(callable $fn): ?PayitgoException
{
    try {
        $fn();
        return null;
    } catch (PayitgoException $e) {
        return $e;
    }
}
$payitgo = $client();
$url = 'https://shop.example/payitgo/ipn';

try {
    check('isLive() is false for a test key', $payitgo->isLive() === false);
    $req = $last();
    check('isLive() reads the mode\'s webhook with the key', $req['method'] === 'GET' && $req['path'] === '/v1/webhook'
        && $req['headers']['authorization'] === 'Bearer ' . KEY);
    file_put_contents($log, '');
    check('isLive() asks once', $payitgo->isLive() === false && file_get_contents($log) === '');
    $live = $client(LIVE_KEY);
    check('isLive() is true for a live key', $live->isLive() === true && $last()['headers']['authorization'] === 'Bearer ' . LIVE_KEY);
    file_put_contents($log, '');
    check('isLive() asks once, live too', $live->isLive() === true && file_get_contents($log) === '');

    $webhook = $payitgo->webhooks->get();
    $req = $last();
    check('webhooks->get reads the mode\'s webhook', $req['method'] === 'GET' && $req['path'] === '/v1/webhook' && $req['body'] === null
        && $req['headers']['authorization'] === 'Bearer ' . KEY && $webhook['url'] === null && strlen($webhook['secret']) === 64);

    $e = apiError(fn () => $payitgo->webhooks->test());
    check('webhooks->test without a URL is a 400', $e !== null && $e->status === 400 && $e->getMessage() === 'no webhook url is set for this mode: set one first');

    $webhook = $payitgo->webhooks->set($url);
    $req = $last();
    check('webhooks->set puts the URL', $req['method'] === 'PUT' && $req['path'] === '/v1/webhook'
        && $req['headers']['content-type'] === 'application/json' && $req['body'] === ['url' => $url] && $webhook['url'] === $url);

    $delivery = $payitgo->webhooks->test();
    $req = $last();
    check('webhooks->test queues a ping', $req['method'] === 'POST' && $req['path'] === '/v1/webhook/test'
        && $req['headers']['authorization'] === 'Bearer ' . KEY && $delivery['event_type'] === 'ping'
        && $delivery['status'] === 'pending' && $delivery['target'] === 'account' && $delivery['url'] === $url);

    $removed = $payitgo->webhooks->remove();
    $req = $last();
    check('webhooks->remove deletes the URL and keeps the secret', $req['method'] === 'DELETE' && $req['path'] === '/v1/webhook'
        && $removed['url'] === null && $removed['secret'] === $webhook['secret']);

    $e = apiError(fn () => $payitgo->webhooks->set('http://shop.example/hook'));
    check('webhooks errors carry the message and status', $e !== null && $e->status === 400 && $e->getMessage() === 'url must use https');

    $store = $client(STORE_KEY);
    $e = apiError(fn () => $store->webhooks->set($url));
    check('a storefront\'s key cannot set the webhook', $e !== null && $e->status === 403
        && $e->getMessage() === "a storefront's key cannot change the account's webhook: set webhook_url on its payments instead");
    check('a storefront\'s key reads it', $store->webhooks->get()['secret'] === $webhook['secret']);

    $payment = $payitgo->payments->create(['order_id' => 'ORD-1', 'amount' => 10]);
    check('payments->create sends no webhook_url without one', $last()['body'] === ['order_id' => 'ORD-1', 'amount' => '10'] && !isset($payment['webhook_url']));
    $payment = $payitgo->payments->create(['order_id' => 'ORD-2', 'amount' => 10, 'webhook_url' => 'https://shop.example/own']);
    check('payments->create sends its own webhook_url', $last()['body']['webhook_url'] === 'https://shop.example/own'
        && $payment['webhook_url'] === 'https://shop.example/own');
    $withDefault = $client(KEY, ['webhook_url' => 'https://shop.example/default']);
    $payment = $withDefault->payments->create(['order_id' => 'ORD-3', 'amount' => 10]);
    check('payments->create sends the client\'s webhook_url', $last()['body']['webhook_url'] === 'https://shop.example/default'
        && $payment['webhook_url'] === 'https://shop.example/default');
    $payment = $withDefault->payments->create(['order_id' => 'ORD-4', 'amount' => 10, 'webhook_url' => 'https://shop.example/own']);
    check('payments->create: the call\'s webhook_url wins', $last()['body']['webhook_url'] === 'https://shop.example/own');

    $payment = $payitgo->payments->selectToken('pay_1', 'XRP');
    $req = $last();
    check('payments->selectToken posts the coin with the key', $req['method'] === 'POST' && $req['path'] === '/v1/payments/pay_1/token'
        && $req['headers']['authorization'] === 'Bearer ' . KEY && $req['headers']['content-type'] === 'application/json'
        && $req['body'] === ['token' => 'XRP']);
    check('payments->selectToken returns the whole payment', $payment === $req['reply'] && $payment['token'] === 'XRP'
        && $payment['deposit_address'] === 'rPT1Sjq2YGrBMTttX4GZHjKu9dyfzbpAYe' && $payment['destination_tag'] === 1042
        && $payment['crypto_amount'] === '20' && $payment['rate_locked'] === '0.5' && $payment['expires_at'] === '2026-10-10T12:15:00Z'
        && $payment['qr_payload'] === 'rPT1Sjq2YGrBMTttX4GZHjKu9dyfzbpAYe'
        && $payment['checkout_token'] === 'ct_abc' && $payment['checkout_url'] === 'https://pay.example/ct_abc'
        && $payment['metadata'] === ['cart' => '42'] && $payment['deposits'] === []);
    $e = apiError(fn () => $payitgo->payments->selectToken('ORD 7/1', 'XRP'));
    check('payments->selectToken escapes an order id', $last()['path'] === '/v1/payments/ORD%207%2F1/token');
    check('payments->selectToken: a coin already chosen is a 409', $e !== null && $e->status === 409
        && $e->getMessage() === 'the coin for this payment can no longer be changed');

    $payment = $payitgo->payments->simulate('pay_2', 'confirmed');
    $req = $last();
    check('payments->simulate posts the outcome with the key', $req['method'] === 'POST' && $req['path'] === '/v1/payments/pay_2/simulate'
        && $req['headers']['authorization'] === 'Bearer ' . KEY && $req['headers']['content-type'] === 'application/json'
        && $req['body'] === ['outcome' => 'confirmed']);
    check('payments->simulate returns the payment with its simulation', $payment === $req['reply']
        && $payment['simulation'] === ['outcome' => 'confirmed', 'state' => 'running']
        && $payment['deposits'][0]['simulated'] === true && strlen($payment['deposits'][0]['tx_id']) === 64);
    $e = apiError(fn () => $payitgo->payments->simulate('ORD 8/1', 'confirmed'));
    check('payments->simulate escapes an order id', $last()['path'] === '/v1/payments/ORD%208%2F1/simulate');
    check('payments->simulate: a simulation still running is a 409', $e !== null && $e->status === 409
        && $e->getMessage() === 'a simulation is already running for this payment');
    $payment = $payitgo->payments->create(['order_id' => 'ORD-8', 'amount' => 20, 'token' => 'USDT_TRC20', 'simulate' => 'confirmed']);
    check('payments->create sends simulate', $last()['body'] === ['order_id' => 'ORD-8', 'amount' => '20', 'token' => 'USDT_TRC20', 'simulate' => 'confirmed']
        && $payment['simulation'] === ['outcome' => 'confirmed', 'state' => 'running']
        && in_array('confirmed', Payments::SIMULATION_OUTCOMES, true));

    $payment = $payitgo->payments->create(['order_id' => 'ORD-10', 'amount' => 45.5, 'currency' => 'eur']);
    check('payments->create sends the price in its currency, its amount as a string', $last()['body'] === ['order_id' => 'ORD-10', 'amount' => '45.5', 'currency' => 'eur']);
    check('payments->create: the payment\'s figures are in its currency', $payment['currency'] === 'EUR' && $payment['amount'] === '45.5'
        && $payment['amount_due'] === '45.5');
    $payment = $payitgo->payments->create(['order_id' => 'ORD-11', 'amount' => '10']);
    check('payments->create: without a currency the payment is in dollars', $last()['body'] === ['order_id' => 'ORD-11', 'amount' => '10']
        && $payment['currency'] === 'USD' && $payment['amount'] === '10');
    $e = apiError(fn () => $payitgo->payments->create(['order_id' => 'ORD-12', 'amount' => 0]));
    check('payments->create: an amount of 0 is a 400', $last()['body'] === ['order_id' => 'ORD-12', 'amount' => '0'] && $e !== null
        && $e->status === 400 && $e->getMessage() === 'amount must be > 0');
    $e = apiError(fn () => $payitgo->payments->create(['order_id' => 'ORD-13', 'amount' => '45.50', 'currency' => 'EURO']));
    check('payments->create: a code that is no currency is a 400', $e !== null && $e->status === 400
        && $e->getMessage() === 'currency must be the ISO 4217 code of a currency (USD, EUR, GBP, NGN…)');
    $e = apiError(fn () => $payitgo->payments->create(['order_id' => 'ORD-14', 'amount' => '5000', 'currency' => 'XAF']));
    check('payments->create: no exchange rate is a 503', $e !== null && $e->status === 503
        && $e->getMessage() === 'exchange rate unavailable for currency: try again shortly');

    $tokens = $payitgo->tokens->list();
    check('tokens->list lists every token', $last()['method'] === 'GET' && $last()['path'] === '/v1/tokens' && $last()['query'] === ''
        && $tokens[0]['token'] === 'USDT_BEP20' && $last()['reply']['currency'] === 'USD');
    $tokens = $payitgo->tokens->list('49.90');
    check('tokens->list with an amount asks for its options', $last()['query'] === 'amount=49.90' && $tokens[0]['amount_due'] === '40.43');
    $tokens = $payitgo->tokens->list(49.9, 'eur');
    check('tokens->list with a currency lists the figures in it', $last()['query'] === 'amount=49.9&currency=eur'
        && $last()['reply']['currency'] === 'EUR' && $tokens[0]['minimum'] === '1');

    // Payout requests, one by one.
    $item = $payitgo->payouts->request(['reference' => 'WD 77/a', 'asset' => 'USDT_TRC20', 'address' => 'TR7mNkucnhLZoJQLLJ6DR88jpLQufWxUJi',
        'amount' => 120, 'name' => 'Ana Silva', 'note' => 'withdrawal']);
    $req = $last();
    check('payouts->request posts the request with the key, its amount as a string', $req['method'] === 'POST' && $req['path'] === '/v1/payouts'
        && $req['headers']['authorization'] === 'Bearer ' . KEY && $req['headers']['content-type'] === 'application/json'
        && $req['body'] === ['reference' => 'WD 77/a', 'asset' => 'USDT_TRC20', 'address' => 'TR7mNkucnhLZoJQLLJ6DR88jpLQufWxUJi',
            'amount' => '120', 'name' => 'Ana Silva', 'note' => 'withdrawal']);
    check('payouts->request returns the new payment (201), checked and priced', $req['status'] === 201 && $item === $req['reply']['item']
        && $item['id'] === 'po_1' && $item['batch_id'] === 'inbox' && $item['status'] === 'ready' && $item['debit'] === '126.348213'
        && $item['recipient'] === ['name' => 'Ana Silva', 'note' => 'withdrawal'] && $item['errors'] === []);
    $again = $payitgo->payouts->request(['reference' => 'WD 77/a', 'asset' => 'USDT_TRC20', 'address' => 'TR7mNkucnhLZoJQLLJ6DR88jpLQufWxUJi', 'amount' => '120']);
    check('payouts->request is safe to retry: its reference returns that payment (200)', $last()['status'] === 200 && $again === $item);
    $invalid = $payitgo->payouts->request(['reference' => 'WD-78', 'asset' => 'usdt_trc20', 'address' => '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed', 'amount' => 12.5]);
    check('payouts->request: an invalid request is kept, with its errors', $last()['status'] === 201 && $last()['body']['amount'] === '12.5'
        && $invalid['status'] === 'invalid' && $invalid['errors'][0]['code'] === 'invalid_address' && $invalid['errors'][0]['field'] === 'address');
    $third = $payitgo->payouts->request(['reference' => 'WD-79', 'asset' => 'USDT_TRC20', 'address' => 'TLa2f6VPqDgRE67v1736s7bJ8Ray5wYjU7', 'amount' => '30']);
    $e = apiError(fn () => $store->payouts->request(['asset' => 'USDT_TRC20', 'address' => 'TR7mNkucnhLZoJQLLJ6DR88jpLQufWxUJi', 'amount' => '1']));
    check('payouts->request: a key without payouts is a 403', $e !== null && $e->status === 403
        && $e->getMessage() === 'this api key cannot make payouts: turn payouts on for it in the dashboard');

    $got = $payitgo->payouts->retrieve('po_1');
    $req = $last();
    check('payouts->retrieve reads a payment by its id', $req['method'] === 'GET' && $req['path'] === '/v1/payouts/po_1' && $req['body'] === null
        && $req['query'] === '' && $got === $item);
    $e = apiError(fn () => $payitgo->payouts->retrieve('no such/id'));
    check('payouts->retrieve escapes the id; an unknown one is a 404', $last()['path'] === '/v1/payouts/no%20such%2Fid'
        && $e !== null && $e->status === 404 && $e->getMessage() === 'not found');
    $got = $payitgo->payouts->retrieveByReference('WD 77/a');
    $req = $last();
    check('payouts->retrieveByReference reads a payment by your reference', $req['method'] === 'GET' && $req['path'] === '/v1/payouts'
        && $req['query'] === 'reference=WD+77%2Fa' && $got === $item);

    $inbox = $payitgo->payouts->inbox();
    $req = $last();
    check('payouts->inbox reads the open list', $req['method'] === 'GET' && $req['path'] === '/v1/payouts/inbox' && $req['query'] === '');
    check('payouts->inbox returns the whole inbox', $inbox === $req['reply'] && $inbox['inbox']['inbox'] === true && $inbox['inbox']['open'] === true
        && $inbox['inbox']['counts']['ready'] === 2 && $inbox['inbox']['counts']['invalid'] === 1 && $inbox['total'] === 3
        && count($inbox['items']) === 3 && $inbox['limit'] === 50 && $inbox['offset'] === 0
        && $inbox['coverage'][0]['asset'] === 'USDT_TRC20' && $inbox['coverage'][0]['needed'] === '162.696426'
        && $inbox['coverage'][0]['top_up'] === '162.696426' && $inbox['coverage'][0]['address'] === 'TMkq7dZ3vN8xR2pB6cH9sJ4fL1wY5tGaQe');
    $inbox = $payitgo->payouts->inbox(['status' => ['ready', 'invalid'], 'limit' => 2, 'offset' => 0, 'store_id' => null]);
    check('payouts->inbox sends a list of statuses comma-separated, and the page', $last()['query'] === 'status=ready%2Cinvalid&limit=2&offset=0'
        && count($inbox['items']) === 2 && $inbox['total'] === 3 && $inbox['limit'] === 2);
    $inbox = $payitgo->payouts->inbox(['status' => 'invalid']);
    check('payouts->inbox filters on one status', $last()['query'] === 'status=invalid' && $inbox['items'][0]['id'] === $invalid['id']);

    $removed = $payitgo->payouts->cancel($third['id']);
    $req = $last();
    check('payouts->cancel deletes a request from the open list', $req['method'] === 'DELETE' && $req['path'] === '/v1/payouts/' . $third['id']
        && $req['body'] === null && $removed === $req['reply']['item'] && $removed['status'] === 'canceled');
    check('payouts->cancel: a removed request keeps its record, out of the list, its reference given back', $removed['cancel_reason'] === 'removed'
        && $removed['released_reference'] === 'WD-79' && !isset($removed['reference']) && !isset($removed['batch_id'])
        && $removed['canceled_by'] === 'key_1' && $removed['canceled_at'] === $removed['updated_at']
        && $payitgo->payouts->retrieve($third['id']) === $removed);

    file_put_contents($log, '');
    try {
        $payitgo->payouts->send([]);
        check('payouts->send([]) refuses an empty list of ids', false);
    } catch (InvalidArgumentException $e) {
        check('payouts->send([]) refuses an empty list of ids, sending nothing', file_get_contents($log) === ''
            && $e->getMessage() === 'payitgo: no ids given: pass null to send every valid request');
    }
    $e = apiError(fn () => $payitgo->payouts->send([$invalid['id']]));
    check('payouts->send: an id that is no valid request is a 400', $last()['body'] === ['ids' => [$invalid['id']]] && $e !== null && $e->status === 400
        && $e->getMessage() === 'payout request ' . $invalid['id'] . ' is not a valid request waiting in your list');
    $sent = $payitgo->payouts->send(['po_1']);
    $req = $last();
    check('payouts->send posts the ids', $req['method'] === 'POST' && $req['path'] === '/v1/payouts/send' && $req['body'] === ['ids' => ['po_1']]);
    check('payouts->send returns the batch they went into', $sent === $req['reply']['batch'] && $sent['source'] === 'requests'
        && $sent['inbox'] === true && $sent['open'] === false && $sent['status'] === 'awaiting_funds' && $sent['counts']['ready'] === 1
        && $sent['waiting']['reason'] === 'funds' && $sent['waiting']['shortfall'][0]['amount'] === '126.348213');
    $e = apiError(fn () => $payitgo->payouts->send());
    check('payouts->send() sends no body (every valid request)', $last()['method'] === 'POST' && $last()['path'] === '/v1/payouts/send'
        && $last()['body'] === null && $e !== null && $e->status === 400 && $e->getMessage() === 'no valid payout request to send');
    check('payouts->retrieve: a sent request is in its batch', $payitgo->payouts->retrieve('po_1')['batch_id'] === $sent['id']);
    $canceled = $payitgo->payouts->cancel('po_1');
    check('payouts->cancel cancels a sent request', $canceled['status'] === 'canceled' && $canceled['cancel_reason'] === 'canceled'
        && $canceled['batch_id'] === $sent['id'] && $canceled['released_reference'] === 'WD 77/a'
        && $payitgo->payouts->retrieve('po_1')['status'] === 'canceled');
    check('payouts->cancel is safe to retry: a canceled payment is answered as it is', $payitgo->payouts->cancel('po_1') === $canceled
        && $last()['status'] === 200);

    $quote = $payitgo->payouts->quote(['fee_paid_by' => 'recipient', 'items' => [
        ['asset' => 'USDT_TRC20', 'address' => 'TR7mNkucnhLZoJQLLJ6DR88jpLQufWxUJi', 'amount' => 120],
        ['asset' => 'USDT_TRC20', 'address' => 'nope', 'amount' => '5'],
    ]]);
    $req = $last();
    check('payouts->quote posts the payments, their amounts as strings', $req['method'] === 'POST' && $req['path'] === '/v1/payout-quotes'
        && $req['body'] === ['fee_paid_by' => 'recipient', 'items' => [
            ['asset' => 'USDT_TRC20', 'address' => 'TR7mNkucnhLZoJQLLJ6DR88jpLQufWxUJi', 'amount' => '120'],
            ['asset' => 'USDT_TRC20', 'address' => 'nope', 'amount' => '5'],
        ]]);
    check('payouts->quote returns the quote and the payments', $quote === $req['reply'] && array_keys($quote) === ['quote', 'items']
        && $quote['quote']['fee_paid_by'] === 'recipient' && $quote['quote']['counts']['ready'] === 1 && $quote['quote']['counts']['invalid'] === 1
        && $quote['items'][0]['debit'] === '126.348213' && !isset($quote['items'][0]['id']) && $quote['items'][1]['status'] === 'invalid');
    $e = apiError(fn () => $payitgo->payouts->quote(['items' => []]));
    check('payouts->quote: no payment is a 400', $e !== null && $e->status === 400 && $e->getMessage() === 'quote between 1 and 100 payments');

    // Batches.
    $rows = [
        ['reference' => 'payroll-ana', 'asset' => 'USDT_TRC20', 'address' => 'TR7mNkucnhLZoJQLLJ6DR88jpLQufWxUJi', 'amount' => 120, 'name' => 'Ana Silva'],
        ['reference' => 'payroll-ben', 'asset' => 'USDT_TRC20', 'address' => 'TLa2f6VPqDgRE67v1736s7bJ8Ray5wYjU7', 'amount' => '250'],
    ];
    $batch = $payitgo->payoutBatches->create(['reference' => 'payroll-2026-10', 'name' => 'October payroll', 'webhook_url' => 'https://shop.example/payouts', 'items' => $rows]);
    $req = $last();
    check('payoutBatches->create posts the batch, its amounts as strings', $req['method'] === 'POST' && $req['path'] === '/v1/payout-batches'
        && $req['headers']['authorization'] === 'Bearer ' . KEY && $req['body']['reference'] === 'payroll-2026-10'
        && $req['body']['name'] === 'October payroll' && $req['body']['webhook_url'] === 'https://shop.example/payouts'
        && $req['body']['items'][0]['amount'] === '120' && $req['body']['items'][1]['amount'] === '250' && $req['body']['items'][0]['name'] === 'Ana Silva');
    check('payoutBatches->create returns the new batch (201), quoted', $req['status'] === 201 && $batch === $req['reply']['batch']
        && $batch['status'] === 'quoted' && $batch['reference'] === 'payroll-2026-10' && $batch['counts']['ready'] === 2
        && $batch['total_usd'] === '382.696426' && $batch['totals'][0]['asset'] === 'USDT_TRC20' && $batch['livemode'] === false);
    $again = $payitgo->payoutBatches->create(['reference' => 'payroll-2026-10', 'items' => $rows]);
    check('payoutBatches->create is safe to retry: its reference returns that batch (200)', $last()['status'] === 200 && $again['id'] === $batch['id']
        && $again['counts']['total'] === 2);
    $e = apiError(fn () => $store->payoutBatches->create(['items' => $rows]));
    check('payoutBatches->create: a key without payouts is a 403', $e !== null && $e->status === 403);

    $list = $payitgo->payoutBatches->list(['status' => 'quoted', 'limit' => 20, 'offset' => 0]);
    $req = $last();
    check('payoutBatches->list sends the filters', $req['method'] === 'GET' && $req['path'] === '/v1/payout-batches'
        && $req['query'] === 'status=quoted&limit=20&offset=0');
    check('payoutBatches->list returns the list object', $list === $req['reply'] && array_keys($list) === ['batches', 'total', 'limit', 'offset']
        && $list['batches'][0]['id'] === $batch['id'] && $list['total'] === 1 && $list['limit'] === 20);
    $all = $payitgo->payoutBatches->list();
    check('payoutBatches->list without filters', $last()['query'] === '' && $all['total'] === 2);

    $got = $payitgo->payoutBatches->retrieve($batch['id']);
    check('payoutBatches->retrieve reads the batch', $last()['method'] === 'GET' && $last()['path'] === '/v1/payout-batches/' . $batch['id']
        && $got === $last()['reply']['batch'] && $got['id'] === $batch['id']);
    $e = apiError(fn () => $payitgo->payoutBatches->retrieve('pb 9/x'));
    check('payoutBatches->retrieve escapes the id; an unknown one is a 404', $last()['path'] === '/v1/payout-batches/pb%209%2Fx'
        && $e !== null && $e->status === 404);

    $grown = $payitgo->payoutBatches->addItems($batch['id'], [
        ['reference' => 'payroll-chen', 'asset' => 'USDT_TRC20', 'address' => '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed', 'amount' => 75.5],
    ]);
    $req = $last();
    check('payoutBatches->addItems posts the payments, their amounts as strings', $req['method'] === 'POST'
        && $req['path'] === '/v1/payout-batches/' . $batch['id'] . '/items'
        && $req['body'] === ['items' => [['reference' => 'payroll-chen', 'asset' => 'USDT_TRC20', 'address' => '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed', 'amount' => '75.5']]]);
    check('payoutBatches->addItems returns the batch', $grown === $req['reply']['batch'] && $grown['counts']['total'] === 3 && $grown['counts']['invalid'] === 1);

    $items = $payitgo->payoutBatches->items($batch['id']);
    $req = $last();
    check('payoutBatches->items lists the batch\'s payments', $req['method'] === 'GET' && $req['path'] === '/v1/payout-batches/' . $batch['id'] . '/items'
        && $req['query'] === '' && $items === $req['reply'] && array_keys($items) === ['items', 'total', 'limit', 'offset']
        && $items['total'] === 3 && $items['items'][2]['row'] === 3 && $items['items'][2]['reference'] === 'payroll-chen');
    $items = $payitgo->payoutBatches->items($batch['id'], ['status' => ['invalid', 'failed'], 'limit' => 10]);
    check('payoutBatches->items sends a list of statuses comma-separated', $last()['query'] === 'status=invalid%2Cfailed&limit=10'
        && $items['total'] === 1 && $items['items'][0]['status'] === 'invalid');
    $items = $payitgo->payoutBatches->items($batch['id'], ['status' => 'sent,failed']);
    check('payoutBatches->items passes a comma-separated string as it is', $last()['query'] === 'status=sent%2Cfailed' && $items['total'] === 0);

    $requoted = $payitgo->payoutBatches->quote($batch['id']);
    $req = $last();
    check('payoutBatches->quote prices the batch again', $req['method'] === 'POST' && $req['path'] === '/v1/payout-batches/' . $batch['id'] . '/quote'
        && $req['body'] === null && $requoted === $req['reply']['batch'] && $requoted['quote_expires_at'] === '2026-10-20T11:15:01Z');

    $submitted = $payitgo->payoutBatches->submit($batch['id']);
    $req = $last();
    check('payoutBatches->submit submits the batch', $req['method'] === 'POST' && $req['path'] === '/v1/payout-batches/' . $batch['id'] . '/submit'
        && $req['body'] === null && $submitted === $req['reply']['batch'] && $submitted['status'] === 'awaiting_funds'
        && $submitted['waiting']['shortfall'][0] === ['asset' => 'USDT_TRC20', 'network' => 'Tron (TRC-20)', 'amount' => '382.696426',
            'address' => 'TMkq7dZ3vN8xR2pB6cH9sJ4fL1wY5tGaQe']);
    $e = apiError(fn () => $payitgo->payoutBatches->submit($batch['id']));
    check('payoutBatches->submit: a submitted batch is a 409', $e !== null && $e->status === 409 && $e->getMessage() === 'the batch was submitted already');
    $e = apiError(fn () => $payitgo->payoutBatches->addItems($batch['id'], $rows));
    check('payoutBatches->addItems: a submitted batch is a 409', $e !== null && $e->status === 409
        && $e->getMessage() === 'a submitted batch cannot change: cancel it and make another');

    $canceled = $payitgo->payoutBatches->cancel($batch['id']);
    $req = $last();
    check('payoutBatches->cancel cancels the batch', $req['method'] === 'POST' && $req['path'] === '/v1/payout-batches/' . $batch['id'] . '/cancel'
        && $req['body'] === null && $canceled === $req['reply']['batch'] && $canceled['status'] === 'canceled');
    $e = apiError(fn () => $payitgo->payoutBatches->cancel($batch['id']));
    check('payoutBatches->cancel: nothing left to cancel is a 409', $e !== null && $e->status === 409 && $e->getMessage() === 'the batch is canceled');

    // Claim links: no address, its recipient chooses.
    $claim = $payitgo->payouts->request(['reference' => 'WD-90', 'asset' => 'USDT_TRC20', 'amount' => 50, 'email' => 'ana@example.com',
        'claim_assets' => ['USDT_BEP20', 'BNB']]);
    $req = $last();
    check('payouts->request sends a claim link\'s keys as given', $req['body'] === ['reference' => 'WD-90', 'asset' => 'USDT_TRC20', 'amount' => '50',
        'email' => 'ana@example.com', 'claim_assets' => ['USDT_BEP20', 'BNB']]);
    check('payouts->request: claim_assets make a claim link, emailed with an email', $req['status'] === 201 && $claim === $req['reply']['item']
        && $claim['address'] === '' && $claim['status'] === 'ready' && $claim['claim'] === ['email' => 'ana@example.com',
            'assets' => ['USDT_BEP20', 'BNB'], 'emailed' => 0, 'extended' => 0, 'url' => 'https://pay.example/claim/c' . $claim['id']]);
    $shared = $payitgo->payouts->request(['reference' => 'WD-91', 'asset' => 'USDT_BEP20', 'amount' => '20', 'claim' => true]);
    check('payouts->request: claim => true makes a link you share', $last()['body']['claim'] === true && $shared['status'] === 'ready'
        && !isset($shared['claim']['email']) && $shared['claim']['assets'] === [] && $shared['claim']['url'] === 'https://pay.example/claim/c' . $shared['id']);
    $bad = $payitgo->payouts->request(['asset' => 'USDT_TRC20', 'amount' => '5', 'email' => 'ana@example.com']);
    check('payouts->request: an email alone is no claim link, its missing address invalid', $last()['body'] === ['asset' => 'USDT_TRC20',
            'amount' => '5', 'email' => 'ana@example.com'] && $bad['status'] === 'invalid' && !isset($bad['claim'])
        && $bad['errors'][0]['code'] === 'invalid_address' && $bad['errors'][0]['field'] === 'address');
    $bad = $payitgo->payouts->request(['asset' => 'USDT_TRC20', 'amount' => '5', 'claim' => true, 'email' => 'not-an-email']);
    check('payouts->request: a claim link with a bad email is invalid_email', $bad['status'] === 'invalid'
        && $bad['errors'][0]['code'] === 'invalid_email' && $bad['errors'][0]['field'] === 'email');
    $bad = $payitgo->payouts->request(['asset' => 'USDT_TRC20', 'amount' => '5', 'claim' => true, 'address' => 'TR7mNkucnhLZoJQLLJ6DR88jpLQufWxUJi']);
    check('payouts->request: a claim link with an address is invalid', $bad['status'] === 'invalid'
        && $bad['errors'][0]['message'] === 'a claim link has no address: its recipient chooses one');

    // Converted payments: paid from another coin, converted on their way.
    $prizes = $payitgo->payoutBatches->create(['reference' => 'prizes-2026-10', 'convert' => 'convert', 'convert_from' => 'USDT_TRC20', 'items' => [
        ['reference' => 'prize-1', 'asset' => 'BNB', 'address' => '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed', 'amount' => '0.5'],
        ['reference' => 'prize-2', 'asset' => 'USDT_TRC20', 'address' => 'TR7mNkucnhLZoJQLLJ6DR88jpLQufWxUJi', 'amount' => 20],
        ['reference' => 'prize-3', 'asset' => 'USDT_TRC20', 'amount' => '10', 'email' => 'ben@example.com', 'claim' => true],
    ]]);
    $req = $last();
    check('payoutBatches->create sends convert and convert_from as given', $req['body']['convert'] === 'convert'
        && $req['body']['convert_from'] === 'USDT_TRC20' && $req['body']['items'][0] === ['reference' => 'prize-1', 'asset' => 'BNB',
            'address' => '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed', 'amount' => '0.5'] && $req['body']['items'][1]['amount'] === '20');
    check('payoutBatches->create: the batch carries its conversion and the new counts', $prizes['convert'] === 'convert'
        && $prizes['convert_from'] === 'USDT_TRC20' && $prizes['counts']['ready'] === 3 && $prizes['counts']['claimable'] === 0
        && $prizes['counts']['expired'] === 0);
    $rows = $payitgo->payoutBatches->items($prizes['id'])['items'];
    check('a converted payment is in the wallet\'s coin, with its conversion', $rows[0]['asset'] === 'USDT_TRC20' && $rows[0]['amount'] === '300'
        && $rows[0]['address'] === '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed'
        && $rows[0]['fee'] === '9.348213' && $rows[0]['debit'] === '309.348213' && $rows[0]['convert'] === ['to' => 'BNB', 'ticker' => 'BNB',
            'network' => 'BNB Smart Chain (BEP-20)', 'to_amount' => '0.5', 'estimate' => '0.4975']);
    check('a payment in the wallet\'s coin, or a claim link, is not converted', !isset($rows[1]['convert']) && $rows[1]['fee'] === '6.348213'
        && $rows[1]['amount'] === '20' && !isset($rows[2]['convert']) && $rows[2]['claim']['email'] === 'ben@example.com');
    $plain = $payitgo->payoutBatches->create(['items' => [['asset' => 'USDT_TRC20', 'address' => 'TR7mNkucnhLZoJQLLJ6DR88jpLQufWxUJi', 'amount' => '1']]]);
    check('payoutBatches->create sends no convert unless given', !array_key_exists('convert', $last()['body']) && $plain['convert'] === 'same_network'
        && !isset($plain['convert_from']));
    $e = apiError(fn () => $payitgo->payoutBatches->create(['convert' => 'swap', 'items' => $rows]));
    check('payoutBatches->create: an unknown convert is a 400', $e !== null && $e->status === 400 && $e->getMessage() === 'convert must be same_network or convert');
    $quote = $payitgo->payouts->quote(['convert' => 'convert', 'convert_from' => 'USDT_BEP20', 'items' => [
        ['asset' => 'TRX', 'address' => 'TR7mNkucnhLZoJQLLJ6DR88jpLQufWxUJi', 'amount' => 100],
    ]]);
    check('payouts->quote sends convert as given; its payments carry their conversion', $last()['body'] === ['convert' => 'convert',
            'convert_from' => 'USDT_BEP20', 'items' => [['asset' => 'TRX', 'address' => 'TR7mNkucnhLZoJQLLJ6DR88jpLQufWxUJi', 'amount' => '100']]]
        && $quote['items'][0]['asset'] === 'USDT_BEP20' && $quote['items'][0]['amount'] === '30'
        && $quote['items'][0]['convert']['to'] === 'TRX' && $quote['items'][0]['convert']['to_amount'] === '100');

    // The payout wallet.
    $wallets = $payitgo->payoutWallets->list();
    $req = $last();
    check('payoutWallets->list reads the wallets', $req['method'] === 'GET' && $req['path'] === '/v1/payout-wallets' && $req['body'] === null
        && $wallets === $req['reply']['wallets'] && array_is_list($wallets) && $wallets[0]['chain'] === 'tron' && $wallets[1]['chain'] === 'bsc'
        && $wallets[0]['assets'][0] === ['asset' => 'USDT_TRC20', 'ticker' => 'USDT', 'balance' => '0', 'reserved' => '0', 'spendable' => '0', 'needed' => '0']);
    $wallets = $payitgo->payoutWallets->simulateTopUp('USDT_TRC20', 1000);
    $req = $last();
    check('payoutWallets->simulateTopUp posts the amount as a string', $req['method'] === 'POST'
        && $req['path'] === '/v1/payout-wallets/USDT_TRC20/simulate-top-up' && $req['body'] === ['amount' => '1000']);
    check('payoutWallets->simulateTopUp returns the wallets', $wallets === $req['reply']['wallets'] && $wallets[0]['assets'][0]['balance'] === '1000'
        && $wallets[0]['assets'][0]['spendable'] === '1000');
    $wallets = $payitgo->payoutWallets->simulateTopUp('usdt_bep20', '250.5');
    check('payoutWallets->simulateTopUp: another coin', $last()['path'] === '/v1/payout-wallets/usdt_bep20/simulate-top-up'
        && $last()['body'] === ['amount' => '250.5'] && $wallets[1]['assets'][0]['balance'] === '250.5');
    $e = apiError(fn () => $live->payoutWallets->simulateTopUp('USDT_TRC20', '10'));
    check('payoutWallets->simulateTopUp: a live key is a 400', $e !== null && $e->status === 400
        && $e->getMessage() === 'only a test wallet is topped up on paper: use a test key');

    // Removing several requests at once.
    $a = $payitgo->payouts->request(['reference' => 'WD-80', 'asset' => 'USDT_TRC20', 'address' => 'TR7mNkucnhLZoJQLLJ6DR88jpLQufWxUJi', 'amount' => '15',
        'webhook_url' => 'https://shop.example/withdrawals/ipn']);
    check('payouts->request sends its webhook_url', $last()['body']['webhook_url'] === 'https://shop.example/withdrawals/ipn'
        && $a['webhook_url'] === 'https://shop.example/withdrawals/ipn');
    $b = $payitgo->payouts->request(['reference' => 'WD-81', 'asset' => 'USDT_TRC20', 'address' => 'TLa2f6VPqDgRE67v1736s7bJ8Ray5wYjU7', 'amount' => '25']);
    $away = $payitgo->payouts->send([$b['id']]);
    file_put_contents($log, '');
    try {
        $payitgo->payouts->remove([]);
        check('payouts->remove([]) refuses an empty list of ids', false);
    } catch (InvalidArgumentException $e) {
        check('payouts->remove([]) refuses an empty list of ids, sending nothing', file_get_contents($log) === ''
            && $e->getMessage() === 'payitgo: no ids given: pass the ids of the payout requests to remove');
    }
    $results = $payitgo->payouts->remove([$a['id'], $a['id'], 'po_1', $third['id'], 'no such', $b['id']]);
    $req = $last();
    check('payouts->remove posts the ids as given', $req['method'] === 'POST' && $req['path'] === '/v1/payouts/remove' && $req['query'] === ''
        && $req['headers']['authorization'] === 'Bearer ' . KEY && $req['headers']['content-type'] === 'application/json'
        && $req['body'] === ['ids' => [$a['id'], $a['id'], 'po_1', $third['id'], 'no such', $b['id']]]);
    check('payouts->remove returns a result per distinct id, in the order given', $results === $req['reply']['results'] && array_is_list($results)
        && array_column($results, 'id') === [$a['id'], 'po_1', $third['id'], 'no such', $b['id']]);
    check('payouts->remove: a request in the list is removed, its record kept', $results[0]['item']['status'] === 'canceled'
        && $results[0]['item']['cancel_reason'] === 'removed' && $results[0]['item']['released_reference'] === 'WD-80'
        && !isset($results[0]['item']['batch_id']) && !isset($results[0]['item']['reference']) && !isset($results[0]['error'])
        && $payitgo->payouts->retrieve($a['id']) === $results[0]['item']);
    check('payouts->remove: a request removed already is answered as it is', $results[2]['item'] === $removed && !isset($results[2]['error']));
    check('payouts->remove: an unknown id is a 404 result', $results[3]['error'] === ['status' => 404, 'message' => 'not found'] && !isset($results[3]['item']));
    check('payouts->remove: a payment canceled otherwise, or ended, is a 409 result', $results[1]['error'] === ['status' => 409, 'message' => 'the payment is canceled']);
    check('payouts->remove: a request sent from the list already is a 409 result', $results[4]['error'] === ['status' => 409,
        'message' => 'not in your list of payout requests (sent already): cancel it with DELETE /v1/payouts/' . $b['id']]
        && $payitgo->payouts->retrieve($b['id'])['batch_id'] === $away['id']);
    $again = $payitgo->payouts->remove([$a['id']]);
    check('payouts->remove is safe to retry', $again === [['id' => $a['id'], 'item' => $results[0]['item']]]);
    $e = apiError(fn () => $store->payouts->remove([$a['id']]));
    check('payouts->remove: a key without payouts is a 403', $e !== null && $e->status === 403);

    // A reference given back: read by it, then used again.
    $got = $payitgo->payouts->retrieveByReference('WD-80');
    check('payouts->retrieveByReference falls back to the latest payment that gave it back', $last()['query'] === 'reference=WD-80'
        && $got === $results[0]['item']);
    $renewed = $payitgo->payouts->request(['reference' => 'WD-80', 'asset' => 'USDT_TRC20', 'address' => 'TR7mNkucnhLZoJQLLJ6DR88jpLQufWxUJi', 'amount' => '15']);
    check('a reference given back makes a new payment, read by it from then on', $last()['status'] === 201 && $renewed['id'] !== $a['id']
        && $renewed['reference'] === 'WD-80' && $payitgo->payouts->retrieveByReference('WD-80') === $renewed);
    file_put_contents($log, '');
    try {
        $payitgo->payouts->retrieveByReference('');
        check('payouts->retrieveByReference(\'\') refuses an empty reference', false);
    } catch (InvalidArgumentException $e) {
        check('payouts->retrieveByReference(\'\') refuses an empty reference, sending nothing', file_get_contents($log) === ''
            && $e->getMessage() === 'payitgo: reference is required');
    }

    // Listing every payment, for resync.
    $list = $payitgo->payouts->list(['status' => ['canceled', 'ready'], 'updated_since' => new DateTimeImmutable('2026-10-20 11:20:00', new DateTimeZone('+02:00')),
        'limit' => 2, 'cursor' => null]);
    $req = $last();
    check('payouts->list sends the statuses comma-separated and updated_since as RFC 3339 in UTC', $req['method'] === 'GET'
        && $req['path'] === '/v1/payouts' && $req['body'] === null && $req['headers']['authorization'] === 'Bearer ' . KEY
        && $req['query'] === 'status=canceled%2Cready&updated_since=2026-10-20T09%3A20%3A00Z&limit=2');
    check('payouts->list returns the page, oldest change first', $list === $req['reply'] && array_keys($list) === ['items', 'has_more', 'next_cursor']
        && array_column($list['items'], 'id') === [$third['id'], 'po_1'] && $list['has_more'] === true && $list['next_cursor'] !== '');
    $next = $payitgo->payouts->list(['status' => 'canceled,ready', 'cursor' => $list['next_cursor'], 'limit' => 2]);
    check('payouts->list reads on from next_cursor', $last()['query'] === 'status=canceled%2Cready&cursor=' . urlencode($list['next_cursor']) . '&limit=2'
        && array_column($next['items'], 'id') === [$b['id'], $a['id']] && $next['has_more'] === false && isset($next['next_cursor']));
    $none = $payitgo->payouts->list(['cursor' => $next['next_cursor']]);
    check('payouts->list: nothing changed since, an empty page without next_cursor', $none === ['items' => [], 'has_more' => false]);
    $all = $payitgo->payouts->list();
    check('payouts->list without filters lists every payment, removed ones too', $last()['query'] === '' && $all['has_more'] === false
        && in_array($third['id'], array_column($all['items'], 'id'), true) && end($all['items'])['id'] === $a['id']);
    $since = $payitgo->payouts->list(['updated_since' => '2026-10-20T09:20:05Z']);
    check('payouts->list sends a string updated_since as it is', $last()['query'] === 'updated_since=2026-10-20T09%3A20%3A05Z'
        && array_column($since['items'], 'id') === [$a['id']]);
    $payitgo->payouts->list(['updated_since' => new DateTime('2026-10-20T09:20:04.5Z')]);
    check('payouts->list keeps the fraction of a second', $last()['query'] === 'updated_since=2026-10-20T09%3A20%3A04.500000Z');
    $e = apiError(fn () => $payitgo->payouts->list(['updated_since' => 'yesterday']));
    check('payouts->list: an updated_since that is no RFC 3339 time is a 400', $e !== null && $e->status === 400
        && $e->getMessage() === 'updated_since must be an RFC 3339 time, such as 2026-10-20T09:00:00Z');
    $e = apiError(fn () => $payitgo->payouts->list(['cursor' => 'nope']));
    check('payouts->list: a cursor it did not give is a 400', $e !== null && $e->status === 400 && $e->getMessage() === 'cursor is not one this API gave');
    $e = apiError(fn () => $payitgo->payouts->list(['limit' => 500]));
    check('payouts->list: a limit over 200 is a 400', $e !== null && $e->status === 400 && $e->getMessage() === 'limit must be 1 to 200');

    // Replaying events.
    check('the client has events', $payitgo->events instanceof Events);
    $replayed = $payitgo->events->list();
    $req = $last();
    check('events->list reads the mode\'s events with the key', $req['method'] === 'GET' && $req['path'] === '/v1/events' && $req['query'] === ''
        && $req['body'] === null && $req['headers']['authorization'] === 'Bearer ' . KEY);
    check('events->list returns the events, oldest first, as their webhooks carried them', $replayed === $req['reply']
        && array_keys($replayed) === ['events', 'has_more'] && $replayed['has_more'] === false
        && array_column($replayed['events'], 'type') === ['payout_item.canceled', 'payout_item.processing', 'payout_item.canceled',
            'payout_item.processing', 'payout_item.canceled']
        && array_keys($replayed['events'][0]) === ['id', 'type', 'livemode', 'created_at', 'data']
        && array_diff(array_column($replayed['events'], 'type'), Webhooks::EVENTS) === []);
    check('events->list: each event carries the payment as it was then', $replayed['events'][0]['data']['id'] === $third['id']
        && $replayed['events'][0]['data']['cancel_reason'] === 'removed' && $replayed['events'][0]['livemode'] === false
        && $replayed['events'][1]['data']['id'] === 'po_1' && $replayed['events'][1]['data']['batch_id'] === $sent['id']
        && $replayed['events'][1]['data']['status'] === 'ready'
        && $replayed['events'][2]['data']['cancel_reason'] === 'canceled' && $replayed['events'][2]['data']['canceled_by'] === 'key_1'
        && $replayed['events'][4]['data'] === $results[0]['item'] && $replayed['events'][4]['created_at'] === $results[0]['item']['canceled_at']);
    $page = $payitgo->events->list(['type' => ['payout_item.canceled', 'payment.*'], 'after' => $replayed['events'][0]['id'], 'limit' => 1]);
    check('events->list sends the types comma-separated, after and limit', $last()['query'] === 'type=payout_item.canceled%2Cpayment.%2A&after='
        . $replayed['events'][0]['id'] . '&limit=1' && $page['events'] === [$replayed['events'][2]] && $page['has_more'] === true);
    $page = $payitgo->events->list(['type' => 'payout_item.*', 'created_after' => new DateTimeImmutable('2026-10-20 11:20:03', new DateTimeZone('+02:00')),
        'after' => '']);
    check('events->list sends created_after as RFC 3339 in UTC', $last()['query'] === 'type=payout_item.%2A&created_after=2026-10-20T09%3A20%3A03Z'
        && $page['events'] === array_slice($replayed['events'], 3) && $page['has_more'] === false);
    $e = apiError(fn () => $store->events->list());
    check('events->list: a storefront\'s key is a 403', $e !== null && $e->status === 403
        && $e->getMessage() === "a storefront's key cannot read the account's events: use an account key");
    $e = apiError(fn () => $payitgo->events->list(['type' => 'Payout Item']));
    check('events->list: a type that is no event type is a 400', $e !== null && $e->status === 400
        && $e->getMessage() === 'type must be event types, such as payout_item.sent or payout_item.*');
    $e = apiError(fn () => $payitgo->events->list(['after' => 'evt_99']));
    check('events->list: an unknown after is a 400', $e !== null && $e->status === 400 && $e->getMessage() === 'after is not an event of this mode');
} catch (Throwable $e) {
    check('webhook: ' . $e::class . ': ' . $e->getMessage(), false);
} finally {
    proc_terminate($gateway);
    proc_close($gateway);
    @unlink($log);
    @unlink($state);
}

$withSecret = new Client(KEY, ['base_url' => 'http://x', 'webhook_secret' => SECRET]);
check('webhooks->constructEvent uses the client\'s secret', $withSecret->webhooks->constructEvent(BODY, HEADER, null, 0)['id'] === 'b8e1f80bd70ae0784c7855a4'
    && $withSecret->webhooks->constructEvent(BODY, $fresh)['id'] === 'b8e1f80bd70ae0784c7855a4');
$otherSecret = new Client(KEY, ['base_url' => 'http://x', 'webhook_secret' => OTHER_SECRET]);
check('webhooks->constructEvent: an explicit secret wins', rejects(fn () => $otherSecret->webhooks->constructEvent(BODY, HEADER, null, 0))
    && $otherSecret->webhooks->constructEvent(BODY, HEADER, SECRET, 0)['id'] === 'b8e1f80bd70ae0784c7855a4');
$noSecret = new Client(KEY, ['base_url' => 'http://x']);
try {
    $noSecret->webhooks->constructEvent(BODY, HEADER);
    check('webhooks->constructEvent without a secret', false);
} catch (SignatureVerificationException $e) {
    check('webhooks->constructEvent without a secret', str_contains($e->getMessage(), 'no webhook secret'));
}

foreach (array_unique($problems) as $problem) {
    check('no notice, warning or deprecation: ' . $problem, false);
}
echo $failures === 0 ? "ALL PASSED\n" : "$failures FAILED\n";

exit($failures === 0 ? 0 : 1);
