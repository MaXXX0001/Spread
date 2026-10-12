<?php

namespace App\HotPath;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use RedisException;
use Symfony\Component\Uid\Ulid;

/**
 * GET/HEAD /c/{alias} by docs/contract.md. Reads only Redis; never touches the database.
 */
final class RedirectController
{
    private const string CAMPAIGNS_KEY = 'spread:config:campaigns';

    private const string META_KEY = 'spread:config:meta';

    private const string CLICKS_KEY = 'spread:clicks';

    private const int MESSAGE_VERSION = 1;

    private const int USER_AGENT_LIMIT = 1024;

    private const int REFERER_LIMIT = 2048;

    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public function __invoke(Request $request, string $alias): RedirectResponse
    {
        try {
            [$metaJson, $campaignJson] = Redis::connection('spread')->pipeline(function (\Redis $pipe) use ($alias): void {
                $pipe->get(self::META_KEY);
                $pipe->hGet(self::CAMPAIGNS_KEY, $alias);
            });
        } catch (RedisException) {
            return $this->ownFallback();
        }

        if (! is_string($metaJson)) {
            return $this->ownFallback();
        }

        $meta = json_decode($metaJson, true, flags: JSON_THROW_ON_ERROR);

        if (! is_string($campaignJson)) {
            return $this->redirect($meta['fallback_url']);
        }

        $campaign = json_decode($campaignJson, true, flags: JSON_THROW_ON_ERROR);
        $ulid = new Ulid;
        $clickId = (string) $ulid;
        $queryString = (string) $request->server('QUERY_STRING', '');
        $params = QueryParams::parse($queryString);

        // HEAD comes from link checkers and ad network moderation, not visitors: same Location, no click.
        if (! $request->isMethod('HEAD')) {
            $message = $this->clickMessage($request, $ulid, $campaign, $params);

            try {
                $streamId = Redis::connection('spread')->xadd(self::CLICKS_KEY, '*', ['payload' => $message]);
            } catch (RedisException) {
                return $this->ownFallback();
            }

            if ($streamId === false) {
                return $this->ownFallback();
            }
        }

        if (! $campaign['active']) {
            return $this->redirect($meta['fallback_url']);
        }

        $offerUrl = OfferUrl::build($campaign['offer_url'], $clickId, $params);

        return $this->redirect($offerUrl);
    }

    /**
     * @param  array{id: int, offer_id: int, active: bool, offer_url: string}  $campaign
     * @param  array<string, string>  $params
     */
    private function clickMessage(Request $request, Ulid $ulid, array $campaign, array $params): string
    {
        $userAgent = $request->headers->get('User-Agent');
        $referer = $request->headers->get('Referer');
        $clickedAt = $ulid->getDateTime();

        $message = [
            'v' => self::MESSAGE_VERSION,
            'click_id' => (string) $ulid,
            'ts' => $clickedAt->format('Y-m-d\TH:i:s.v\Z'),
            'status' => $campaign['active'] ? 'ok' : 'campaign_disabled',
            'campaign_id' => $campaign['id'],
            'offer_id' => $campaign['offer_id'],
            // nginx overwrites X-Real-IP with the peer address, so the client cannot spoof it.
            'ip' => $request->headers->get('X-Real-IP') ?? '',
            'user_agent' => $userAgent === null ? null : Utf8::truncate($userAgent, self::USER_AGENT_LIMIT),
            'referer' => $referer === null ? null : Utf8::truncate($referer, self::REFERER_LIMIT),
            // An object even when empty: PHP would encode [] as a JSON list.
            'params' => (object) $params,
        ];

        return json_encode($message, self::JSON_FLAGS);
    }

    private function ownFallback(): RedirectResponse
    {
        $fallbackUrl = (string) config('spread.fallback_url');

        return $this->redirect($fallbackUrl === '' ? '/' : $fallbackUrl);
    }

    private function redirect(string $url): RedirectResponse
    {
        return new RedirectResponse($url, 302, ['Cache-Control' => 'no-store']);
    }
}
