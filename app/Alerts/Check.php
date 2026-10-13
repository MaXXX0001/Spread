<?php

namespace App\Alerts;

use App\Models\AlertSetting;

/**
 * One kind of alert: which subjects are in trouble right now.
 */
interface Check
{
    public function type(): AlertType;

    /**
     * @return array<string, string> Subject (e.g. "campaign:12") => HTML lines describing its problem.
     */
    public function problems(AlertSetting $settings): array;
}
