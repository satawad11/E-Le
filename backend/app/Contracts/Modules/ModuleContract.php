<?php

namespace App\Contracts\Modules;

interface ModuleContract
{
    public function code(): string;

    public function thaiName(): string;

    public function version(): string;

    public function enabledByDefault(): bool;

    public function canDisable(): bool;

    /** @return array<int, string> */
    public function dependencies(): array;

    /** @return array<int, string> */
    public function routes(): array;

    /** @return array<int, string> */
    public function routeFiles(): array;

    /** @return array<int|string, mixed> */
    public function menus(): array;

    /** @return array<string, string> */
    public function permissions(): array;

    /** @return array<int|string, mixed> */
    public function policies(): array;

    /** @return array<int|string, mixed> */
    public function workflowDefinitions(): array;

    /** @return array<int|string, mixed> */
    public function settings(): array;

    /** @return array<int|string, mixed> */
    public function notifications(): array;

    /** @return array<int|string, mixed> */
    public function reports(): array;

    /** @return array<int|string, mixed> */
    public function auditEvents(): array;

    public function toArray(): array;
}
