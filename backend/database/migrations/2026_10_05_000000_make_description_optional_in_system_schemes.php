<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * #24: the built-in schemes marked `description` as required, but only the edit
 * form enforced it — the wizard, FullFrame and AI ingest and the API all create
 * live resources without one, which then couldn't be saved (or archived) from
 * the edit form until someone wrote a description. Make it optional in the
 * three system schemes, as CollectionSchemaSeeder now seeds them.
 *
 * Only the `required` flag changes (min_length still applies when a description
 * is given), so no search mapping is affected; the query builder is used on
 * purpose to skip the model's `fields` hooks.
 */
return new class extends Migration
{
    private const SCHEMES = ['multimedia', 'documents', 'general'];

    public function up(): void
    {
        $this->setDescriptionRequired(false);
    }

    public function down(): void
    {
        $this->setDescriptionRequired(true);
    }

    private function setDescriptionRequired(bool $required): void
    {
        DB::table('collection_schemes')
            ->whereIn('name', self::SCHEMES)
            ->get(['id', 'fields'])
            ->each(function (object $scheme) use ($required): void {
                $fields = json_decode((string) $scheme->fields, true);
                if (! is_array($fields)) {
                    return;
                }

                $changed = false;
                foreach ($fields as &$field) {
                    if (($field['name'] ?? null) === 'description' && ($field['required'] ?? null) !== $required) {
                        $field['required'] = $required;
                        $changed = true;
                    }
                }
                unset($field);

                if ($changed) {
                    DB::table('collection_schemes')
                        ->where('id', $scheme->id)
                        ->update(['fields' => json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                }
            });
    }
};
