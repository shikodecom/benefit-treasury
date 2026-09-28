<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BenefitLot extends Model
{
    protected $table = 'benefit_lots';

    public function account(): BelongsTo
    {
        return $this->belongsTo(BenefitAccount::class, 'account_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BenefitTransaction::class, 'lot_id');
    }

    public function listingItems(): HasMany
    {
        return $this->hasMany(BenefitListingItem::class, 'lot_id');
    }
}
