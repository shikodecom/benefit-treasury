<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConversionRuleGroup extends Model
{
    protected $table = 'conversion_rule_groups';

    public function fromProgram(): BelongsTo
    {
        return $this->belongsTo(BenefitProgram::class, 'from_program_id');
    }

    public function toProgram(): BelongsTo
    {
        return $this->belongsTo(BenefitProgram::class, 'to_program_id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(ConversionRule::class, 'rule_group_id');
    }
}
