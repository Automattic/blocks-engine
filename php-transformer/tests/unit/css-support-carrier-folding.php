<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// Support compaction shares independent class carriers, but structural roots
// and native descendants must retain their standalone rules for consumers.
$css = ':where(#header){position:relative}.carrier-a{position:relative}'
    . '.carrier-b{position:relative}.navigation .item{position:relative}';
$actual = (new CssStylesheetTransformer())->mergeRepeatedDeclarationRules($css, false, true);
$expected = ':where(#header){position:relative}.carrier-a,.carrier-b{position:relative}'
    . '.navigation .item{position:relative}';
if ($expected !== $actual) throw new RuntimeException('Support carrier folding changed structural rules: ' . $actual);
echo "Support carrier folding preserves standalone structural rules\n";
