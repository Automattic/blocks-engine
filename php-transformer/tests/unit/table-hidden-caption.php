<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assert = static function (bool $ok, string $message): void {
    if (! $ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$compile = static function (string $class): string {
    $html = '<!DOCTYPE html><html><head><title>Table</title><style>.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border-width:0}</style></head><body><main><h1>Compare</h1><table><caption class="' . $class . '">Comparison of <em>homes</em></caption><thead><tr><th>Feature</th><th>A</th></tr></thead><tbody><tr><td>Beds</td><td>3</td></tr></tbody></table></main></body></html>';
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['serialized_blocks'];
};
foreach (array('sr-only', 'extra sr-only other', 'screen-reader-only', 'screen-reader-text', 'visually-hidden', '', 'not-sr-only') as $class) {
    $markup = $compile($class);
    $hidden = ! in_array($class, array('', 'not-sr-only'), true);
    $assert(str_contains($markup, '<!-- wp:table'), 'caption remains on a native table');
    // Ignore JSON block attributes when counting rendered text.
    $html = preg_replace('/<!--.*?-->/s', '', $markup);
    $assert(1 === substr_count($html, 'Comparison of'), 'caption appears once');
    $assert(str_contains($html, '<em>homes</em>'), 'caption preserves rich text');
    $assert($hidden === str_contains($html, '<span class="screen-reader-text"'), 'only known hiding utilities map to hidden text: ' . $class);
    if ($hidden) {
        $assert(str_contains($html, '<span class="screen-reader-text">Comparison of <em>homes</em></span>'), 'hidden caption uses the core class without inline styles');
    } else {
        $assert(str_contains($html, '<figcaption class="wp-element-caption">Comparison of <em>homes</em></figcaption>'), 'visible caption remains unchanged');
    }
    $assert('pass' === (new Runtime())->validateBlockSerialization($markup)['status'], 'caption is editor-valid: ' . $class);
}
fwrite(STDOUT, "Table hidden caption tests passed.\n");
