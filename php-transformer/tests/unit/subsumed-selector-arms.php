<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorListCompactor;

// An arm that only adds zero-specificity restrictions (`:where()`,
// `:not(:where())`) to another arm of the same list matches a subset of it
// with the same weight: it is published weight that decides nothing.
$cases = array(
    '.btn,.btn:not(:where(.role))' => '.btn',
    '.btn:hover,.btn:not(:where(.role)):hover' => '.btn:hover',
    '*,*::before,:where(.marker),:where(.marker)::before,*:not(:where(.role))' => '*,*::before',
    '.card > img,.card:where(.inline) > img' => '.card > img',
    // Higher specificity, a different subject or a restriction after a
    // pseudo-element are not covered by the plain arm.
    '.btn,.btn:not(.role)' => '.btn,.btn:not(.role)',
    '.btn,.btn:where(.a).b' => '.btn,.btn:where(.a).b',
    '.a:before,.a:before:where(.x)' => '.a:before,.a:before:where(.x)',
    '.a *,.a :where(.b) .c' => '.a *,.a :where(.b) .c',
    '[data-x="*"],.q:where(.z),.q' => '[data-x="*"],.q',
);
foreach ($cases as $input => $expected) {
    $actual = CssSelectorListCompactor::withoutSubsumedArms($input);
    if ($expected !== $actual) throw new RuntimeException("Subsumed arms of {$input}: expected {$expected}, got {$actual}");
}

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p class="lead">Hello</p><p class="note">World</p></main></body></html>',
    'site.css' => ".lead,.lead:not(:where(.quiet)){letter-spacing:.05em}\n.note{margin:3px}\n",
)))->toArray();
$css = implode("\n", array_column(array_filter($result['source_reports']['wordpress_site_plan']['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null) && str_contains((string) $asset['content'], 'letter-spacing')), 'content'));
if ('' === $css) throw new RuntimeException('Author stylesheet missing');
if (str_contains($css, ':not(:where(.quiet))')) throw new RuntimeException("A subsumed selector arm must not ship:\n" . $css);
if (!preg_match('/\.lead\{letter-spacing:\.05em\}/', $css)) throw new RuntimeException("The covering arm keeps the declarations:\n" . $css);
echo "Selector arms another arm of the same rule covers do not ship\n";
