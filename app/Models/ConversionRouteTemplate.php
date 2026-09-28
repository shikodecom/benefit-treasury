<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConversionRouteTemplate extends Model
{
    protected $table = 'conversion_route_templates';

    public function targetProgram(): BelongsTo
    {
        return $this->belongsTo(BenefitProgram::class, 'target_program_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ConversionRouteTemplateStep::class, 'route_template_id');
    }
}
