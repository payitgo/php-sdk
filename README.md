# payitgo for PHP

Accept crypto payments with [payitgo](https://api.payitgo.co/docs), and pay your own clients from
your payout wallet, from PHP 8.1+. Needs only the curl and json extensions.

Full reference, with every method, option, type and error: [api.payitgo.co/docs/sdk-php](https://api.payitgo.co/docs/sdk-php).

```bash
composer require payitgo/payitgo-php
```

## Accept a payment in three steps

```php
<?php
require 'vendor/autoload.php';

$payitgo = new \Payitgo\Client(getenv('PAYITGO_KEY'), [
    'webhook_secret' => getenv('PAYITGO_WEBHOOK_SECRET'), // the signing secret: see Webhooks (IPN) below
]);

// 1. checkout.php — create a payment and send the customer to the checkout page.
$payment = $payitgo->payments->create([
    'order_id'    => $order->id,        // your order id: safe to retry
    'amount_usd'  => $order->total,     // e.g. 19.99
    // 'token'    => 'USDT_TRC20',      // optional: without it the customer picks the coin
    'success_url' => 'https://yourdomain.com/thanks',
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

Start with a test key, where you [simulate](#test-mode) what the customer pays; switch to
a live key when the account owner has turned live mode on. A key does not say its mode
(the dashboard shows a Test or Live badge next to it): `$payitgo->isLive()` asks payitgo,
once.

## Prices in other currencies

To price a payment in your own currency, give `price_amount` and `price_currency` (an ISO
4217 code such as `EUR`, `GBP` or `NGN`) instead of `amount_usd`. payitgo converts the price
into dollars at the rate of the moment, and the payment keeps both:

```php
$payment = $payitgo->payments->create([
    'order_id'       => 'ORD-1002',
    'price_amount'   => '45.50',   // sent as a string; prefer a string to a float
    'price_currency' => 'EUR',     // any case
]);
// $payment['price_amount'] '45.5', $payment['price_currency'] 'EUR',
// $payment['price_rate'] '1.082' (dollars per euro), $payment['usd_amount'] '49.23'
```

Everything else (`usd_amount`, `crypto_amount`, fees, minimums) stays in dollars; a payment
priced in dollars has no `price_*` keys (read them with `??`). Give `amount_usd` or the pair,
not both (`400`). When no exchange rate can be had, `create()` throws a `PayitgoException`
with status `503` (`exchange rate unavailable for price_currency: try again shortly`) once the
client's retries are spent: try again shortly.

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

The customer can change the coin while the payment still has `token_options` (nothing has
arrived yet): call `payments->selectToken()` again with another. Once it can no longer change, it is a `409`
(`payments->retrieve()` then returns the payment with its coin). A coin outside `token_options`, or a
payment that can no longer be paid, is a `400`.

## Test mode

A test payment gets an address like a live one, but no coins are involved: you choose an
outcome and payitgo plays its deposits, status changes and webhooks a few seconds apart
([Test mode](https://api.payitgo.co/docs/testing)).

```php
// Choose the outcome when you create the payment (with token it starts at once,
// without it once the coin is chosen)…
$payitgo->payments->create(['order_id' => 'TEST-1', 'amount_usd' => 10, 'token' => 'USDT_TRC20', 'simulate' => 'confirmed']);

// …or later, on a payment you already have (its id or your order id).
$payment = $payitgo->payments->simulate('TEST-2', 'partially_paid');
// $payment['simulation']: ['outcome' => 'partially_paid', 'state' => 'running']
```

Outcomes (`\Payitgo\Payments::SIMULATION_OUTCOMES`): `confirmed`, `overpaid`, `partially_paid`,
`underpaid`, `expired`, `failed`, `wrong_asset`, `paid_late`. Simulated deposits have
`'simulated' => true`; their `tx_id` names no transaction on any chain. A live key gets
`400`; a payment whose simulation is still running, `409`.

## Webhooks (IPN)

payitgo POSTs a signed notification for every change of a payment (`payment.confirmed`,
`payment.expired`, …), of a direct settlement and of a payout. Each mode has one webhook:
a test key manages the test one, a live key the live one.

**Where the notifications go.** Set the URL once per mode (https:// on a public host,
accepting POST requests), or give a payment its own when you create it:

```php
// Once per mode (safe to repeat):
$payitgo->webhooks->set('https://api.yourdomain.com/payitgo/ipn');

// Or per payment: its events go there instead of the webhook's URL.
$payitgo->payments->create(['order_id' => 'ORD-1', 'amount_usd' => 10, 'webhook_url' => 'https://api.yourdomain.com/orders/ipn']);

// Or as the client's default for every payment it creates (a payment's own webhook_url wins):
$shop = new \Payitgo\Client(getenv('PAYITGO_KEY'), ['webhook_url' => 'https://api.yourdomain.com/orders/ipn']);
```

A storefront's key cannot set the account's URL (`403`): pass `webhook_url` instead.
A payout's events go to its own `webhook_url` (payout requests and batch rows take one), else
to its batch's `webhook_url`, else to the webhook's URL.

**The signing secret.** Every notification of a mode is signed with that mode's secret,
whichever URL it goes to. Copy it from the dashboard or read it with
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

## Payouts

Pay your own clients (withdrawals from your platform, payroll, prizes) from your **payout
wallet**: one address per network, yours alone, that you top up
([Mass payouts](https://api.payitgo.co/docs/payouts)). Payout coins, such as `USDT_TRC20`, `USDT_BEP20`
or `BTC`, are listed in [Networks and coins](https://api.payitgo.co/docs/payouts#networks-and-coins).
Any key reads; everything that writes needs a key with
**payouts** turned on in the dashboard (`403` otherwise). Amounts are decimal strings: a number
you pass is sent as a string.

### Payout requests, one by one

When one of your clients asks for a payout, send the request as it comes in. payitgo checks and
prices it at once and keeps it in your open list, with its costs:

```php
$item = $payitgo->payouts->request([
    'reference' => $withdrawal->id,        // your id for it: safe to retry, never paid twice
    'asset'     => 'USDT_TRC20',
    'address'   => $withdrawal->address,
    'amount'    => '120',                  // fees on top, unless your payout settings' fee_paid_by is 'recipient'
    'name'      => $client->name,          // optional: name, email, note (kept with it; nothing is emailed)
]);
if ($item['status'] === 'invalid') {
    // kept, never sent: $item['errors'][0]['message'], e.g. "this address is for Tron, not BNB Smart Chain"
}
// $item['fee'], $item['send_amount'] (what arrives), $item['debit'] (what leaves the wallet)
```

The same `reference` again returns that payment (`201` the first time, `200` after: both
return the payment), so a timeout is safe to retry. Then send the list when you choose, from a
cron job say (or let it send itself with [auto-send](https://api.payitgo.co/docs/payouts#auto-send)):

```php
$inbox = $payitgo->payouts->inbox();       // ['inbox' => …, 'coverage' => […], 'items' => […], 'total' => …]
foreach ($inbox['coverage'] as $c) {
    if ($c['top_up'] !== '0') {
        // send $c['top_up'] of $c['asset'] to $c['address'] first, or the batch waits for it
    }
}
$batch = $payitgo->payouts->send();        // every valid request, as a batch; or send([$id, …])
// $batch['status']: 'pending_approval', 'awaiting_funds' ($batch['waiting'] says why) or 'processing'
// (invalid requests stay in the list; up to 10,000 go per call)

$payitgo->payouts->cancel($item['id']);    // out of the list (record kept); a sent one not on its way yet is canceled
$payitgo->payouts->remove([$id, …]);       // several out of the list at once: a result per id, 'item' or 'error'
$payitgo->payouts->retrieve($item['id']);  // any payment, request or batch row, by id…
$payitgo->payouts->retrieveByReference($withdrawal->id); // …or by your reference (or the latest that gave it back)
```

`send([])` and `remove([])` throw `\InvalidArgumentException` rather than call payitgo: pass no
ids (or `null`) to `send()` to send every valid request. Canceling or removing is safe to
retry: a payment canceled already is returned as it is.

To show a client the fee before they ask, quote up to 100 payments without keeping anything:

```php
$quote = $payitgo->payouts->quote(['fee_paid_by' => 'recipient', 'items' => [
    ['asset' => 'USDT_BEP20', 'address' => '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed', 'amount' => '120'],
]]);
// $quote['items'][0]['send_amount'] is what arrives; $quote['quote']['total_usd'] what it all takes
```

### Batches

A whole list at once (payroll, prizes): up to 1000 payments a call, 10,000 a batch.

```php
$batch = $payitgo->payoutBatches->create([
    'reference' => 'payroll-2026-10',      // safe to retry: returns that batch
    'name'      => 'October payroll',
    'items'     => [
        ['reference' => 'payroll-2026-10-ana', 'asset' => 'USDT_TRC20', 'address' => 'TR7mNkucnhLZoJQLLJ6DR88jpLQufWxUJi', 'amount' => '120', 'name' => 'Ana Silva'],
        ['reference' => 'payroll-2026-10-ben', 'asset' => 'USDT_BEP20', 'address' => '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed', 'amount' => '250'],
    ],
]);
// 'quoted': $batch['totals'] per coin, $batch['counts'], $batch['total_usd']; its prices hold for an hour
// (15 minutes when it has converted payments)
$invalid = $payitgo->payoutBatches->items($batch['id'], ['status' => 'invalid']); // each with its errors
$batch = $payitgo->payoutBatches->submit($batch['id']); // 409 "its prices expired": quote() it, then submit
```

Also `payoutBatches->list()`, `retrieve($id)`, `addItems($id, $items)` (before it is
submitted), `quote($id)` (priced again) and `cancel($id)`.

### Claim links

When you don't have the recipient's address, make the payment a claim link: `'claim' => true`
(or `claim_assets`) and no `address`. Its recipient chooses the coin (`asset`, or one of
`claim_assets`, at the same dollar value) and the address
([Claim links](https://api.payitgo.co/docs/payouts#claim-links)). With an `email`, payitgo emails
the link once the payment is funded, and a code sent there confirms the claim; without one, share
`$item['claim']['url']` yourself and give its PIN, `$item['claim']['pin']`, another way: the
recipient enters it to claim. An email alone makes no claim link: a payment without an address
is `invalid`. Payout requests, batch rows and quotes all take them.

```php
$item = $payitgo->payouts->request([
    'reference'    => 'prize-2026-10-ana',
    'asset'        => 'USDT_TRC20',
    'amount'       => '50',
    'email'        => 'ana@example.com',     // the link is emailed here
    'claim'        => true,                  // no address: a claim link
    'claim_assets' => ['USDT_BEP20', 'BNB'], // optional: the other coins the recipient may choose
]);
// $item['claim']: ['email' => …, 'assets' => […], 'emailed' => 0, 'extended' => 0, 'url' => …]
```

Once funded it is `claimable` until `$item['claim']['expires_at']`. Claimed, it goes on like any
payment (`queued`, then `sent`), with `claim['claimed_at']`, and `claim['funded_asset']` and
`claim['funded_amount']` when the recipient chose another coin. Never claimed, it is `expired`
and its money is yours to spend again. Batches count them in `counts['claimable']` and
`counts['expired']`. Emailing a link again and extending it are done in the dashboard.

### Converted payments

To pay recipients in another coin than your wallet holds, give a batch (or a quote)
`'convert' => 'convert'` and `convert_from`: every payment in another coin is paid from
`convert_from`, converted on its way
([Conversion](https://api.payitgo.co/docs/payouts#conversion)). Both default to your payout
settings; `'same_network'` pays each payment from the wallet's own coin.

```php
$batch = $payitgo->payoutBatches->create([
    'reference'    => 'prizes-2026-10',
    'convert'      => 'convert',
    'convert_from' => 'USDT_TRC20',          // the wallet's coin the payments are paid from
    'items'        => [
        ['reference' => 'prize-1', 'asset' => 'BNB', 'address' => '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed', 'amount' => '0.5'],
    ],
]);
$row = $payitgo->payoutBatches->items($batch['id'])['items'][0];
// $row['convert']: ['to' => 'BNB', 'ticker' => 'BNB', 'network' => …, 'to_amount' => '0.5', 'estimate' => …]
// $row['asset'], $row['amount'], $row['fee'] (converting included) and $row['debit'] are in USDT_TRC20
```

`convert['estimate']` is about what arrives; `convert['amount_out']` is what did, once sent.
`address` is the recipient's on `convert['to']`'s network. A coin that cannot be converted to
right now makes the row `invalid` (`conversion_unavailable`). Payout requests follow your payout
settings.

### The payout wallet

```php
foreach ($payitgo->payoutWallets->list() as $wallet) { // one per network: tron, bsc, ethereum, bitcoin, …
    foreach ($wallet['assets'] as $a) {
        echo $wallet['address'], ' ', $a['asset'], ': spendable ', $a['spendable'], ', needed ', $a['needed'], PHP_EOL;
    }
}
```

A batch the wallet cannot cover waits (`awaiting_funds`), and its `waiting.shortfall` says what
to send where; it starts by itself once the money is there.

### Payouts in test mode

With a test key the payout wallet is on paper: top it up with a call, and payments are sent
within seconds, with made-up `tx_id`s on no chain.

```php
$payitgo->payoutWallets->simulateTopUp('USDT_TRC20', '1000'); // test keys only: a live key gets 400
```

### Payout webhooks

Each step raises an event, signed like every other: `payout_batch.quoted`,
`payout_batch.pending_approval`, `payout_batch.approved`, `payout_batch.rejected`,
`payout_batch.awaiting_funds`, `payout_batch.processing`, `payout_batch.completed`,
`payout_batch.canceled`, `payout_item.processing` (a request left your list for a batch),
`payout_item.returned` (it came back to the list; not final), `payout_item.sent`,
`payout_item.failed`, `payout_item.canceled`, `payout_item.claimed`, `payout_item.claim_expired`,
`payout_wallet.withdrawn` and `payout_wallet.withdrawal_failed` (with `fail_reason`; its money
stays in the wallet). `data` is the batch, the payment or the withdrawal. A payment's events go
to its own `webhook_url`, else to its batch's `webhook_url`, else to the webhook's URL.
Schedules made in the dashboard (a batch made again and again; its batches have
`'source' => 'schedule'`) send `payout_schedule.run` (`data`: the schedule) and
`payout_schedule.funding_needed` (`data`: `['schedule' => …, 'shortfall' => [['asset' => …,
'network' => …, 'amount' => …, 'address' => …]]]`, before a run the wallet cannot cover). The
schedule there is a summary (`id`, `name`, `status`, `cadence`, `next_run_at`, `last_batch_id`,
`totals`, …) without its rows.

```php
switch ($event['type']) {
    case 'payout_item.sent':
        markWithdrawalPaid($event['data']['reference'], $event['data']['tx_id']);
        break;
    case 'payout_item.failed':             // never sent: its money stays in the wallet, its reference is free again
    case 'payout_item.canceled':           // whoever canceled it: $event['data']['cancel_reason'] says why
    case 'payout_item.claim_expired':      // a claim link never claimed: the same
        reopenWithdrawal($event['data']['released_reference']);
        break;
}
```

**Staying in sync.** Every payment, a payout request or a batch row, ends `sent`, `failed`,
`canceled` or `expired`, with one event each, delivered at least once: deduplicate on the
event's `id` (the same however often it is sent). Order is not guaranteed: act on
`$event['data']['status']` and the latest `$event['data']['updated_at']`. Replay the events you
missed with `$payitgo->events->list(['after' => $lastEventId])`, and resync your payments with
`$payitgo->payouts->list()` (keep its `next_cursor` for next time):

```php
$page = $payitgo->events->list(['type' => 'payout_item.*', 'after' => $lastEventId]); // oldest first
// $page['events']: each as its webhook carried it; while $page['has_more'], read on from the last id

$page = $payitgo->payouts->list(['status' => ['sent', 'failed', 'canceled', 'expired'], 'updated_since' => new \DateTimeImmutable('-1 day')]);
// $page['items'], oldest change first; while $page['has_more'], read on with ['cursor' => $page['next_cursor']]
```

## API

```php
$payitgo = new \Payitgo\Client($apiKey, [
    'timeout'        => 30,
    'max_retries'    => 2,                         // network errors, 429 and 5xx
    'webhook_secret' => $secret,                   // for $payitgo->webhooks->constructEvent()
    'webhook_url'    => 'https://…',               // default webhook_url of payments->create()
]);

$payitgo->isLive();                                   // false for a test key (asks payitgo once)
$payitgo->payments->create(['order_id' => …, 'amount_usd' => …, 'token' => …, 'tokens' => […],
                          'description' => …, 'metadata' => […], 'customer_email' => …,
                          'success_url' => …, 'cancel_url' => …, 'webhook_url' => …, 'simulate' => …]);
$payitgo->payments->create(['order_id' => …, 'price_amount' => …, 'price_currency' => …, …]); // priced in another currency
$payitgo->payments->selectToken($paymentId, $token);  // or your order id: the payment with its coin
$payitgo->payments->simulate($paymentId, 'confirmed'); // test keys: plays the outcome
$payitgo->payments->retrieve($paymentId);             // or your order id
$payitgo->payments->list(['status' => 'confirmed', 'store_id' => $storeId, 'limit' => 50, 'offset' => 0]);
$payitgo->tokens->list();                             // [['token' => …, 'chain' => …, 'min_usd' => …, 'network_fee_usd' => …, 'fee_note' => …, …]]
$payitgo->tokens->list('49.90');                      // the coins a $49.90 payment offers, with amount_due_usd
$payitgo->checkout->retrieve($checkoutToken);         // public payer view
$payitgo->webhooks->get();                            // ['livemode' => …, 'url' => …, 'secret' => …, 'events' => […], 'created_at' => …, 'updated_at' => …]
$payitgo->webhooks->set($url);                        // the webhook
$payitgo->webhooks->remove();                         // the webhook, 'url' => null (the secret stays)
$payitgo->webhooks->test();                           // the delivery of a "ping" event
$payitgo->webhooks->constructEvent($rawBody, $signatureHeader, $secret = null, $tolerance = 300);
\Payitgo\Webhook::constructEvent($rawBody, $signatureHeader, $secret, $tolerance = 300); // no client needed
$payitgo->events->list(['type' => ['payout_item.*'], 'after' => …, 'created_after' => …, 'limit' => …]); // ['events' => […], 'has_more' => …]

$payitgo->payouts->request(['reference' => …, 'asset' => …, 'address' => …, 'amount' => …,
                          'tag' => …, 'name' => …, 'email' => …, 'note' => …, 'webhook_url' => …]); // the payment, in your open list
$payitgo->payouts->request(['asset' => …, 'amount' => …, 'email' => …, 'claim' => true, 'claim_assets' => […]]); // a claim link
$payitgo->payouts->retrieve($id);                     // a payment: a request or a batch row
$payitgo->payouts->retrieveByReference($reference);   // or the latest payment that gave it back
$payitgo->payouts->list(['status' => …, 'updated_since' => …, 'cursor' => …, 'limit' => …]); // ['items' => […], 'has_more' => …, 'next_cursor' => …]
$payitgo->payouts->inbox(['status' => …, 'limit' => …, 'offset' => …]); // ['inbox' => …, 'coverage' => …, 'items' => …, 'total' => …, …]
$payitgo->payouts->send($ids = null);                 // the batch the valid requests went into
$payitgo->payouts->cancel($id);                       // the payment, 'status' => 'canceled'
$payitgo->payouts->remove([$id, …]);                  // [['id' => …, 'item' => …] or ['id' => …, 'error' => ['status' => …, 'message' => …]], …]
$payitgo->payouts->quote(['fee_paid_by' => …, 'convert' => …, 'convert_from' => …, 'items' => [[…], …]]); // ['quote' => …, 'items' => […]], nothing kept
$payitgo->payoutBatches->create(['reference' => …, 'name' => …, 'fee_paid_by' => …, 'webhook_url' => …,
                               'convert' => …, 'convert_from' => …, 'items' => [[…], …]]);
$payitgo->payoutBatches->list(['status' => …, 'limit' => …, 'offset' => …]); // ['batches' => […], 'total' => …, …]
$payitgo->payoutBatches->retrieve($batchId);
$payitgo->payoutBatches->items($batchId, ['status' => ['sent', 'failed'], 'limit' => …, 'offset' => …]); // ['items' => […], 'total' => …, …]
$payitgo->payoutBatches->addItems($batchId, [[…], …]);
$payitgo->payoutBatches->quote($batchId);             // priced again
$payitgo->payoutBatches->submit($batchId);
$payitgo->payoutBatches->cancel($batchId);
$payitgo->payoutWallets->list();                      // [['chain' => …, 'address' => …, 'assets' => [[…], …]], …]
$payitgo->payoutWallets->simulateTopUp($asset, $amount); // test keys: the wallets
```

Responses are associative arrays with the API's field names (see the
[payment object](https://api.payitgo.co/docs/payments), [mass payouts](https://api.payitgo.co/docs/payouts)
and [webhooks](https://api.payitgo.co/docs/webhooks)).
`\Payitgo\Webhooks::EVENTS` lists the event types.

## Errors

```php
try {
    $payitgo->payments->create(['order_id' => 'ORD-1', 'amount_usd' => 1, 'token' => 'BTC']);
} catch (\Payitgo\Exception\PayitgoException $e) {
    echo $e->status, ' ', $e->getMessage(); // 400 minimum payment in BTC is $10 right now (network fees)
}
```

`PayitgoException::$status` is the HTTP status (0 for a network error): for payouts, `403` for
a key without payouts and `409` for a batch whose state refuses the call. `constructEvent`
throws `SignatureVerificationException` for a bad signature, a stale timestamp, or when it
has no secret (none passed and no `webhook_secret`).

## Development

```bash
php tests/run.php
```
