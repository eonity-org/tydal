<?php

namespace App\Console\Commands;

use App\Http\Controllers\API\TokenController;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Issue a scoped API key on a dedicated machine user for MCP clients.
 *
 * Creates (or reuses) a service account, attaches it to the organization,
 * revokes any previous token with the same name, and prints the new key
 * once, together with a ready-to-paste MCP env block.
 */
class McpToken extends Command
{
    protected $signature = 'mcp:token
        {--email=mcp@tydal.test : Machine user email (created if missing)}
        {--org= : Organization slug or UUID (default: first organization)}
        {--role=editor : Role for the machine user in the organization (viewer|editor|admin)}
        {--name=claude-desktop : Token name (previous token with this name is revoked)}
        {--abilities=read,ask : Comma-separated abilities (read, ask, write)}
        {--days= : Token lifetime in days (default: no expiry)}';

    protected $description = 'Issue a scoped API key on a dedicated machine user for MCP clients';

    public function handle(): int
    {
        $abilities = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('abilities')))));
        $invalid = array_diff($abilities, TokenController::ABILITIES);
        if ($abilities === [] || $invalid !== []) {
            $this->error('Abilities must be a non-empty subset of: '.implode(', ', TokenController::ABILITIES));

            return self::FAILURE;
        }

        $name = (string) $this->option('name');
        if ($name === TokenController::SESSION_TOKEN_NAME) {
            $this->error("'".TokenController::SESSION_TOKEN_NAME."' is reserved for session tokens.");

            return self::FAILURE;
        }

        $role = (string) $this->option('role');
        if (! in_array($role, ['viewer', 'editor', 'admin'], true)) {
            $this->error('Role must be one of: viewer, editor, admin');

            return self::FAILURE;
        }

        $organization = $this->resolveOrganization();
        if (! $organization) {
            $this->error('No organization found. Pass --org=<slug|uuid> or seed one first.');

            return self::FAILURE;
        }

        $user = User::firstOrCreate(
            ['email' => (string) $this->option('email')],
            [
                'name' => 'MCP Service',
                // Machine account: random password, never used interactively.
                'password' => Hash::make(Str::password(40)),
                'is_active' => true,
                'is_superadmin' => false,
            ]
        );

        if (! $organization->users()->where('users.id', $user->id)->exists()) {
            $organization->users()->attach($user->id, ['role' => $role]);
        }

        // Fallback org context for requests that omit X-Organization-ID
        // (CurrentOrganizationService falls back to this when no header or
        // session is present).
        if ($user->last_organization_id !== $organization->id) {
            $user->update(['last_organization_id' => $organization->id]);
        }

        // One active key per name — reissuing revokes the previous one.
        $user->tokens()->where('name', $name)->delete();

        $days = $this->option('days');
        $token = $user->createToken($name, $abilities, $days ? now()->addDays((int) $days) : null);

        $this->info('API key issued — shown once, store it now.');
        $this->newLine();
        $this->line('  User:      '.$user->email.' ('.$role.' @ '.$organization->name.')');
        $this->line('  Token:     '.$name.' ['.implode(', ', $abilities).']'.($days ? " expires in {$days} days" : ' (no expiry)'));
        $this->newLine();
        $this->line('MCP server env (claude_desktop_config.json):');
        $this->newLine();
        $this->line(json_encode([
            'TYDAL_BASE_URL' => rtrim(config('app.url'), '/').'/api/v1',
            'TYDAL_TOKEN' => $token->plainTextToken,
            'TYDAL_ORG_ID' => (string) $organization->id,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function resolveOrganization(): ?Organization
    {
        $org = $this->option('org');

        if (! $org) {
            return Organization::first();
        }

        return Organization::where('slug', $org)
            ->orWhere('id', $org)
            ->first();
    }
}
