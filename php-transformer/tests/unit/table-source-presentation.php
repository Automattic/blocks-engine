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
$compile = static function (string $attributes, string $style = ''): array {
    $html = '<style>' . $style . '</style><div class="field-item"><table ' . $attributes . '><tbody>'
        . '<tr><th>Name</th><th>Year</th></tr><tr><td>Andrei Shchegrov</td><td>1995</td></tr>'
        . '<tr><td>Yinglei Yu</td><td>1996</td></tr></tbody></table></div><p>after</p>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $css = implode("\n", array_column(array_filter($result['assets'] ?? array(), static fn (array $asset): bool => str_ends_with($asset['path'], '.css')), 'content'));
    return array($result['serialized_blocks'], $css);
};

foreach (array('left', 'right') as $align) {
    list($markup, $css) = $compile('align="' . $align . '" border="0" cellpadding="0" cellspacing="0"', 'table{border-collapse:collapse;border-spacing:0;width:70%}');
    $assert(1 === preg_match('/\b(blocks-engine-table-[a-z0-9-]+)/', $markup, $match), 'table has a scoped marker');
    $scope = '.' . $match[1];
    $assert(str_contains($css, $scope . '>table{border-collapse:collapse;border-spacing:0;width:70%}'), 'author table width survives core defaults');
    $assert(str_contains($css, $scope . '>table th,' . $scope . '>table td{padding:0px}'), 'zero cellpadding survives for headers and data cells');
    $assert(str_contains($css, $scope . '{float:' . $align . '}'), 'the whole figure floats beside following content');
    $assert(str_contains($css, $scope . '{width:70%}') && str_contains($css, $scope . '>table{width:100%}'), 'percentage float width resolves against the original container, not twice');
    $assert('pass' === (new Runtime())->validateBlockSerialization($markup)['status'], 'presentation leaves editor-valid core/table markup');
}
foreach (array('8' => '8px', '2.5' => '2.5px', '-1' => null, 'bad' => null, '0; color:red' => null) as $input => $expected) {
    list($markup, $css) = $compile('cellpadding="' . $input . '"');
    $assert(null === $expected ? ! str_contains($css, '{padding:') : str_contains($css, '{padding:' . $expected . '}'), 'cellpadding validation: ' . $input);
}
list($markup, $css) = $compile('style="width:750px" align="left" cellpadding="0"');
$assert(str_contains($css, '{width:750px}'), 'inline width sizes floated figure');
list($markup, $css) = $compile('cellpadding="0"', 'td{padding:12px}');
$assert(str_contains($css, 'padding:12px'), 'author cell padding remains projected');
fwrite(STDOUT, "Table source presentation tests passed.\n");
