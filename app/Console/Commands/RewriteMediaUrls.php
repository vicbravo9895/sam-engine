<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RewriteMediaUrls extends Command
{
    protected $signature = 'media:rewrite-urls
                            {old_base : URL base a reemplazar (ej: https://xxx.r2.cloudflarestorage.com)}
                            {new_base : URL base nueva (ej: https://media.tudominio.com)}
                            {--dry-run : Preview sin hacer cambios}';

    protected $description = 'Reescribe URLs de media en la base de datos (media_assets, eventos, signals, chat)';

    private const TARGETS = [
        ['media_assets', 'local_url', 'text'],
        ['safety_signals', 'media_urls', 'jsonb'],
        ['samsara_events', 'ai_actions', 'jsonb'],
        ['samsara_events', 'raw_ai_output', 'jsonb'],
        ['samsara_events', 'supporting_evidence', 'jsonb'],
        ['samsara_events', 'ai_assessment', 'jsonb'],
        ['chat_messages', 'content', 'text'],
    ];

    public function handle(): int
    {
        $oldBase = rtrim($this->argument('old_base'), '/');
        $newBase = rtrim($this->argument('new_base'), '/');
        $isDryRun = (bool) $this->option('dry-run');

        if ($oldBase === $newBase) {
            $this->error('old_base y new_base son iguales. Nada que hacer.');
            return Command::FAILURE;
        }

        $this->info($isDryRun ? '🔍 DRY RUN — no se harán cambios' : '⚡ Ejecutando rewrite de URLs');
        $this->newLine();
        $this->info("Old: {$oldBase}");
        $this->info("New: {$newBase}");
        $this->newLine();

        if (!$isDryRun && !$this->confirm('¿Continuar con el rewrite?')) {
            $this->info('Cancelado.');
            return Command::SUCCESS;
        }

        $totalUpdated = 0;

        foreach (self::TARGETS as [$table, $column, $type]) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
                $this->warn("  ⏭  {$table}.{$column} — tabla o columna no existe, saltando");
                continue;
            }

            $count = $this->countMatches($table, $column, $type, $oldBase);

            if ($count === 0) {
                $this->line("  ·  {$table}.{$column} — 0 filas");
                continue;
            }

            if ($isDryRun) {
                $this->info("  📋 {$table}.{$column} — {$count} filas a actualizar");
            } else {
                $updated = $this->rewrite($table, $column, $type, $oldBase, $newBase);
                $this->info("  ✅ {$table}.{$column} — {$updated} filas actualizadas");
                $totalUpdated += $updated;
            }
        }

        $this->newLine();

        if ($isDryRun) {
            $this->info("Dry run completado. Ejecuta sin --dry-run para aplicar.");
        } else {
            $this->info("Listo. {$totalUpdated} filas actualizadas en total.");
        }

        return Command::SUCCESS;
    }

    private function countMatches(string $table, string $column, string $type, string $oldBase): int
    {
        if ($type === 'jsonb') {
            return DB::table($table)
                ->whereRaw("{$column}::text LIKE ?", ["%{$oldBase}%"])
                ->count();
        }

        return DB::table($table)
            ->where($column, 'LIKE', "%{$oldBase}%")
            ->count();
    }

    private function rewrite(string $table, string $column, string $type, string $oldBase, string $newBase): int
    {
        if ($type === 'jsonb') {
            return DB::statement(
                "UPDATE {$table} SET {$column} = REPLACE({$column}::text, ?, ?)::jsonb WHERE {$column}::text LIKE ?",
                [$oldBase, $newBase, "%{$oldBase}%"]
            ) ? DB::table($table)->whereRaw("{$column}::text LIKE ?", ["%{$newBase}%"])->count() : 0;
        }

        return DB::table($table)
            ->where($column, 'LIKE', "%{$oldBase}%")
            ->update([
                $column => DB::raw("REPLACE({$column}, " . DB::getPdo()->quote($oldBase) . ", " . DB::getPdo()->quote($newBase) . ")"),
            ]);
    }
}
