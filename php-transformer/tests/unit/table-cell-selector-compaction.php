<?php
declare(strict_types=1);

// Author rules that match table cells are projected onto core/table's markup
// by structural position. When a rule matches every cell of a column, row or
// section, one folded path replaces one path per cell, so a utility-styled
// comparison table no longer emits a selector for every cell it styles. Folded
// paths keep the per-cell specificity; a rule matching only some cells keeps
// exact per-cell paths.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$compile = static function (int $rows): string {
    $body = '';
    for ($row = 1; $row <= $rows; $row++) {
        $body .= '<tr' . (2 === $row ? ' class="hl"' : '') . '><td class="pad label' . (3 === $row ? ' only' : '') . '">Row ' . $row . '</td><td class="pad">A</td><td class="pad">B</td></tr>';
    }
    $css = '.pad{padding:16px}.label{font-weight:600}.only{color:red}.hl td{background:#eef}th{letter-spacing:.2em}';
    $html = '<!doctype html><html><head><style>' . $css . '</style></head><body><main><table><thead><tr><th class="pad">Feature</th><th class="pad">DIY</th><th class="pad">Us</th></tr></thead><tbody>' . $body . '</tbody></table></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $out = '';
    foreach ($result['assets'] ?? array() as $asset) {
        if ('css' === ($asset['kind'] ?? '')) {
            $out .= (string) ($asset['content'] ?? '') . "\n";
        }
    }
    return $out;
};
$fail = static function (string $message, string $css): void {
    fwrite(STDERR, "FAIL: {$message}\n" . substr($css, 0, 4000) . "\n");
    exit(1);
};
$table = '\.blocks-engine-table-[a-z0-9-]+>table>';
$specificity = '(?::not\([^)]*\))*';

$css = $compile(6);
if (1 !== preg_match('/' . $table . ':is\(thead,tbody\)>tr:nth-child\(n\)>:is\(td,th\):nth-child\(n\)' . $specificity . '\{padding:16px/', $css)) {
    $fail('a rule matching every cell folds to one section path', $css);
}
if (1 !== preg_match('/' . $table . 'tbody>tr:nth-child\(n\)>:is\(td,th\):nth-child\(1\)' . $specificity . '\{font-weight:600/', $css)) {
    $fail('a rule matching every cell of a column folds to one column path', $css);
}
if (1 !== preg_match('/' . $table . 'tbody>tr:nth-child\(2\)>:is\(td,th\):nth-child\(n\)' . $specificity . '\{background:#eef/', $css)) {
    $fail('a rule matching every cell of a row folds to one row path', $css);
}
if (1 !== preg_match('/' . $table . 'thead>tr:nth-child\(n\)>:is\(td,th\):nth-child\(n\)' . $specificity . '\{letter-spacing/', $css)) {
    $fail('a rule matching every cell of a section folds to one section path', $css);
}
if (1 !== preg_match('/' . $table . 'tbody>tr:nth-child\(3\)>td:nth-child\(1\)' . $specificity . '\{color:red/', $css)) {
    $fail('a rule matching a single cell keeps its exact per-cell path', $css);
}
if (0 !== preg_match('/tr:nth-child\([0-9]+\)>td:nth-child\([0-9]+\)[^{]*\{(?:padding:16px|font-weight:600|background:#eef)/', $css)) {
    $fail('folded rules do not also emit per-cell paths', $css);
}

// The projected stylesheet no longer grows with every row a utility class touches.
$small = strlen($compile(3));
$large = strlen($compile(30));
if ($large > $small * 1.2) {
    $fail("projected table CSS stays flat as rows are added ({$small} bytes for 3 rows, {$large} for 30)", '');
}

fwrite(STDOUT, "table cell selector compaction tests: passed\n");
