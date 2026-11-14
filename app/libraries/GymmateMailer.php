<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class GymmateMailer
{
    private function send_email(string $recipientEmail, string $subject, string $html, string $text, ?string $replyTo = null): bool
    {
        $apiKey = trim((string) getenv('RESEND_API_KEY'));
        $fromAddress = trim((string) getenv('MAIL_FROM_ADDRESS'));
        $fromName = trim((string) (getenv('MAIL_FROM_NAME') ?: 'GymMate'));

        if ($apiKey === '' || $fromAddress === '') {
            throw new RuntimeException('Resend is not configured. Set RESEND_API_KEY and MAIL_FROM_ADDRESS.');
        }
        if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid sender and recipient email address are required.');
        }
        if (preg_match('/[\r\n]/', $fromName)) {
            throw new RuntimeException('MAIL_FROM_NAME must not contain line breaks.');
        }

        $payload = [
            'from' => $fromName . ' <' . $fromAddress . '>',
            'to' => [$recipientEmail],
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
        ];
        if ($replyTo !== null) {
            $payload['reply_to'] = $replyTo;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new RuntimeException('The email request could not be encoded.');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                'content' => $body,
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);

        error_clear_last();
        $response = @file_get_contents('https://api.resend.com/emails', false, $context);
        if ($response === false) {
            $lastError = error_get_last();
            $reason = $lastError['message'] ?? 'No response was received.';
            throw new RuntimeException('The Resend API request failed: ' . $reason);
        }

        $statusLine = $http_response_header[0] ?? '';
        if (!preg_match('/\s(\d{3})\s/', $statusLine, $matches)) {
            throw new RuntimeException('The Resend API returned an invalid HTTP response.');
        }

        $status = (int) $matches[1];
        if ($status < 200 || $status >= 300) {
            $result = json_decode($response, true);
            $message = is_array($result) ? ($result['message'] ?? $result['name'] ?? 'Request rejected.') : 'Request rejected.';
            throw new RuntimeException(sprintf('The Resend API returned HTTP %d: %s', $status, $message));
        }

        return true;
    }

    public function send_welcome(string $recipientName, string $recipientEmail): bool
    {
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid recipient email address is required.');
        }

        $safeName = htmlspecialchars($recipientName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = "Welcome to GymMate, {$recipientName}!\n\n"
            . 'Your account has been created successfully. Sign in to choose your membership and meet our trainers.'
            . "\n\nMove with intention. Get stronger together.";
        $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#20231c">'
            . '<h1>Welcome to GymMate, ' . $safeName . '!</h1>'
            . '<p>Your account has been created successfully. Sign in to choose your membership and meet our trainers.</p>'
            . '<p>Move with intention. Get stronger together.</p></body></html>';

        return $this->send_email($recipientEmail, 'Welcome to GymMate', $html, $text);
    }

    public function send_contact(string $recipientEmail, string $senderName, string $senderEmail, string $message): bool
    {
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid sender and recipient email address are required.');
        }

        $safeName = htmlspecialchars($senderName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeEmail = htmlspecialchars($senderEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#20231c">'
            . '<h1>GymMate contact message</h1><p><strong>From:</strong> ' . $safeName
            . ' (' . $safeEmail . ')</p><p>' . $safeMessage . '</p></body></html>';
        $text = "From: {$senderName} ({$senderEmail})\n\n{$message}";

        return $this->send_email(
            $recipientEmail,
            'GymMate website contact message',
            $html,
            $text,
            $senderEmail
        );
    }
}
