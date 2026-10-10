# payitgo for PHP

Accept crypto payments with [payitgo](https://api.payitgo.co/docs) from PHP 8.1+. Needs only the curl
and json extensions.

Full reference, with every method, option, type and error: [api.payitgo.co/docs/sdk-php](https://api.payitgo.co/docs/sdk-php).

```bash
composer require payitgo/payitgo-php
```

## Accept a payment in three steps

```php
<?php
require 'vendor/autoload.php';

$payitgo = new \Payitgo\Client(getenv('PAYITGO_KEY'), [
    'base_url'       => getenv('PAYITGO_BASE_URL'),
    'webhook_secret' => getenv('PAYITGO_WEBHOOK_SECRET'), // "whsec_…": see Webhooks (IPN) below
]);

// 1. checkout.php — create a payment and send the customer to the checkout page.
$payment = $payitgo->payments->create([
    'order_id'    => $order->id,        // your order id: safe to retry
    'amount_usd'  => $order->total,     // e.g. 19.99
    // 'token'    => 'USDT_TRC20',      // optional: without it the customer picks the coin
    'success_url' => 'https://shop.example/thanks',
]);
header('Location: ' . $payment['checkout_url'], true, 303);
```

```php
<?php
// 2. ipn.php — receive the notification (the complete handler is under Webhooks (IPN) below).
//    Use the raw body: the signature covers its exact bytes. $payitgo is made as above.
try {
    $event = $payitgo->webhooks->constructEvent(
        file_get_contents('php://input'),
        $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '',
    );
} catch (\Payitgo\Exception\SignatureVerificationException) {
    http_response_code(400);
    exit;
}
// 3. Fulfil the order once the payment is final.
if ($event['type'] === 'payment.confirmed') {
    markOrderPaid($event['data']['order_id']); // make this idempotent: events can arrive twice
}
http_response_code(200);
```

Start with your test key (`sk_test_…`) and testnet coins; switch to the live key
(`sk_live_…`) when the account owner has turned live mode on.

## Your own payment page

`checkout_url` is only for payitgo's hosted page. To show the payment on your own page,
render the payment your server gets back (`deposit_address`, `crypto_amount`,
`destination_tag` for XRP, a QR code of `qr_payload`, `expires_at`); no `checkout` calls
are needed.

- **You know the coin:** create the payment with `token` and render the payment it returns.
- **The customer chooses it:** create the payment without `token`, show
  `$payment['token_options']` on your page, then call
  `$payitgo->payments->selectToken($payment['id'], $chosen)` from your server and render the
  payment it returns: it now has its coin, address, amount, locked rate and a deadline 15
  minutes away.

```php
$payment = $payitgo->payments->create(['order_id' => 'ORD-1', 'amount_usd' => 10]);
// your page shows $payment['token_options']; the customer picks one
$chosen = $payitgo->payments->selectToken($payment['id'], 'USDT_TRC20'); // or your order id
// render $chosen['deposit_address'], $chosen['crypto_amount'], $chosen['qr_payload'], $chosen['expires_at']
```

The coin is chosen once: choosing again is a `409` (`payments->retrieve()` then returns the
payment with its coin). A coin outside `token_options`, or a payment that can no longer be
paid, is a `400`.

## Webhooks (IPN)

payitgo POSTs a signed notification for every change of a payment (`payment.confirmed`,
`payment.expired`, …), of a direct settlement and of a payout. Each mode has one webhook:
a test key manages the test one, a live key the live one.

**Where the notifications go.** Set the URL once per mode (https:// on a public host,
accepting POST requests), or give a payment its own when you create it:

```php
// Once per mode (safe to repeat):
$payitgo->webhooks->set('https://shop.example/payitgo/ipn');

// Or per payment: its events go there instead of the webhook's URL.
$payitgo->payments->create(['order_id' => 'ORD-1', 'amount_usd' => 10, 'webhook_url' => 'https://shop.example/orders/ipn']);

// Or as the client's default for every payment it creates (a payment's own webhook_url wins):
$shop = new \Payitgo\Client(getenv('PAYITGO_KEY'), ['webhook_url' => 'https://shop.example/orders/ipn']);
```

A storefront's key cannot set the account's URL (`403`): pass `webhook_url` instead.
Payout events always go to the webhook's URL.

**The signing secret.** Every notification of a mode is signed with that mode's secret
(`whsec_…`), whichever URL it goes to. Copy it from the dashboard or read it with
`$payitgo->webhooks->get()['secret']`, and give it to the client once:

```php
$payitgo = new \Payitgo\Client(getenv('PAYITGO_KEY'), ['webhook_secret' => getenv('PAYITGO_WEBHOOK_SECRET')]);
```

**Receiving them** (plain PHP; any framework works the same way). Verify the raw body,
handle each event once, and answer `2xx` within 10 seconds; otherwise payitgo retries after
1 min, 5 min, 30 min, 2 h, 6 h and 24 h.

```php
<?php
// ipn.php
require 'vendor/autoload.php';

$payitgo = new \Payitgo\Client(getenv('PAYITGO_KEY'), ['webhook_secret' => getenv('PAYITGO_WEBHOOK_SECRET')]);

try {
    // Checks the signature with the client's webhook_secret and refuses anything older than 5 minutes.
    $event = $payitgo->webhooks->constructEvent(
        file_get_contents('php://input'),          // the raw body
        $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '',
    );
} catch (\Payitgo\Exception\SignatureVerificationException) {
    http_response_code(400);
    exit;
}

// The same event can arrive more than once: handle each event id once,
// e.g. INSERT IGNORE into a table with a unique key on the id.
if (!recordEventOnce($event['id'])) {
    http_response_code(200);
    exit;
}

switch ($event['type']) {
    case 'payment.confirmed':
        $jobs->push('fulfil', $event['data']['order_id']); // slow work off the request
        break;
    case 'payment.expired':
        $jobs->push('release-stock', $event['data']['order_id']);
        break;
}
http_response_code(200);
```

In Laravel, read the body with `$request->getContent()` and the header with
`$request->header('X-Webhook-Signature')`, and exclude the route from CSRF protection.

**Testing it.** `$payitgo->webhooks->test()` queues a signed `ping` event to the webhook's
URL and returns its delivery (`400` when no URL is set).

## API

```php
$payitgo = new \Payitgo\Client($apiKey, [
    'base_url'       => 'https://api.example.com', // or PAYITGO_BASE_URL
    'timeout'        => 30,
    'max_retries'    => 2,                         // network errors, 429 and 5xx
    'webhook_secret' => 'whsec_…',                 // for $payitgo->webhooks->constructEvent()
    'webhook_url'    => 'https://…',               // default webhook_url of payments->create()
]);

$payitgo->livemode;                                   // false for sk_test_ keys
$payitgo->payments->create(['order_id' => …, 'amount_usd' => …, 'token' => …,
                          'description' => …, 'metadata' => […], 'customer_email' => …,
                          'success_url' => …, 'cancel_url' => …, 'webhook_url' => …]);
$payitgo->payments->selectToken('pay_…', $token);     // or your order id: the payment with its coin
$payitgo->payments->retrieve('pay_…');                // or your order id
$payitgo->payments->list(['status' => 'confirmed', 'store_id' => 'sto_…', 'limit' => 50, 'offset' => 0]);
$payitgo->tokens->list();                             // [['token' => …, 'chain' => …, 'min_usd' => …, 'network_fee_usd' => …, 'fee_note' => …]]
$payitgo->tokens->list('49.90');                      // the coins a $49.90 payment offers, with amount_due_usd
$payitgo->checkout->retrieve($checkoutToken);         // public payer view
$payitgo->webhooks->get();                            // ['livemode' => …, 'url' => …, 'secret' => …, 'created_at' => …, 'updated_at' => …]
$payitgo->webhooks->set($url);                        // the webhook
$payitgo->webhooks->remove();                         // the webhook, 'url' => null (the secret stays)
$payitgo->webhooks->test();                           // the delivery of a "ping" event
$payitgo->webhooks->constructEvent($rawBody, $signatureHeader, $secret = null, $tolerance = 300);
\Payitgo\Webhook::constructEvent($rawBody, $signatureHeader, $secret, $tolerance = 300); // no client needed
```

Responses are associative arrays with the API's field names (see the
[payment object](https://api.payitgo.co/docs/payments) and [webhooks](https://api.payitgo.co/docs/webhooks)).
`\Payitgo\Webhooks::EVENTS` lists the event types.

## Errors

```php
try {
    $payitgo->payments->create(['order_id' => 'ORD-1', 'amount_usd' => 1, 'token' => 'BTC']);
} catch (\Payitgo\Exception\PayitgoException $e) {
    echo $e->status, ' ', $e->getMessage(); // 400 minimum payment in BTC is $10 right now (network fees)
}
```

`PayitgoException::$status` is the HTTP status (0 for a network error). `constructEvent`
throws `SignatureVerificationException` for a bad signature, a stale timestamp, or when it
has no secret (none passed and no `webhook_secret`).

## Development

```bash
php tests/run.php
```
