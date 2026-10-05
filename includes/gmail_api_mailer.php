<?php

use Google\Client;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;

class GmailApiMailer
{
    private static ?Client $client = null;

    public static function isConfigured(): bool
    {
        return GMAIL_API_ENABLED
            && GOOGLE_CLIENT_ID !== ''
            && GOOGLE_CLIENT_SECRET !== ''
            && GOOGLE_REFRESH_TOKEN !== ''
            && GMAIL_FROM !== '';
    }

    public static function send(string $to, string $subject, string $htmlBody, string $altBody = ''): bool
    {
        if (!self::isConfigured()) {
            error_log('Gmail API configuration missing');
            return false;
        }

        try {
            $client = self::getClient();
            $service = new Gmail($client);

            $rawMessage = self::buildRawMessage($to, $subject, $htmlBody, $altBody);

            $message = new Message();
            $message->setRaw($rawMessage);

            $service->users_messages->send('me', $message);
            return true;
        } catch (\Google\Service\Exception $e) {
            error_log('Gmail API message send failed: ' . $e->getMessage());
            return false;
        } catch (\Google\Exception $e) {
            error_log('Gmail OAuth authentication failed: ' . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            error_log('Gmail API error: ' . $e->getMessage());
            return false;
        }
    }

    private static function getClient(): Client
    {
        if (self::$client !== null) {
            return self::$client;
        }

        $client = new Client();
        $client->setClientId(GOOGLE_CLIENT_ID);
        $client->setClientSecret(GOOGLE_CLIENT_SECRET);
        $client->setRedirectUri('urn:ietf:wg:oauth:2.0:oob');
        $client->addScope(Gmail::GMAIL_SEND);
        $client->setAccessType('offline');
        $client->setPrompt('select_account consent');
        $client->setAccessToken([
            'refresh_token' => GOOGLE_REFRESH_TOKEN,
            'expires_in' => 0,
        ]);

        if ($client->isAccessTokenExpired()) {
            try {
                $client->fetchAccessTokenWithRefreshToken(GOOGLE_REFRESH_TOKEN);
            } catch (\Google\Exception $e) {
                error_log('Gmail access token refresh failed: ' . $e->getMessage());
                throw $e;
            }
        }

        self::$client = $client;
        return $client;
    }

    private static function buildRawMessage(string $to, string $subject, string $htmlBody, string $altBody): string
    {
        $boundary = '----=_Part_' . md5(uniqid('', true));

        $headers = [
            'From: ' . GMAIL_FROM_NAME . ' <' . GMAIL_FROM . '>',
            'To: ' . $to,
            'Subject: ' . self::encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $body = '--' . $boundary . "\r\n";
        $body .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
        $body .= 'Content-Transfer-Encoding: 7bit' . "\r\n\r\n";
        $body .= ($altBody !== '' ? $altBody : strip_tags($htmlBody)) . "\r\n\r\n";

        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
        $body .= 'Content-Transfer-Encoding: 7bit' . "\r\n\r\n";
        $body .= $htmlBody . "\r\n\r\n";

        $body .= '--' . $boundary . '--';

        $raw = implode("\r\n", $headers) . "\r\n\r\n" . $body;

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
