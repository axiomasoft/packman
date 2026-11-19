<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Packman\Modules\Data\Compiler\InstalledPackageVersion;
use Packman\Modules\Data\Entities\Entity;
use Packman\Modules\Data\Entities\FieldDefinition;
use Packman\Modules\Data\Extenders\Menu;
use Packman\Modules\Data\Lifecycle\ModuleManifest;

/**
 * Scalar fixtures are shared by the root ratchet and external extension tests.
 *
 * @return array{list<ModuleManifest>, array<string, InstalledPackageVersion>}
 */
function extensionContractFixture(string $path): array
{
    $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($data) || ! is_array($data['modules'] ?? null)) {
        throw new InvalidArgumentException("Invalid extension fixture {$path}.");
    }

    $manifests = [];
    $versions = [];
    foreach ($data['modules'] as $module) {
        $extenders = [];

        if (isset($module['entity'])) {
            $extenders[] = Entity::make(Model::class)
                ->handle($module['entity'])
                ->table($module['prefix'].'_things')
                ->fields([new FieldDefinition('name', 'string')]);
        }
        foreach ($module['menu'] ?? [] as $row) {
            $menu = Menu::item('app', $row['handle'])->label($row['label']);

            if (isset($row['after'])) {
                $menu->after($row['after']);
            }
            $extenders[] = $menu;
        }

        $manifest = ModuleManifest::make($module['name'])
            ->title($module['name'])
            ->tablePrefix($module['prefix'])
            ->extends($extenders);
        foreach ($module['replacements'] ?? [] as $replacement) {
            $manifest->replace(
                point: $replacement['point'],
                targetId: $replacement['target'],
                replacementId: $replacement['replacement'],
            );
        }
        $manifests[] = $manifest;
        $versions[$module['name']] = new InstalledPackageVersion(
            name: $module['name'],
            version: $module['version'],
            reference: $module['reference'] ?? null,
        );
    }

    return [$manifests, $versions];
}
