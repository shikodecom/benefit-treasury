<?php

namespace App\Services\Imports;

class ImportFingerprint
{
    public static function forAccount(string $sourceFingerprint, int $accountId): string
    {
        return 'account-v1:'.hash('sha256', $accountId.':'.$sourceFingerprint);
    }
}
