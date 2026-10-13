<?php

namespace App\Alerts;

use App\Models\AlertSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Sends HTML messages to the alert chat through the Telegram Bot API.
 */
class TelegramClient
{
    private const int TIMEOUT_SECONDS = 10;

    /**
     * @param  int|null  $chatId  The saved alert chat when null.
     *
     * @throws TelegramUnavailable
     */
    public function send(string $html, ?int $chatId = null): void
    {
        $token = config('services.telegram.bot_token');
        $chatId ??= AlertSetting::current()->chat_id;

        if (blank($token)) {
            throw new TelegramUnavailable('TELEGRAM_BOT_TOKEN is not set.');
        }

        if ($chatId === null) {
            throw new TelegramUnavailable('The alert chat ID is not set.');
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $html,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (ConnectionException $exception) {
            // cURL errors quote the request URL, which carries the token.
            $reason = str_replace($token, '***', $exception->getMessage());

            throw new TelegramUnavailable("Telegram is unreachable: {$reason}");
        }

        $isDelivered = $response->successful() && $response->json('ok') === true;

        if (! $isDelivered) {
            $description = $response->json('description') ?? "HTTP {$response->status()}";

            throw new TelegramUnavailable("Telegram refused the message: {$description}");
        }
    }
}
