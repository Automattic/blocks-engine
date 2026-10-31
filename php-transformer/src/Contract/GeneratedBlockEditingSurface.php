<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

/** Shared block.json opt-in for companions edited as raw markup. */
final class GeneratedBlockEditingSurface
{
    public const RAW_HTML = array('editingSurface' => 'raw_html');

    /** @param array<int,array<string,mixed>> $definitions @return array<string,true> */
    public static function rawHtmlBlockNames(array $definitions): array
    {
        $names = array();
        foreach ($definitions as $definition) {
            $metadata = $definition['block_json'] ?? array();
            if ('raw_html' === ($metadata['blocksEngine']['editingSurface'] ?? null) && is_string($metadata['name'] ?? null)) {
                $names[$metadata['name']] = true;
            }
        }
        return $names;
    }
}
