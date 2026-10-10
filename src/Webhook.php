<?php

declare(strict_types=1);

namespace Payitgo;

use Payitgo\Exception\SignatureVerificationException;

final class Webhook
{
    public const DEFAULT_TOLERANCE = 300;

    /**
     * Verifies a webhook request and returns its event as an array. Pass the
     * raw request body, the X-Webhook-Signature header and the mode's signing
     * secret (the webhook's 'secret', from $client->webhooks->get() or the
     * dashboard). Throws SignatureVerificationException when the signature
     * is wrong or the timestamp is more than $tolerance seconds old (0
     * disables the timestamp check).
     *
     *     $event = \Payitgo\Webhook::constructEvent(
     *         file_get_contents('php://input'),
     *         $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '',
     *         getenv('PAYITGO_WEBHOOK_SECRET'),
     *     );
     *
     * @return array<string, mixed>
     */
    public static function constructEvent(string $payload, ?string $signatureHeader, string $secret, int $tolerance = self::DEFAULT_TOLERANCE): array
    {
        $parts = [];
        foreach (explode(',', $signatureHeader ?? '') as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2) {
                $parts[$kv[0]] = $kv[1];
            }
        }
        $t = $parts['t'] ?? '';
        $v1 = $parts['v1'] ?? '';
        if ($t === '' || $v1 === '') {
            throw new SignatureVerificationException('missing or malformed X-Webhook-Signature header');
        }
        $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
        if (!hash_equals($expected, $v1)) {
            throw new SignatureVerificationException('signature does not match');
        }
        if ($tolerance > 0 && (!ctype_digit($t) || abs(time() - (int) $t) > $tolerance)) {
            throw new SignatureVerificationException('timestamp outside the tolerance');
        }
        return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    }
}
