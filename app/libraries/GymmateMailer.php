<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

class GymmateMailer
{
    private function configured_mailer(): PHPMailer
    {
        $host = trim((string) getenv('SMTP_HOST'));
        $port = (int) (getenv('SMTP_PORT') ?: 587);
        $username = (string) getenv('SMTP_USER');
        $password = (string) getenv('SMTP_PASSWORD');
        $encryption = strtolower(trim((string) (getenv('SMTP_ENCRYPTION') ?: 'tls')));
        $fromAddress = trim((string) getenv('MAIL_FROM_ADDRESS'));
        $fromName = trim((string) (getenv('MAIL_FROM_NAME') ?: 'GymMate'));

        if ($host === '' || $username === '' || $password === '' || $fromAddress === '') {
            throw new RuntimeException('SMTP is not configured. Set SMTP_HOST, SMTP_USER, SMTP_PASSWORD, and MAIL_FROM_ADDRESS.');
        }
        if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid sender email address is required.');
        }
        if ($port < 1 || $port > 65535 || !in_array($encryption, ['tls', 'ssl'], true)) {
            throw new RuntimeException('SMTP_PORT or SMTP_ENCRYPTION has an invalid value.');
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $port;
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->SMTPSecure = $encryption === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Timeout = 15;
        $mail->setFrom($fromAddress, $fromName);
        return $mail;
    }

    public function send_welcome(string $recipientName, string $recipientEmail): bool
    {
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid recipient email address is required.');
        }
        $mail = $this->configured_mailer();
        try {
            $mail->addAddress($recipientEmail, $recipientName);
            $mail->isHTML(true);
            $safeName = htmlspecialchars($recipientName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $mail->Subject = 'Welcome to GymMate';
            $mail->Body = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#20231c">'
                . '<h1>Welcome to GymMate, ' . $safeName . '!</h1>'
                . '<p>Your account has been created successfully. Sign in to choose your membership and meet our trainers.</p>'
                . '<p>Move with intention. Get stronger together.</p></body></html>';
            $mail->AltBody = "Welcome to GymMate, {$recipientName}!\n\n"
                . 'Your account has been created successfully. Sign in to choose your membership and meet our trainers.'
                . "\n\nMove with intention. Get stronger together.";
            return $mail->send();
        } catch (MailException $exception) {
            throw new RuntimeException('SMTP could not send the welcome email.', 0, $exception);
        }
    }

    public function send_contact(string $recipientEmail, string $senderName, string $senderEmail, string $message): bool
    {
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid sender and recipient email address are required.');
        }
        $mail = $this->configured_mailer();
        try {
            $mail->addAddress($recipientEmail);
            $mail->addReplyTo($senderEmail, $senderName);
            $mail->isHTML(true);
            $safeName = htmlspecialchars($senderName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeEmail = htmlspecialchars($senderEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            $mail->Subject = 'GymMate website contact message';
            $mail->Body = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#20231c">'
                . '<h1>GymMate contact message</h1><p><strong>From:</strong> ' . $safeName
                . ' (' . $safeEmail . ')</p><p>' . $safeMessage . '</p></body></html>';
            $mail->AltBody = "From: {$senderName} ({$senderEmail})\n\n{$message}";
            return $mail->send();
        } catch (MailException $exception) {
            throw new RuntimeException('SMTP could not send the contact message.', 0, $exception);
        }
    }
}
