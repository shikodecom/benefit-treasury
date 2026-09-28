<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversionRule extends Model
{
    protected $table = 'conversion_rules';

    public function group(): BelongsTo
    {
        return $this->belongsTo(ConversionRuleGroup::class, 'rule_group_id');
    }

    public function fromProgram(): BelongsTo
    {
        return $this->belongsTo(BenefitProgram::class, 'from_program_id');
    }

    public function toProgram(): BelongsTo
    {
        return $this->belongsTo(BenefitProgram::class, 'to_program_id');
    }
}
