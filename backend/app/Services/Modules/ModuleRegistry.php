<?php

namespace App\Services\Modules;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ModuleRegistry
{
    /** @var array<string, ModuleDefinition> */
    private array $modules = [];

    public function __construct(private readonly array $manifests)
    {
        foreach ($manifests as $configKey => $manifest) {
            if (! is_array($manifest)) {
                throw new ModuleManifestException("Module [{$configKey}] manifest must be an array.");
            }
            $normalized = $this->normalize((string) $configKey, $manifest);
            $definition = new ModuleDefinition((string) $configKey, $normalized);
            if (isset($this->modules[$definition->code()])) {
                throw new ModuleManifestException("Duplicate module code [{$definition->code()}].");
            }
            $this->modules[$definition->code()] = $definition;
        }

        $this->validateDependencies();
    }

    /** @return array<string, ModuleDefinition> */
    public function all(): array
    {
        $modules = $this->modules;
        uasort($modules, fn (ModuleDefinition $left, ModuleDefinition $right) => $left->order() <=> $right->order());

        return $modules;
    }

    public function get(string $code): ModuleDefinition
    {
        return $this->modules[$code] ?? throw new ModuleManifestException("Unknown module [{$code}].");
    }

    public function has(string $code): bool
    {
        return isset($this->modules[$code]);
    }

    public function isEnabled(string $code, array $trail = []): bool
    {
        if (! isset($this->modules[$code]) || in_array($code, $trail, true)) {
            return false;
        }
        $module = $this->modules[$code];
        if (! $module->canDisable()) {
            return true;
        }
        $configured = (bool) config("modules.{$module->configKey}.enabled", $module->enabledByDefault());
        if (! $configured) {
            return false;
        }

        $trail[] = $code;

        return collect($module->dependencies())->every(fn (string $dependency) => $this->isEnabled($dependency, $trail));
    }

    /** @return array<string, ModuleDefinition> */
    public function enabled(): array
    {
        return array_filter($this->all(), fn (ModuleDefinition $module) => $this->isEnabled($module->code()));
    }

    public function moduleForPath(string $path): ?ModuleDefinition
    {
        $candidates = [];
        foreach ($this->all() as $module) {
            foreach ($module->routePatterns() as $pattern) {
                if (Str::is($pattern, $path)) {
                    $candidates[] = [$module, strlen($pattern)];
                }
            }
        }
        usort($candidates, fn (array $left, array $right) => $right[1] <=> $left[1]);

        return $candidates[0][0] ?? null;
    }

    public function stateFingerprint(): string
    {
        return sha1(json_encode(array_map(fn (ModuleDefinition $module) => $this->isEnabled($module->code()), $this->all()), JSON_THROW_ON_ERROR));
    }

    public function describe(): array
    {
        return array_map(function (ModuleDefinition $module): array {
            $data = Arr::except($module->toArray(), ['providers', 'route_patterns', 'legacy_permissions']);
            $data['enabled'] = $this->isEnabled($module->code());
            $data['configured_enabled'] = (bool) config("modules.{$module->configKey}.enabled", $module->enabledByDefault());
            /*
             * CLEANUP-02 — หน้าจอ "โมดูลระบบ" ต้องรู้ทั้งคีย์ที่ใช้ตั้งค่าและว่าปิดได้หรือไม่
             * โมดูลแกน (`system`) ปิดไม่ได้ จึงต้องไม่แสดงสวิตช์ให้กด
             */
            $data['config_key'] = $module->configKey;
            $data['can_disable'] = $module->canDisable();
            $data['disabled_dependencies'] = array_values(array_filter(
                $module->dependencies(),
                fn (string $dependency) => ! $this->isEnabled($dependency),
            ));

            return $data;
        }, $this->all());
    }

    private function normalize(string $configKey, array $manifest): array
    {
        $required = ['code', 'name_th', 'version', 'enabled', 'dependencies', 'order'];
        foreach ($required as $field) {
            if (! array_key_exists($field, $manifest)) {
                throw new ModuleManifestException("Module [{$configKey}] is missing [{$field}].");
            }
        }
        $manifest = array_merge([
            'routes' => [], 'route_files' => [], 'menus' => [], 'permissions' => [], 'policies' => [],
            'workflow_definitions' => [], 'settings' => [], 'notifications' => [], 'reports' => [],
            'audit_events' => [], 'providers' => [], 'route_patterns' => [],
            'can_disable' => true, 'legacy_permissions' => [],
        ], $manifest);
        if (! preg_match('/^[a-z][a-z0-9_]*$/', (string) $manifest['code'])) {
            throw new ModuleManifestException("Module [{$configKey}] has an invalid code.");
        }
        if (! preg_match('/^\d+\.\d+\.\d+$/', (string) $manifest['version'])) {
            throw new ModuleManifestException("Module [{$manifest['code']}] must use semantic versioning.");
        }
        foreach (['dependencies', 'routes', 'route_files', 'menus', 'permissions', 'policies', 'workflow_definitions', 'settings', 'notifications', 'reports', 'audit_events', 'providers', 'route_patterns', 'legacy_permissions'] as $field) {
            if (! is_array($manifest[$field])) {
                throw new ModuleManifestException("Module [{$manifest['code']}] field [{$field}] must be an array.");
            }
        }
        foreach (array_keys($manifest['permissions']) as $permission) {
            $canonical = preg_match('/^'.preg_quote($manifest['code'], '/').'\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', (string) $permission);
            if (! $canonical && ! in_array($permission, $manifest['legacy_permissions'], true)) {
                throw new ModuleManifestException("Permission [{$permission}] must follow module.resource.action.");
            }
        }

        return $manifest;
    }

    private function validateDependencies(): void
    {
        foreach ($this->modules as $module) {
            foreach ($module->dependencies() as $dependency) {
                if (! isset($this->modules[$dependency])) {
                    throw new ModuleManifestException("Module [{$module->code()}] requires unknown module [{$dependency}].");
                }
            }
            $this->assertAcyclic($module->code(), []);
        }
    }

    private function assertAcyclic(string $code, array $trail): void
    {
        if (in_array($code, $trail, true)) {
            throw new ModuleManifestException('Circular module dependency: '.implode(' -> ', [...$trail, $code]));
        }
        $trail[] = $code;
        foreach ($this->modules[$code]->dependencies() as $dependency) {
            $this->assertAcyclic($dependency, $trail);
        }
    }
}
