<?php
// app/Http/Controllers/TestingController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class TestingController extends Controller
{
    /**
     * Ejecutar tests de PHPUnit
     */
    public function runTests(Request $request)
    {
        $filter = $request->input('filter');
        $testSuite = $request->input('suite');

        $command = 'test';
        $params = [];

        if ($filter) {
            $params['--filter'] = $filter;
        }

        if ($testSuite) {
            $params['--testsuite'] = $testSuite;
        }

        // Ejecutar tests
        $exitCode = Artisan::call($command, $params);
        $output = Artisan::output();

        return response()->json([
            'success' => $exitCode === 0,
            'exit_code' => $exitCode,
            'output' => $output,
            'filter' => $filter,
            'suite' => $testSuite,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Ejecutar migraciones frescas (⚠️ PELIGROSO)
     */
    public function freshMigrations(Request $request)
    {
        // Solo permitir si está explícitamente habilitado
        if (!config('app.allow_fresh_migrations', false)) {
            abort(403, 'Fresh migrations not allowed');
        }

        Artisan::call('migrate:fresh', ['--force' => true]);
        $output = Artisan::output();

        return response()->json([
            'success' => true,
            'output' => $output,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Ver estado de la base de datos
     */
    public function databaseStatus()
    {
        $tables = DB::select('SELECT name FROM sqlite_master WHERE type="table" ORDER BY name');
        
        $counts = [];
        foreach ($tables as $table) {
            $tableName = $table->name;
            if ($tableName !== 'sqlite_sequence') {
                $counts[$tableName] = DB::table($tableName)->count();
            }
        }

        return response()->json([
            'database' => config('database.connections.sqlite.database'),
            'tables' => array_column($tables, 'name'),
            'counts' => $counts,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Limpiar base de datos (truncate all tables)
     */
    public function cleanDatabase()
    {
        $tables = DB::select('SELECT name FROM sqlite_master WHERE type="table" ORDER BY name');
        
        DB::statement('PRAGMA foreign_keys = OFF');
        
        foreach ($tables as $table) {
            $tableName = $table->name;
            if (!in_array($tableName, ['sqlite_sequence', 'migrations'])) {
                DB::table($tableName)->truncate();
            }
        }
        
        DB::statement('PRAGMA foreign_keys = ON');

        return response()->json([
            'success' => true,
            'message' => 'All tables cleaned',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Información del sistema
     */
    public function systemInfo()
    {
        return response()->json([
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'environment' => config('app.env'),
            'debug' => config('app.debug'),
            'database' => config('database.default'),
            'cache_driver' => config('cache.default'),
            'queue_driver' => config('queue.default'),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}