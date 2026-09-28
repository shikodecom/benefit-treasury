<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversionRouteTemplateStep extends Model
{
    protected $table = 'conversion_route_template_steps';

    public const UPDATED_AT = null;

    public function route(): BelongsTo
    {
        return $this->belongsTo(ConversionRouteTemplate::class, 'route_template_id');
    }

    public function ruleGroup(): BelongsTo
    {
        return $this->belongsTo(ConversionRuleGroup::class, 'rule_group_id');
    }

    public function preferredRule(): BelongsTo
    {
        return $this->belongsTo(ConversionRule::class, 'preferred_rule_id');
    }
}
