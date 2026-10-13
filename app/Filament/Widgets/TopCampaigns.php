<?php

namespace App\Filament\Widgets;

class TopCampaigns extends CampaignsByProfit
{
    protected static ?int $sort = 2;

    protected static ?string $heading = 'Top campaigns, last 7 days';

    protected function listsProfitable(): bool
    {
        return true;
    }
}
