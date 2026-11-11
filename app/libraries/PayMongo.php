<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class PayMongo
{
    private $secretKey;
    private $webhookSecret;
    private $isLive;

    public function __construct()
    {
        $this->secretKey = trim((string) getenv('PAYMONGO_SECRET_KEY'));
        $this->webhookSecret = trim((string) getenv('PAYMONGO_WEBHOOK_SECRET'));
        $this->isLive = strtolower((string) getenv('APP_ENV')) === 'production';
    }

    public function is_configured()
    {
        $expectedPrefix = $this->isLive ? 'sk_live_' : 'sk_test_';
        return $this->secretKey !== ''
            && strpos($this->secretKey, $expectedPrefix) === 0
            && $this->webhookSecret !== '';
    }

    public function create_checkout_session(array $plan, $membershipId, $reference)
    {
        if (!$this->is_configured()) {
            throw new RuntimeException('PayMongo GCash checkout is not configured for this environment.');
        }

        $frontendUrl = rtrim((string) getenv('APP_FRONTEND_URL'), '/');
        if (!filter_var($frontendUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('APP_FRONTEND_URL must be an absolute URL to start a payment checkout.');
        }

        $amount = (int) round((float) $plan['price'] * 100);
        if ($amount < 1) {
            throw new RuntimeException('The membership plan has an invalid payment amount.');
        }

        $payload = [
            'data' => [
                'attributes' => [
                    'line_items' => [[
                        'name' => (string) $plan['plan_name'] . ' GymMate membership',
                        'amount' => $amount,
                        'currency' => 'PHP',
                        'quantity' => 1,
                    ]],
                    'payment_method_types' => ['gcash'],
                    'success_url' => $frontendUrl . '/payment-return?status=success&reference=' . rawurlencode($reference),
                    'cancel_url' => $frontendUrl . '/payment-return?status=cancelled&reference=' . rawurlencode($reference),
                    'reference_number' => $reference,
                    'metadata' => [
                        'membership_id' => (string) $membershipId,
                    ],
                ],
            ],
        ];

        $curl = curl_init('https://api.paymongo.com/v2/checkout_sessions');
        if ($curl === false) {
            throw new RuntimeException('The payment service could not be initialized.');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->secretKey . ':',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
        $responseBody = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($responseBody === false) {
            error_log('[GymMate PayMongo] Checkout request failed: ' . $curlError);
            throw new RuntimeException('Could not connect to PayMongo. Please try again later.');
        }

        $response = json_decode($responseBody, true);
        $checkoutUrl = $response['data']['attributes']['checkout_url'] ?? '';
        $sessionId = $response['data']['id'] ?? '';
        if (
            $status < 200 || $status >= 300
            || !is_string($checkoutUrl)
            || !is_string($sessionId)
            || strpos($checkoutUrl, 'https://checkout.paymongo.com/') !== 0
        ) {
            error_log('[GymMate PayMongo] Checkout request returned HTTP ' . $status . '.');
            throw new RuntimeException('PayMongo could not start the GCash checkout. Please try again later.');
        }

        return ['checkout_url' => $checkoutUrl, 'session_id' => $sessionId];
    }

    public function verify_webhook($rawBody, $signatureHeader)
    {
        if (!$this->is_configured() || $rawBody === '' || $signatureHeader === '') {
            return null;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $component) {
            $pair = explode('=', trim($component), 2);
            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }
        $timestamp = $parts['t'] ?? '';
        $signatureKey = $this->isLive ? 'li' : 'te';
        $providedSignature = $parts[$signatureKey] ?? '';
        if (
            $timestamp === '' || !ctype_digit($timestamp)
            || abs(time() - (int) $timestamp) > 300
            || $providedSignature === ''
        ) {
            return null;
        }

        $expectedSignature = hash_hmac(
            'sha256',
            $timestamp . '.' . $rawBody,
            $this->webhookSecret
        );
        if (!hash_equals($expectedSignature, $providedSignature)) {
            return null;
        }

        $event = json_decode($rawBody, true);
        if (!is_array($event) || (bool) ($event['data']['livemode'] ?? false) !== $this->isLive) {
            return null;
        }
        return $event;
    }
}
