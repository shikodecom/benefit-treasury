<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenefitListingItem extends Model
{
    protected $table = 'benefit_listing_items';

    public const UPDATED_AT = null;

    public function listing(): BelongsTo
    {
        return $this->belongsTo(BenefitListing::class, 'listing_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(BenefitLot::class, 'lot_id');
    }
}
