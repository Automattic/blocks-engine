<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Closure;

/** Per-transform source identities projected from author CSS selectors. */
final class AuthorSelectorProjectionState
{
    private ?AuthorStyleAnalysis $authorStyles = null;

    /** @var array<string, string> */
    private array $tagMarkers = array();

    /** @var array<string, string> */
    private array $controlMarkers = array();

    /** @var array<string, true> */
    private array $buttonPresentationPaths = array();

    /** @var array<string, true> */
    private array $controlPaths = array();

    /** @var array<string, string> */
    private array $semanticMarkers = array();

    /** @var array<string, string> */
    private array $attributeMarkers = array();

    /** @var array<string, string> */
    private array $attributeNegationMarkers = array();

    /** @var array<string, list<string>> */
    private array $attributeStateMarkers = array();

    /** @var array<string, string> */
    private array $rootChildMarkers = array();

    /** @var array<string, string> */
    private array $tableMarkers = array();

    /** @var array<int, bool> */
    private array $tableRepresentability = array();

    /** @var array<int, array<int, string>> */
    private array $tableDescendantPaths = array();

    /** @var array<string, string> */
    private array $richTextMarkers = array();

    public function installAuthorStyles(AuthorStyleAnalysis $authorStyles): void
    {
        $this->authorStyles = $authorStyles;
    }

    public function ensureTagMarker(string $tagName): string
    {
        $tagName = strtolower($tagName);
        return $this->tagMarkers[$tagName] ??= $this->allocateMarker('source-' . $tagName);
    }

    public function tagMarker(string $tagName): string
    {
        return $this->tagMarkers[strtolower($tagName)] ?? '';
    }

    /** @return array<string, string> */
    public function tagMarkers(): array
    {
        return $this->tagMarkers;
    }

    public function markControlPath(string $path): void
    {
        $this->controlPaths[$path] = true;
    }

    public function isControlPath(string $path): bool
    {
        return isset($this->controlPaths[$path]);
    }

    public function ensureControlMarker(string $path): string
    {
        return $this->controlMarkers[$path] ??= $this->allocateMarker('control');
    }

    public function controlMarker(string $path): string
    {
        return $this->controlMarkers[$path] ?? '';
    }

    public function installButtonPresentationMarker(string $path, string $marker): void
    {
        $this->controlMarkers[$path] = $marker;
        $this->buttonPresentationPaths[$path] = true;
    }

    public function isButtonPresentationPath(string $path): bool
    {
        return isset($this->buttonPresentationPaths[$path]);
    }

    public function ensureSemanticMarker(string $path): string
    {
        return $this->semanticMarkers[$path] ??= $this->allocateMarker('semantic');
    }

    public function semanticMarker(string $path): string
    {
        return $this->semanticMarkers[$path] ?? '';
    }

    public function ensureRichTextMarker(string $path): string
    {
        return $this->richTextMarkers[$path] ??= $this->allocateMarker('richtext');
    }

    public function richTextMarker(string $path): string
    {
        return $this->richTextMarkers[$path] ?? '';
    }

    public function hasRichTextMarkers(): bool
    {
        return array() !== $this->richTextMarkers;
    }

    public function ensureAttributeMarker(string $path): string
    {
        return $this->attributeMarkers[$path] ??= $this->allocateMarker('attribute');
    }

    public function attributeMarker(string $path): string
    {
        return $this->attributeMarkers[$path] ?? '';
    }

    public function installAttributeNegationMarker(string $selector, string $marker): void
    {
        $this->attributeNegationMarkers[$selector] = $marker;
    }

    public function attributeNegationMarker(string $selector): string
    {
        return $this->attributeNegationMarkers[$selector] ?? '';
    }

    /** @return array<string,string> */
    public function attributeNegationMarkers(): array
    {
        return $this->attributeNegationMarkers;
    }

    public function addAttributeStateMarker(string $path, string $marker): void
    {
        $this->attributeStateMarkers[$path][] = $marker;
    }

    /** @return list<string> */
    public function attributeStateMarkers(string $path): array
    {
        return $this->attributeStateMarkers[$path] ?? array();
    }

    public function ensureRootChildMarker(string $path): string
    {
        return $this->rootChildMarkers[$path] ??= $this->allocateMarker('root-child');
    }

    public function rootChildMarker(string $path): string
    {
        return $this->rootChildMarkers[$path] ?? '';
    }

    /** @return list<string> */
    public function semanticMarkersForPath(string $path): array
    {
        return array_values(array_filter(array_merge(
            array($this->semanticMarker($path), $this->attributeMarker($path)),
            $this->attributeStateMarkers($path),
            array($this->rootChildMarker($path))
        ), static fn (string $marker): bool => '' !== $marker));
    }

    public function ensureTableMarker(string $path): string
    {
        return $this->tableMarkers[$path] ??= $this->allocateMarker('table');
    }

    public function tableMarker(string $path): string
    {
        return $this->tableMarkers[$path] ?? '';
    }

    /** @param Closure(): bool $resolve */
    public function tableRepresentable(int $tableId, Closure $resolve): bool
    {
        return $this->tableRepresentability[$tableId] ??= $resolve();
    }

    /** @param Closure(): array<int, string> $buildPaths */
    public function tableDescendantPath(int $tableId, int $elementId, Closure $buildPaths): string
    {
        $paths = $this->tableDescendantPaths[$tableId] ??= $buildPaths();
        return $paths[$elementId] ?? '';
    }

    private function allocateMarker(string $kind): string
    {
        return ($this->authorStyles
            ?? throw new \LogicException('Author styles have not been installed for selector projection.'))
            ->allocateMarker($kind);
    }
}
