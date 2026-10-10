<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/../src/' . str_replace(['Payitgo\\', '\\'], ['', '/'], $class) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use Payitgo\Client;
use Payitgo\Exception\PayitgoException;
use Payitgo\Exception\SignatureVerificationException;
use Payitgo\Webhook;

// Signed by the gateway's own signer.
const SECRET = 'whsec_test_secret';
const HEADER = 't=1760000000,v1=6abd292fdf2ecc51779c161f5d4be2d47236f7a43fbaa9dd7093303904b91a7b';
const BODY = '{"id":"evt_test_123","type":"payment.confirmed","livemode":false,"created_at":"2026-10-09T12:00:00Z","data":{"id":"pay_123","order_id":"ORD-1","status":"confirmed","usd_amount":"10"}}';

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
check('rejects the wrong secret', rejects(fn () => Webhook::constructEvent(BODY, HEADER, 'whsec_other', 0)));
check('rejects a missing header', rejects(fn () => Webhook::constructEvent(BODY, null, SECRET, 0)));
check('rejects an old timestamp', rejects(fn () => Webhook::constructEvent(BODY, HEADER, SECRET)));
$now = time();
$fresh = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . BODY, SECRET);
check('accepts a fresh delivery', Webhook::constructEvent(BODY, $fresh, SECRET)['id'] === 'evt_test_123');

