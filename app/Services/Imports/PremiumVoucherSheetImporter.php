<?php

namespace App\Services\Imports;

use App\Models\BenefitAccount;
use App\Models\BenefitLot;
use App\Models\BenefitProgram;
use App\Models\HouseholdMember;
use App\Models\ImportRecord;
use App\Services\BenefitAccountService;
use App\Services\BenefitTransactionService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class PremiumVoucherSheetImporter extends MappedSheetImporter
{
    public function supports(string $sheet): bool
    {
        return $sheet === 'プレ商品券';
    }

    public function type(): string
    {
        return 'premium_voucher';
    }

    protected function requiredHeaders(): array
    {
        return ['program', 'remaining_yen'];
    }

    protected function columns(): array
    {
        // Only inventory fields cross the input boundary. ID/code/contact columns are never retained.
        return [
            'program' => ['券種', '制度', '商品券名'],
            'acquired_at' => ['取得日', '購入日', '日付'],
            'expires_at' => ['有効期限', '期限'],
            'snapshot_at' => ['残額確認日', '残高確認日'],
            'remaining_quantity' => ['残数量', 'native残数量'],
            'native_unit' => ['数量単位', 'native単位'],
            'remaining_yen' => ['残額', '残額円'],
            'voucher_state' => ['利用状態', 'ステータス', '状態'],
        ];
    }

    public function normalize(ImportRecord $record, array $options): array
    {
        $account = null;
        $plan = null;
        if (! empty($options['create_account'])) {
            $program = BenefitProgram::query()->where('active', true)->find($options['new_program_id'] ?? 0);
            $member = empty($options['new_member_id']) ? null : HouseholdMember::query()->where('active', true)->find($options['new_member_id']);
            if (! $program || ! empty($options['new_member_id']) && ! $member) {
                $this->fail('account_unresolved');
            }
            $plan = ['program_id' => $program->id, 'household_member_id' => $member?->id,
                'account_label' => trim((string) ($options['new_label'] ?? '')) ?: null];
            $account = $this->plannedAccount($plan);
        } else {
            $account = BenefitAccount::query()->with('program')->find($options['account_id'] ?? 0);
            $program = $account?->program;
        }
        if (! $program || ! $program->active || $account && ! $account->active) {
            $this->fail('account_unresolved');
        }
        if ($this->programs->resolve($record->raw_data_json['program'] ?? '', $record->source_sheet) !== $program->id) {
            $this->fail('program_unresolved');
        }
        $unit = trim((string) $this->field($record, $options, 'native_unit'));
        if (empty($options['confirm_native']) || $unit !== $program->unit_name) {
            $this->fail('native_quantity_unconfirmed');
        }
        if (empty($options['confirm_snapshot'])) {
            $this->fail('snapshot_unconfirmed');
        }
        $quantity = $this->quantity($this->field($record, $options, 'remaining_quantity'), false);
        $yen = $this->quantity($this->field($record, $options, 'remaining_yen'), false);
        if ($quantity === null || $yen === null) {
            $this->fail('native_quantity_missing');
        }
        if (! BigDecimal::of($yen)->isEqualTo(BigDecimal::of($yen)->toScale(0, RoundingMode::DOWN))) {
            $this->fail('invalid_remaining_yen');
        }
        $state = $this->field($record, $options, 'voucher_state');
        $state = ['未使用' => 'unused', '一部使用' => 'partial', '使用中' => 'partial', '使用済' => 'used', '使用済み' => 'used'][$state ?? ''] ?? $state;
        if (! in_array($state, ['unused', 'partial', 'used'], true)) {
            $this->fail('voucher_state_unconfirmed');
        }
        $zero = BigDecimal::of($quantity)->isZero();
        if ($zero !== BigDecimal::of($yen)->isZero() || ($state === 'used') !== $zero
            || $unit === '円' && ! BigDecimal::of($quantity)->isEqualTo($yen)) {
            $this->fail('voucher_balance_mismatch');
        }
        $acquired = $this->date($this->field($record, $options, 'acquired_at'));
        $expires = $this->date($this->field($record, $options, 'expires_at'));
        $snapshot = $this->date($this->field($record, $options, 'snapshot_at'));
        if (! $acquired || ! $expires || ! $snapshot || $expires < $acquired || $snapshot < $acquired
            || $snapshot > now('Asia/Tokyo')->toDateString() || ! $zero && $expires < $snapshot) {
            $this->fail('voucher_dates_unconfirmed');
        }

        return ['account_id' => $account?->id, 'account_plan' => $plan,
            'program_id' => $program->id, 'member_id' => $account?->household_member_id ?? $plan['household_member_id'] ?? null,
            'remaining_quantity' => $quantity, 'remaining_yen' => BigDecimal::of($yen)->toScale(0)->toInt(),
            'native_unit' => $unit, 'voucher_state' => $state, 'acquired_at' => $acquired,
            'expires_at' => $expires, 'snapshot_at' => $snapshot,
            'import_record_id' => $record->id, 'import_batch_id' => $record->import_batch_id];
    }

    private function plannedAccount(array $plan): ?BenefitAccount
    {
        $accounts = BenefitAccount::query()->where('program_id', $plan['program_id'])
            ->where('household_member_id', $plan['household_member_id'])
            ->where('account_label', $plan['account_label'])->get();
        if ($accounts->count() > 1) {
            $this->fail('account_unresolved');
        }

        return $accounts->first();
    }

    public function prepareCommit(array $data): array
    {
        if ($data['account_plan']) {
            BenefitProgram::query()->lockForUpdate()->findOrFail($data['program_id']);
            $account = $this->plannedAccount($data['account_plan'])
                ?? app(BenefitAccountService::class)->create($data['account_plan']);
            $data['account_id'] = $account->id;
        }

        return $data;
    }

    public function validatePreview(array $data): void
    {
        if (! $data['account_id']) {
            return;
        }
        // This is an initial inventory migration. A second snapshot must not add to a live ledger.
        $account = BenefitAccount::query()->findOrFail($data['account_id']);
        if ($account->transactions()->where(function ($query) use ($data): void {
            $query->whereNull('import_record_id')->orWhereDoesntHave('importRecord', fn ($record) => $record
                ->where('import_batch_id', $data['import_batch_id'])->where('record_type', $this->type()));
        })->exists()
            || $account->lots()->where('source', '!=', 'excel_voucher_snapshot:'.$data['import_batch_id'])->exists()
            || $account->lots()->whereNull('source')->exists()) {
            $this->fail('snapshot_account_has_history');
        }
    }

    public function scopeKey(ImportRecord $record, array $data): string
    {
        $scope = $data['account_id'] ?? json_encode($data['account_plan']);

        return 'voucher-v1:'.hash('sha256', $scope.':'.$record->row_fingerprint);
    }

    public function commit(array $data): array
    {
        $account = BenefitAccount::query()->lockForUpdate()->findOrFail($data['account_id']);
        if (! $account->active || ! $account->program->active || $account->program_id !== $data['program_id']
            || $account->household_member_id !== $data['member_id'] || $account->program->unit_name !== $data['native_unit']) {
            $this->fail('account_unresolved');
        }
        $this->validatePreview($data);
        $lot = new BenefitLot;
        $lot->account_id = $account->id;
        $lot->display_name = $account->program->name.' 残額移行';
        $lot->acquired_at = $data['acquired_at'];
        $lot->expires_at = $data['expires_at'];
        $lot->face_value_yen = $data['remaining_yen'];
        $lot->estimated_use_value_yen = $data['remaining_yen'];
        $lot->source = 'excel_voucher_snapshot:'.$data['import_batch_id'];
        $lot->memo = '残額確認日 '.$data['snapshot_at'].' / '.$data['voucher_state'].'（取得・利用履歴は復元しません）';
        $lot->save();
        if (BigDecimal::of($data['remaining_quantity'])->isGreaterThan(0)) {
            $transaction = app(BenefitTransactionService::class)->record($account, 'earn', $data['remaining_quantity'],
                $data['snapshot_at'], '商品券の残額移行', null, $lot->memo, $lot);
            $transaction->source_type = 'excel_voucher_snapshot';
            $transaction->import_record_id = $data['import_record_id'];
            $transaction->save();
        }

        return ['benefit_lots', $lot->id];
    }
}
