<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// Projection spaces out the combinators of every arm it writes. Final theme
// stylesheets ship each selector at its smallest equivalent spelling, while
// declaration values and functional pseudo-class arguments stay verbatim.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"><style>.steps > li + li { margin-top: 4px } .steps:has(> li) ~ p { text-shadow: 1px 2px red }</style></head><body><main><ul class="steps"><li>One</li><li>Two</li></ul><p class="note">Done</p></main></body></html>',
    'site.css' => '.note , .steps > li { color: rgb(1, 2, 3) }',
)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$css = '';
foreach ($plan['assets'] as $asset) {
    if ('css' === ($asset['kind'] ?? null) && is_string($asset['content'] ?? null)) $css .= $asset['content'] . "\n";
}
if ('' === $css) throw new RuntimeException('Expected generated theme CSS');
$spaced = array();
(new CssStylesheetTransformer())->visitStyleRules($css, static function (string $selector) use (&$spaced): void {
    // Top-level only: arguments of :is()/:has()/:not() are left as authored.
    $depth = 0;
    $top = '';
    foreach (str_split($selector) as $character) {
        if ('(' === $character) ++$depth;
        $top .= 0 === $depth ? $character : '_';
        if (')' === $character) --$depth;
    }
    if (preg_match('/\s[,>+~]|[,>+~]\s/', trim($top))) $spaced[] = $selector;
});
if (array() !== $spaced) throw new RuntimeException("Theme CSS ships redundant selector whitespace:\n" . implode("\n", array_slice($spaced, 0, 5)));
if (!str_contains($css, 'rgb(1, 2, 3)') && !str_contains($css, 'rgb(1,2,3)')) throw new RuntimeException('Declaration values must survive selector compaction');
if (!str_contains($css, '1px 2px red')) throw new RuntimeException('Declaration whitespace must stay verbatim');
echo "Site plan selectors omit redundant combinator whitespace\n";
