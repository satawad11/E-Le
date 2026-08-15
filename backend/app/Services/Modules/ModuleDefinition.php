<?php

namespace App\Services\Modules;

use App\Contracts\Modules\ModuleContract;

final readonly class ModuleDefinition implements ModuleContract
{
    public function __construct(public string $configKey, private array $manifest) {}

    public function code(): string
    {
        return $this->manifest['code'];
    }

    public function thaiName(): string
    {
        return $this->manifest['name_th'];
    }

    public function version(): string
    {
        return $this->manifest['version'];
    }

    public function enabledByDefault(): bool
    {
        return (bool) $this->manifest['enabled'];
    }

    public function canDisable(): bool
    {
        return (bool) $this->manifest['can_disable'];
    }

    public function dependencies(): array
    {
        return $this->manifest['dependencies'];
    }

    public function routes(): array
    {
        return $this->manifest['routes'];
    }

    public function routeFiles(): array
    {
        return $this->manifest['route_files'];
    }

    public function menus(): array
    {
        return $this->manifest['menus'];
    }

    public function permissions(): array
    {
        return $this->manifest['permissions'];
    }

    public function policies(): array
    {
        return $this->manifest['policies'];
    }

    public function workflowDefinitions(): array
    {
        return $this->manifest['workflow_definitions'];
    }

    public function settings(): array
    {
        return $this->manifest['settings'];
    }

    public function notifications(): array
    {
        return $this->manifest['notifications'];
    }

    public function reports(): array
    {
        return $this->manifest['reports'];
    }

    public function auditEvents(): array
    {
        return $this->manifest['audit_events'];
    }

    public function providers(): array
    {
        return $this->manifest['providers'];
    }

    public function routePatterns(): array
    {
        return $this->manifest['route_patterns'];
    }

    public function order(): int
    {
        return $this->manifest['order'];
    }

    public function toArray(): array
    {
        return $this->manifest;
    }
}
