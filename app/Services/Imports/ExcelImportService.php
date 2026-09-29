<?php

namespace App\Services\Imports;

use App\Models\BenefitAccount;
use App\Models\BenefitProgram;
use App\Models\HouseholdMember;
use App\Models\ImportBatch;
use App\Models\ImportRecord;
use App\Services\BenefitTransactionService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExcelImportService
{
    public const VERSION = '2026.09.1';

    private const COLUMNS = [
        'date' => ['日付', '年月日', '取引日', '獲得日', '利用日', 'date', 'transaction date'],
        'description' => ['内容', '摘要', '項目', '詳細', '利用内容', 'description', '備考'],
        'quantity' => ['数量', 'マイル', 'ポイント', '増減', '獲得', '利用', 'amount', 'quantity'],
        'in' => ['入', '獲得数', '加算', '入金', 'in'],
        'out' => ['出', '利用数', '減算', '出金', 'out'],
        'balance' => ['残高', 'balance'],
        'program' => ['制度', 'プログラム', 'program', 'ポイント名', 'マイル名'],
    ];

    public function __construct(private readonly SafeXlsxReader $reader,
        private readonly ImportSanitizer $sanitizer, private readonly BenefitProgramResolver $programs,
        private readonly BenefitTransactionService $transactions) {}

    public function analyze(UploadedFile $file): ImportBatch
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'xlsx' || $file->getSize() > 20 * 1024 * 1024
            || ! in_array($file->getMimeType(), ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'], true)) {
            throw ValidationException::withMessages(['file' => '20MB以下のXLSXファイルを選択してください。']);
        }
        $sheets = $this->reader->read($file->getRealPath());

        return DB::transaction(function () use ($file, $sheets): ImportBatch {
            $batch = new ImportBatch;
            $batch->source_filename = basename($file->getClientOriginalName());
            $batch->file_checksum = hash_file('sha256', $file->getRealPath());
            $batch->importer_version = self::VERSION;
            $batch->started_at = now();
            $batch->status = 'preview';
            $batch->save();
            foreach ($sheets as $sheet => $rows) {
                $type = $this->sheetType($sheet);
                if ($type === 'excluded') {
                    $this->record($batch, $sheet, 0, 'skipped_out_of_scope', 'out_of_scope', [], null, null, 'excluded');

                    continue;
                }
                if ($type === 'needs_review') {
                    $this->record($batch, $sheet, 0, 'needs_review', 'mapping_required', [], null, 'このシートは明示的な取込マッピングが必要です。');

                    continue;
                }
                [$headerNo, $headers] = $this->sheetHeaders($sheet, $rows);
                if ($type === 'transaction' && $headerNo === null) {
                    $this->record($batch, $sheet, 0, 'needs_review', 'unsupported_headers', [], null, '必要な日付・数量列を認識できません。');

                    continue;
                }
                $occurrences = [];
                foreach ($rows as $number => $cells) {
                    if ($number <= ($headerNo ?? 0)) {
                        continue;
                    }
                    $raw = [];
                    foreach ($headers as $column => $field) {
                        if (isset($cells[$column])) {
                            $raw[$field] = trim((string) $cells[$column]);
                        }
                    }
                    $raw = $this->sanitizer->row($raw);
                    if ($raw === [] || ! isset($raw['date']) && ! isset($raw['quantity']) && ! isset($raw['in']) && ! isset($raw['out'])) {
                        continue;
                    }
                    $base = hash('sha256', json_encode([$sheet, $type, $raw], JSON_UNESCAPED_UNICODE));
                    $occurrences[$base] = ($occurrences[$base] ?? 0) + 1;
                    $fingerprint = hash('sha256', $base.':'.$occurrences[$base]);
                    $existing = DB::table('imported_fingerprints')->where('row_fingerprint', $fingerprint)->exists();
                    if ($existing) {
                        $this->record($batch, $sheet, $number, 'skipped_duplicate', 'duplicate', $raw, $fingerprint, null, $type);

                        continue;
                    }
                    $this->record($batch, $sheet, $number, 'pending', null, $raw, $fingerprint, null, $type);
                }
            }
            $batch->total_rows = $batch->records()->where('source_row_number', '>', 0)->count();
            $batch->skipped_rows = $batch->records()->whereIn('import_status', ['skipped_duplicate', 'skipped_out_of_scope'])->count();
            $batch->warning_rows = $batch->records()->where('import_status', 'needs_review')->count();
            $batch->save();

            return $batch;
        });
    }

    public function configure(ImportBatch $batch, array $mappings): void
    {
        abort_unless($batch->status === 'preview' || $batch->status === 'completed_with_errors', 409);
        foreach ($batch->records()->whereIn('import_status', ['pending', 'needs_review', 'error', 'ready', 'warning'])->cursor() as $record) {
            $mapping = $mappings[$record->source_sheet] ?? [];
            if (($mapping['action'] ?? '') === 'skip') {
                $record->import_status = 'skipped_out_of_scope';
                $record->save();

                continue;
            }
            if ($record->record_type !== 'transaction') {
                continue;
            }
            $account = empty($mapping['create_account']) ? BenefitAccount::query()->with('program')->find($mapping['account_id'] ?? 0) : null;
            $plan = null;
            if (! empty($mapping['create_account'])) {
                $program = BenefitProgram::query()->where('active', true)->find($mapping['new_program_id'] ?? 0);
                $member = empty($mapping['new_member_id']) ? null : HouseholdMember::query()->where('active', true)->find($mapping['new_member_id']);
                if ($program && (empty($mapping['new_member_id']) || $member)) {
                    $plan = ['program_id' => $program->id, 'member_id' => $member?->id,
                        'label' => trim((string) ($mapping['new_label'] ?? ''))];
                }
            }
            if (($account && (! $account->active || ! $account->program->active)) || (! $account && ! $plan)) {
                $record->import_status = 'needs_review';
                $record->warning_code = 'account_unresolved';
                $record->save();

                continue;
            }
            $programId = $account?->program_id ?? $plan['program_id'];
            if (filled($record->raw_data_json['program'] ?? null)
                && $this->programs->resolve($record->raw_data_json['program'], $record->source_sheet) !== $programId) {
                $record->import_status = 'needs_review';
                $record->warning_code = 'program_unresolved';
                $record->save();

                continue;
            }
            try {
                $normalized = $this->normalizeTransaction($record->raw_data_json ?? [], $account?->id);
                if ($plan) {
                    $normalized['account_plan'] = $plan;
                }
                $record->normalized_data_json = $normalized;
                $pointSheet = in_array($record->source_sheet, ['ポイント積立', 'ポイント利用'], true);
                $record->import_status = $pointSheet && empty($mapping['confirm_native']) ? 'needs_review' : 'ready';
                $record->warning_code = $pointSheet && empty($mapping['confirm_native']) ? 'native_quantity_unconfirmed' : null;
                $record->error_message = null;
            } catch (\Throwable) {
                $record->import_status = 'needs_review';
                $record->warning_code = 'ambiguous_row';
                $record->normalized_data_json = null;
            }
            $record->save();
        }
        $this->verify($batch);
    }

    public function overrideRecord(ImportRecord $record, array $data): void
    {
        if (! in_array($record->batch->status, ['preview', 'completed_with_errors'], true)) {
            abort(409);
        }
        if (in_array($record->import_status, ['imported', 'skipped_duplicate'], true)) {
            abort(409);
        }
        if ($data['action'] === 'skip') {
            $record->import_status = 'skipped_out_of_scope';
            $record->save();

            return;
        }
        if ($record->record_type !== 'transaction') {
            abort(409);
        }
        $account = BenefitAccount::query()->with('program')->findOrFail($data['account_id']);
        if (! $account->active || ! $account->program->active) {
            throw ValidationException::withMessages(['account_id' => '有効な口座を選択してください。']);
        }
        try {
            $raw = $record->raw_data_json ?? [];
            if (! empty($data['date_override'])) {
                $raw['date'] = $data['date_override'];
            }
            if (isset($data['quantity_override']) && $data['quantity_override'] !== '') {
                $raw['quantity'] = (string) $data['quantity_override'];
                unset($raw['in'], $raw['out']);
            }
            $normalized = $this->normalizeTransaction($raw, $account->id);
            if (! empty($data['date_override'])) {
                $normalized['date_override'] = $data['date_override'];
            }
            if (isset($data['quantity_override']) && $data['quantity_override'] !== '') {
                $normalized['quantity_override'] = (string) $data['quantity_override'];
            }
        } catch (\Throwable) {
            throw ValidationException::withMessages(['record' => '日付または数量を読み取れない行です。元ファイルを確認してください。']);
        }
        if (! empty($data['transaction_type'])) {
            $type = $data['transaction_type'];
            $direction = in_array($type, ['opening_balance', 'earn'], true) ? 'in' : 'out';
            if ($direction !== $normalized['direction']) {
                throw ValidationException::withMessages(['transaction_type' => '元データの増減方向と一致しません。']);
            }
            $normalized['type'] = $type;
        }
        $record->normalized_data_json = $normalized;
        $record->import_status = 'ready';
        $record->warning_code = null;
        $record->error_message = null;
        $record->save();
        $this->verify($record->batch);
    }

    public function execute(ImportBatch $batch, bool $confirmMismatch = false): void
    {
        if (! in_array($batch->status, ['preview', 'completed_with_errors'], true)) {
            abort(409);
        }
        $this->verify($batch);
        if ($batch->records()->where('warning_code', 'balance_mismatch')->exists() && ! $confirmMismatch) {
            throw ValidationException::withMessages(['confirm_mismatch' => '残高不一致があります。確認してから実行してください。']);
        }
        foreach ($batch->records()->whereIn('import_status', ['ready', 'warning'])->orderBy('id')->cursor() as $record) {
            try {
                DB::transaction(function () use ($record): void {
                    if (DB::table('imported_fingerprints')->insertOrIgnore([
                        'row_fingerprint' => $record->row_fingerprint, 'import_record_id' => $record->id, 'created_at' => now(),
                    ]) === 0) {
                        $record->import_status = 'skipped_duplicate';
                        $record->warning_code = 'duplicate';
                        $record->save();

                        return;
                    }
                    $data = $record->normalized_data_json;
                    $account = $data['account_id'] ? BenefitAccount::query()->findOrFail($data['account_id']) : $this->plannedAccount($data['account_plan']);
                    if (! $data['account_id']) {
                        $data['account_id'] = $account->id;
                        $record->normalized_data_json = $data;
                    }
                    $transaction = $this->transactions->record($account, $data['type'], $data['quantity'], $data['date'], $data['description']);
                    $transaction->source_type = 'excel_import';
                    $transaction->import_record_id = $record->id;
                    $transaction->save();
                    $record->target_table = 'benefit_transactions';
                    $record->target_id = $transaction->id;
                    $record->import_status = 'imported';
                    $record->save();
                });
            } catch (\Throwable) {
                $record->import_status = 'error';
                $record->warning_code = 'commit_failed';
                $record->error_message = '取引を登録できませんでした。口座残高・順序・取引種別を確認してください。';
                $record->save();
            }
        }
        $batch->imported_rows = $batch->records()->where('import_status', 'imported')->count();
        $batch->error_rows = $batch->records()->where('import_status', 'error')->count();
        $batch->warning_rows = $batch->records()->whereIn('import_status', ['needs_review', 'warning'])->count();
        $batch->skipped_rows = $batch->records()->whereIn('import_status', ['skipped_out_of_scope', 'skipped_duplicate'])->count();
        $batch->status = $batch->error_rows || $batch->warning_rows ? 'completed_with_errors' : 'completed';
        $batch->completed_at = now();
        $batch->save();
    }

    public function verify(ImportBatch $batch): void
    {
        $balances = [];
        foreach ($batch->records()->whereIn('import_status', ['ready', 'warning', 'imported'])->orderBy('source_sheet')->orderBy('source_row_number')->cursor() as $record) {
            $data = $record->normalized_data_json;
            if (! $data) {
                continue;
            }
            $key = $record->source_sheet.':'.(isset($data['account_plan']) ? json_encode($data['account_plan']) : $data['account_id']);
            $balances[$key] ??= BigDecimal::zero();
            $change = BigDecimal::of($data['quantity']);
            $balances[$key] = $data['direction'] === 'in' ? $balances[$key]->plus($change) : $balances[$key]->minus($change);
            if ($record->import_status === 'imported') {
                continue;
            }
            if ($data['balance'] !== null && ! $balances[$key]->isEqualTo($data['balance'])) {
                $record->warning_code = 'balance_mismatch';
                $record->import_status = 'warning';
            } else {
                $record->warning_code = null;
                $record->import_status = 'ready';
            }
            $record->save();
        }
    }

    private function normalizeTransaction(array $raw, ?int $accountId): array
    {
        $date = $this->date($raw['date'] ?? '');
        $in = $this->decimal($raw['in'] ?? '');
        $out = $this->decimal($raw['out'] ?? '');
        $quantity = $this->decimal($raw['quantity'] ?? '');
        if (($in !== null && BigDecimal::of($in)->isNegative()) || ($out !== null && BigDecimal::of($out)->isNegative())
            || ($in !== null && $out !== null && ! BigDecimal::of($in)->isZero() && ! BigDecimal::of($out)->isZero())) {
            throw new \InvalidArgumentException;
        }
        $amount = $in !== null && $in !== '0' ? $in : ($out !== null && $out !== '0' ? '-'.$out : $quantity);
        if ($amount === null || BigDecimal::of($amount)->isZero()) {
            throw new \InvalidArgumentException;
        }
        $description = trim($raw['description'] ?? '');
        $direction = BigDecimal::of($amount)->isNegative() ? 'out' : 'in';
        $type = $direction === 'out' ? 'use' : (preg_match('/繰越|過去分合計|opening/i', $description) ? 'opening_balance' : 'earn');

        return ['account_id' => $accountId, 'date' => $date, 'description' => mb_substr($description, 0, 255),
            'quantity' => (string) BigDecimal::of($amount)->abs()->toScale(4), 'direction' => $direction,
            'type' => $type, 'balance' => $this->decimal($raw['balance'] ?? '')];
    }

    private function plannedAccount(array $plan): BenefitAccount
    {
        BenefitProgram::query()->lockForUpdate()->where('active', true)->findOrFail($plan['program_id']);
        $query = BenefitAccount::query()->where('program_id', $plan['program_id'])
            ->where('household_member_id', $plan['member_id'])->where('account_label', $plan['label'] ?: null);
        $account = $query->first();
        if ($account) {
            if (! $account->active) {
                throw ValidationException::withMessages(['account' => '同名の無効な口座があります。口座設定を確認してください。']);
            }

            return $account;
        }
        $account = new BenefitAccount;
        $account->program_id = $plan['program_id'];
        $account->household_member_id = $plan['member_id'];
        $account->account_label = $plan['label'] ?: null;
        $account->active = true;
        $account->save();

        return $account;
    }

    private function headers(array $rows): array
    {
        foreach (array_slice($rows, 0, 30, true) as $number => $cells) {
            $found = [];
            foreach ($cells as $column => $heading) {
                foreach (self::COLUMNS as $field => $names) {
                    if (in_array(mb_strtolower(trim((string) $heading)), $names, true) && ! in_array($field, $found, true)) {
                        $found[$column] = $field;
                    }
                }
            }
            if (in_array('date', $found, true) && count(array_intersect(['quantity', 'in', 'out'], $found))) {
                return [$number, $found];
            }
        }

        return [null, []];
    }

    private function sheetHeaders(string $sheet, array $rows): array
    {
        $first = $rows[1] ?? [];
        $label = fn (string $column): string => preg_replace('/\s+/u', '', (string) ($first[$column] ?? ''));
        if ($sheet === 'JAL' && $label('A') === '日付' && $label('B') === '内容'
            && str_contains($label('H'), '合計マイル') && str_contains($label('I'), '有効マイル')) {
            return [1, ['A' => 'date', 'B' => 'description', 'H' => 'quantity', 'I' => 'balance']];
        }
        if (preg_match('/(?:ANA)$/u', $sheet) && $label('A') === 'ご利用日' && $label('C') === '内容'
            && $label('I') === '合計') {
            return [1, ['A' => 'date', 'C' => 'description', 'I' => 'quantity']];
        }
        if (in_array($sheet, ['ポイント積立', 'ポイント利用'], true) && $label('A') === '項目'
            && $label('B') === '交換額' && $label('C') === '交換日') {
            return [1, ['A' => 'description', 'B' => 'quantity', 'C' => 'date']];
        }

        return $this->headers($rows);
    }

    private function sheetType(string $sheet): string
    {
        if (in_array($sheet, ['ANA', 'JAL', 'ポイント積立', 'ポイント利用'], true)
            || (preg_match('/(?:ANA|JAL)$/u', $sheet) && ! preg_match('/移行/u', $sheet))) {
            return 'transaction';
        }
        if (in_array($sheet, ['ANA移行', 'JAL移行', 'ポイントマイル状況', 'プレ商品券'], true)) {
            return 'needs_review';
        }

        return 'excluded';
    }

    private function date(string $value): string
    {
        $value = trim($value);
        if ($value !== '' && is_numeric($value)) {
            return CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'Asia/Tokyo')->addDays((int) $value)->toDateString();
        }
        if (! preg_match('/^(\d{4})[\/\-年](\d{1,2})[\/\-月](\d{1,2})日?$/u', $value, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new \InvalidArgumentException;
        }

        return sprintf('%04d-%02d-%02d', (int) $parts[1], (int) $parts[2], (int) $parts[3]);
    }

    private function decimal(string $value): ?string
    {
        $value = str_replace([',', '，', ' ', '−', '－'], ['', '', '', '-', '-'], mb_convert_kana(trim($value), 'n'));
        if ($value === '') {
            return null;
        }
        $number = BigDecimal::of($value);
        if ($number->getScale() > 4) {
            throw new \InvalidArgumentException;
        }

        return (string) $number;
    }

    private function record(ImportBatch $batch, string $sheet, int $number, string $status, ?string $warning, array $raw, ?string $fingerprint, ?string $error = null, ?string $type = null): void
    {
        $record = new ImportRecord;
        $record->import_batch_id = $batch->id;
        $record->source_sheet = $sheet;
        $record->source_row_number = $number;
        $record->row_fingerprint = $fingerprint ?? hash('sha256', $batch->id.':'.$sheet.':'.$number);
        $record->record_type = $type;
        $record->import_status = $status;
        $record->warning_code = $warning;
        $record->raw_data_json = $raw;
        $record->error_message = $error;
        $record->save();
    }
}
