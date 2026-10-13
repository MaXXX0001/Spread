<?php

namespace Tests\Feature;

use App\Alerts\TelegramClient;
use App\Alerts\TelegramUnavailable;
use App\Models\AlertSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramClientTest extends TestCase
{
    use RefreshDatabase;

    private const string TOKEN = '123456:test-token';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.bot_token' => self::TOKEN]);
    }

    public function test_message_goes_to_the_saved_chat(): void
    {
        AlertSetting::create(array_merge(AlertSetting::DEFAULTS, ['chat_id' => 576513036]));
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        app(TelegramClient::class)->send('<b>Hello</b>');

        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === 576513036
            && $request['text'] === '<b>Hello</b>'
            && $request['disable_web_page_preview'] === true);
    }

    public function test_missing_chat_fails_without_request(): void
    {
        Http::fake();

        $this->expectException(TelegramUnavailable::class);
        $this->expectExceptionMessage('chat ID is not set');

        try {
            app(TelegramClient::class)->send('Hello');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_ok_false_is_not_a_delivery(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);

        $this->expectException(TelegramUnavailable::class);
        $this->expectExceptionMessage('bot was blocked');

        app(TelegramClient::class)->send('Hello', 42);
    }

    public function test_connection_error_hides_the_token(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host for https://api.telegram.org/bot'.self::TOKEN.'/sendMessage'));

        try {
            app(TelegramClient::class)->send('Hello', 42);
            $this->fail('No exception thrown.');
        } catch (TelegramUnavailable $exception) {
            $this->assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }
}
