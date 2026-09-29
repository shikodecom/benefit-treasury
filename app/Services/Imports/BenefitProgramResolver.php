<?php

namespace App\Services\Imports;

use App\Models\BenefitProgram;
use App\Models\BenefitProgramAlias;

class BenefitProgramResolver
{
    public function resolve(string $name, ?string $scope = null): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $programs = BenefitProgram::query()->where('name', $name)->pluck('id');
        if ($programs->count() === 1) {
            return $programs->first();
        }
        if ($programs->count() > 1) {
            return null;
        }
        if ($scope !== null) {
            $scoped = BenefitProgramAlias::query()->where('alias', $name)->where('source_scope', $scope)->distinct()->pluck('program_id');
            if ($scoped->count() === 1) {
                return $scoped->first();
            }
            if ($scoped->count() > 1) {
                return null;
            }
        }
        $global = BenefitProgramAlias::query()->where('alias', $name)->whereNull('source_scope')->distinct()->pluck('program_id');

        return $global->count() === 1 ? $global->first() : null;
    }
}
