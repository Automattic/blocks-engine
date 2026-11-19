<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (! $condition) {
        $failures[] = $label;
    }
};
$convert = static function (string $html): array {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $css = '';
    foreach ($result['assets'] ?? array() as $asset) {
        if ('css' === ($asset['kind'] ?? '')) {
            $css .= $asset['content'] ?? '';
        }
    }
    return array($result['blocks'], $css);
};
$hasHint = static function (array $block, string $css, string $declaration): bool {
    foreach (explode(' ', $block['attrs']['className'] ?? '') as $class) {
        if (preg_match('/@layer blocks-engine-presentational-hints\{:where\(\.' . preg_quote($class, '/') . '\)\{[^}]*' . preg_quote($declaration, '/') . '(?:;|\})/', $css)) {
            return true;
        }
    }
    return false;
};

// Issue #6864: yellow text must retain the black table beneath it.
[$blocks, $css] = $convert('<table border="0" cellspacing="0" cellpadding="10" width="750" bgcolor="#000000"><tbody><tr valign="TOP"><td width="200" bgcolor="#FFFFCC"><font color="#000000">Welcome</font></td><td><h1><font color="#FFFFCC">Physical Biology</font></h1><p><font color="#FFFFCC">People</font></p></td></tr></tbody></table>');
$table = $blocks[0];
$assert('core/group' === $table['blockName'], 'repro follows layout-table path');
$cells = $table['innerBlocks'][0]['innerBlocks'];
$assert($hasHint($table, $css, 'background-color:#000000'), 'black background belongs to table wrapper');
$assert($hasHint($cells[0], $css, 'background-color:#ffffcc'), 'yellow background belongs to first cell');
$assert(! $hasHint($cells[1], $css, 'background-color:#000000'), 'transparent cell exposes table rather than copying its color');

foreach (array('', ' style="table-layout:fixed"') as $style) {
    // A nested table makes the fixed-track version a layout table too.
    [$blocks, $css] = $convert('<table width="750" border="2" bgcolor="#000000"' . $style . '><tr bgcolor="#112233"><td bgcolor="#ffffcc" style="background:transparent">Welcome<table><tr><td>Nested</td></tr></table></td><td>Rail</td></tr><tr bgcolor="#445566"><td>Footer</td><td bgcolor="not-a-color">More</td></tr></table>');
    $table = $blocks[0];
    $assert('core/group' === $table['blockName'], 'multi-row layout keeps table group');
    $assert($hasHint($table, $css, 'background-color:#000000'), 'table paint survives both track algorithms');
    $assert($hasHint($table, $css, 'border:2px outset'), 'outer legacy border survives');
    $row = $table['innerBlocks'][0];
    $assert($hasHint($row, $css, 'background-color:#112233'), 'row background stays on row');
    $assert($hasHint($row['innerBlocks'][0], $css, 'background-color:#ffffcc'), 'cell hint remains below authored background');
    $assert(! str_contains($css, 'background-color:not-a-color'), 'invalid color is not emitted');
}

// Even a single source row needs a row box when it paints a background.
[$blocks, $css] = $convert('<table width="750" bgcolor="#000000"><tr bgcolor="#112233"><td><h1>Welcome</h1><p>Intro</p></td><td><p>Rail</p></td></tr></table>');
$assert($hasHint($blocks[0]['innerBlocks'][0], $css, 'background-color:#112233'), 'single painted row is not flattened away');

if (array() !== $failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Layout-table paint hints passed.\n";
