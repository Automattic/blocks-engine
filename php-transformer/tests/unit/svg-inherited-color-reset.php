<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

foreach (array('inherit', 'unset') as $keyword) {
    $out = (new HtmlTransformer())->transform('<style>.ink{color:#315a7f}button{color:' . $keyword . '}</style><nav class="ink"><button><span><svg width="22" height="22" viewBox="0 0 22 22" fill="none"><path d="M3 3L19 19" stroke="currentColor"/></svg></span><span>Section</span></button></nav>')->toArray();
    $svg = implode('', array_map(static fn(array $asset): string => 'inline-svg' === ($asset['source'] ?? '') ? (string)($asset['content'] ?? '') : '', $out['assets'] ?? array()));
    if (!str_contains($svg, 'stroke="#315a7f"') || str_contains($svg, 'stroke="' . $keyword . '"')) {
        fwrite(STDERR, "FAIL: isolated icon paint must resolve $keyword through ancestor color\n$svg\n"); exit(1);
    }
}
fwrite(STDOUT, "SVG inherited color reset tests: passed\n");
