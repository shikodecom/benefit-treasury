<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BenefitProgram extends Model
{
    protected $table = 'benefit_programs';

    public function aliases(): HasMany
    {
        return $this->hasMany(BenefitProgramAlias::class, 'program_id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(BenefitAccount::class, 'program_id');
    }
}
