<?php

namespace App\Filament\Widgets;

class WorstCampaigns extends CampaignsByProfit
{
    protected static ?int $sort = 3;

    protected static ?string $heading = 'Worst campaigns, last 7 days';

    protected function listsProfitable(): bool
    {
        return false;
    }
}
