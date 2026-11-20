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
foreach (array('style="height:10px;width:450px"' => '10px', 'style="height:1000px;width:450px"' => '1000px', 'height="1000" style="width:450px"' => '1000px', 'height="1000" style="height:10px !important;width:450px"' => '10px') as $attributes => $height) {
    $html = '<div><table align="left" border="0" ' . $attributes . '><tbody><tr><td>Young Paik</td><td>1991</td></tr><tr><td>Joe Tien</td><td>1992</td></tr></tbody></table></div><p>after</p>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $markup = $result['serialized_blocks'];
    $css = implode("\n", array_column(array_filter($result['assets'] ?? array(), static fn (array $asset): bool => str_ends_with($asset['path'], '.css')), 'content'));
    $assert(str_contains($markup, '<!-- wp:table'), 'fixture remains an editable native table');
    $assert(str_contains($markup, 'Young Paik') && str_contains($markup, 'Joe Tien') && str_contains($markup, '>after<'), 'all rows and following content survive');
    $assert(1 === preg_match('/<figure[^>]*class="([^"]+)"[^>]*>/', $markup, $figure), 'table has a figure wrapper');
    foreach (explode(' ', $figure[1]) as $class) {
        $assert(1 !== preg_match('/\\.' . preg_quote($class, '/') . '\\s*\\{[^}]*?(?<![-\\w])height\\s*:/', $css), 'no figure carrier fixes the table height');
    }
    $assert(str_contains($css, '>table{height:' . $height . ' !important}') || str_contains($css, '>table){height:' . $height . '}'), 'height stays on the inner table as a content-expanding minimum');
    $assert(str_contains($css, 'width:450px !important'), 'inline width keeps its existing figure geometry');
    $assert('pass' === (new Runtime())->validateBlockSerialization($markup)['status'], 'table serialization remains editor-valid');
}
fwrite(STDOUT, "Table height geometry tests passed.\n");
