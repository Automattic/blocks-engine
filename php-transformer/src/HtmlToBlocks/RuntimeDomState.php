<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

/** Per-transform runtime island and retained DOM contract evidence. */
final class RuntimeDomState
{
    /** @var array<int, array<string, mixed>> */
    private array $islands = array();

    /** @var array<int, array{document: int, path: string}> */
    private array $islandOrigins = array();

    /** @var array<string, array<string, string>> */
    private array $preservations = array();

    /** @var array<string, array<string, string>> */
    private array $fallbacks = array();

    /** @param array<string, mixed> $island */
    public function recordIsland(array $island, int $document, string $path): void
    {
        $key = $this->islandKey($island);
        $nestedIslandIndexes = array();
        foreach ($this->islands as $index => $existing) {
            if ($key === $this->islandKey($existing)) {
                return;
            }

            $existingOrigin = $this->islandOrigins[$index] ?? null;
            if (!is_array($existingOrigin)
                || 0 === $document
                || $document !== ($existingOrigin['document'] ?? 0)
                || '' === $path
                || '' === ($existingOrigin['path'] ?? '')
            ) {
                continue;
            }

            $existingPath = (string) $existingOrigin['path'];
            if (str_starts_with($path, $existingPath . '/')) {
                return;
            }
            if (str_starts_with($existingPath, $path . '/')) {
                $nestedIslandIndexes[] = $index;
            }
        }

        foreach (array_reverse($nestedIslandIndexes) as $index) {
            array_splice($this->islands, $index, 1);
            array_splice($this->islandOrigins, $index, 1);
        }
        $this->islands[] = $island;
        $this->islandOrigins[] = array('document' => $document, 'path' => $path);
    }

    /** @return array<int, array<string, mixed>> */
    public function islands(): array
    {
        return $this->islands;
    }

    public function hasIslandSelector(string $selector): bool
    {
        foreach ($this->islands as $island) {
            if (($island['selector'] ?? null) === $selector) {
                return true;
            }
        }
        return false;
    }

    public function recordPreservation(string $blockName, string $tag, string $selector): void
    {
        $key = $blockName . "\n" . $selector;
        if (isset($this->preservations[$key])) {
            return;
        }
        $this->preservations[$key] = array(
            'block_name' => $blockName,
            'tag' => $tag,
            'selector' => $selector,
        );
    }

    /** @return array<int, array<string, string>> */
    public function preservations(): array
    {
        return array_values($this->preservations);
    }

    public function recordFallback(string $blockName, string $tag, string $selector): void
    {
        $key = $blockName . "\n" . $selector;
        if (isset($this->fallbacks[$key])) {
            return;
        }
        $this->fallbacks[$key] = array(
            'block_name' => $blockName,
            'tag' => $tag,
            'selector' => $selector,
        );
    }

    /** @return array<int, array<string, string>> */
    public function fallbacks(): array
    {
        return array_values($this->fallbacks);
    }

    /** @param array<string, mixed> $island */
    private function islandKey(array $island): string|false
    {
        return json_encode(array(
            'kind' => $island['kind'] ?? '',
            'selector' => $island['selector'] ?? '',
            'snippet' => $island['source_snippet'] ?? '',
        ), JSON_UNESCAPED_SLASHES);
    }
}
