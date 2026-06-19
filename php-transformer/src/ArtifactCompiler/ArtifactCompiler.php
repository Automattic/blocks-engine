<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;

final class ArtifactCompiler
{
    /**
     * @param array<string, mixed> $artifact
     */
    public function compile(array $artifact): TransformerResult
    {
        return new TransformerResult(
            diagnostics: array(
                array(
                    'code'    => 'not_implemented',
                    'message' => 'Artifact compiler migration target is scaffolded but not implemented yet.',
                    'source'  => self::class,
                ),
            ),
            provenance: array(
                array(
                    'source_format' => 'artifact',
                    'input_keys'    => array_keys($artifact),
                ),
            )
        );
    }
}
