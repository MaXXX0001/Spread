<?php

namespace App\HotPath;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Redis;
use RedisException;

/**
 * GET /health by docs/contract.md: OK only while the redirect can work, i.e. Redis answers and the config snapshot exists.
 */
final class HealthController
{
    private const string META_KEY = 'spread:config:meta';

    public function __invoke(): Response
    {
        try {
            $meta = Redis::connection('spread')->get(self::META_KEY);
        } catch (RedisException) {
            return $this->respond(503, 'redis unavailable');
        }

        if (! is_string($meta)) {
            return $this->respond(503, 'no config snapshot');
        }

        return $this->respond(200, 'OK');
    }

    private function respond(int $status, string $body): Response
    {
        return new Response($body, $status, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
