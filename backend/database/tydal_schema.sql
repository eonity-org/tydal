/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS */;
/*!40014 SET FOREIGN_KEY_CHECKS=0 */;
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
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `organizations_slug_unique` (`slug`),
  KEY `organizations_type_index` (`type`),
  KEY `organizations_slug_index` (`slug`),
  KEY `organizations_is_active_index` (`is_active`)
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
  KEY `users_last_organization_id_foreign` (`last_organization_id`)
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
CREATE TABLE `search_indexes` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `index_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `index_mappings` json DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `search_indexes_index_name_unique` (`index_name`),
  KEY `search_indexes_index_name_index` (`index_name`),
  KEY `search_indexes_is_active_index` (`is_active`)
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
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `collection_schemes_name_unique` (`name`),
  KEY `collection_schemes_name_index` (`name`),
  KEY `collection_schemes_is_system_index` (`is_system`)
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
  KEY `workspaces_organization_id_user_owner_id_purpose_index` (`organization_id`,`user_owner_id`,`purpose`)
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
  KEY `collections_index_id_index` (`index_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  KEY `categories_is_active_index` (`is_active`)
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
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `visibility` enum('private','organization','workspace','public','draft') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'private',
  `aity_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_applicable',
  `metadata` json DEFAULT NULL,
  `promoted_file_metadata` json DEFAULT NULL,
  `payload` json NOT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
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
  KEY `resources_published_at_index` (`published_at`),
  KEY `resources_created_at_index` (`created_at`),
  KEY `resources_name_index` (`name`),
  KEY `resources_active_index` (`active`),
  KEY `resources_visibility_index` (`visibility`),
  KEY `resources_aity_status_index` (`aity_status`),
  KEY `resources_name_source_file_id_foreign` (`name_source_file_id`),
  KEY `resources_description_source_file_id_foreign` (`description_source_file_id`),
  KEY `resources_tags_source_file_id_foreign` (`tags_source_file_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `files` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `resource_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_owner_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `media_id` bigint unsigned DEFAULT NULL,
  `role` enum('canonical','component','supporting') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'supporting',
  `relation` enum('derived','rendition','variant','translation','transcript','extracted') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
  KEY `files_uncommitted_at_index` (`uncommitted_at`)
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
  KEY `system_files_aity_lookup_idx` (`source_file_id`,`purpose`,`is_active`,`applied_at`)
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
  KEY `file_chunks_archive_file_id_index` (`archive_file_id`)
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
  KEY `semantic_tags_organization_id_index` (`organization_id`)
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
  KEY `organization_user_role_index` (`role`)
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
  KEY `workspace_user_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `dam_resource_workspace` (
  `resource_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `workspace_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`resource_id`,`workspace_id`),
  KEY `dam_resource_workspace_resource_id_index` (`resource_id`),
  KEY `dam_resource_workspace_workspace_id_index` (`workspace_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `category_resource` (
  `category_id` bigint unsigned NOT NULL,
  `resource_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`category_id`,`resource_id`),
  KEY `category_resource_category_id_index` (`category_id`),
  KEY `category_resource_resource_id_index` (`resource_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `semantic_tag_resource` (
  `resource_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `semantic_tag_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`semantic_tag_id`,`resource_id`),
  KEY `semantic_tag_resource_resource_id_index` (`resource_id`),
  KEY `semantic_tag_resource_semantic_tag_id_index` (`semantic_tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `vaults` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `salt` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `has_public_workspace` tinyint(1) NOT NULL DEFAULT '0',
  `is_downloadable` tinyint(1) NOT NULL DEFAULT '0',
  `hash_ttl_hours` smallint unsigned DEFAULT NULL,
  `allowed_ips` json DEFAULT NULL,
  `base_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vaults_slug_unique` (`slug`),
  KEY `vaults_is_active_index` (`is_active`),
  KEY `vaults_slug_index` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `workspace_vault` (
  `workspace_id` bigint unsigned NOT NULL,
  `vault_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`workspace_id`,`vault_id`),
  KEY `workspace_vault_vault_id_foreign` (`vault_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `vault_links` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `vault_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `workspace_id` bigint unsigned NOT NULL,
  `resource_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `link_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hash` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vault_links_link_key_unique` (`link_key`),
  UNIQUE KEY `vault_links_hash_unique` (`hash`),
  KEY `vault_links_workspace_id_foreign` (`workspace_id`),
  KEY `vault_links_file_id_foreign` (`file_id`),
  KEY `vault_links_vault_id_workspace_id_index` (`vault_id`,`workspace_id`),
  KEY `vault_links_resource_id_index` (`resource_id`)
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
  KEY `resource_events_type_idx` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `users` ADD CONSTRAINT `users_last_organization_id_foreign` FOREIGN KEY (`last_organization_id`) REFERENCES `organizations` (`id`) ON DELETE SET NULL;
ALTER TABLE `workspaces` ADD CONSTRAINT `workspaces_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE;
ALTER TABLE `workspaces` ADD CONSTRAINT `workspaces_user_owner_id_foreign` FOREIGN KEY (`user_owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `collections` ADD CONSTRAINT `collections_index_id_foreign` FOREIGN KEY (`index_id`) REFERENCES `search_indexes` (`id`) ON DELETE SET NULL;
ALTER TABLE `collections` ADD CONSTRAINT `collections_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE;
ALTER TABLE `collections` ADD CONSTRAINT `collections_scheme_id_foreign` FOREIGN KEY (`scheme_id`) REFERENCES `collection_schemes` (`id`) ON DELETE SET NULL;
ALTER TABLE `collections` ADD CONSTRAINT `collections_user_owner_id_foreign` FOREIGN KEY (`user_owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `categories` ADD CONSTRAINT `categories_collection_id_foreign` FOREIGN KEY (`collection_id`) REFERENCES `collections` (`id`) ON DELETE CASCADE;
ALTER TABLE `categories` ADD CONSTRAINT `categories_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE;
ALTER TABLE `categories` ADD CONSTRAINT `categories_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL;
ALTER TABLE `categories` ADD CONSTRAINT `categories_user_owner_id_foreign` FOREIGN KEY (`user_owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `resources` ADD CONSTRAINT `resources_collection_id_foreign` FOREIGN KEY (`collection_id`) REFERENCES `collections` (`id`) ON DELETE CASCADE;
ALTER TABLE `resources` ADD CONSTRAINT `resources_description_source_file_id_foreign` FOREIGN KEY (`description_source_file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL;
ALTER TABLE `resources` ADD CONSTRAINT `resources_name_source_file_id_foreign` FOREIGN KEY (`name_source_file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL;
ALTER TABLE `resources` ADD CONSTRAINT `resources_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE;
ALTER TABLE `resources` ADD CONSTRAINT `resources_tags_source_file_id_foreign` FOREIGN KEY (`tags_source_file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL;
ALTER TABLE `resources` ADD CONSTRAINT `resources_user_owner_id_foreign` FOREIGN KEY (`user_owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `files` ADD CONSTRAINT `files_resource_id_foreign` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE;
ALTER TABLE `files` ADD CONSTRAINT `files_uncommitted_by_foreign` FOREIGN KEY (`uncommitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `files` ADD CONSTRAINT `files_user_owner_id_foreign` FOREIGN KEY (`user_owner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `system_files` ADD CONSTRAINT `system_files_resource_id_foreign` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE;
ALTER TABLE `system_files` ADD CONSTRAINT `system_files_source_file_id_foreign` FOREIGN KEY (`source_file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL;
ALTER TABLE `file_chunks` ADD CONSTRAINT `file_chunks_archive_file_id_foreign` FOREIGN KEY (`archive_file_id`) REFERENCES `system_files` (`id`) ON DELETE SET NULL;
ALTER TABLE `file_chunks` ADD CONSTRAINT `file_chunks_resource_id_foreign` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE;
ALTER TABLE `file_chunks` ADD CONSTRAINT `file_chunks_source_file_id_foreign` FOREIGN KEY (`source_file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL;
ALTER TABLE `semantic_tags` ADD CONSTRAINT `semantic_tags_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE;
ALTER TABLE `organization_user` ADD CONSTRAINT `organization_user_organization_id_foreign` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE;
ALTER TABLE `organization_user` ADD CONSTRAINT `organization_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `workspace_user` ADD CONSTRAINT `workspace_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `workspace_user` ADD CONSTRAINT `workspace_user_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE;
ALTER TABLE `dam_resource_workspace` ADD CONSTRAINT `dam_resource_workspace_resource_id_foreign` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE;
ALTER TABLE `dam_resource_workspace` ADD CONSTRAINT `dam_resource_workspace_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE;
ALTER TABLE `category_resource` ADD CONSTRAINT `category_resource_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE;
ALTER TABLE `category_resource` ADD CONSTRAINT `category_resource_resource_id_foreign` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE;
ALTER TABLE `semantic_tag_resource` ADD CONSTRAINT `semantic_tag_resource_resource_id_foreign` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE;
ALTER TABLE `semantic_tag_resource` ADD CONSTRAINT `semantic_tag_resource_semantic_tag_id_foreign` FOREIGN KEY (`semantic_tag_id`) REFERENCES `semantic_tags` (`id`) ON DELETE CASCADE;
ALTER TABLE `workspace_vault` ADD CONSTRAINT `workspace_vault_vault_id_foreign` FOREIGN KEY (`vault_id`) REFERENCES `vaults` (`id`) ON DELETE CASCADE;
ALTER TABLE `workspace_vault` ADD CONSTRAINT `workspace_vault_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE;
ALTER TABLE `vault_links` ADD CONSTRAINT `vault_links_vault_id_foreign` FOREIGN KEY (`vault_id`) REFERENCES `vaults` (`id`) ON DELETE CASCADE;
ALTER TABLE `vault_links` ADD CONSTRAINT `vault_links_file_id_foreign` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE;
ALTER TABLE `vault_links` ADD CONSTRAINT `vault_links_resource_id_foreign` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE;
ALTER TABLE `vault_links` ADD CONSTRAINT `vault_links_workspace_id_foreign` FOREIGN KEY (`workspace_id`) REFERENCES `workspaces` (`id`) ON DELETE CASCADE;
ALTER TABLE `resource_events` ADD CONSTRAINT `resource_events_resource_id_foreign` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
