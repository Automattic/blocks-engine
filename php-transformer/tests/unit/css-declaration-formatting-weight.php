<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$transformer = new CssStylesheetTransformer();
$source = '@media (min-width: 40em){.card { color : red; display: -webkit-box; display: flex; content: "a; b: c"; width: calc(100% - 2px); }}';
$actual = $transformer->compactStylesheet($source);
$expected = '@media (min-width: 40em){.card {color:red;display:-webkit-box;display:flex;content:"a; b: c";width:calc(100% - 2px)}}';
if ($actual !== $expected) throw new RuntimeException('Declaration formatting was not compacted safely: ' . $actual);
foreach (array('.a{--gap: ; margin: var(--gap) 1px;}', '.a{color:/**/red;}', '.a{content:"\\41 ";}', '.a{color:red;& .b{color:blue}}') as $protected) {
    if ($transformer->compactStylesheet($protected) !== $protected) throw new RuntimeException('Protected declaration changed');
}
if ($transformer->compactStylesheet($actual) !== $actual) throw new RuntimeException('Compaction must be idempotent');
echo "Declaration formatting weight passed\n";
