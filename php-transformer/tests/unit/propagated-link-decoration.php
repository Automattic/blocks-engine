<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;

$transform = static fn (string $decoration): array => (new HtmlTransformer())->transform('<a href="/contact" style="display:block;text-decoration:' . $decoration . '"><h5>Location</h5><p>Town</p></a>', array())->toArray();
$plain = $transform('none');
$css = implode("\n", array_column($plain['assets'], 'content'));
$marker = SourceBlockAttributeProjector::SYNTHETIC_ANCHOR_UNDECORATED_CLASS;
if (!str_contains($plain['serialized_blocks'], $marker)
    || !str_contains($css, $marker . ')>a{text-decoration:none}')
    || str_contains($transform('underline')['serialized_blocks'], $marker)) {
    fwrite(STDERR, "Propagated links must replay only the source's explicit undecorated ownership\n");
    exit(1);
}
echo "Propagated link decoration passed\n";
