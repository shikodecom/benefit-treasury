<?php

namespace App\Services\Imports;

class ImportSanitizer
{
    public function row(array $row): array
    {
        $safe = array_filter($row, fn ($key) => ! preg_match('/mail|メール|電話|tel|phone|パスワード|password|pin|認証|ログイン|login|会員番号|member.?no|qr|token|secret|api.?key/i', (string) $key), ARRAY_FILTER_USE_KEY);
        foreach ($safe as &$value) {
            $value = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted]', (string) $value);
            $value = preg_replace('/(?<!\d)(?:\+81[- ]?|0)\d{1,4}[- ]?\d{2,4}[- ]?\d{3,4}(?!\d)/', '[redacted]', $value);
        }

        return $safe;
    }
}
