<?php

// APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=benefit_excel_test php tests/manual/mysql-excel-performance.php
// Only disposable databases are permitted. Output contains synthetic counts/metrics, never row contents.

use App\Models\BenefitAccount;
use App\Models\BenefitProgram;
use App\Services\BenefitReadService;
use App\Services\Imports\ExcelImportService;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (app()->environment() !== 'testing' || DB::getDriverName() !== 'mysql'
    || ! str_ends_with(DB::connection()->getDatabaseName(), '_test')) {
    throw new RuntimeException('Refusing to run outside a disposable MySQL test database.');
}
if (DB::table('benefit_transactions')->exists() || DB::table('import_batches')->exists()) {
    throw new RuntimeException('Excel performance database must start without transactions or imports.');
}
$sizes = array_map('intval', explode(',', $argv[1] ?? '2000,10000,20000'));
foreach ($sizes as $size) {
    if (! in_array($size, [2000, 10000, 20000], true)) {
        throw new RuntimeException('Supported synthetic row counts: 2000,10000,20000.');
    }
}
$queries = 0;
$lockQueries = 0;
$sqlMs = 0;
DB::listen(function (QueryExecuted $query) use (&$queries, &$lockQueries, &$sqlMs): void {
    $queries++;
    $sqlMs += $query->time;
    $lockQueries += str_contains(strtolower($query->sql), 'for update') ? 1 : 0;
});

function lockWaitMs(): int
{
    return (int) DB::selectOne("SHOW GLOBAL STATUS LIKE 'Innodb_row_lock_time'")->Value;
}

function measure(string $scenario, string $phase, callable $operation): mixed
{
    global $queries, $lockQueries, $sqlMs;
    gc_collect_cycles();
    memory_reset_peak_usage();
    $waitBefore = lockWaitMs();
    $queries = $lockQueries = $sqlMs = 0;
    $started = hrtime(true);
    $result = $operation();
    $ms = (hrtime(true) - $started) / 1e6;
    $count = $queries;
    $locks = $lockQueries;
    $time = $sqlMs;
    $wait = lockWaitMs() - $waitBefore;
    echo json_encode(['scenario' => $scenario, 'phase' => $phase, 'elapsed_ms' => round($ms, 1),
        'peak_mib' => round(memory_get_peak_usage(true) / 1048576, 1), 'queries' => $count,
        'sql_ms' => round($time, 1), 'lock_queries' => $locks, 'innodb_lock_wait_ms' => $wait], JSON_UNESCAPED_SLASHES)."\n";

    return $result;
}

function syntheticWorkbook(string $path, int $rows, int $accountCount): array
{
    $zip = new ZipArchive;
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create synthetic workbook.');
    }
    $sheets = $rels = '';
    $names = [];
    // Reader caps a sheet at 20,000 rows including its header. Split the 20k case into sheets.
    $sheetCount = max($accountCount, (int) ceil($rows / 19999));
    for ($sheet = 1; $sheet <= $sheetCount; $sheet++) {
        $name = 'Synthetic'.$sheet.'ANA';
        $names[] = $name;
        $sheets .= '<sheet name="'.$name.'" sheetId="'.$sheet.'" r:id="r'.$sheet.'"/>';
        $rels .= '<Relationship Id="r'.$sheet.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$sheet.'.xml"/>';
        $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $values = ['日付', '内容', '数量', '残高'];
        for ($row = 1; $row <= $rows / $sheetCount + 1; $row++) {
            if ($row > 1) {
                $values = ['2026-09-01', 'Synthetic row '.($row - 1), '1', (string) ($row - 1)];
            }
            $xml .= '<row r="'.$row.'">';
            foreach ($values as $column => $value) {
                $xml .= '<c r="'.chr(65 + $column).$row.'" t="inlineStr"><is><t>'.$value.'</t></is></c>';
            }
            $xml .= '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet'.$sheet.'.xml', $xml.'</sheetData></worksheet>');
    }
    $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$sheets.'</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
    $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>');
    $zip->close();

    return $names;
}

echo json_encode(['php' => PHP_VERSION, 'mysql' => DB::selectOne('SELECT VERSION() AS version')->version,
    'isolation' => DB::selectOne('SELECT @@transaction_isolation AS isolation')->isolation,
    'importer' => ExcelImportService::VERSION])."\n";
$program = BenefitProgram::query()->forceCreate(['name' => 'Synthetic Excel benchmark', 'category' => 'mile', 'unit_name' => 'mile', 'active' => true]);
$service = app(ExcelImportService::class);
foreach ($sizes as $rows) {
    foreach ([1, 4] as $accountCount) {
        $scenario = $rows.'rows-'.$accountCount.'accounts';
        $path = tempnam(sys_get_temp_dir(), 'benefit-excel-');
        try {
            $names = syntheticWorkbook($path, $rows, $accountCount);
            $mappings = [];
            $accounts = [];
            foreach ($names as $index => $name) {
                $accounts[$index % $accountCount] ??= BenefitAccount::query()->forceCreate(['program_id' => $program->id, 'account_label' => $scenario.'-'.$index, 'active' => true]);
                $account = $accounts[$index % $accountCount];
                $mappings[$name] = ['action' => 'import', 'account_id' => $account->id];
            }
            $file = new UploadedFile($path, 'synthetic.xlsx', null, null, true);
            $batch = measure($scenario, 'analyze', fn () => $service->analyze($file));
            measure($scenario, 'configure_including_verify', fn () => $service->configure($batch, $mappings));
            measure($scenario, 'verify', fn () => $service->verify($batch));
            measure($scenario, 'execute_including_verify', fn () => $service->execute($batch));
            if ($batch->fresh()->imported_rows !== $rows || $batch->error_rows || $batch->warning_rows) {
                throw new RuntimeException('Imported counts or warnings mismatch.');
            }
            $again = measure($scenario, 're_analyze', fn () => $service->analyze(new UploadedFile($path, 'renamed.xlsx', null, null, true)));
            measure($scenario, 're_configure_including_verify', fn () => $service->configure($again, $mappings));
            measure($scenario, 're_execute_including_verify', fn () => $service->execute($again));
            if ($again->fresh()->skipped_rows !== $rows || $again->imported_rows !== 0 || $again->warning_rows || $again->error_rows) {
                throw new RuntimeException('Reimport deduplication mismatch.');
            }
            foreach ($accounts as $account) {
                if ($account->transactions()->count() !== $rows / $accountCount
                    || ! BigDecimal::of(app(BenefitReadService::class)->accountBalance($account->id))->isEqualTo($rows / $accountCount)) {
                    throw new RuntimeException('Account transaction count or balance mismatch.');
                }
            }
            echo json_encode(['scenario' => $scenario, 'result' => 'pass', 'imported' => $rows,
                'reimported' => 0, 'duplicates' => $rows, 'total_native_balance' => $rows])."\n";
        } finally {
            @unlink($path);
        }
    }
}
