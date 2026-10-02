<?php

namespace App\Services\Imports;

use App\Models\BenefitAccount;
use App\Models\BenefitProgram;
use App\Models\BenefitTransaction;
use App\Models\ImportRecord;
use App\Services\BenefitTransferService;
use Brick\Math\BigDecimal;

class TransferSheetImporter extends MappedSheetImporter
{
    public function supports(string $sheet): bool
    {
        return in_array($sheet, ['ANA移行', 'JAL移行'], true);
    }

    public function type(): string
    {
        return 'transfer';
    }

    protected function columns(): array
    {
        return [
            'from_program' => ['移行元', '交換元'], 'to_program' => ['移行先', '交換先'],
            'source_quantity' => ['元数量', '移行元数量', 'native元数量', 'ポイント数'],
            'expected_destination_quantity' => ['移行先予定数量', 'native先予定数量'],
            'actual_destination_quantity' => ['着弾数量', '移行先実数量', 'native先実数量'],
            'source_equivalent' => ['ポイント相当'], 'planning_equivalent_quantity' => ['マイル相当', '換算数量'],
            'started_at' => ['手続開始日', '申請日'], 'expected_complete_at' => ['移行完了予定日', '予定日'],
            'completed_at' => ['移行完了日', '完了日'], 'status' => ['ステータス', '状態'],
            'name' => ['移行名', '目的'],
        ];
    }

    public function normalize(ImportRecord $record, array $options): array
    {
        $from = BenefitAccount::query()->with('program')->find($options['from_account_id'] ?? 0);
        $to = BenefitAccount::query()->with('program')->find($options['to_account_id'] ?? 0);
        if (! $from || ! $to || ! $from->active || ! $to->active || ! $from->program->active || ! $to->program->active
            || $from->program_id === $to->program_id) {
            $this->fail('account_unresolved');
        }
        foreach (['from_program' => $from->program_id, 'to_program' => $to->program_id] as $field => $id) {
            if (filled($record->raw_data_json[$field] ?? null) && $this->programs->resolve($record->raw_data_json[$field], $record->source_sheet) !== $id) {
                $this->fail('program_unresolved');
            }
        }
        if (empty($options['confirm_native'])) {
            $this->fail('native_quantity_unconfirmed');
        }
        $source = $this->quantity($this->field($record, $options, 'source_quantity'));
        if ($source === null) {
            $this->fail('native_quantity_missing');
        }
        $status = $this->field($record, $options, 'status');
        $status = ['予定' => 'planned', '手続中' => 'processing', '処理中' => 'processing', '移行中' => 'processing', '完了' => 'completed', '済' => 'completed'][$status ?? ''] ?? $status;
        if (empty($options['confirm_status']) || ! in_array($status, ['planned', 'processing', 'completed'], true)) {
            $this->fail('status_unconfirmed');
        }
        $started = $this->date($this->field($record, $options, 'started_at'));
        $expected = $this->date($this->field($record, $options, 'expected_complete_at'));
        $completed = $this->date($this->field($record, $options, 'completed_at'));
        if ($status === 'planned' && ($started || $completed) || $status !== 'planned' && ! $started
            || $status === 'processing' && $completed || $status === 'completed' && (! $completed || $completed < $started)
            || $started && $expected && $expected < $started) {
            $this->fail('invalid_status_dates');
        }
        $actual = $this->quantity($this->field($record, $options, 'actual_destination_quantity'), false);
        if ($status === 'completed' && $actual === null || $status !== 'completed' && $actual !== null) {
            $this->fail('actual_quantity_unconfirmed');
        }
        $equivalent = $this->quantity($this->field($record, $options, 'planning_equivalent_quantity'), false);
        $equivalentProgram = empty($options['planning_equivalent_program_id']) ? null : BenefitProgram::query()->where('active', true)->find($options['planning_equivalent_program_id']);
        if (($equivalent !== null) !== ($equivalentProgram !== null)) {
            $this->fail('equivalent_program_unresolved');
        }
        $data = ['from_account_id' => $from->id, 'to_account_id' => $to->id,
            'source_quantity' => $source, 'expected_destination_quantity' => $this->quantity($this->field($record, $options, 'expected_destination_quantity'), false),
            'actual_destination_quantity' => $actual, 'planning_equivalent_program_id' => $equivalentProgram?->id,
            'planning_equivalent_quantity' => $equivalent, 'status' => $status, 'started_at' => $started,
            'expected_complete_at' => $expected, 'completed_at' => $completed,
            'name' => mb_substr($record->raw_data_json['name'] ?? $record->source_sheet, 0, 200),
            'out_transaction_id' => $options['out_transaction_id'] ?? null, 'in_transaction_id' => $options['in_transaction_id'] ?? null];

        return $data;
    }

