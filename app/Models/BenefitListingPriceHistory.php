<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenefitListingPriceHistory extends Model
{
    protected $table = 'benefit_listing_price_history';

    public const UPDATED_AT = null;

    public function listing(): BelongsTo
    {
        return $this->belongsTo(BenefitListing::class, 'listing_id');
    }
}
