<?php

namespace App\Services\Imports;

use App\Models\ImportRecord;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

abstract class MappedSheetImporter
{
    public function __construct(protected readonly ImportSanitizer $sanitizer, protected readonly BenefitProgramResolver $programs) {}

    abstract public function supports(string $sheet): bool;

    abstract public function type(): string;

    abstract protected function columns(): array;

    abstract public function normalize(ImportRecord $record, array $options): array;

    abstract public function scopeKey(ImportRecord $record, array $data): string;

    abstract public function commit(array $data): array;

    public function validatePreview(array $data): void {}

    public function prepareCommit(array $data): array
    {
        return $data;
    }

    protected function requiredHeaders(): array
    {
        return ['from_program', 'to_program'];
    }

    public function analyze(string $sheet, array $rows): array
    {
        $headers = [];
        $headerNo = null;
        foreach (array_slice($rows, 0, 30, true) as $number => $cells) {
            $found = [];
            foreach ($cells as $column => $heading) {
                foreach ($this->columns() as $field => $labels) {
                    if (in_array(mb_strtolower(trim((string) $heading)), $labels, true) && ! in_array($field, $found, true)) {
                        $found[$column] = $field;
                    }
                }
            }
            if (array_diff($this->requiredHeaders(), $found) === []) {
                $headers = $found;
                $headerNo = $number;
                break;
            }
        }
        if ($headerNo === null) {
            return [];
        }
        $result = [];
        $occurrences = [];
        foreach ($rows as $number => $cells) {
            if ($number <= $headerNo) {
                continue;
            }
            $raw = [];
            foreach ($headers as $column => $field) {
                if (isset($cells[$column]) && trim((string) $cells[$column]) !== '') {
                    $raw[$field] = trim((string) $cells[$column]);
                }
            }
            $raw = $this->sanitizer->row($raw);
            if ($raw === []) {
                continue;
            }
            $base = hash('sha256', json_encode([$sheet, $this->type(), $raw], JSON_UNESCAPED_UNICODE));
            $occurrences[$base] = ($occurrences[$base] ?? 0) + 1;
            $result[] = ['number' => $number, 'raw' => $raw, 'fingerprint' => hash('sha256', $base.':'.$occurrences[$base])];
        }

        return $result;
    }

    protected function fail(string $code): never
    {
        throw ValidationException::withMessages(['import' => $code]);
    }

    protected function quantity(mixed $value, bool $positive = true): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        try {
            $number = BigDecimal::of(str_replace([',', '，', ' '], '', mb_convert_kana(trim((string) $value), 'n')));
            if ($number->getScale() > 4 || $number->isGreaterThanOrEqualTo('100000000000000')
                || ($positive ? $number->isLessThanOrEqualTo(0) : $number->isLessThan(0))) {
                $this->fail('invalid_native_quantity');
            }

            return (string) $number->toScale(4);
        } catch (\Throwable) {
            $this->fail('invalid_native_quantity');
        }
    }

    protected function date(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        if (is_numeric($value) && (int) $value > 0 && (int) $value < 100000) {
            return CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'Asia/Tokyo')->addDays((int) $value)->toDateString();
        }
        if (! preg_match('/^(\d{4})[\/\-年](\d{1,2})[\/\-月](\d{1,2})日?$/u', trim((string) $value), $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            $this->fail('invalid_date');
        }

        return sprintf('%04d-%02d-%02d', (int) $parts[1], (int) $parts[2], (int) $parts[3]);
    }

    protected function field(ImportRecord $record, array $options, string $field): mixed
    {
        return array_key_exists($field, $options) ? $options[$field] : ($record->raw_data_json[$field] ?? null);
    }
}
