<?php

namespace App\Logging;

use App\Alerts\TelegramClient;
use App\Models\AlertSetting;
use Illuminate\Support\Facades\Cache;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Forwards error log records to the alert chat in Telegram, at most once per 10 minutes for the same error.
 */
class TelegramLogHandler extends AbstractProcessingHandler
{
    private const int THROTTLE_SECONDS = 600;

    // Telegram allows 4096 characters; the rest is left for the header and markup.
    private const int MESSAGE_LIMIT = 3500;

    private const string CHAT_ID_CACHE_KEY = 'spread:alerts:chat_id';

    // The file store: the error may be the database itself, which backs the default cache.
    private const string CACHE_STORE = 'file';

    private static bool $isSending = false;

    public function __construct(int|string|Level $level = Level::Error, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        // Logging while sending (e.g. Telegram itself failing) must not loop back here.
        if (self::$isSending) {
            return;
        }

        self::$isSending = true;

        try {
            $this->send($record);
        } catch (Throwable) {
            // The record is already in the file log; reporting must never break the request.
        } finally {
            self::$isSending = false;
        }
    }

    private function send(LogRecord $record): void
    {
        $exception = $record->context['exception'] ?? null;
        $place = $exception instanceof Throwable ? "{$exception->getFile()}:{$exception->getLine()}" : '';
        $fingerprint = sha1("{$record->message}|{$place}");
        $cache = Cache::store(self::CACHE_STORE);
        $isFirstInWindow = $cache->add("spread:log-alert:{$fingerprint}", 1, self::THROTTLE_SECONDS);

        if (! $isFirstInWindow) {
            return;
        }

        $chatId = $this->chatId();

        if ($chatId === null) {
            return;
        }

        $text = $this->text($record, $exception, $place);

        app(TelegramClient::class)->send($text, $chatId);
    }

    private function chatId(): ?int
    {
        $cache = Cache::store(self::CACHE_STORE);

        try {
            $chatId = AlertSetting::current()->chat_id;
        } catch (Throwable) {
            return $cache->get(self::CHAT_ID_CACHE_KEY);
        }

        $cache->forever(self::CHAT_ID_CACHE_KEY, $chatId);

        return $chatId;
    }

    private function text(LogRecord $record, mixed $exception, string $place): string
    {
        $details = $record->message;

        if ($exception instanceof Throwable) {
            $class = $exception::class;
            $details = "{$class}: {$exception->getMessage()}\nat {$place}";
        }

        $isTooLong = mb_strlen($details) > self::MESSAGE_LIMIT;
        $shortened = $isTooLong ? mb_substr($details, 0, self::MESSAGE_LIMIT).'…' : $details;
        $escaped = e($shortened);
        $level = $record->level->getName();

        return "🚨 <b>{$level}</b>\n<pre>{$escaped}</pre>";
    }
}
