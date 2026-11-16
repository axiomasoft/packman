<?php

declare(strict_types=1);

use Packman\Schema\Internal\Services\SchemaNames;

it('builds index names from table and suffix and keeps them within 63 bytes', function (): void {
    expect(SchemaNames::identifier('pmn_messages', 'channel_position_unique'))->toBe('pmn_messages_channel_position_unique');

    $long = SchemaNames::identifier('tenant_acme_corporation_forum_category_tag_rules', 'tag_group_id_fk');
    expect($long)->toBe('tenant_acme_corporation_forum_category_tag_rules_tag_g_bf628246')
        ->and(strlen($long))->toBe(63)
        ->and(SchemaNames::identifier('x_'.str_repeat('a', 61), 'idx'))->toBe('x_'.str_repeat('a', 52).'_79af84f9');
});

it('normalises space ids to migration slugs', function (string $id, string $slug): void {
    expect(SchemaNames::spaceSlug($id))->toBe($slug);
})->with([
    ['default', 'default'],
    ['crm-alias', 'crm_alias'],
    ['Sales Team', 'sales_team'],
    ['__eu__', 'eu'],
    ['ÄÖ', ''],
]);

it('derives the migration name from step timestamp, space slug, component and step name', function (): void {
    expect(SchemaNames::migration('crm_alias', 'core', '2026_10_01_000002_create_channels'))
        ->toBe('2026_10_01_000002_packman_crm_alias_core_create_channels')
        ->and(SchemaNames::migration('crm_alias', 'core', '2026_10_01_000002_create_channels', 'app'))
        ->toBe('2026_10_01_000002_app_crm_alias_core_create_channels');
});

it('rejects a step id that is not YYYY_MM_DD_HHMMSS_<snake_name>', function (): void {
    expect(fn () => SchemaNames::parseStepId('v1_create_channels'))
        ->toThrow(InvalidArgumentException::class, 'Schema step id [v1_create_channels] must be YYYY_MM_DD_HHMMSS_<snake_name>.');
});
