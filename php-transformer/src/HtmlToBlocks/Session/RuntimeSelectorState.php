<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session;

/** Per-transform declared and superseded runtime selectors. */
final class RuntimeSelectorState
{
    /** @var array<string, true> */
    private array $superseded = array();

    /**
     * @param array<string, bool> $dom
     * @param array<string, bool> $behavioral
     * @param array<string, bool> $canvas
     */
    public function __construct(
        private readonly array $dom,
        private readonly array $behavioral,
        private readonly array $canvas
    ) {
    }

    /** @return array<string, bool> */
    public function domSelectors(): array
    {
        return $this->dom;
    }

    /** @return array<string, bool> */
    public function canvasSelectors(): array
    {
        return $this->canvas;
    }

    public function hasDom(string $selector): bool
    {
        return isset($this->dom[$selector]);
    }

    public function hasBehavioral(string $selector): bool
    {
        return isset($this->behavioral[$selector]);
    }

    public function hasCanvas(string $selector): bool
    {
        return isset($this->canvas[$selector]);
    }

    public function hasRuntimeTargets(): bool
    {
        return array() !== $this->dom || array() !== $this->canvas;
    }

    public function supersede(string $selector): void
    {
        $this->superseded[$selector] = true;
    }

    /** @return array<int, string> */
    public function supersededSelectors(): array
    {
        return array_keys($this->superseded);
    }
}
