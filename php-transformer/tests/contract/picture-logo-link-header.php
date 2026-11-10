<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
	if (!$condition) throw new RuntimeException($message);
};

// Reduced fixture from #5268: two pages share a header whose logo is a
// picture-in-a-link lockup next to a text label. RichText cannot carry the
// picture, so the logo anchor must lower into a link-wrapper row group (image
// block + linked label) instead of a core/html island.
$logo = '<a class="site-logo" href="/"><picture><img src="/logo.png" alt="Acme"></picture><span>Acme</span></a>';
$css = 'header .inner{display:flex;align-items:center;justify-content:space-between}'
	. '.site-logo,.site-logo picture{align-items:center;display:inline-flex}'
	. '.site-logo img,.site-logo picture{height:2rem}';
$header = '<header><div class="inner">' . $logo . '<nav><a href="/">Home</a><a href="/about/">About</a></nav></div></header>';
$document = static fn (string $title, string $body): string => '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="/style.css"></head><body>' . $body . '<footer><p>Footer text</p></footer></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
	'style.css' => $css,
	'index.html' => $document('Home', $header . '<main><h1>Welcome home</h1><p>Home page body text.</p></main>'),
	'about/index.html' => $document('About', $header . '<main><h1>About us</h1><p>About page body text.</p></main>'),
)))->toWordPressSitePlanView()['wordpress_site_plan'];

$headers = array_values(array_filter($plan['template_parts'], static fn (array $part): bool => 'header' === ($part['area'] ?? null)));
$assert(1 === count($headers), 'one shared header part exists');
$markup = $headers[0]['canonical_block_markup'];

$assert(!str_contains($markup, 'wp:html'), 'no core/html fallback: ' . substr($markup, 0, 2000));
$assert(preg_match('/<!-- wp:image [^>]*-->.{0,600}?<a href="\/">/s', $markup) === 1, 'an image block links to "/": ' . substr($markup, 0, 2000));
$assert(preg_match('/<!-- wp:paragraph [^>]*-->.{0,400}?<a href="\/">.{0,200}?Acme/s', $markup) === 1, 'the label "Acme" links to "/": ' . substr($markup, 0, 2000));
$assert(preg_match('/<!-- wp:image [^\r\n]*"height":"2rem"/s', $markup) === 1, 'image height 2rem preserved: ' . substr($markup, 0, 2000));

echo "picture-logo-link-header: ok (picture logo lockup lowers to a linked image plus linked label, no core/html)\n";
