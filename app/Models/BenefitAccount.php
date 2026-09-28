<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BenefitAccount extends Model
{
    protected $table = 'benefit_accounts';

    public function program(): BelongsTo
    {
        return $this->belongsTo(BenefitProgram::class, 'program_id');
    }

    public function householdMember(): BelongsTo
    {
        return $this->belongsTo(HouseholdMember::class, 'household_member_id');
    }

    public function lots(): HasMany
    {
        return $this->hasMany(BenefitLot::class, 'account_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BenefitTransaction::class, 'account_id');
    }
}
