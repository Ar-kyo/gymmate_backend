<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ResendInbound
{
    private $apiKey;
    private $webhookSecret;

    public function __construct()
    {
        $this->apiKey = trim((string) getenv('RESEND_API_KEY'));
        $this->webhookSecret = trim((string) getenv('RESEND_RECEIVING_WEBHOOK_SECRET'));
    }

    public function is_configured()
    {
        return $this->apiKey !== ''
            && $this->webhookSecret !== ''
            && trim((string) getenv('RESEND_RECEIVING_ADDRESS')) !== '';
    }

    public function verify_webhook($rawBody, $messageId, $timestamp, $signatureHeader)
    {
        if (
            $rawBody === '' || $messageId === '' || !preg_match('/\A[A-Za-z0-9._-]{1,128}\z/', $messageId)
            || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300
            || strpos($this->webhookSecret, 'whsec_') !== 0
        ) {
            return null;
        }

        $secret = base64_decode(substr($this->webhookSecret, 6), true);
        if ($secret === false || $secret === '') {
            return null;
        }

        $expected = base64_encode(hash_hmac(
            'sha256',
            $messageId . '.' . $timestamp . '.' . $rawBody,
            $secret,
            true
        ));
        foreach (preg_split('/\s+/', trim($signatureHeader)) as $signature) {
            if (strpos($signature, 'v1,') === 0 && hash_equals($expected, substr($signature, 3))) {
                $event = json_decode($rawBody, true);
                return is_array($event) ? $event : null;
            }
        }

        return null;
    }

    public function get_received_email($emailId)
    {
        if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $emailId)) {
            throw new InvalidArgumentException('Resend returned an invalid received-email identifier.');
        }
        if ($this->apiKey === '') {
            throw new RuntimeException('RESEND_API_KEY is not configured for inbound email retrieval.');
        }

        $curl = curl_init('https://api.resend.com/emails/receiving/' . rawurlencode($emailId));
        if ($curl === false) {
            throw new RuntimeException('The Resend email service could not be initialized.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
            ],
        ]);
        $responseBody = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($responseBody === false) {
            error_log('[GymMate Resend inbound] Received-email request failed: ' . $curlError);
            throw new RuntimeException('Could not retrieve the received email from Resend.');
        }
        if ($status < 200 || $status >= 300) {
            error_log('[GymMate Resend inbound] Received-email request returned HTTP ' . $status . '.');
            throw new RuntimeException('Resend could not retrieve the received email.');
        }

        $response = json_decode($responseBody, true);
        $email = is_array($response['data'] ?? null) ? $response['data'] : $response;
        if (!is_array($email) || ($email['id'] ?? '') !== $emailId) {
            throw new RuntimeException('Resend returned an unexpected received-email response.');
        }
        return $email;
    }
}
