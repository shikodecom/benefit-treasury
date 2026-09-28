<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HouseholdMember extends Model
{
    protected $table = 'household_members';

    public function accounts(): HasMany
    {
        return $this->hasMany(BenefitAccount::class, 'household_member_id');
    }
}
