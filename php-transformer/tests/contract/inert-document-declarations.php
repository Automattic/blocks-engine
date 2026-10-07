<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactNormalizer;
use Automattic\BlocksEngine\PhpTransformer\Support\StyleTagScanner;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ($condition) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};
$compile = static fn(string $html, array $files = array()): array => (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html', 'files' => array_merge(array('index.html' => $html), $files),
))->toArray();
$resolver = new WordPressSitePlanResolver();
$options = array('theme_uri' => 'https://example.test/theme', 'require_proven_dynamic_client_assets' => true);
$comment = '<!--[if lt IE 9]> <script src="//cdn.example.test/legacy.js"></script> <![endif]-->';
$ordinary = '<!-- <meta name="fake" content="fake"><title>Fake title</title><link rel="stylesheet" href="https://example.test/fake.css"><style>.fake{color:red}</style><script>window.fake=true;</script> -->';
$html = '<!doctype html><html><head>' . $comment . $ordinary . '</head><body><main><h1>Real page</h1></main></body></html>';
$result = $compile($html);
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$metadata = $plan['pages'][0]['document_metadata'] ?? array();
foreach (array('meta', 'links', 'scripts') as $field) $assert(array() === ($metadata[$field] ?? null), "comments do not fabricate {$field}");
$assert('Real page' === ($metadata['title'] ?? null), 'commented title is ignored');
$assert('proven' === ($plan['reference_semantics']['dynamic_client_assets']['status'] ?? null), 'conditional external script comment leaves a proven plan');
try {
    $resolved = $resolver->resolve($plan, $options);
    $assert($options['theme_uri'] === ($resolved['resolution']['theme_uri'] ?? null), 'comment-only plan resolves with mandatory dynamic asset proof');
} catch (Throwable $error) {
    $assert(false, 'comment-only plan must resolve: ' . $error->getMessage());
}
$assert(array() === StyleTagScanner::scan($html), 'commented styles are ignored');
$assert(array() === StyleTagScanner::scanLinks($html), 'commented links are ignored');
$withoutHeading = $compile('<html><head>' . $ordinary . '</head><body><main>Page without heading</main></body></html>')['source_reports']['wordpress_site_plan'];
$assert('Index' === ($withoutHeading['pages'][0]['document_metadata']['title'] ?? null) && 'Index' === ($withoutHeading['pages'][0]['title'] ?? null), 'commented titles cannot leak through document-title fallback');

$external = $compile('<html><head><script src="https://cdn.example.test/active.js" async></script></head><body><main>Active</main></body></html>')['source_reports']['wordpress_site_plan'];
$assert('not_proven' === ($external['reference_semantics']['dynamic_client_assets']['status'] ?? null), 'active external script remains unproven');
try { $resolver->resolve($external, $options); $assert(false, 'active external script must reject'); }
catch (InvalidArgumentException $error) { $assert(str_contains($error->getMessage(), 'cannot prove dynamic client asset'), 'active external script still rejects at proof gate'); }

// Source bytes remain intact: a comment-looking JS string is not an HTML comment.
$body = 'window.example = "<!-- <meta name=raw><link href=raw.css> -->";';
$json = '{"example":"<script src=raw.js><meta name=raw><link href=raw.css><title>Raw</title>"}';
$activeHtml = '<html><head>' . $comment . $ordinary
    . '<title data-example="<meta name=attribute>">Active &amp; real</title>'
    . '<meta name="description" content="Example > <script src=attribute.js>">'
    . '<link rel="stylesheet" href="assets/site.css" media="screen > print">'
    . '<style>.real::before{content:"<link href=raw.css><!--"}</style>'
    . '<script type="application/ld+json">' . $json . '</script>'
    . '<script data-example="<script src=attribute.js>" defer>' . $body . '</script>'
    . '</head><body><main><h1>Active</h1><textarea><script src=raw.js></script><link href=raw.css></textarea></main>'
    . '<script src="assets/site.js" type="module" crossorigin></script>'
    . '<script async nomodule>window.tail = true;</script></body></html>';
