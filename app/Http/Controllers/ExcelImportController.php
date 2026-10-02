<?php

namespace App\Http\Controllers;

use App\Models\BenefitAccount;
use App\Models\BenefitProgram;
use App\Models\ConversionRuleGroup;
use App\Models\HouseholdMember;
use App\Models\ImportBatch;
use App\Models\ImportRecord;
use App\Services\Imports\ExcelImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ExcelImportController extends Controller
{
    public function index(): View
    {
        return view('imports.index', ['batches' => ImportBatch::query()->orderByDesc('id')->paginate(20)]);
    }

    public function upload(Request $request, ExcelImportService $service): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:20480']]);
        $batch = $service->analyze($request->file('file'));

        return redirect()->route('imports.show', $batch)->with('status', '解析しました。内容を確認してから実行してください。');
    }

    public function show(ImportBatch $batch): View
    {
        $records = $batch->records()->orderBy('source_sheet')->orderBy('source_row_number')->paginate(100)->withQueryString();
        $accounts = BenefitAccount::query()->with(['program', 'householdMember'])->where('active', true)->orderBy('id')->get();
        $programs = BenefitProgram::query()->where('active', true)->orderBy('name')->get();
        $members = HouseholdMember::query()->where('active', true)->orderBy('display_name')->get();
        $sheets = $batch->records()->select('source_sheet')->distinct()->pluck('source_sheet');
        $sheetTypes = $batch->records()->whereNotNull('record_type')->pluck('record_type', 'source_sheet');
        $ruleGroups = ConversionRuleGroup::query()->with(['fromProgram', 'toProgram'])->orderBy('id')->get();
        $alreadyUploaded = ImportBatch::query()->where('file_checksum', $batch->file_checksum)->whereKeyNot($batch->id)->exists();

        return view('imports.show', compact('batch', 'records', 'accounts', 'programs', 'members', 'sheets', 'sheetTypes', 'ruleGroups', 'alreadyUploaded'));
    }

    public function configure(Request $request, ImportBatch $batch, ExcelImportService $service): RedirectResponse
    {
        $data = $request->validate(array_merge([
            'mappings' => ['required', 'array'], 'mappings.*.action' => ['required', Rule::in(['import', 'skip'])],
            'mappings.*.account_id' => ['nullable', 'integer', Rule::exists('benefit_accounts', 'id')],
            'mappings.*.confirm_native' => ['nullable', 'boolean'],
            'mappings.*.create_account' => ['nullable', 'boolean'],
            'mappings.*.new_program_id' => ['nullable', 'integer', Rule::exists('benefit_programs', 'id')],
            'mappings.*.new_member_id' => ['nullable', 'integer', Rule::exists('household_members', 'id')],
            'mappings.*.new_label' => ['nullable', 'string', 'max:150'],
        ], $this->mappedRules('mappings.*.')));
        $service->configure($batch, $data['mappings']);

        return redirect()->route('imports.show', $batch)->with('status', 'マッピングを反映しました。プレビューを確認してください。');
    }

    public function execute(Request $request, ImportBatch $batch, ExcelImportService $service): RedirectResponse
    {
        $service->execute($batch, $request->boolean('confirm_mismatch'));

        return redirect()->route('imports.show', $batch)->with('status', '取込を実行しました。');
    }

    public function override(Request $request, ImportBatch $batch, ImportRecord $record, ExcelImportService $service): RedirectResponse
    {
        abort_unless($record->import_batch_id === $batch->id, 404);
        if (in_array($record->record_type, ['transfer', 'conversion_rule'], true) && $request->has('row')) {
            $request->validate(['row' => ['array']]);
            $request->merge($request->input('row'));
        }
        $data = $request->validate(array_merge([
            'action' => ['required', Rule::in(['import', 'skip'])],
            'account_id' => ['nullable', 'integer', Rule::exists('benefit_accounts', 'id')],
            'transaction_type' => ['nullable', Rule::in(['opening_balance', 'earn', 'use'])],
            'date_override' => ['nullable', 'date'],
            'quantity_override' => ['nullable', 'numeric', 'not_in:0', 'decimal:0,4'],
        ], $this->mappedRules()));
        if ($record->record_type === 'transaction' && $data['action'] === 'import' && empty($data['account_id'])) {
            throw ValidationException::withMessages(['account_id' => '口座を選択してください。']);
        }
        $service->overrideRecord($record, $data);

        return back()->with('status', '行の取込設定を更新しました。');
    }

    private function mappedRules(string $prefix = ''): array
    {
        $rules = [];
        foreach (['from_account_id', 'to_account_id'] as $field) {
            $rules[$prefix.$field] = ['nullable', 'integer', Rule::exists('benefit_accounts', 'id')];
        }
        foreach (['from_program_id', 'to_program_id', 'planning_equivalent_program_id'] as $field) {
            $rules[$prefix.$field] = ['nullable', 'integer', Rule::exists('benefit_programs', 'id')];
        }
        foreach (['out_transaction_id', 'in_transaction_id'] as $field) {
            $rules[$prefix.$field] = ['nullable', 'integer', Rule::exists('benefit_transactions', 'id')];
        }
        $rules[$prefix.'rule_group_id'] = ['nullable', 'integer', Rule::exists('conversion_rule_groups', 'id')];
        foreach (['confirm_native', 'confirm_status', 'activate_rule', 'campaign_only'] as $field) {
            $rules[$prefix.$field] = ['nullable', 'boolean'];
        }
        $rules[$prefix.'status'] = ['nullable', Rule::in(['planned', 'processing', 'completed'])];
        foreach (['source_quantity', 'expected_destination_quantity', 'actual_destination_quantity', 'planning_equivalent_quantity', 'from_quantity', 'to_quantity'] as $field) {
            $rules[$prefix.$field] = ['nullable', 'numeric', 'min:0', 'decimal:0,4', 'lt:100000000000000'];
        }
        foreach (['started_at', 'expected_complete_at', 'completed_at', 'valid_from', 'valid_to'] as $field) {
            $rules[$prefix.$field] = ['nullable', 'date_format:Y-m-d'];
        }

        return $rules;
    }
}
