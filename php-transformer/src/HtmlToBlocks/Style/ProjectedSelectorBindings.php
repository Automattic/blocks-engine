<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/** Authored→projected selector pairs recorded while rewriting one stylesheet. */
final class ProjectedSelectorBindings
{
    /** @var list<array{authored: string, projected: string}> */
    private array $rows = array();

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
