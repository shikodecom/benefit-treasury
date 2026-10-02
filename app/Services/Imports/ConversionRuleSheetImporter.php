<?php

namespace App\Services\Imports;

use App\Models\BenefitProgram;
use App\Models\ConversionRuleGroup;
use App\Models\ImportRecord;
use App\Services\ConversionRuleService;

class ConversionRuleSheetImporter extends MappedSheetImporter
{
    public function supports(string $sheet): bool
    {
        return $sheet === 'ポイントマイル状況';
    }

    public function type(): string
    {
        return 'conversion_rule';
    }

    protected function columns(): array
    {
        return [
            'from_program' => ['交換元', '移行元'], 'to_program' => ['優先交換先', '交換先', '移行先'],
            'from_quantity' => ['交換元数量', 'native元数量'], 'to_quantity' => ['交換先数量', 'native先数量'],
            'minimum_from_quantity' => ['交換下限', '最低数量'], 'effective_rate' => ['実質レート'],
            'conditions_text' => ['還元注意事項', '条件'], 'instructions' => ['交換手順'], 'duration_text' => ['所要時間'],
            'campaign_only' => ['キャンペーン限定'], 'campaign_name' => ['キャンペーン名'],
            'valid_from' => ['開始日', '有効開始日'], 'valid_to' => ['終了日', '有効終了日'],
        ];
    }

    public function normalize(ImportRecord $record, array $options): array
    {
        $from = BenefitProgram::query()->where('active', true)->find($options['from_program_id'] ?? 0);
        $to = BenefitProgram::query()->where('active', true)->find($options['to_program_id'] ?? 0);
        if (! $from || ! $to || $from->id === $to->id) {
            $this->fail('program_unresolved');
        }
        foreach (['from_program' => $from->id, 'to_program' => $to->id] as $field => $id) {
            if (filled($record->raw_data_json[$field] ?? null) && $this->programs->resolve($record->raw_data_json[$field], $record->source_sheet) !== $id) {
                $this->fail('program_unresolved');
            }
        }
        if (empty($options['confirm_native'])) {
            $this->fail('native_quantity_unconfirmed');
        }
        $fromQuantity = $this->quantity($this->field($record, $options, 'from_quantity'));
        $toQuantity = $this->quantity($this->field($record, $options, 'to_quantity'), false);
        if ($fromQuantity === null || $toQuantity === null) {
            $this->fail('native_quantity_missing');
        }
        $group = empty($options['rule_group_id']) ? null : ConversionRuleGroup::query()->find($options['rule_group_id']);
        if (! empty($options['rule_group_id']) && (! $group || $group->from_program_id !== $from->id || $group->to_program_id !== $to->id)) {
            $this->fail('rule_group_mismatch');
        }
        $campaignValue = $this->field($record, $options, 'campaign_only');
        if (! array_key_exists('campaign_only', $options) && ! filled($record->raw_data_json['campaign_only'] ?? null)
            && preg_match('/キャンペーン(?:時のみ|限定)|campaign.only/i', $record->raw_data_json['conditions_text'] ?? '')) {
            $campaignValue = '1';
        }
        if (! in_array($campaignValue, [null, '', '0', '1', 0, 1, false, true, 'はい', 'いいえ', '限定'], true)) {
            $this->fail('campaign_unconfirmed');
        }
        $campaign = in_array($campaignValue, ['1', 1, true, 'はい', '限定'], true);
        $validFrom = $this->date($this->field($record, $options, 'valid_from'));
        $validTo = $this->date($this->field($record, $options, 'valid_to'));
        if ($campaign && (! $validFrom || ! $validTo) || $validFrom && $validTo && $validTo < $validFrom) {
            $this->fail('campaign_period_unresolved');
        }
        $raw = $record->raw_data_json;
        $duration = mb_substr($raw['duration_text'] ?? '', 0, 150);
        $minimumDays = $maximumDays = null;
        if (preg_match('/^(\d+)(?:[〜～\-](\d+))?日$/u', $duration, $match)) {
            $minimumDays = (int) $match[1];
            $maximumDays = (int) ($match[2] ?? $match[1]);
            if ($maximumDays < $minimumDays || $maximumDays > 36500) {
                $this->fail('invalid_duration');
            }
        }

        return ['from_program_id' => $from->id, 'to_program_id' => $to->id, 'rule_group_id' => $group?->id,
            'from_quantity' => $fromQuantity, 'to_quantity' => $toQuantity,
            'minimum_from_quantity' => $this->quantity($raw['minimum_from_quantity'] ?? null, false),
            'campaign_only' => $campaign, 'campaign_name' => mb_substr($raw['campaign_name'] ?? '', 0, 255) ?: null,
            'valid_from' => $validFrom, 'valid_to' => $validTo, 'duration_text' => $duration ?: null,
            'estimated_days_min' => $minimumDays, 'estimated_days_max' => $maximumDays,
            'conditions_text' => $raw['conditions_text'] ?? null, 'instructions' => $raw['instructions'] ?? null,
            'notes' => filled($raw['effective_rate'] ?? null) ? '実質レート（参考原文）: '.$raw['effective_rate'] : null,
            'active' => ! empty($options['activate_rule'])];
    }

    public function scopeKey(ImportRecord $record, array $data): string
    {
        return 'rule-v1:'.hash('sha256', $data['from_program_id'].':'.$data['to_program_id'].':'.$record->row_fingerprint);
    }

    public function commit(array $data): array
    {
        $ids = [$data['from_program_id'], $data['to_program_id']];
        sort($ids);
        foreach ($ids as $id) {
            BenefitProgram::query()->where('active', true)->lockForUpdate()->findOrFail($id);
        }
        $service = app(ConversionRuleService::class);
        $group = $data['rule_group_id'] ? ConversionRuleGroup::query()->lockForUpdate()->findOrFail($data['rule_group_id']) : $service->createRuleGroup($data);
        if ($group->from_program_id !== $data['from_program_id'] || $group->to_program_id !== $data['to_program_id']) {
            $this->fail('rule_group_mismatch');
        }
        $rule = $service->createRuleVersion($group, $data);

        return ['conversion_rules', $rule->id];
    }
}
