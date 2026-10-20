<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/** Authored→projected selector pairs recorded while rewriting one stylesheet. */
final class ProjectedSelectorBindings
{
    /** @var list<array{authored: string, projected: string}> */
    private array $rows = array();

    /** @var array<string, true> */
    private array $nativeSelectors = array();

    public function recordNativeSelector(string $selector): void
    {
        $selector = preg_replace('#/\*.*?\*/#s', '', $selector) ?? $selector;
        $this->nativeSelectors[trim($selector)] = true;
    }

    /** @return list<string> */
    public function nativeSelectors(): array
    {
        return array_keys($this->nativeSelectors);
    }

    public function record(string $authored, string $projected): void
    {
        $authored = trim($authored);
        $projected = trim($projected);
        if ( '' === $authored || '' === $projected || $authored === $projected ) {
            return;
        }

        $this->rows[] = array(
            'authored'  => $authored,
            'projected' => $projected,
        );
    }

    /** @return list<array{authored: string, projected: string}> */
    public function all(): array
    {
        return $this->rows;
    }
}
