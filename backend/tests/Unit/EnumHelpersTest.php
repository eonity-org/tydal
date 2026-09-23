<?php

namespace Tests\Unit;

use App\Enums\FileRelation;
use App\Enums\FileRole;
use App\Enums\OrganizationType;
use App\Enums\ResourceState;
use App\Enums\ResourceType;
use App\Enums\VaultState;
use Tests\TestCase;

/**
 * Enum Helpers Test
 *
 * Tests the common helper methods available on all enums.
 */
class EnumHelpersTest extends TestCase
{
    public function test_resource_type_enum_helpers(): void
    {
        // values() method
        $values = ResourceType::values();
        $this->assertIsArray($values);
        $this->assertContains('document', $values);
        $this->assertContains('image', $values);
        $this->assertCount(10, $values);

        // names() method
        $names = ResourceType::names();
        $this->assertIsArray($names);
        $this->assertContains('DOCUMENT', $names);
        $this->assertContains('IMAGE', $names);

        // toArray() method
        $array = ResourceType::toArray();
        $this->assertIsArray($array);
        $this->assertArrayHasKey('DOCUMENT', $array);
        $this->assertEquals('document', $array['DOCUMENT']);

        // isValid() method
        $this->assertTrue(ResourceType::isValid('document'));
        $this->assertTrue(ResourceType::isValid('image'));
        $this->assertFalse(ResourceType::isValid('invalid'));

        // random() method
        $random = ResourceType::random();
        $this->assertInstanceOf(ResourceType::class, $random);
        $this->assertTrue(in_array($random->value, $values));
    }

    public function test_resource_type_helper_methods(): void
    {
        // multimediaTypes()
        $multimedia = ResourceType::multimediaTypes();
        $this->assertContains('multimedia', $multimedia);
        $this->assertContains('image', $multimedia);
        $this->assertContains('video', $multimedia);
        $this->assertContains('audio', $multimedia);
        $this->assertNotContains('document', $multimedia);

        // educationalTypes()
        $educational = ResourceType::educationalTypes();
        $this->assertContains('course', $educational);
        $this->assertContains('assessment', $educational);
        $this->assertNotContains('image', $educational);

        // documentTypes()
        $documents = ResourceType::documentTypes();
        $this->assertContains('document', $documents);
        $this->assertContains('url', $documents);
        $this->assertNotContains('image', $documents);
    }

    public function test_resource_type_instance_methods(): void
    {
        // isMultimedia()
        $this->assertTrue(ResourceType::IMAGE->isMultimedia());
        $this->assertTrue(ResourceType::VIDEO->isMultimedia());
        $this->assertTrue(ResourceType::AUDIO->isMultimedia());
        $this->assertTrue(ResourceType::MULTIMEDIA->isMultimedia());
        $this->assertFalse(ResourceType::DOCUMENT->isMultimedia());

        // isEducational()
        $this->assertTrue(ResourceType::COURSE->isEducational());
        $this->assertTrue(ResourceType::ASSESSMENT->isEducational());
        $this->assertFalse(ResourceType::IMAGE->isEducational());

        // normalizedForCollection()
        $this->assertEquals('multimedia', ResourceType::IMAGE->normalizedForCollection());
        $this->assertEquals('multimedia', ResourceType::VIDEO->normalizedForCollection());
        $this->assertEquals('document', ResourceType::DOCUMENT->normalizedForCollection());

        // label()
        $this->assertEquals('Image', ResourceType::IMAGE->label());
        $this->assertEquals('E-book', ResourceType::BOOK->label());
        $this->assertEquals('Video', ResourceType::VIDEO->label());
    }

    public function test_organization_type_enum_helpers(): void
    {
        // values()
        $values = OrganizationType::values();
        $this->assertContains('individual', $values);
        $this->assertContains('business', $values);
        $this->assertContains('non_profit', $values);

        // businessTypes()
        $business = OrganizationType::businessTypes();
        $this->assertContains('business', $business);
        $this->assertContains('educational', $business);
        $this->assertNotContains('individual', $business);

        // isBusinessType()
        $this->assertTrue(OrganizationType::BUSINESS->isBusinessType());
        $this->assertTrue(OrganizationType::NON_PROFIT->isBusinessType());
        $this->assertFalse(OrganizationType::INDIVIDUAL->isBusinessType());

        // label()
        $this->assertEquals('Individual', OrganizationType::INDIVIDUAL->label());
        $this->assertEquals('Non-Profit', OrganizationType::NON_PROFIT->label());
    }

