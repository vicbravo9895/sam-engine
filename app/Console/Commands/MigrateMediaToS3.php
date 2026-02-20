<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MediaAsset;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class MigrateMediaToS3 extends Command
{
    protected $signature = 'media:migrate-to-s3
                            {--dry-run : Preview without making changes}
                            {--delete : Delete local files after successful upload}
                            {--update-urls : Replace local /storage/ URLs with S3 URLs in the database}
                            {--prefix= : Migrate only a specific directory (evidence, dashcam-media, signal-media, company-logos)}';

    protected $description = 'Migrate media files from local public disk to S3 using streaming';

    private const MEDIA_PREFIXES = [
        'evidence',
        'dashcam-media',
        'signal-media',
        'company-logos',
    ];

    private const URL_COLUMNS = [
        ['safety_signals', 'media_urls', 'jsonb'],
        ['samsara_events', 'ai_actions', 'jsonb'],
        ['samsara_events', 'raw_ai_output', 'jsonb'],
        ['samsara_events', 'supporting_evidence', 'jsonb'],
        ['samsara_events', 'ai_assessment', 'jsonb'],
        ['chat_messages', 'content', 'text'],
    ];

    public function handle(): int
    {
        $prefixes = $this->resolvePrefixes();
        if ($prefixes === null) {
            return Command::FAILURE;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $shouldDelete = (bool) $this->option('delete');
        $shouldUpdateUrls = (bool) $this->option('update-urls');

        if ($isDryRun) {
            $this->components->info('DRY RUN — no changes will be made.');
        }

        $local = Storage::disk('public');
        $s3 = Storage::disk('s3');

        $prefixStats = [];
        $totals = ['files' => 0, 'bytes' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($prefixes as $prefix) {
            $stats = $this->migratePrefix($local, $s3, $prefix, $isDryRun, $shouldDelete);
            $prefixStats[] = $stats;
            $totals['files'] += $stats['uploaded'];
            $totals['bytes'] += $stats['bytes'];
            $totals['skipped'] += $stats['skipped'];
            $totals['failed'] += $stats['failed'];
        }

        if ($shouldUpdateUrls) {
            $this->newLine();
            $this->updateDatabaseUrls($s3, $isDryRun);
            $this->updateMediaAssetsTable($s3, $isDryRun);
        }

        $this->printSummary($prefixStats, $totals, $isDryRun);

        return $totals['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return string[]|null
     */
    private function resolvePrefixes(): ?array
    {
        $prefix = $this->option('prefix');

        if ($prefix === null) {
            return self::MEDIA_PREFIXES;
        }

        if (!in_array($prefix, self::MEDIA_PREFIXES, true)) {
            $this->components->error(
                "Invalid prefix: {$prefix}. Valid options: " . implode(', ', self::MEDIA_PREFIXES)
            );
            return null;
        }

        return [$prefix];
    }

    /**
     * @return array{prefix: string, total: int, uploaded: int, skipped: int, failed: int, bytes: int}
     */
    private function migratePrefix(
        Filesystem $local,
        Filesystem $s3,
        string $prefix,
        bool $isDryRun,
        bool $shouldDelete,
    ): array {
        $stats = ['prefix' => $prefix, 'total' => 0, 'uploaded' => 0, 'skipped' => 0, 'failed' => 0, 'bytes' => 0];

        $this->newLine();
        $this->components->info("Processing: {$prefix}/");

        $localFiles = $local->allFiles($prefix);
        $stats['total'] = count($localFiles);

        if (empty($localFiles)) {
            $this->line("  No files found.");
            return $stats;
        }

        $this->line("  Found {$stats['total']} local files. Checking S3…");
        $s3Existing = collect($s3->allFiles($prefix))->flip();
        $this->line("  {$s3Existing->count()} already on S3.");

        $bar = $this->output->createProgressBar($stats['total']);
        $bar->start();

        foreach ($localFiles as $path) {
            if ($s3Existing->has($path)) {
                $stats['skipped']++;
                if ($shouldDelete && !$isDryRun) {
                    $local->delete($path);
                }
                $bar->advance();
                continue;
            }

            $fileSize = $local->size($path);

            if ($isDryRun) {
                $stats['uploaded']++;
                $stats['bytes'] += $fileSize;
                $bar->advance();
                continue;
            }

            try {
                $stream = $local->readStream($path);
                $s3->writeStream($path, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }

                $stats['uploaded']++;
                $stats['bytes'] += $fileSize;

                if ($shouldDelete) {
                    $local->delete($path);
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
                $this->newLine();
                $this->components->error("  Failed: {$path} — {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        if ($shouldDelete && !$isDryRun) {
            $this->cleanEmptyDirectories($local, $prefix);
        }

        return $stats;
    }

    private function updateDatabaseUrls(Filesystem $s3, bool $isDryRun): void
    {
        $oldPrefix = '/storage/';
        $s3BaseUrl = $s3->url('__probe__');
        $newPrefix = str_replace('__probe__', '', $s3BaseUrl);

        $this->components->info('Updating database URLs');
        $this->line("  Old prefix: {$oldPrefix}");
        $this->line("  New prefix: {$newPrefix}");
        $this->newLine();

        foreach (self::URL_COLUMNS as [$table, $column, $type]) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
                $this->line("  {$table}.{$column}: skipped (column not found)");
                continue;
            }

            $count = $this->countRowsWithLocalUrls($table, $column, $type, $oldPrefix);

            if ($count > 0 && !$isDryRun) {
                $this->replaceUrls($table, $column, $type, $oldPrefix, $newPrefix);
            }

            $verb = $isDryRun ? 'would update' : 'updated';
            $this->line("  {$table}.{$column}: {$count} rows {$verb}");
        }
    }

    /**
     * Update media_assets: set disk=s3 and local_url to S3 URL for assets that were on public.
     */
    private function updateMediaAssetsTable(Filesystem $s3, bool $isDryRun): void
    {
        if (!Schema::hasTable('media_assets')) {
            return;
        }

        $query = MediaAsset::where('disk', 'public')
            ->whereNotNull('storage_path');

        $count = $query->count();
        if ($count === 0) {
            $this->line('  media_assets: no rows with disk=public to update');
            return;
        }

        $this->line("  media_assets: {$count} rows with disk=public to point to S3");

        if ($isDryRun) {
            $this->line('  (dry-run: would set disk=s3 and local_url to S3 URL per asset)');
            return;
        }

        $updated = 0;
        foreach (MediaAsset::where('disk', 'public')->whereNotNull('storage_path')->cursor() as $asset) {
            if (!$s3->exists($asset->storage_path)) {
                continue;
            }
            $newUrl = $s3->url($asset->storage_path);
            $asset->update(['disk' => 's3', 'local_url' => $newUrl]);
            $updated++;
        }

        $this->line("  media_assets: {$updated} rows updated (disk=s3, local_url → MinIO/S3).");
    }

    private function countRowsWithLocalUrls(string $table, string $column, string $type, string $oldPrefix): int
    {
        if ($type === 'jsonb') {
            return DB::table($table)
                ->whereRaw("{$column}::text LIKE ?", ["%{$oldPrefix}%"])
                ->count();
        }

        return DB::table($table)
            ->where($column, 'LIKE', "%{$oldPrefix}%")
            ->count();
    }

    private function replaceUrls(string $table, string $column, string $type, string $old, string $new): void
    {
        if ($type === 'jsonb') {
            DB::statement(
                "UPDATE {$table} SET {$column} = REPLACE({$column}::text, ?, ?)::jsonb WHERE {$column}::text LIKE ?",
                [$old, $new, "%{$old}%"]
            );
            return;
        }

        DB::table($table)
            ->where($column, 'LIKE', "%{$old}%")
            ->update([$column => DB::raw("REPLACE({$column}, " . DB::getPdo()->quote($old) . ", " . DB::getPdo()->quote($new) . ")")]);
    }

    private function cleanEmptyDirectories(Filesystem $disk, string $prefix): void
    {
        $directories = $disk->allDirectories($prefix);

        usort($directories, fn (string $a, string $b) => substr_count($b, '/') - substr_count($a, '/'));

        foreach ($directories as $dir) {
            if (empty($disk->allFiles($dir))) {
                $disk->deleteDirectory($dir);
            }
        }

        if (empty($disk->allFiles($prefix)) && empty($disk->allDirectories($prefix))) {
            $disk->deleteDirectory($prefix);
        }
    }

    /**
     * @param array<int, array{prefix: string, total: int, uploaded: int, skipped: int, failed: int, bytes: int}> $prefixStats
     * @param array{files: int, bytes: int, skipped: int, failed: int} $totals
     */
    private function printSummary(array $prefixStats, array $totals, bool $isDryRun): void
    {
        $rows = array_map(fn (array $s) => [
            $s['prefix'],
            $s['total'],
            $s['uploaded'],
            $s['skipped'],
            $s['failed'],
            $this->formatBytes($s['bytes']),
        ], $prefixStats);

        $this->newLine();
        $this->components->info($isDryRun ? 'DRY RUN Summary' : 'Migration Summary');
        $this->table(
            ['Directory', 'Total', 'Uploaded', 'Skipped (exists)', 'Failed', 'Size'],
            $rows,
        );

        $verb = $isDryRun ? 'would be transferred' : 'transferred';
        $this->newLine();
        $this->line("  Total: {$totals['files']} files, {$this->formatBytes($totals['bytes'])} {$verb}");

        if ($totals['skipped'] > 0) {
            $this->line("  {$totals['skipped']} files already on S3 (skipped)");
        }

        if ($totals['failed'] > 0) {
            $this->components->error("{$totals['failed']} files failed to upload");
        }

        if ($this->option('delete') && !$isDryRun && $totals['bytes'] > 0) {
            $this->components->info("Disk space freed: {$this->formatBytes($totals['bytes'])}");
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1_073_741_824) {
            return number_format($bytes / 1_073_741_824, 2) . ' GB';
        }
        if ($bytes >= 1_048_576) {
            return number_format($bytes / 1_048_576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }

        return "{$bytes} B";
    }
}
