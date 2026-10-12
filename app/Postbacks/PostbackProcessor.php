<?php

namespace App\Postbacks;

use App\Models\Click;
use App\Models\Conversion;
use App\Models\ConversionStatusChange;
use App\Models\CpaNetwork;
use App\Models\Postback;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Turns one raw postback log entry into a conversion change and records the result on the entry.
 */
class PostbackProcessor
{
    private const string CLICK_ID_PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/';

    private const string PAYOUT_PATTERN = '/^\d{1,8}([.,]\d{1,4})?$/';

    private const string CURRENCY_PATTERN = '/^[A-Za-z]{3}$/';

    private const int TXID_LIMIT = 255;

    public function process(Postback $postback): void
    {
        DB::transaction(function () use ($postback): void {
            $this->apply($postback);
            $postback->processed_at = Carbon::now('UTC');
            $postback->save();
        });
    }

    private function apply(Postback $postback): void
    {
        $network = $postback->cpaNetwork;

        if ($network === null) {
            $this->finish($postback, PostbackState::REJECTED, 'invalid_key');

            return;
        }

        if (! $this->isIpAllowed($network, $postback->ip)) {
            $this->finish($postback, PostbackState::REJECTED, 'ip_not_allowed');

            return;
        }

        $params = $postback->params;
        $clickId = $this->clickId($params['click_id'] ?? '');

        if ($clickId === null) {
            $this->finish($postback, PostbackState::REJECTED, 'invalid_click_id');

            return;
        }

        $postback->click_id = $clickId;
        $status = $this->status($network, $params['status'] ?? '');

        if ($status === null) {
            $this->finish($postback, PostbackState::REJECTED, 'unknown_status');

            return;
        }

        $warnings = [];
        $payout = $this->payout($params['payout'] ?? '', $warnings);
        $currency = $this->currency($params['currency'] ?? '', $warnings);
        $txid = $this->txid($params['txid'] ?? '');
        $values = ['payout' => $payout, 'currency' => $currency, 'txid' => $txid];
        $conversion = Conversion::query()
            ->where('click_id', '=', $clickId)
            ->lockForUpdate()
            ->first();

        if ($conversion === null) {
            $conversion = $this->createConversion($postback, $network, $clickId, $status, $values);
            $this->finish($postback, PostbackState::PROCESSED, $this->warningsReason($warnings), $conversion);

            return;
        }

        if ($conversion->cpa_network_id !== $network->id) {
            $this->finish($postback, PostbackState::REJECTED, 'network_mismatch');

            return;
        }

        if ($postback->received_at->lt($conversion->last_postback_at)) {
            $this->finish($postback, PostbackState::IGNORED, 'stale', $conversion);

            return;
        }

        if ($conversion->status === $status) {
            $this->finish($postback, PostbackState::IGNORED, 'same_status', $conversion);

            return;
        }

        if (! $conversion->status->canTransitionTo($status)) {
            $this->finish($postback, PostbackState::IGNORED, 'transition_not_allowed', $conversion);

            return;
        }

        $this->changeStatus($postback, $conversion, $status, $values);
        $this->finish($postback, PostbackState::PROCESSED, $this->warningsReason($warnings), $conversion);
    }

    /**
     * @param  array{payout: ?string, currency: ?string, txid: ?string}  $values
     */
    private function createConversion(Postback $postback, CpaNetwork $network, string $clickId, ConversionStatus $status, array $values): Conversion
    {
        $click = Click::query()->find($clickId, ['campaign_id', 'offer_id']);
        $conversion = new Conversion;
        $conversion->click_id = $clickId;
        $conversion->cpa_network_id = $network->id;
        $conversion->campaign_id = $click?->campaign_id;
        $conversion->offer_id = $click?->offer_id;
        $conversion->status = $status;
        $conversion->payout = $values['payout'];
        $conversion->currency = $values['currency'];
        $conversion->txid = $values['txid'];
        $conversion->last_postback_at = $postback->received_at;
        $conversion->status_changed_at = $postback->received_at;
        $conversion->save();

        $this->recordStatusChange($postback, $conversion, null);

        return $conversion;
    }

    /**
     * Payout, currency and txid change only together with the status and only to a valid value.
     *
     * @param  array{payout: ?string, currency: ?string, txid: ?string}  $values
     */
    private function changeStatus(Postback $postback, Conversion $conversion, ConversionStatus $status, array $values): void
    {
        $previousStatus = $conversion->status;
        $conversion->status = $status;
        $conversion->payout = $values['payout'] ?? $conversion->payout;
        $conversion->currency = $values['currency'] ?? $conversion->currency;
        $conversion->txid = $values['txid'] ?? $conversion->txid;
        $conversion->last_postback_at = $postback->received_at;
        $conversion->status_changed_at = $postback->received_at;
        $conversion->save();

        $this->recordStatusChange($postback, $conversion, $previousStatus);
    }

    private function recordStatusChange(Postback $postback, Conversion $conversion, ?ConversionStatus $previousStatus): void
    {
        $change = new ConversionStatusChange;
        $change->conversion_id = $conversion->id;
        $change->postback_id = $postback->postback_id;
        $change->from_status = $previousStatus;
        $change->to_status = $conversion->status;
        $change->payout = $conversion->payout;
        $change->currency = $conversion->currency;
        $change->changed_at = $postback->received_at;
        $change->save();
    }

    private function finish(Postback $postback, PostbackState $state, ?string $reason, ?Conversion $conversion = null): void
    {
        $postback->state = $state;
        $postback->reason = $reason;
        $postback->conversion_id = $conversion?->id;
    }

    private function isIpAllowed(CpaNetwork $network, ?string $ip): bool
    {
        $allowedIps = $network->allowed_ips;

        if ($allowedIps === []) {
            return true;
        }

        if ($ip === null) {
            return false;
        }

        return IpUtils::checkIp($ip, $allowedIps);
    }

    private function clickId(string $value): ?string
    {
        $clickId = strtoupper($value);

        return preg_match(self::CLICK_ID_PATTERN, $clickId) === 1 ? $clickId : null;
    }

    /**
     * The network's mapping wins over our own status names.
     */
    private function status(CpaNetwork $network, string $value): ?ConversionStatus
    {
        $networkValue = StatusMap::normalize($value);
        $mapped = $network->status_map[$networkValue] ?? $networkValue;

        return ConversionStatus::tryFrom($mapped);
    }

    /**
     * @param  list<string>  $warnings
     */
    private function payout(string $value, array &$warnings): ?string
    {
        if ($value === '') {
            return null;
        }

        if (preg_match(self::PAYOUT_PATTERN, $value) !== 1) {
            $warnings[] = 'invalid_payout';

            return null;
        }

        return str_replace(',', '.', $value);
    }

    /**
     * @param  list<string>  $warnings
     */
    private function currency(string $value, array &$warnings): ?string
    {
        if ($value === '') {
            return null;
        }

        if (preg_match(self::CURRENCY_PATTERN, $value) !== 1) {
            $warnings[] = 'invalid_currency';

            return null;
        }

        return strtoupper($value);
    }

    private function txid(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, self::TXID_LIMIT);
    }

    /**
     * @param  list<string>  $warnings
     */
    private function warningsReason(array $warnings): ?string
    {
        return $warnings === [] ? null : implode(',', $warnings);
    }
}
