<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Baseline schema for TYDAL open-source installs.
     *
     * This replaces the pre-release migration history AND every incremental
     * migration through 2026-07-29 (vault org scoping, vault keys, resource
     * embeddings, per-resource link slugs, vault schema overlays, per-vault
     * indexing, the resource relation graph, vault provenance/grant epoch,
     * the resources/vaults state-flag collapse, vault write abilities +
     * audit, and scheme/index visibility). A fresh install only ever needs
     * this single migration; existing databases that already have the
     * historical schema are left untouched. Future schema changes should be
     * added as normal incremental migrations after this file.
     */
    public function up(): void
    {
        if (Schema::hasTable('organizations')) {
            return;
        }

        DB::unprepared(<<<'SQL'
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
CREATE TABLE `categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `collection_id` bigint unsigned DEFAULT NULL,
  `user_owner_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `parent_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `categories_organization_id_index` (`organization_id`),
  KEY `categories_collection_id_index` (`collection_id`),
  KEY `categories_user_owner_id_index` (`user_owner_id`),
  KEY `categories_parent_id_index` (`parent_id`),
  KEY `categories_is_active_index` (`is_active`),
  CONSTRAINT `categories_collection_id_foreign` FOREIGN KEY (`collection_id`) REFERENCES `collections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `categories_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `categories_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `categories_user_owner_id_foreign` FOREIGN KEY (`user_owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `category_resource` (
  `category_id` bigint unsigned NOT NULL,
  `resource_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`category_id`,`resource_id`),
  KEY `category_resource_category_id_index` (`category_id`),
  KEY `category_resource_resource_id_index` (`resource_id`),
  CONSTRAINT `category_resource_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `category_resource_resource_id_foreign` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `collection_scheme_organization` (
  `collection_scheme_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `organization_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`collection_scheme_id`,`organization_id`),
  KEY `collection_scheme_organization_organization_id_foreign` (`organization_id`),
  CONSTRAINT `collection_scheme_organization_collection_scheme_id_foreign` FOREIGN KEY (`collection_scheme_id`) REFERENCES `collection_schemes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `collection_scheme_organization_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `collection_schemes` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `fields` json NOT NULL,
  `accepted_mimetypes` json DEFAULT NULL,
  `processing_config` json DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `visibility` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'global',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `collection_schemes_name_unique` (`name`),
  KEY `collection_schemes_name_index` (`name`),
  KEY `collection_schemes_is_system_index` (`is_system`),
  KEY `collection_schemes_visibility_index` (`visibility`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `collections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_owner_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `language` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en',
  `scheme_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `index_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `collections_organization_id_slug_unique` (`organization_id`,`slug`),
  KEY `collections_organization_id_index` (`organization_id`),
  KEY `collections_user_owner_id_index` (`user_owner_id`),
  KEY `collections_is_active_index` (`is_active`),
  KEY `collections_scheme_id_index` (`scheme_id`),
  KEY `collections_index_id_index` (`index_id`),
  CONSTRAINT `collections_index_id_foreign` FOREIGN KEY (`index_id`) REFERENCES `search_indexes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `collections_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `collections_scheme_id_foreign` FOREIGN KEY (`scheme_id`) REFERENCES `collection_schemes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `collections_user_owner_id_foreign` FOREIGN KEY (`user_owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `dam_resource_workspace` (
  `resource_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `workspace_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`resource_id`,`workspace_id`),
  KEY `dam_resource_workspace_resource_id_index` (`resource_id`),
  KEY `dam_resource_workspace_workspace_id_index` (`workspace_id`),
  CONSTRAINT `dam_resource_workspace_resource_id_foreign` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dam_resource_workspace_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `file_chunks` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `resource_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_file_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `archive_file_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sequence` smallint unsigned NOT NULL,
  `page_number` smallint unsigned DEFAULT NULL,
  `word_count` int unsigned NOT NULL DEFAULT '0',
  `char_start` int unsigned NOT NULL DEFAULT '0',
  `char_end` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `file_chunks_resource_id_index` (`resource_id`),
  KEY `file_chunks_source_file_id_index` (`source_file_id`),
  KEY `file_chunks_archive_file_id_index` (`archive_file_id`),
  CONSTRAINT `file_chunks_archive_file_id_foreign` FOREIGN KEY (`archive_file_id`) REFERENCES `system_files` (`id`) ON DELETE SET NULL,
  CONSTRAINT `file_chunks_resource_id_foreign` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE,
  CONSTRAINT `file_chunks_source_file_id_foreign` FOREIGN KEY (`source_file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `files` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `resource_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_owner_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `media_id` bigint unsigned DEFAULT NULL,
  `role` enum('canonical','component','supporting') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'supporting',
  `relation` enum('derived','rendition','variant','translation','transcript','extracted') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `position` smallint unsigned DEFAULT NULL,
  `usage` json DEFAULT NULL,
  `filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `size` bigint NOT NULL,
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `disk` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public',
  `metadata` json DEFAULT NULL,
  `processing_status` json DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `uncommitted_at` timestamp NULL DEFAULT NULL,
  `uncommitted_by` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `files_resource_id_index` (`resource_id`),
  KEY `files_user_owner_id_index` (`user_owner_id`),
  KEY `files_media_id_index` (`media_id`),
  KEY `files_role_index` (`role`),
  KEY `files_relation_index` (`relation`),
  KEY `files_mime_type_index` (`mime_type`),
  KEY `files_disk_index` (`disk`),
  KEY `files_is_active_index` (`is_active`),
  KEY `files_resource_id_is_active_index` (`resource_id`,`is_active`),
  KEY `files_resource_id_role_index` (`resource_id`,`role`),
  KEY `files_uncommitted_by_foreign` (`uncommitted_by`),
  KEY `files_uncommitted_at_index` (`uncommitted_at`),
  CONSTRAINT `files_resource_id_foreign` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE,
  CONSTRAINT `files_uncommitted_by_foreign` FOREIGN KEY (`uncommitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `files_user_owner_id_foreign` FOREIGN KEY (`user_owner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `media` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `collection_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disk` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `conversions_disk` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size` bigint unsigned NOT NULL,
  `manipulations` json NOT NULL,
  `custom_properties` json NOT NULL,
  `generated_conversions` json NOT NULL,
  `responsive_images` json NOT NULL,
  `order_column` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `media_uuid_unique` (`uuid`),
  KEY `media_collection_name_index` (`collection_name`),
  KEY `media_disk_index` (`disk`),
  KEY `media_order_column_index` (`order_column`),
  KEY `media_model_type_model_id_index` (`model_type`,`model_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `notifications` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `organization_search_index` (
  `search_index_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `organization_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`search_index_id`,`organization_id`),
  KEY `organization_search_index_organization_id_foreign` (`organization_id`),
  CONSTRAINT `organization_search_index_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `organization_search_index_search_index_id_foreign` FOREIGN KEY (`search_index_id`) REFERENCES `search_indexes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `organization_user` (
  `organization_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'viewer',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`organization_id`,`user_id`),
  KEY `organization_user_organization_id_index` (`organization_id`),
  KEY `organization_user_user_id_index` (`user_id`),
  KEY `organization_user_role_index` (`role`),
  CONSTRAINT `organization_user_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `organization_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `organizations` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `type` enum('individual','business','educational','government','non_profit') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'business',
  `logo_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `settings` json DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `collection_quota` smallint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `organizations_slug_unique` (`slug`),
  KEY `organizations_type_index` (`type`),
  KEY `organizations_slug_index` (`slug`),
  KEY `organizations_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `resource_events` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `resource_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_type` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `target_type` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `target_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `resource_events_timeline_idx` (`resource_id`,`created_at`),
  KEY `resource_events_type_idx` (`event_type`),
  CONSTRAINT `resource_events_resource_id_foreign` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `resource_relations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_resource_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `object_resource_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `origin` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `weight` double DEFAULT NULL,
  `created_by` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `resource_relations_edge_unique` (`subject_resource_id`,`object_resource_id`,`type`),
  KEY `resource_relations_created_by_foreign` (`created_by`),
  KEY `resource_relations_object_resource_id_index` (`object_resource_id`),
  KEY `resource_relations_organization_id_origin_index` (`organization_id`,`origin`),
  CONSTRAINT `resource_relations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `resource_relations_object_resource_id_foreign` FOREIGN KEY (`object_resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE,
  CONSTRAINT `resource_relations_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `resource_relations_subject_resource_id_foreign` FOREIGN KEY (`subject_resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `resources` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `collection_id` bigint unsigned DEFAULT NULL,
  `organization_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_owner_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_origin` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name_source_file_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name_set_by` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name_set_at` timestamp NULL DEFAULT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `description_origin` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description_source_file_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description_set_by` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description_set_at` timestamp NULL DEFAULT NULL,
  `type` enum('document','video','image','audio','url','multimedia','course','assessment','activity','book') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'document',
  `state` enum('draft','live','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'live',
  `aity_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_applicable',
  `metadata` json DEFAULT NULL,
  `promoted_file_metadata` json DEFAULT NULL,
  `embedding` json DEFAULT NULL,
  `embedding_updated_at` timestamp NULL DEFAULT NULL,
  `payload` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `tags_origin` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tags_source_file_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tags_set_by` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tags_set_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `resources_collection_id_index` (`collection_id`),
  KEY `resources_organization_id_index` (`organization_id`),
  KEY `resources_user_owner_id_index` (`user_owner_id`),
  KEY `resources_type_index` (`type`),
  KEY `resources_created_at_index` (`created_at`),
  KEY `resources_name_index` (`name`),
  KEY `resources_aity_status_index` (`aity_status`),
  KEY `resources_name_source_file_id_foreign` (`name_source_file_id`),
  KEY `resources_description_source_file_id_foreign` (`description_source_file_id`),
  KEY `resources_tags_source_file_id_foreign` (`tags_source_file_id`),
  KEY `resources_state_index` (`state`),
  CONSTRAINT `resources_collection_id_foreign` FOREIGN KEY (`collection_id`) REFERENCES `collections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `resources_description_source_file_id_foreign` FOREIGN KEY (`description_source_file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL,
  CONSTRAINT `resources_name_source_file_id_foreign` FOREIGN KEY (`name_source_file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL,
  CONSTRAINT `resources_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `resources_tags_source_file_id_foreign` FOREIGN KEY (`tags_source_file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL,
  CONSTRAINT `resources_user_owner_id_foreign` FOREIGN KEY (`user_owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `search_indexes` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `index_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `index_mappings` json DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `visibility` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'global',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `search_indexes_index_name_unique` (`index_name`),
  KEY `search_indexes_index_name_index` (`index_name`),
  KEY `search_indexes_is_active_index` (`is_active`),
  KEY `search_indexes_visibility_index` (`visibility`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `semantic_tag_resource` (
  `resource_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `semantic_tag_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`semantic_tag_id`,`resource_id`),
  KEY `semantic_tag_resource_resource_id_index` (`resource_id`),
  KEY `semantic_tag_resource_semantic_tag_id_index` (`semantic_tag_id`),
  CONSTRAINT `semantic_tag_resource_resource_id_foreign` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE,
  CONSTRAINT `semantic_tag_resource_semantic_tag_id_foreign` FOREIGN KEY (`semantic_tag_id`) REFERENCES `semantic_tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `semantic_tags` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `entity_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `vocabulary` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'organization',
  `reviewer` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `semantic_tags_slug_unique` (`slug`),
  UNIQUE KEY `semantic_tags_org_label_entity_type_unique` (`organization_id`,`label`,`entity_type`),
  KEY `semantic_tags_organization_id_index` (`organization_id`),
  CONSTRAINT `semantic_tags_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `system_files` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `resource_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_file_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purpose` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `size` bigint unsigned NOT NULL DEFAULT '0',
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `disk` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'local',
  `metadata` json DEFAULT NULL,
  `language` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `applied_at` timestamp NULL DEFAULT NULL,
  `applied_by_aity` tinyint(1) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `system_files_resource_id_purpose_index` (`resource_id`,`purpose`),
  KEY `system_files_resource_id_is_active_index` (`resource_id`,`is_active`),
  KEY `system_files_resource_id_index` (`resource_id`),
  KEY `system_files_source_file_id_index` (`source_file_id`),
  KEY `system_files_aity_lookup_idx` (`source_file_id`,`purpose`,`is_active`,`applied_at`),
  CONSTRAINT `system_files_resource_id_foreign` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE,
  CONSTRAINT `system_files_source_file_id_foreign` FOREIGN KEY (`source_file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `users` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `last_organization_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `avatar_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_superadmin` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_email_index` (`email`),
  KEY `users_is_active_index` (`is_active`),
  KEY `users_is_superadmin_index` (`is_superadmin`),
  KEY `users_last_organization_id_foreign` (`last_organization_id`),
  CONSTRAINT `users_last_organization_id_foreign` FOREIGN KEY (`last_organization_id`) REFERENCES `organizations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `vault_keys` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `vault_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `key_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `key_prefix` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` json DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vault_keys_key_hash_unique` (`key_hash`),
  KEY `vault_keys_vault_id_revoked_at_index` (`vault_id`,`revoked_at`),
  CONSTRAINT `vault_keys_vault_id_foreign` FOREIGN KEY (`vault_id`) REFERENCES `vaults` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `vault_links` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `vault_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `workspace_id` bigint unsigned DEFAULT NULL,
  `resource_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `link_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hash` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `resource_slug_key` varchar(255) COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (if((`file_id` is null),`slug`,NULL)) VIRTUAL,
  `file_slug_key` varchar(255) COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (if((`file_id` is null),NULL,`slug`)) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vault_links_link_key_unique` (`link_key`),
  UNIQUE KEY `vault_links_hash_unique` (`hash`),
  UNIQUE KEY `vault_links_resource_slug_unique` (`vault_id`,`resource_slug_key`),
  UNIQUE KEY `vault_links_file_slug_unique` (`vault_id`,`resource_id`,`file_slug_key`),
  KEY `vault_links_workspace_id_foreign` (`workspace_id`),
  KEY `vault_links_file_id_foreign` (`file_id`),
  KEY `vault_links_vault_id_workspace_id_index` (`vault_id`,`workspace_id`),
  KEY `vault_links_resource_id_index` (`resource_id`),
  KEY `vault_links_vault_id_slug_index` (`vault_id`,`slug`),
  CONSTRAINT `vault_links_file_id_foreign` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vault_links_resource_id_foreign` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vault_links_vault_id_foreign` FOREIGN KEY (`vault_id`) REFERENCES `vaults` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vault_links_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `vault_schema_overlays` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `vault_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scheme_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `field_roles` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vault_schema_overlays_vault_id_scheme_id_unique` (`vault_id`,`scheme_id`),
  KEY `vault_schema_overlays_scheme_id_foreign` (`scheme_id`),
  CONSTRAINT `vault_schema_overlays_scheme_id_foreign` FOREIGN KEY (`scheme_id`) REFERENCES `collection_schemes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vault_schema_overlays_vault_id_foreign` FOREIGN KEY (`vault_id`) REFERENCES `vaults` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `vault_writes` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `vault_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `vault_key_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `method` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` json DEFAULT NULL,
  `client_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `vault_writes_vault_key_id_foreign` (`vault_key_id`),
  KEY `vault_writes_vault_id_created_at_index` (`vault_id`,`created_at`),
  CONSTRAINT `vault_writes_vault_id_foreign` FOREIGN KEY (`vault_id`) REFERENCES `vaults` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vault_writes_vault_key_id_foreign` FOREIGN KEY (`vault_key_id`) REFERENCES `vault_keys` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `vaults` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `organization_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hash` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `purpose` enum('delivery','gallery','obsidian','ai','mixed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'delivery',
  `generated_from` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` enum('disabled','private','public') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'private',
  `salt` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `grant_epoch` int unsigned NOT NULL DEFAULT '0',
  `has_public_workspace` tinyint(1) NOT NULL DEFAULT '0',
  `selection_snapshot` json DEFAULT NULL,
  `is_downloadable` tinyint(1) NOT NULL DEFAULT '0',
  `hash_ttl_hours` smallint unsigned DEFAULT NULL,
  `allowed_ips` json DEFAULT NULL,
  `exposure_policy` json DEFAULT NULL,
  `indexed_at` timestamp NULL DEFAULT NULL,
  `base_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vaults_hash_unique` (`hash`),
  UNIQUE KEY `vaults_organization_id_slug_unique` (`organization_id`,`slug`),
  KEY `vaults_slug_index` (`slug`),
  KEY `vaults_organization_id_generated_from_index` (`organization_id`,`generated_from`),
  KEY `vaults_state_index` (`state`),
  CONSTRAINT `vaults_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `workspace_user` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `workspace_id` bigint unsigned NOT NULL,
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`user_id`,`workspace_id`),
  UNIQUE KEY `workspace_user_workspace_id_user_id_unique` (`workspace_id`,`user_id`),
  KEY `workspace_user_workspace_id_index` (`workspace_id`),
  KEY `workspace_user_user_id_index` (`user_id`),
  CONSTRAINT `workspace_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `workspace_user_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `workspace_vault` (
  `workspace_id` bigint unsigned NOT NULL,
  `vault_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`workspace_id`,`vault_id`),
  KEY `workspace_vault_vault_id_foreign` (`vault_id`),
  CONSTRAINT `workspace_vault_vault_id_foreign` FOREIGN KEY (`vault_id`) REFERENCES `vaults` (`id`) ON DELETE CASCADE,
  CONSTRAINT `workspace_vault_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `workspaces` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_owner_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `purpose` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `auto_approve_status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `auto_approve_log` json DEFAULT NULL,
  `auto_approve_reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workspaces_organization_id_user_owner_id_name_unique` (`organization_id`,`user_owner_id`,`name`),
  KEY `workspaces_organization_id_index` (`organization_id`),
  KEY `workspaces_user_owner_id_index` (`user_owner_id`),
  KEY `workspaces_is_active_index` (`is_active`),
  KEY `workspaces_organization_id_user_owner_id_purpose_index` (`organization_id`,`user_owner_id`,`purpose`),
  CONSTRAINT `workspaces_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `workspaces_user_owner_id_foreign` FOREIGN KEY (`user_owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
SQL);
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'vault_writes',
            'vault_schema_overlays',
            'vault_keys',
            'vault_links',
            'workspace_vault',
            'vaults',
            'resource_relations',
            'resource_events',
            'notifications',
            'semantic_tag_resource',
            'category_resource',
            'dam_resource_workspace',
            'workspace_user',
            'organization_user',
            'semantic_tags',
            'file_chunks',
            'system_files',
            'files',
            'resources',
            'categories',
            'collections',
            'collection_scheme_organization',
            'organization_search_index',
            'workspaces',
            'collection_schemes',
            'search_indexes',
            'failed_jobs',
            'media',
            'personal_access_tokens',
            'users',
            'organizations',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }
};
