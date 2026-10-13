<?php

namespace Tests\Feature;

use App\Models\AlertSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class TelegramLogHandlerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<array<string, mixed>>
     */
    private array $sent = [];

    private int $status = 200;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::store('file')->flush();
        config(['services.telegram.bot_token' => '123456:test-token']);
        AlertSetting::create(array_merge(AlertSetting::DEFAULTS, ['chat_id' => 42]));

        Http::fake(function (Request $request) {
            $this->sent[] = $request->data();

            return $this->status === 200
                ? Http::response(['ok' => true])
                : Http::response(['ok' => false, 'description' => 'Internal Server Error'], $this->status);
        });
    }

    protected function tearDown(): void
    {
        Cache::store('file')->flush();

        parent::tearDown();
    }

    public function test_exception_is_sent_with_class_message_and_place(): void
    {
        $exception = new RuntimeException('boom');

        Log::channel('telegram')->error($exception->getMessage(), ['exception' => $exception]);

        $this->assertCount(1, $this->sent);
        $text = $this->sent[0]['text'];
        $this->assertSame(42, $this->sent[0]['chat_id']);
        $this->assertStringContainsString('🚨 <b>ERROR</b>', $text);
        $this->assertStringContainsString('RuntimeException: boom', $text);
        $this->assertStringContainsString('TelegramLogHandlerTest.php:', $text);
    }

    public function test_same_error_is_sent_once_per_ten_minutes(): void
    {
        $exception = new RuntimeException('boom');

        for ($i = 0; $i < 50; $i++) {
            Log::channel('telegram')->error('boom', ['exception' => $exception]);
        }

        $this->assertCount(1, $this->sent);

        $this->travel(11)->minutes();
        Log::channel('telegram')->error('boom', ['exception' => $exception]);

        $this->assertCount(2, $this->sent);
    }

    public function test_warning_is_not_sent(): void
    {
        Log::channel('telegram')->warning('slow query');

        $this->assertSame([], $this->sent);
    }

    public function test_telegram_failure_does_not_break_the_caller(): void
    {
        $this->status = 500;

        Log::channel('telegram')->error('boom');

        $this->assertCount(1, $this->sent);
    }

    public function test_chat_id_survives_a_database_outage(): void
    {
        Log::channel('telegram')->error('first');
        $host = config('database.connections.pgsql.host');
        config(['database.connections.pgsql.host' => 'no-such-host.invalid']);
        DB::purge('pgsql');

        Log::channel('telegram')->error('database is down');

        config(['database.connections.pgsql.host' => $host]);
        DB::purge('pgsql');
        $this->assertCount(2, $this->sent);
        $this->assertSame(42, $this->sent[1]['chat_id']);
    }

    public function test_long_message_is_cut_and_html_escaped(): void
    {
        $message = '<script>'.str_repeat('x', 5000);

        Log::channel('telegram')->error($message);

        $text = $this->sent[0]['text'];
        $this->assertLessThan(4096, mb_strlen($text));
        $this->assertStringContainsString('&lt;script&gt;', $text);
        $this->assertStringNotContainsString('<script>', $text);
    }

    public function test_stack_writes_the_file_log_and_telegram(): void
    {
        config(['logging.channels.stack.channels' => ['single', 'telegram']]);
        Log::forgetChannel('stack');

        Log::error('stacked error');

        $this->assertCount(1, $this->sent);
    }
}
