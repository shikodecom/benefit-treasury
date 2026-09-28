<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BenefitTransferGroup extends Model
{
    protected $table = 'benefit_transfer_groups';

    public function householdMember(): BelongsTo
    {
        return $this->belongsTo(HouseholdMember::class, 'household_member_id');
    }

    public function targetProgram(): BelongsTo
    {
        return $this->belongsTo(BenefitProgram::class, 'target_program_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(BenefitTransferStep::class, 'transfer_group_id');
    }
}
