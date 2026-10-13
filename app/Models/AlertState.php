<?php

namespace App\Models;

use App\Alerts\AlertType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['type', 'subject', 'opened_at', 'last_sent_at', 'message'])]
class AlertState extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'opened_at' => 'immutable_datetime',
            'last_sent_at' => 'immutable_datetime',
        ];
    }
}