putenv('PAYITGO_BASE_URL');
try {
    new Client('pk_123', ['base_url' => 'http://x']);
    check('rejects a bad key', false);
} catch (InvalidArgumentException) {
    check('rejects a bad key', true);
}
try {
    new Client('sk_test_abc', ['base_url' => '']);
    check('requires a base URL', false);
} catch (InvalidArgumentException) {
    check('requires a base URL', true);
}
check('livemode from the key', (new Client('sk_live_abc', ['base_url' => 'http://x']))->livemode && !(new Client('sk_test_abc', ['base_url' => 'http://x']))->livemode);

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
$client = fn (string $key = 'sk_test_abc', array $options = []): Client => new Client($key, ['base_url' => "http://127.0.0.1:$port/", 'max_retries' => 0] + $options);
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
    $webhook = $payitgo->webhooks->get();
    $req = $last();
    check('webhooks->get reads the mode\'s webhook', $req['method'] === 'GET' && $req['path'] === '/v1/webhook' && $req['body'] === null
        && $req['headers']['authorization'] === 'Bearer sk_test_abc' && $webhook['url'] === null && str_starts_with($webhook['secret'], 'whsec_'));

    $e = apiError(fn () => $payitgo->webhooks->test());
    check('webhooks->test without a URL is a 400', $e !== null && $e->status === 400 && $e->getMessage() === 'no webhook url is set for this mode: set one first');

    $webhook = $payitgo->webhooks->set($url);
    $req = $last();
    check('webhooks->set puts the URL', $req['method'] === 'PUT' && $req['path'] === '/v1/webhook'
        && $req['headers']['content-type'] === 'application/json' && $req['body'] === ['url' => $url] && $webhook['url'] === $url);

    $delivery = $payitgo->webhooks->test();
    $req = $last();
    check('webhooks->test queues a ping', $req['method'] === 'POST' && $req['path'] === '/v1/webhook/test'
        && $req['headers']['authorization'] === 'Bearer sk_test_abc' && $delivery['event_type'] === 'ping'
        && $delivery['status'] === 'pending' && $delivery['target'] === 'account' && $delivery['url'] === $url);

    $removed = $payitgo->webhooks->remove();
    $req = $last();
    check('webhooks->remove deletes the URL and keeps the secret', $req['method'] === 'DELETE' && $req['path'] === '/v1/webhook'
        && $removed['url'] === null && $removed['secret'] === $webhook['secret']);

    $e = apiError(fn () => $payitgo->webhooks->set('http://shop.example/hook'));
    check('webhooks errors carry the message and status', $e !== null && $e->status === 400 && $e->getMessage() === 'url must use https');

    $store = $client('sk_test_store');
    $e = apiError(fn () => $store->webhooks->set($url));
    check('a storefront\'s key cannot set the webhook', $e !== null && $e->status === 403
        && $e->getMessage() === "a storefront's key cannot change the account's webhook: set webhook_url on its payments instead");
    check('a storefront\'s key reads it', $store->webhooks->get()['secret'] === $webhook['secret']);

    $payment = $payitgo->payments->create(['order_id' => 'ORD-1', 'amount_usd' => 10]);
    check('payments->create sends no webhook_url without one', $last()['body'] === ['order_id' => 'ORD-1', 'amount_usd' => '10'] && !isset($payment['webhook_url']));
    $payment = $payitgo->payments->create(['order_id' => 'ORD-2', 'amount_usd' => 10, 'webhook_url' => 'https://shop.example/own']);
    check('payments->create sends its own webhook_url', $last()['body']['webhook_url'] === 'https://shop.example/own'
        && $payment['webhook_url'] === 'https://shop.example/own');
    $withDefault = $client('sk_test_abc', ['webhook_url' => 'https://shop.example/default']);
    $payment = $withDefault->payments->create(['order_id' => 'ORD-3', 'amount_usd' => 10]);
    check('payments->create sends the client\'s webhook_url', $last()['body']['webhook_url'] === 'https://shop.example/default'
        && $payment['webhook_url'] === 'https://shop.example/default');
    $payment = $withDefault->payments->create(['order_id' => 'ORD-4', 'amount_usd' => 10, 'webhook_url' => 'https://shop.example/own']);
    check('payments->create: the call\'s webhook_url wins', $last()['body']['webhook_url'] === 'https://shop.example/own');

    $payment = $payitgo->payments->selectToken('pay_1', 'XRP');
    $req = $last();
    check('payments->selectToken posts the coin with the key', $req['method'] === 'POST' && $req['path'] === '/v1/payments/pay_1/token'
        && $req['headers']['authorization'] === 'Bearer sk_test_abc' && $req['headers']['content-type'] === 'application/json'
        && $req['body'] === ['token' => 'XRP']);
    check('payments->selectToken returns the whole payment', $payment === $req['reply'] && $payment['token'] === 'XRP'
        && $payment['deposit_address'] === 'rPT1Sjq2YGrBMTttX4GZHjKu9dyfzbpAYe' && $payment['destination_tag'] === 1042
        && $payment['crypto_amount'] === '20' && $payment['rate_locked'] === '0.5' && $payment['expires_at'] === '2026-10-10T12:15:00Z'
        && $payment['qr_payload'] === 'xrpl:rPT1Sjq2YGrBMTttX4GZHjKu9dyfzbpAYe?amount=20.000000&dt=1042'
        && $payment['checkout_token'] === 'ct_abc' && $payment['checkout_url'] === 'https://pay.example/ct_abc'
        && $payment['metadata'] === ['cart' => '42'] && $payment['deposits'] === []);
    $e = apiError(fn () => $payitgo->payments->selectToken('ORD 7/1', 'XRP'));
    check('payments->selectToken escapes an order id', $last()['path'] === '/v1/payments/ORD%207%2F1/token');
    check('payments->selectToken: a coin already chosen is a 409', $e !== null && $e->status === 409
        && $e->getMessage() === 'the coin for this payment has already been chosen');

    $tokens = $payitgo->tokens->list();
    check('tokens->list lists every token', $last()['method'] === 'GET' && $last()['path'] === '/v1/tokens' && $last()['query'] === ''
        && $tokens[0]['token'] === 'USDT_BEP20');
    $tokens = $payitgo->tokens->list('49.90');
    check('tokens->list with an amount asks for its options', $last()['query'] === 'amount_usd=49.90' && $tokens[0]['amount_due_usd'] === '40.43');
} catch (Throwable $e) {
    check('webhook: ' . $e::class . ': ' . $e->getMessage(), false);
} finally {
    proc_terminate($gateway);
    proc_close($gateway);
    @unlink($log);
    @unlink($state);
}

$withSecret = new Client('sk_test_abc', ['base_url' => 'http://x', 'webhook_secret' => SECRET]);
check('webhooks->constructEvent uses the client\'s secret', $withSecret->webhooks->constructEvent(BODY, HEADER, null, 0)['id'] === 'evt_test_123'
    && $withSecret->webhooks->constructEvent(BODY, $fresh)['id'] === 'evt_test_123');
$otherSecret = new Client('sk_test_abc', ['base_url' => 'http://x', 'webhook_secret' => 'whsec_other']);
check('webhooks->constructEvent: an explicit secret wins', rejects(fn () => $otherSecret->webhooks->constructEvent(BODY, HEADER, null, 0))
    && $otherSecret->webhooks->constructEvent(BODY, HEADER, SECRET, 0)['id'] === 'evt_test_123');
$noSecret = new Client('sk_test_abc', ['base_url' => 'http://x']);
try {
    $noSecret->webhooks->constructEvent(BODY, HEADER);
    check('webhooks->constructEvent without a secret', false);
} catch (SignatureVerificationException $e) {
    check('webhooks->constructEvent without a secret', str_contains($e->getMessage(), 'no webhook secret'));
}

echo $failures === 0 ? "ALL PASSED\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
