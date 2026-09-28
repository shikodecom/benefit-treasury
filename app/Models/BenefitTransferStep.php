<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BenefitTransferStep extends Model
{
    protected $table = 'benefit_transfer_steps';

    public function group(): BelongsTo
    {
        return $this->belongsTo(BenefitTransferGroup::class, 'transfer_group_id');
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(BenefitAccount::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(BenefitAccount::class, 'to_account_id');
    }

    public function conversionRule(): BelongsTo
    {
        return $this->belongsTo(ConversionRule::class, 'conversion_rule_id');
    }

    public function planningEquivalentProgram(): BelongsTo
    {
        return $this->belongsTo(BenefitProgram::class, 'planning_equivalent_program_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BenefitTransaction::class, 'transfer_step_id');
    }
}