$artifact = array('entrypoint' => 'index.html', 'files' => array('index.html' => $activeHtml, 'assets/site.css' => '.real{color:red}', 'assets/site.js' => 'window.local=true;'));
$normal = (new ArtifactNormalizer())->normalize($artifact);
$inline = array_values(array_filter($normal['files'], static fn(array $file): bool => 'inline-script' === ($file['source'] ?? '')));
$assert(array('script:nth-of-type(2)', 'script:nth-of-type(4)') === array_column($inline, 'selector'), 'only real script elements advance inline selectors');
$assert(array($body, 'window.tail = true;') === array_column($inline, 'content'), 'inline bodies retain comment-looking strings exactly');
$assert(array('head', 'body') === array_column($inline, 'placement'), 'inline placement follows real head boundary');
$active = (new ArtifactCompiler())->compile($artifact)->toArray();
$activePlan = $active['source_reports']['wordpress_site_plan'] ?? array();
$activeMetadata = $activePlan['pages'][0]['document_metadata'] ?? array();
$assert('Active & real' === ($activeMetadata['title'] ?? null), 'active title wins over commented and raw-text titles');
$assert(1 === count($activeMetadata['meta'] ?? array()) && 'Example > <script src=attribute.js>' === ($activeMetadata['meta'][0]['content'] ?? null), 'quoted metadata payload stays intact');
$assert(1 === count($activeMetadata['links'] ?? array()) && 'screen > print' === ($activeMetadata['links'][0]['media'] ?? null), 'only actual quoted link declaration is preserved');
$scripts = $activeMetadata['scripts'] ?? array();
$assert(4 === count($scripts), 'raw-text and attribute examples do not fabricate scripts');
$assert(array(0, 1, 2, 3) === array_column($scripts, 'order'), 'active script order stays contiguous');
$assert(array('head', 'head', 'body', 'body') === array_column($scripts, 'placement'), 'active scripts retain head/body placement');
$assert(hash('sha256', $json) === ($scripts[0]['body_hash'] ?? null), 'inert script body hash uses original raw-text bytes');
$assert(true === ($scripts[1]['defer'] ?? null) && 'defer' === ($scripts[1]['effective_loading'] ?? null), 'inline defer semantics survive quoted greater-than');
$assert(true === ($scripts[2]['module'] ?? null) && 'defer' === ($scripts[2]['effective_loading'] ?? null) && 'anonymous' === ($scripts[2]['crossorigin'] ?? null), 'local module loading and CORS remain intact');
$assert(true === ($scripts[3]['async'] ?? null) && true === ($scripts[3]['nomodule'] ?? null) && 'async' === ($scripts[3]['effective_loading'] ?? null), 'body async/nomodule semantics remain intact');
$assetByToken = array(); foreach ($activePlan['assets'] ?? array() as $asset) $assetByToken['{{wordpress-site-plan:asset:' . $asset['token'] . '}}'] = $asset;
foreach (array(1 => $body, 3 => 'window.tail = true;') as $index => $content) {
    $asset = $assetByToken[$scripts[$index]['asset_reference'] ?? ''] ?? array();
    $assert(hash('sha256', $content) === ($asset['content_hash'] ?? null), 'script declaration binds the matching generated inline body ' . $index);
}
try { $resolver->resolve($activePlan, $options); $assert(true, 'active materialized scripts resolve with proof required'); }
catch (Throwable $error) { $assert(false, 'active materialized scripts must resolve: ' . $error->getMessage()); }
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$staged = $compiler->compose($shared, $compiler->compilePreparedPages($shared, $compiler->preparePages($artifact, $shared)))->toArray();
$assert($activeMetadata === ($staged['source_reports']['wordpress_site_plan']['pages'][0]['document_metadata'] ?? null), 'staged and whole compilation preserve identical declarations');

fwrite(STDOUT, "inert-document-declarations: {$passes} passed, {$failures} failed\n");
exit($failures ? 1 : 0);
