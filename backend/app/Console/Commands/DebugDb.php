<?php

namespace App\Console\Commands;

use App\Models\SearchIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugDb extends Command
{
    protected $signature = 'debug:db {table?}';

    protected $description = 'Debug database tables';

    public function handle()
    {
        $table = $this->argument('table') ?? 'search_indexes';

        if ($table === 'search_indexes') {
            $records = SearchIndex::all();
            $this->info('SearchIndex count: '.$records->count());
            $this->newLine();

            foreach ($records as $record) {
                $this->info("Index: {$record->index_name}");
                $this->line('  Display Name: '.($record->display_name ?? 'NULL'));
                $this->line('  Active: '.($record->is_active ? 'Yes' : 'No'));
                $this->newLine();
            }
        } else {
            $results = DB::table($table)->get();
            $this->info(json_encode($results, JSON_PRETTY_PRINT));
        }
    }
}
