<?php

namespace App\Services;

use App\Models\BenefitAccount;
use App\Models\BenefitProgram;
use App\Models\HouseholdMember;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class BenefitAccountService
{
    public function __construct(private readonly BenefitReadService $readService) {}

    public function create(array $data, bool $forceDuplicate = false): BenefitAccount
    {
        $this->assertActiveChoices($data);
        if (! $forceDuplicate && $this->duplicateCandidates($data)->exists()) {
            throw ValidationException::withMessages([
                'account_label' => '同じ制度・名義・ラベルの口座があります。確認してから登録してください。',
            ]);
        }

        $account = new BenefitAccount;
        $this->fill($account, $data);
        $account->active = true;
        $account->save();

        return $account;
    }

    public function update(BenefitAccount $account, array $data): BenefitAccount
    {
        $memberId = isset($data['household_member_id']) ? (int) $data['household_member_id'] : null;
        if (($account->program_id !== (int) $data['program_id']
                || $account->household_member_id !== $memberId)
            && $account->transactions()->exists()) {
            throw ValidationException::withMessages([
                'program_id' => '取引履歴がある口座の制度・名義は変更できません。',
            ]);
        }

        $this->assertActiveChoices($data, $account);
        $this->fill($account, $data);
        $account->save();

        return $account;
    }

    public function deactivate(BenefitAccount $account): void
    {
        $account->forceFill(['active' => false])->save();
    }

    public function activate(BenefitAccount $account): void
    {
        $account->forceFill(['active' => true])->save();
    }

    public function balance(BenefitAccount $account): string
    {
        return $this->readService->accountBalance($account->id);
    }

    public function duplicateCandidates(array $data): Builder
    {
        return BenefitAccount::query()->where('active', true)
            ->where('program_id', $data['program_id'])
            ->where('household_member_id', $data['household_member_id'] ?? null)
            ->where('account_label', $this->nullableTrim($data['account_label'] ?? null));
    }

    public function search(?string $keyword, ?int $programId, ?int $memberId, ?string $category, string $status, int $perPage): LengthAwarePaginator
    {
        return BenefitAccount::query()->with(['program', 'householdMember'])
            ->when($status !== 'all', fn ($query) => $query->where('active', $status === 'active'))
            ->when($programId, fn ($query) => $query->where('program_id', $programId))
            ->when($memberId, fn ($query) => $query->where('household_member_id', $memberId))
            ->when($category, fn ($query) => $query->whereHas('program', fn ($program) => $program->where('category', $category)))
            ->when($keyword, function ($query) use ($keyword): void {
                $query->where(function ($query) use ($keyword): void {
                    $query->where('account_label', 'like', "%{$keyword}%")
                        ->orWhere('external_account_hint', 'like', "%{$keyword}%")
                        ->orWhereHas('program', fn ($query) => $query->where('name', 'like', "%{$keyword}%")
                            ->orWhere('provider', 'like', "%{$keyword}%"));
                });
            })
            ->orderByDesc('active')->orderBy('id')->paginate($perPage)->withQueryString();
    }

    private function assertActiveChoices(array $data, ?BenefitAccount $account = null): void
    {
        if (($account === null || $account->program_id !== (int) $data['program_id'])
            && ! BenefitProgram::query()->whereKey($data['program_id'])->where('active', true)->exists()) {
            throw ValidationException::withMessages(['program_id' => '有効な特典制度を選択してください。']);
        }

        $memberId = $data['household_member_id'] ?? null;
        if ($memberId !== null
            && ($account === null || $account->household_member_id !== (int) $memberId)
            && ! HouseholdMember::query()->whereKey($memberId)->where('active', true)->exists()) {
            throw ValidationException::withMessages(['household_member_id' => '有効な名義を選択してください。']);
        }
    }

    private function fill(BenefitAccount $account, array $data): void
    {
        $account->program_id = $data['program_id'];
        $account->household_member_id = $data['household_member_id'] ?? null;
        $account->account_label = $this->nullableTrim($data['account_label'] ?? null);
        $account->external_account_hint = $this->nullableTrim($data['external_account_hint'] ?? null);
    }

    private function nullableTrim(?string $value): ?string
    {
        return blank($value) ? null : trim($value);
    }
}
