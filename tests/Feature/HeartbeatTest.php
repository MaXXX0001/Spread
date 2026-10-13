<?php

namespace Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class HeartbeatTest extends TestCase
{
    /**
     * @var list<array{request: RequestInterface}>
     */
    private array $requests = [];

    public function test_alert_check_pings_healthchecks_after_success(): void
    {
        $event = $this->alertCheckEvent('https://hc-ping.com/test-uuid');

        $event->finish($this->app, 0);

        $this->assertCount(1, $this->requests);
        $this->assertSame('https://hc-ping.com/test-uuid', (string) $this->requests[0]['request']->getUri());
    }

    public function test_failed_check_does_not_ping(): void
    {
        $event = $this->alertCheckEvent('https://hc-ping.com/test-uuid');

        $event->finish($this->app, 1);

        $this->assertSame([], $this->requests);
    }

    public function test_no_ping_without_url(): void
    {
        $event = $this->alertCheckEvent(null);

        $event->finish($this->app, 0);

        $this->assertSame([], $this->requests);
    }

    /**
     * Rebuilds the schedule with the given URL, since routes/console.php reads it while defining events.
     */
    private function alertCheckEvent(?string $url): Event
    {
        config(['services.healthchecks.scheduler_url' => $url]);
        $handler = HandlerStack::create(new MockHandler([new Response(200), new Response(200)]));
        $handler->push(Middleware::history($this->requests));
        $this->app->instance(Client::class, new Client(['handler' => $handler]));
        $schedule = new Schedule;
        ScheduleFacade::swap($schedule);

        require base_path('routes/console.php');

        return collect($schedule->events())
            ->first(fn (Event $event): bool => str_contains($event->command ?? '', 'spread:alerts:check'));
    }
}