    public function validatePreview(array $data): void
    {
        $this->history($data);
    }

    private function history(array $data, bool $lock = false): array
    {
        if ($data['status'] === 'planned') {
            if ($data['out_transaction_id'] || $data['in_transaction_id']) {
                $this->fail('unexpected_history_link');
            }

            return [];
        }
        $links = ['out' => [$data['out_transaction_id'], $data['from_account_id'], $data['source_quantity'], $data['started_at']]];
        if ($data['status'] === 'completed' && BigDecimal::of($data['actual_destination_quantity'])->isGreaterThan(0)) {
            $links['in'] = [$data['in_transaction_id'], $data['to_account_id'], $data['actual_destination_quantity'], $data['completed_at']];
        } elseif ($data['in_transaction_id']) {
            $this->fail('unexpected_history_link');
        }
        $transactions = [];
        foreach ($links as $direction => [$id, $account, $quantity, $date]) {
            $query = BenefitTransaction::query()->whereKey($id ?? 0);
            $transaction = ($lock ? $query->lockForUpdate() : $query)->first();
            $types = $direction === 'out' ? ['use', 'transfer_out'] : ['earn', 'transfer_in'];
            if (! $transaction || $transaction->account_id !== $account || $transaction->direction !== $direction
                || ! in_array($transaction->transaction_type, $types, true) || $transaction->transfer_step_id || $transaction->listing_id
                || ! BigDecimal::of($transaction->quantity)->isEqualTo($quantity) || $transaction->transaction_at !== $date
                || BenefitTransaction::query()->where('reversal_of_transaction_id', $transaction->id)->exists()) {
                $this->fail('history_link_unresolved');
            }
            $transactions[$direction] = $transaction;
        }

        return $transactions;
    }

    public function scopeKey(ImportRecord $record, array $data): string
    {
        return 'transfer-v1:'.hash('sha256', $data['from_account_id'].':'.$data['to_account_id'].':'.$record->row_fingerprint);
    }

    public function commit(array $data): array
    {
        $ids = [$data['from_account_id'], $data['to_account_id']];
        sort($ids);
        foreach ($ids as $id) {
            BenefitAccount::query()->lockForUpdate()->findOrFail($id);
        }
        // Revalidate at commit: history may have been linked, reversed or disabled since preview.
        $links = $this->history($data, true);
        $service = app(BenefitTransferService::class);
        $from = BenefitAccount::query()->findOrFail($data['from_account_id']);
        $group = $service->createGroup(['name' => $data['name'], 'household_member_id' => $from->household_member_id,
            'target_program_id' => BenefitAccount::query()->findOrFail($data['to_account_id'])->program_id,
            'expected_complete_at' => $data['expected_complete_at']]);
        $step = $service->addStep($group, $data);
        foreach ($links as $direction => $transaction) {
            $transaction->transfer_step_id = $step->id;
            $transaction->transaction_type = $direction === 'out' ? 'transfer_out' : 'transfer_in';
            $transaction->save();
        }
        $step->forceFill(array_intersect_key($data, array_flip(['status', 'started_at', 'completed_at', 'actual_destination_quantity'])))->save();
        $service->calculateGroupStatus($group);

        return ['benefit_transfer_groups', $group->id];
    }
}
