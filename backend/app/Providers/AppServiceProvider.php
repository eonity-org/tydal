<?php

namespace App\Providers;

use App\Jobs\AnalyzeImageContent;
use App\Repositories\CategoryRepository;
use App\Repositories\CollectionRepository;
use App\Repositories\FileRepository;
// Repository Interfaces
use App\Repositories\Interfaces\CategoryRepositoryInterface;
use App\Repositories\Interfaces\CollectionRepositoryInterface;
use App\Repositories\Interfaces\FileRepositoryInterface;
use App\Repositories\Interfaces\OrganizationRepositoryInterface;
use App\Repositories\Interfaces\ResourceRepositoryInterface;
use App\Repositories\Interfaces\SemanticTagRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Repositories\Interfaces\VaultRepositoryInterface;
use App\Repositories\Interfaces\WorkspaceRepositoryInterface;
// Repository Implementations
use App\Repositories\OrganizationRepository;
use App\Repositories\ResourceRepository;
use App\Repositories\SemanticTagRepository;
use App\Repositories\UserRepository;
use App\Repositories\VaultRepository;
use App\Repositories\WorkspaceRepository;
use App\Services\CategoryService;
use App\Services\CollectionService;
use App\Services\CurrentOrganizationService;
// Service Interfaces
// Embedding drivers
use App\Services\ElasticsearchService;
use App\Services\Interfaces\CategoryServiceInterface;
use App\Services\Interfaces\CollectionServiceInterface;
use App\Services\Interfaces\OrganizationServiceInterface;
// LLM drivers
use App\Services\Interfaces\ResourceServiceInterface;
use App\Services\Interfaces\VaultServiceInterface;
use App\Services\Interfaces\WorkspaceServiceInterface;
use App\Services\LLM\Contracts\LlmServiceInterface;
// Service Interfaces
use App\Services\LLM\LlmDriverFactory;
use App\Services\OrganizationService;
use App\Services\Processing\Contracts\EmbeddingServiceInterface;
use App\Services\Processing\JinaEmbeddingService;
use App\Services\Processing\OllamaEmbeddingService;
use App\Services\Processing\VoyageEmbeddingService;
// Service Implementations
use App\Services\RagService;
use App\Services\ResourceService;
use App\Services\VaultLinkService;
use App\Services\VaultService;
use App\Services\VersionService;
use App\Services\WorkspaceService;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Core Services (Singleton)
        $this->app->singleton(CurrentOrganizationService::class);
        $this->app->singleton(VersionService::class);

        // Repositories (Singleton for performance)
        $this->app->singleton(OrganizationRepositoryInterface::class, OrganizationRepository::class);
        $this->app->singleton(UserRepositoryInterface::class, UserRepository::class);
        $this->app->singleton(WorkspaceRepositoryInterface::class, WorkspaceRepository::class);
        $this->app->singleton(CollectionRepositoryInterface::class, CollectionRepository::class);
        $this->app->singleton(ResourceRepositoryInterface::class, ResourceRepository::class);
        $this->app->singleton(CategoryRepositoryInterface::class, CategoryRepository::class);
        $this->app->singleton(FileRepositoryInterface::class, FileRepository::class);
        $this->app->singleton(SemanticTagRepositoryInterface::class, SemanticTagRepository::class);

        // Embedding service — driver selected by EMBEDDING_DRIVER env var
        $this->app->bind(EmbeddingServiceInterface::class, function () {
            return match (config('embedding.driver', 'ollama')) {
                'jina' => new JinaEmbeddingService,
                'voyage' => new VoyageEmbeddingService,
                default => new OllamaEmbeddingService,
            };
        });

        // LLM service — driver selected by LLM_TEXT_DRIVER env var (chat: RAG + auto-tagging)
        $this->app->bind(LlmServiceInterface::class, function () {
            return LlmDriverFactory::chatDriver();
        });

        // Vision LLM — separate driver for image analysis (LLM_VISION_DRIVER, falls back to LLM_TEXT_DRIVER)
        $this->app->when(AnalyzeImageContent::class)
            ->needs(LlmServiceInterface::class)
            ->give(function () {
                return LlmDriverFactory::visionDriver();
            });

        // RagService wires together ElasticsearchService + EmbeddingServiceInterface + LlmServiceInterface
        $this->app->singleton(RagService::class, function ($app) {
            return new RagService(
                $app->make(ElasticsearchService::class),
                $app->make(EmbeddingServiceInterface::class),
                $app->make(LlmServiceInterface::class),
            );
        });

        // Business Services (Singleton for performance)
        $this->app->singleton(OrganizationServiceInterface::class, OrganizationService::class);
        $this->app->singleton(WorkspaceServiceInterface::class, WorkspaceService::class);
        $this->app->singleton(CollectionServiceInterface::class, CollectionService::class);
        $this->app->singleton(ResourceServiceInterface::class, ResourceService::class);
        $this->app->singleton(CategoryServiceInterface::class, CategoryService::class);
        $this->app->singleton(VaultRepositoryInterface::class, VaultRepository::class);
        $this->app->singleton(VaultServiceInterface::class, VaultService::class);
        $this->app->singleton(VaultLinkService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Wire config/permissions.php — the single source of truth for who may
        // do what — to the Gate.
        //
        // The role asked about is the EFFECTIVE one: a platform admin resolves
        // to the config's `superadmin` block in every organization, member or
        // not, which is what lets them work in an organization they were never
        // added to. Everyone else resolves to their membership role.
        //
        // Only dotted abilities ('resources.update') live in the config. Bare
        // policy abilities ('update', 'view') match nothing here and fall
        // through to the policy, which is what keeps the contextual rules —
        // same organization, ownership — in force rather than short-circuited.
        Gate::before(function ($user, string $ability) {
            $permissions = currentPermissions();

            // null, not false: a miss falls through to the policy rather than
            // denying outright, which is what keeps the contextual rules alive.
            return Permissions::allows($permissions, $ability) ? true : null;
        });
    }
}
