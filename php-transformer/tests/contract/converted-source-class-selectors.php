<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// Projection keeps each authored rule's source arm for elements conversion
// leaves alone, guarded against the role carriers it converted. `.cta` became
// a control everywhere, so its guarded arm matches nothing once markup is
// final. `.price` still names one unconverted box, so its guarded arm stays.
$css = '.cta{display:inline-block;padding:12px 20px;background:#e6312e;color:#fff;border-radius:4px}.cta:hover{background:#111}'
    . '.tag{font-weight:700;color:#e6312e}.price{letter-spacing:.1em}';
$html = '<!doctype html><html><head><title>Home</title><link rel="stylesheet" href="site.css"></head><body><main>'
    . '<h1>Hello</h1><p>Intro <span class="tag">New</span> and <span class="price">$10</span></p>'
    . '<div class="price"><h2>Bundle</h2><p>Two for one.</p></div>'
    . '<a class="cta" href="/buy">Buy now</a></main></body></html>';

$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('site.css' => $css, 'index.html' => $html),
))->toWordPressSitePlanView()['wordpress_site_plan'];

$markup = implode("\n", array_map(static fn (array $unit): string => (string) ($unit['canonical_block_markup'] ?? ''), array_merge($plan['pages'] ?? array(), $plan['template_parts'] ?? array(), $plan['templates'] ?? array())));
$assert(str_contains($markup, 'blocks-engine-role-control'), 'The link converts to a control.');
$assert(1 === preg_match('/class="[^"]*\btag\b[^"]*blocks-engine-role-richtext/', $markup), 'The tag span is a rich-text carrier.');

$sheets = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? null) ? (string) ($asset['content'] ?? '') : '', $plan['assets'] ?? array()));
$assert(1 === preg_match('/\.blocks-engine-control-[0-9a-f]+-0(?![\w-])[^{]*\{[^}]*border-radius:4px/', $sheets), 'The control keeps its projected rule.');
$assert(!str_contains($sheets, '.cta:not(:where(.blocks-engine-role-control))'), 'No guarded source arm ships for a class every carrier converted.');
$assert(!str_contains($sheets, '.tag:not(:where(.blocks-engine-role-richtext))'), 'No guarded source arm ships for a class only rich-text carriers name.');
$assert(1 === preg_match('/:where\(\.blocks-engine-role-richtext[^{]*\{[^}]*font-weight:700/', $sheets), 'The rich-text carrier keeps its projected rule.');
$assert(1 === preg_match('/\.price:not\(:where\(\.blocks-engine-role-richtext\)\)[^{]*\{[^}]*letter-spacing:\.1em/', $sheets), 'The unconverted price box keeps its guarded source arm.');

echo "Converted source class selectors passed\n";
