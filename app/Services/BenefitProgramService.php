<?php

namespace App\Services;

use App\Models\BenefitProgram;
use App\Models\BenefitProgramAlias;
use App\Models\BenefitTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class BenefitProgramService
{
    public function create(array $data, bool $forceDuplicateName = false): BenefitProgram
    {
        $name = trim($data['name']);
        if (! $forceDuplicateName && BenefitProgram::query()->where('name', $name)->exists()) {
            throw ValidationException::withMessages([
                'name' => '同じ名前の特典制度があります。確認してから登録してください。',
            ]);
        }

        $program = new BenefitProgram;
        $this->fill($program, $data);
        $program->active = true;
        $program->save();

        return $program;
    }

    public function update(BenefitProgram $program, array $data, bool $forceDuplicateName = false): BenefitProgram
    {
        $name = trim($data['name']);
        if (! $forceDuplicateName && $name !== $program->name
            && BenefitProgram::query()->where('name', $name)->where('id', '!=', $program->id)->exists()) {
            throw ValidationException::withMessages([
                'name' => '同じ名前の特典制度があります。確認してから更新してください。',
            ]);
        }

        if ($this->hasTransactions($program)
            && ($program->unit_name !== trim($data['unit_name']) || $program->category !== $data['category'])) {
            throw ValidationException::withMessages([
                'unit_name' => '取引履歴がある制度の単位・カテゴリは変更できません。',
            ]);
        }

        $this->fill($program, $data);
        $program->save();

        return $program;
    }

    public function deactivate(BenefitProgram $program): void
    {
        $program->forceFill(['active' => false])->save();
    }

    public function activate(BenefitProgram $program): void
    {
        $program->forceFill(['active' => true])->save();
    }

    public function hasTransactions(BenefitProgram $program): bool
    {
        return BenefitTransaction::query()->whereHas('account', function ($query) use ($program): void {
            $query->where('program_id', $program->id);
        })->exists();
    }

    public function addAlias(BenefitProgram $program, string $alias, ?string $scope): BenefitProgramAlias
    {
        $alias = trim($alias);
        $scope = blank($scope) || $scope === 'global' ? null : trim($scope);

        if (BenefitProgramAlias::query()->where('alias', $alias)->where('source_scope', $scope)->exists()) {
            throw ValidationException::withMessages(['alias' => 'この範囲で同じ別名が登録されています。']);
        }

        $record = new BenefitProgramAlias;
        $record->program_id = $program->id;
        $record->alias = $alias;
        $record->source_scope = $scope;
        $record->save();

        return $record;
    }

    public function removeAlias(BenefitProgram $program, BenefitProgramAlias $alias): void
    {
        if ($alias->program_id !== $program->id) {
            abort(404);
        }

        $alias->delete();
    }

    public function listActive(): Collection
    {
        return BenefitProgram::query()->where('active', true)->orderBy('name')->get();
    }

    public function search(?string $keyword, ?string $category, string $status, ?bool $transferable, ?bool $sellable, int $perPage): LengthAwarePaginator
    {
        return BenefitProgram::query()
            ->withCount('accounts')
            ->when($status !== 'all', fn ($query) => $query->where('active', $status === 'active'))
            ->when($category, fn ($query) => $query->where('category', $category))
            ->when($transferable !== null, fn ($query) => $query->where('transferable', $transferable))
            ->when($sellable !== null, fn ($query) => $query->where('sellable', $sellable))
            ->when($keyword, function ($query) use ($keyword): void {
                $query->where(function ($query) use ($keyword): void {
                    $query->where('name', 'like', "%{$keyword}%")
                        ->orWhere('provider', 'like', "%{$keyword}%")
                        ->orWhereHas('aliases', fn ($query) => $query->where('alias', 'like', "%{$keyword}%"));
                });
            })
            ->orderByDesc('active')->orderBy('name')->paginate($perPage)->withQueryString();
    }

    private function fill(BenefitProgram $program, array $data): void
    {
        $program->name = trim($data['name']);
        $program->provider = blank($data['provider'] ?? null) ? null : trim($data['provider']);
        $program->category = $data['category'];
        $program->unit_name = trim($data['unit_name']);
        $program->default_unit_value_yen = $data['default_unit_value_yen'] ?? null;
        $program->transferable = $data['transferable'] ?? false;
        $program->sellable = $data['sellable'] ?? false;
        $program->official_url = blank($data['official_url'] ?? null) ? null : trim($data['official_url']);
        $program->notes = blank($data['notes'] ?? null) ? null : trim($data['notes']);
    }
}