    public function test_file_role_enum_helpers(): void
    {
        // values()
        $values = FileRole::values();
        $this->assertContains('canonical', $values);
        $this->assertContains('component', $values);
        $this->assertContains('supporting', $values);
        $this->assertCount(3, $values);

        // label()
        $this->assertEquals('Canonical', FileRole::CANONICAL->label());
        $this->assertEquals('Component', FileRole::COMPONENT->label());
        $this->assertEquals('Supporting', FileRole::SUPPORTING->label());
    }

    public function test_file_relation_enum_helpers(): void
    {
        // values()
        $values = FileRelation::values();
        $this->assertContains('translation', $values);
        $this->assertContains('rendition', $values);
        $this->assertContains('variant', $values);
        $this->assertContains('transcript', $values);
        $this->assertContains('extracted', $values);
        $this->assertContains('derived', $values);
        $this->assertCount(6, $values);

        // label()
        $this->assertEquals('Translation', FileRelation::TRANSLATION->label());
        $this->assertEquals('Rendition', FileRelation::RENDITION->label());
    }

    public function test_resource_state_enum_helpers(): void
    {
        $values = ResourceState::values();
        $this->assertContains('draft', $values);
        $this->assertContains('live', $values);
        $this->assertContains('archived', $values);

        // Only `live` is listed, searched and projected.
        $this->assertTrue(ResourceState::LIVE->isVisible());
        $this->assertFalse(ResourceState::DRAFT->isVisible());
        $this->assertFalse(ResourceState::ARCHIVED->isVisible());
    }

    public function test_vault_state_enum_helpers(): void
    {
        $values = VaultState::values();
        $this->assertContains('disabled', $values);
        $this->assertContains('private', $values);
        $this->assertContains('public', $values);

        // Reachable = answers at all; open = answers without a credential.
        $this->assertFalse(VaultState::DISABLED->isReachable());
        $this->assertTrue(VaultState::PRIVATE->isReachable());
        $this->assertTrue(VaultState::PUBLIC->isReachable());

        $this->assertFalse(VaultState::PRIVATE->isOpen());
        $this->assertTrue(VaultState::PUBLIC->isOpen());
    }

    public function test_enum_to_label_array(): void
    {
        // ResourceType labels
        $resourceLabels = ResourceType::toLabelArray();
        $this->assertIsArray($resourceLabels);
        $this->assertArrayHasKey('document', $resourceLabels);
        $this->assertEquals('Document', $resourceLabels['document']);
        $this->assertEquals('E-book', $resourceLabels['book']);

        // OrganizationType labels
        $orgLabels = OrganizationType::toLabelArray();
        $this->assertArrayHasKey('individual', $orgLabels);
        $this->assertEquals('Individual', $orgLabels['individual']);
        $this->assertEquals('Non-Profit', $orgLabels['non_profit']);
    }

    public function test_helper_functions_use_php_enums(): void
    {
        // Helper functions should return same values as PHP enums
        $this->assertEquals(
            ResourceType::values(),
            resource_types()
        );

        $this->assertEquals(
            OrganizationType::values(),
            organization_types()
        );

        $this->assertEquals(
            FileRole::values(),
            file_roles()
        );

        $this->assertEquals(
            FileRelation::values(),
            file_relations()
        );

        $this->assertEquals(
            ResourceState::values(),
            resource_states()
        );
    }

    public function test_enum_try_from_value(): void
    {
        // Valid value
        $enum = ResourceType::tryFromValue('document');
        $this->assertInstanceOf(ResourceType::class, $enum);
        $this->assertEquals(ResourceType::DOCUMENT, $enum);

        // Invalid value
        $enum = ResourceType::tryFromValue('invalid');
        $this->assertNull($enum);

        // Null value
        $enum = ResourceType::tryFromValue(null);
        $this->assertNull($enum);
    }
}
