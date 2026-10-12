<?php

namespace App\HotPath;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Redis;
use RedisException;
use Symfony\Component\Uid\Ulid;

/**
 * GET/HEAD /p/{key} by docs/contract.md. Stores the postback as is; the key and params are checked by the worker.
 */
final class PostbackController
{
    private const string POSTBACKS_KEY = 'spread:postbacks';

    private const int MESSAGE_VERSION = 1;

    private const int KEY_LIMIT = 64;

    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public function __invoke(Request $request, string $key): Response
    {
        if ($request->isMethod('HEAD')) {
            return $this->respond('OK', 200);
        }

        $message = $this->postbackMessage($request, $key);

        try {
            $streamId = Redis::connection('spread')->xadd(self::POSTBACKS_KEY, '*', ['payload' => $message]);
        } catch (RedisException) {
            return $this->unavailable();
        }

        if ($streamId === false) {
            return $this->unavailable();
        }

        return $this->respond('OK', 200);
    }

    private function postbackMessage(Request $request, string $key): string
    {
        $ulid = new Ulid;
        $receivedAt = $ulid->getDateTime();
        $queryString = (string) $request->server('QUERY_STRING', '');
        $params = QueryParams::parse($queryString);

        $message = [
            'v' => self::MESSAGE_VERSION,
            'postback_id' => (string) $ulid,
            'ts' => $receivedAt->format('Y-m-d\TH:i:s.v\Z'),
            'key' => Utf8::truncate($key, self::KEY_LIMIT),
            // nginx overwrites X-Real-IP with the peer address, so the client cannot spoof it.
            'ip' => $request->headers->get('X-Real-IP') ?? '',
            // An object even when empty: PHP would encode [] as a JSON list.
            'params' => (object) $params,
        ];

        return json_encode($message, self::JSON_FLAGS);
    }

    /**
     * A 200 would tell the network the postback was delivered and the conversion would be lost; on 503 networks may retry.
     */
    private function unavailable(): Response
    {
        return $this->respond('Service Unavailable', 503);
    }

    private function respond(string $body, int $status): Response
    {
        return new Response($body, $status, [
            'Content-Type' => 'text/plain',
            'Cache-Control' => 'no-store',
        ]);
    }
}
