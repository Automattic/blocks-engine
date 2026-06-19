<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;

final class HtmlTransformer
{
    public function transform(string $html): TransformerResult
    {
        return new TransformerResult(
            diagnostics: array(
                array(
                    'code'    => 'not_implemented',
                    'message' => 'HTML-to-blocks migration target is scaffolded but not implemented yet.',
                    'source'  => self::class,
                ),
            ),
            provenance: array(
                array(
                    'source_format' => 'html',
                    'input_bytes'   => strlen($html),
                ),
            )
        );
    }
}
