<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class GymmateMailer
{
    private function send_email(
        string $recipientEmail,
        string $subject,
        string $html,
        string $text,
        ?string $replyTo = null,
        array $headers = []
    ): string
    {
        $apiKey = trim((string) getenv('RESEND_API_KEY'));
        $fromAddress = trim((string) getenv('MAIL_FROM_ADDRESS'));
        $fromName = trim((string) (getenv('MAIL_FROM_NAME') ?: 'GymMate'));
        if ($replyTo === null) {
            $replyTo = trim((string) getenv('RESEND_RECEIVING_ADDRESS')) ?: null;
        }

        if ($apiKey === '' || $fromAddress === '') {
            throw new RuntimeException('Resend is not configured. Set RESEND_API_KEY and MAIL_FROM_ADDRESS.');
        }
        if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid sender and recipient email address are required.');
        }
        if (preg_match('/[\r\n]/', $fromName)) {
            throw new RuntimeException('MAIL_FROM_NAME must not contain line breaks.');
        }
        if ($replyTo !== null && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('RESEND_RECEIVING_ADDRESS must be a valid email address.');
        }
        foreach ($headers as $name => $value) {
            if (
                !preg_match('/\A[A-Za-z0-9-]{1,64}\z/', (string) $name)
                || !is_string($value) || preg_match('/[\r\n]/', $value)
            ) {
                throw new InvalidArgumentException('The email contains an invalid header.');
            }
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
        if ($headers !== []) {
            $payload['headers'] = $headers;
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

        $result = json_decode($response, true);
        $emailId = is_array($result) ? ($result['id'] ?? '') : '';
        if (!is_string($emailId) || $emailId === '') {
            throw new RuntimeException('The Resend API did not return a sent email ID.');
        }
        return $emailId;
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

        return $this->send_email($recipientEmail, 'Welcome to GymMate', $html, $text) !== '';
    }

    public function send_payment_status(
        string $recipientName,
        string $recipientEmail,
        string $planName,
        $amount,
        string $status,
        string $reference
    ): bool {
        if (!in_array($status, ['Verified', 'Rejected'], true)) {
            throw new InvalidArgumentException('A valid membership payment status is required.');
        }
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid recipient email address is required.');
        }

        $approved = $status === 'Verified';
        $subject = $approved ? 'Your GymMate membership payment is approved' : 'Your GymMate membership payment was rejected';
        $decision = $approved
            ? 'Your payment has been approved, and your membership is now active.'
            : 'Your payment was not approved. Please contact GymMate if you think this was a mistake.';
        $safeName = htmlspecialchars($recipientName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safePlan = htmlspecialchars($planName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeReference = htmlspecialchars($reference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $formattedAmount = 'PHP ' . number_format((float) $amount, 2);
        $text = "Hi {$recipientName},\n\n{$decision}\n\n"
            . "Plan: {$planName}\nAmount: {$formattedAmount}\nGymMate order reference: {$reference}\n\nGymMate";
        $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#20231c">'
            . '<h1>' . ($approved ? 'Payment approved' : 'Payment rejected') . '</h1>'
            . '<p>Hi ' . $safeName . ',</p><p>' . htmlspecialchars($decision, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '<p><strong>Plan:</strong> ' . $safePlan . '<br><strong>Amount:</strong> ' . $formattedAmount
            . '<br><strong>GymMate order reference:</strong> ' . $safeReference . '</p><p>GymMate</p></body></html>';

        return $this->send_email($recipientEmail, $subject, $html, $text) !== '';
    }

    public function send_inbound_reply(
        string $recipientEmail,
        string $subject,
        string $body,
        string $messageId
    ): string {
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('The received email has an invalid sender address.');
        }
        if (!preg_match('/\A<[^<>\r\n]{1,250}>\z/', $messageId)) {
            throw new RuntimeException('The received email does not have a valid message ID for replies.');
        }
        $receivingAddress = trim((string) getenv('RESEND_RECEIVING_ADDRESS'));
        if ($receivingAddress === '') {
            throw new RuntimeException('Set RESEND_RECEIVING_ADDRESS to route customer replies back to the GymMate inbox.');
        }
        $subject = trim((string) preg_replace('/[\r\n\t]+/', ' ', $subject));
        if ($subject === '') {
            $subject = 'your message';
        }
        if (stripos($subject, 'Re:') !== 0) {
            $subject = 'Re: ' . $subject;
        }
        $body = trim($body);
        if ($body === '' || strlen($body) > 5000) {
            throw new InvalidArgumentException('The reply must contain 1 to 5000 characters.');
        }

        $safeBody = nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#20231c">'
            . $safeBody . '<p>GymMate</p></body></html>';

        return $this->send_email(
            $recipientEmail,
            $subject,
            $html,
            $body . "\n\nGymMate",
            $receivingAddress,
            ['In-Reply-To' => $messageId]
        );
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
        ) !== '';
    }
}
