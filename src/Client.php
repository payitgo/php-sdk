<?php

declare(strict_types=1);

namespace Payitgo;

use Payitgo\Exception\PayitgoException;

/**
 * The payitgo client. Use it from your server only: the API key is a secret.
 *
 *     $payitgo = new \Payitgo\Client(getenv('PAYITGO_KEY'), ['base_url' => 'https://api.example.com']);
 *     $payment = $payitgo->payments->create(['order_id' => 'ORD-1001', 'amount_usd' => 10, 'token' => 'USDT_TRC20']);
 *     // send the customer to $payment['checkout_url']; fulfil on the payment.confirmed webhook
 */
final class Client
{
    public const VERSION = '0.3.0';

    /** Used when neither base_url nor PAYITGO_BASE_URL is set. */
    private const DEFAULT_BASE_URL = 'https://api.payitgo.co';

    public readonly Payments $payments;
    public readonly Tokens $tokens;
    public readonly Checkout $checkout;
    /** The key's mode's webhook, and constructEvent to verify its notifications. */
    public readonly Webhooks $webhooks;

    private string $baseUrl;
    private int $timeout;
    private int $maxRetries;
    /** The key's mode, once payitgo has said it (isLive()). */
    private ?bool $live = null;

    /**
     * $apiKey is a secret key, test or live (isLive() says which).
     * webhook_secret is the signing secret of the key's mode, which
     * $client->webhooks->constructEvent() uses when given none; webhook_url is
     * sent as the webhook_url of every payment created without its own.
     *
     * @param array{base_url?: string, timeout?: int, max_retries?: int, webhook_secret?: string, webhook_url?: string} $options
     */
    public function __construct(private readonly string $apiKey, array $options = [])
    {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException('payitgo: the API key is required');
        }
        $baseUrl = $options['base_url'] ?? (getenv('PAYITGO_BASE_URL') ?: self::DEFAULT_BASE_URL);
        if ($baseUrl === '') {
            throw new \InvalidArgumentException("payitgo: set base_url (or PAYITGO_BASE_URL) to your gateway's address");
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $options['timeout'] ?? 30;
        $this->maxRetries = $options['max_retries'] ?? 2;
        $this->payments = new Payments($this, ($options['webhook_url'] ?? '') ?: null);
        $this->tokens = new Tokens($this);
        $this->checkout = new Checkout($this);
        $this->webhooks = new Webhooks($this, ($options['webhook_secret'] ?? '') ?: null);
    }

    /**
     * Whether the key is a live key (its payments are real) rather than a test
     * key. payitgo says which (GET /v1/webhook's livemode) the first time; the
     * answer is kept for the client's life.
     *
     * @throws PayitgoException When payitgo refuses the key or cannot be reached.
     */
    public function isLive(): bool
    {
        return $this->live ??= (bool) $this->webhooks->get()['livemode'];
    }

    /**
     * @internal
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, ?array $body = null, bool $authenticated = true): array
    {
        $headers = ['Accept: application/json', 'User-Agent: payitgo-php/' . self::VERSION];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($authenticated) {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        for ($attempt = 0; ; $attempt++) {
            $ch = curl_init($this->baseUrl . $path);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => $this->timeout,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
            }
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            // No curl_close(): the handle is freed with $ch (PHP 8.0+), and
            // PHP 8.5 deprecates the call.

            $retryable = $raw === false || $status === 429 || $status >= 500;
            if ($retryable && $attempt < $this->maxRetries) {
                usleep((int) (500000 * 2 ** $attempt));
                continue;
            }
            if ($raw === false) {
                throw new PayitgoException('payitgo: request failed: ' . $error, 0);
            }
            $data = $raw === '' ? [] : json_decode($raw, true);
            if ($status < 200 || $status >= 300) {
                $message = is_array($data) && isset($data['error']) ? (string) $data['error'] : "HTTP $status";
                throw new PayitgoException($message, $status);
            }
            return is_array($data) ? $data : [];
        }
    }
}
