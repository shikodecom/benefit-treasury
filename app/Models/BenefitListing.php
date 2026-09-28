<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BenefitListing extends Model
{
    protected $table = 'benefit_listings';

    public function items(): HasMany
    {
        return $this->hasMany(BenefitListingItem::class, 'listing_id');
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(BenefitListingPriceHistory::class, 'listing_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BenefitTransaction::class, 'listing_id');
    }
}
