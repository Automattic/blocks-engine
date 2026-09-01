<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session;

final class RuntimeBehaviorState
{
    /** @var list<array<string, mixed>> */
    private array $scriptMetadata = array();

    /** @var array<int, array<string, mixed>> */
    private array $runtimeScriptMetadata = array();

    /** @var array<int, array<string, mixed>> */
    private array $runtimeProjectionScriptAssets = array();

    /** @var array<string, true> */
    private array $nativeDisclosureRootPaths = array();

    private bool $emptyRuntimeTargetGenerated = false;

    private bool $emptyVisualGroupGenerated = false;

    /** @param array<int, array<string, mixed>> $metadata */
    public function installRuntimeScriptMetadata(array $metadata): void
    {
        $this->runtimeScriptMetadata = $metadata;
    }

    /** @return array<int, array<string, mixed>> */
    public function runtimeScriptMetadata(): array
    {
        return $this->runtimeScriptMetadata;
    }

    public function hasRuntimeScriptMetadata(): bool
    {
        return array() !== $this->runtimeScriptMetadata;
    }

    /** @param array<int, array<string, mixed>> $assets */
    public function installRuntimeProjectionScriptAssets(array $assets): void
    {
        $this->runtimeProjectionScriptAssets = $assets;
    }

    /** @return array<int, array<string, mixed>> */
    public function runtimeProjectionScriptAssets(): array
    {
        return $this->runtimeProjectionScriptAssets;
    }

    /** @param array<string, mixed> $metadata */
    public function recordScriptMetadata(array $metadata): void
    {
        $this->scriptMetadata[] = $metadata;
    }

    /** @return list<array<string, mixed>> */
    public function scriptMetadata(): array
    {
        return $this->scriptMetadata;
    }

    public function rememberNativeDisclosureRoot(string $path): void
    {
        $this->nativeDisclosureRootPaths[$path] = true;
    }

    public function hasNativeDisclosureRoots(): bool
    {
        return array() !== $this->nativeDisclosureRootPaths;
    }

    public function isNativeDisclosureRoot(string $path): bool
    {
        return isset($this->nativeDisclosureRootPaths[$path]);
    }

    public function markEmptyRuntimeTargetGenerated(): void
    {
        $this->emptyRuntimeTargetGenerated = true;
    }

    public function emptyRuntimeTargetGenerated(): bool
    {
        return $this->emptyRuntimeTargetGenerated;
    }

    public function markEmptyVisualGroupGenerated(): void
    {
        $this->emptyVisualGroupGenerated = true;
    }

    public function emptyVisualGroupGenerated(): bool
    {
        return $this->emptyVisualGroupGenerated;
    }
}
