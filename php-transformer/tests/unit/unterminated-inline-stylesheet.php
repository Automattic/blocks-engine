<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;

// CSS Syntax closes a block left open at the end of a stylesheet, so each
// <style> is complete on its own. A sheet ending mid-rule (live GoDaddy pages
// ship `.gd-ad-flex-parent { … opacity: 0;</style>`) must not swallow the
// sheets after it, nor the inline <body> spacing rule appended to the last one.
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$html = '<html><head><style>.ad{opacity:0;</style><style>.sample{color:red}</style><style>@media screen{.lead{color:blue}</style></head>'
    . '<body style="padding-top:12px"><main><p class="sample">Sample</p><p class="lead">Lead</p></main></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'content' => $html))))->toArray()['source_reports']['wordpress_site_plan'];
$sheets = array();
foreach ($plan['assets'] as $asset) foreach ($asset['stylesheet_instances'] ?? array() as $instance) $sheets[$instance['order'] * 1000 + count($sheets)] = $asset['content'];
ksort($sheets);
$css = implode("\n", $sheets);
$dom = new DOMDocument();
@$dom->loadHTML('<html><body>' . array_column($plan['pages'], null, 'source_path')['index.html']['canonical_block_markup'] . '</body></html>');
$paragraphs = $dom->getElementsByTagName('p');
$cascade = new StaticCssCascade($dom, $css);
$assert('red' === ($cascade->resolve($paragraphs->item(0), array('color'), array('color'))['color'] ?? null), 'A sheet after an unterminated rule still paints: ' . $css);
$assert(1 === preg_match('/@media screen\{\.lead\{[^{}]*\}\}/', $css), 'An open conditional block closes at the end of its own sheet: ' . $css);
$assert(1 === preg_match('/body[^{]*\{[^}]*padding-top:\s*12px/', $css), 'The body spacing rule appended to the last sheet stays a rule of its own: ' . $css);
echo "unterminated inline stylesheet contract passed\n";
