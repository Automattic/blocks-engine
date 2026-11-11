<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$compact = static fn(string $css): string => (new CssStylesheetTransformer())->compactStylesheet($css);

// Each case is cascade-equivalent input => smallest output.
foreach (array(
    'an earlier copy goes; the later copy keeps its position' => array('.a{color:red}.b{color:blue}.a { color: red; }', '.b{color:blue}.a { color: red; }'),
    'copies in the same conditional group are one rule' => array('@media (max-width:9px){.a{x:1}}.b{y:2}@media (max-width: 9px){.a{x:1}}', '.b{y:2}@media (max-width: 9px){.a{x:1}}'),
    'a copy in another group is another rule' => array('@media print{.a{x:1}}.a{x:1}', '@media print{.a{x:1}}.a{x:1}'),
    'quoted text is significant' => array('.a{content:"a  b"}.b{content:"a b"}', '.a{content:"a  b"}.b{content:"a b"}'),
    'distinct rules with one body stay distinct' => array('.a{x:1}.b{x:1}.a{x:2}', '.a{x:1}.b{x:1}.a{x:2}'),
    'a layer statement keeps its order' => array('@layer a,b;.x{y:1}@layer a,b;', '@layer a,b;.x{y:1}@layer a,b;'),
    'malformed input is unchanged' => array('.a{x:1}.a{x:1', '.a{x:1}.a{x:1'),
) as $label => [$input, $expected]) {
    $actual = $compact($input);
    $assert($expected === $actual, $label . ': ' . $actual);
}

// Emitted stylesheets carry no rule a later identical rule overrides; this
// realistic multi-page site emits such repeats before compaction.
$files = require __DIR__ . '/support-owner-set-realistic-fixture.php';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
foreach ($plan['writes'] as $write) {
    if (!str_ends_with($write['target_path'], '.css')) continue;
    $css = (string) $write['payload']['data'];
    $assert($compact($css) === $css, 'Emitted stylesheets are already compact: ' . $write['target_path']);
}
echo "Stylesheet compaction passed\n";
