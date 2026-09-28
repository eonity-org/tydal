<?php

namespace Tests\Feature;

use App\Enums\VaultPurpose;
use App\Models\Collection;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vault;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The workspace ↔ vault links, edited from both sides: the vault form
 * (`workspace_ids` on the platform vault API) and the workspace selector
 * (`/workspaces/{id}/vaults`). Both refuse the same changes — the ones the
 * vault itself controls (VaultService::associationLock).
 */
class VaultWorkspaceLinksTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Organization $org;

    private Workspace $submissions;

    private Workspace $archive;

    private Vault $vault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = User::factory()->superAdmin()->create()->createToken('test')->plainTextToken;
        $this->org = Organization::factory()->create();
        $this->submissions = Workspace::factory()->create(['organization_id' => $this->org->id, 'name' => 'Submissions']);
        $this->archive = Workspace::factory()->create(['organization_id' => $this->org->id, 'name' => 'Archive']);
        $collection = Collection::factory()->create(['organization_id' => $this->org->id, 'index_id' => null]);

        $this->vault = Vault::factory()->purpose(VaultPurpose::GALLERY)->create([
            'organization_id' => $this->org->id,
            'exposure_policy' => ['ingest' => ['workspace_id' => $this->submissions->id, 'collection_id' => $collection->id]],
        ]);
        $this->vault->workspaces()->attach($this->submissions->id);
    }

    private function updateVault(array $data)
    {
        return $this->withToken($this->token)->putJson("/api/v1/platform/vaults/{$this->vault->id}", $data);
    }

    // =========================================================================
    // Vault form
    // =========================================================================

    public function test_vault_form_sets_the_workspaces_it_reads_from(): void
    {
        $this->updateVault(['workspace_ids' => [$this->submissions->id, $this->archive->id]])
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.vault.workspaces');

        $this->assertDatabaseHas('workspace_vault', ['workspace_id' => $this->archive->id, 'vault_id' => $this->vault->id]);
    }

    public function test_vault_list_reports_each_vaults_workspaces(): void
    {
        $this->withToken($this->token)->getJson('/api/v1/platform/vaults')
            ->assertStatus(200)
            ->assertJsonPath('data.vaults.0.workspaces.0.name', 'Submissions');
    }

    public function test_vault_form_refuses_another_organizations_workspace(): void
    {
        $foreign = Workspace::factory()->create();

        $this->updateVault(['workspace_ids' => [$this->submissions->id, $foreign->id]])->assertStatus(422);
        $this->assertDatabaseMissing('workspace_vault', ['workspace_id' => $foreign->id]);
    }

    public function test_removing_the_ingest_target_is_refused_and_rolls_back(): void
    {
        $this->updateVault(['name' => 'Renamed', 'workspace_ids' => [$this->archive->id]])->assertStatus(409);

        // Nothing from the refused save stuck — not the links, not the name.
        $this->assertDatabaseHas('workspace_vault', ['workspace_id' => $this->submissions->id, 'vault_id' => $this->vault->id]);
        $this->assertNotSame('Renamed', $this->vault->fresh()->name);
    }

    public function test_moving_the_ingest_target_in_the_same_save_frees_the_old_one(): void
    {
        $policy = $this->vault->exposure_policy;
        $policy['ingest']['workspace_id'] = $this->archive->id;

        $this->updateVault(['exposure_policy' => $policy, 'workspace_ids' => [$this->archive->id]])
            ->assertStatus(200);
        $this->assertDatabaseMissing('workspace_vault', ['workspace_id' => $this->submissions->id, 'vault_id' => $this->vault->id]);
    }

    // =========================================================================
    // Workspace selector
    // =========================================================================

    public function test_selector_cannot_remove_the_ingest_target(): void
    {
        $this->withToken($this->token)
            ->deleteJson("/api/v1/workspaces/{$this->submissions->id}/vaults/{$this->vault->id}")
            ->assertStatus(409);
    }

    public function test_selector_is_told_which_links_the_vault_controls(): void
    {
        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->submissions->id}/vaults")
            ->assertStatus(200)
            ->assertJsonPath('data.vaults.0.association_lock', 'This is the vault\'s ingest target — uploads land here. Change the target first.');
    }

    public function test_active_selection_locks_both_sides(): void
    {
        $this->vault->update(['selection_snapshot' => ['workspace_ids' => [$this->submissions->id], 'has_public_workspace' => false]]);

        $this->withToken($this->token)
            ->postJson("/api/v1/workspaces/{$this->archive->id}/vaults", ['vault_id' => $this->vault->id])
            ->assertStatus(409);
        $this->updateVault(['workspace_ids' => [$this->submissions->id, $this->archive->id]])->assertStatus(409);

        $this->withToken($this->token)
            ->withHeader('X-Organization-ID', $this->org->id)
            ->getJson('/api/v1/vaults')
            ->assertStatus(200)
            ->assertJsonPath('data.vaults.0.attach_lock', 'This vault\'s published selection decides what it shows — close the exhibition first.');
    }
}
