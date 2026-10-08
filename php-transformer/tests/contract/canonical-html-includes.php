<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactNormalizer;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\PayloadReader;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$fixture = dirname(__DIR__) . '/fixtures/canonical-shared-chrome/';
$header = file_get_contents($fixture . 'parts/header-a1.html');
$footer = file_get_contents($fixture . 'parts/footer-b2.html');
$page = static fn(string $title): string => file_get_contents($fixture . ('Home' === $title ? 'index.html' : 'about.html'));
$compiler = new ArtifactCompiler();
foreach (array('', 'website/') as $root) {
    $artifact = array('entrypoint' => $root . 'index.html', 'files' => array(
        $root . 'index.html' => $page('Home'),
        $root . 'about.html' => $page('About'),
        $root . 'parts/header-a1.html' => $header,
        $root . 'parts/footer-b2.html' => $footer,
        $root . 'site.css' => file_get_contents($fixture . 'site.css'),
        $root . 'site.js' => file_get_contents($fixture . 'site.js'),
        $root . 'assets/logo.svg' => file_get_contents($fixture . 'assets/logo.svg'),
    ));
    $normalized = (new ArtifactNormalizer())->normalize($artifact);
    $resolved = array_column($normalized['files'], 'content', 'path');
    $assert($resolved[$root . 'index.html'] === str_replace(array('<!--#include virtual="/parts/header-a1.html" -->', '<!--#include virtual="/parts/footer-b2.html" -->'), array($header, $footer), $page('Home')), 'Only include comments change; all surrounding bytes are intact.');
    $whole = $compiler->compile($artifact)->toArray();
    $plan = $whole['source_reports']['wordpress_site_plan'];
    WordPressSitePlan::assertValid($plan);
    $pagePaths = array_column($plan['pages'], 'source_path'); sort($pagePaths);
    $partAreas = array_column($plan['template_parts'], 'area'); sort($partAreas);
    $assert($pagePaths === array($root . 'about.html', $root . 'index.html'), 'Only the two real routes are pages.');
    $assert(count(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area'])) === 1 && count(array_filter($plan['template_parts'], static fn(array $part): bool => 'footer' === $part['slug'])) === 1, 'One native header and footer shell exist; existing footer-copy factoring may bind an inner part.');
    $assert(!array_filter($plan['template_parts'], static fn(array $part): bool => 'unbound' === ($part['placement']['kind'] ?? null)), 'No redundant unbound component materialization.');
    $assert(str_contains(implode('', array_column($plan['template_parts'], 'canonical_block_markup')), 'site-chrome') && str_contains(implode('', array_column($plan['template_parts'], 'canonical_block_markup')), 'colophon'), 'Fragment IDs survive shared-shell extraction.');
    $expanded = $artifact;
    $expanded['files'][$root . 'index.html'] = $resolved[$root . 'index.html'];
    $expanded['files'][$root . 'about.html'] = $resolved[$root . 'about.html'];
    unset($expanded['files'][$root . 'parts/header-a1.html'], $expanded['files'][$root . 'parts/footer-b2.html']);
    $expandedPlan = $compiler->compile($expanded)->toArray()['source_reports']['wordpress_site_plan'];
    $assert($plan['pages'] === $expandedPlan['pages'], 'Resolved semantics and document metadata match ordinary HTML compilation.');
    $assert($plan['assets'] === $expandedPlan['assets'], 'Assets, scripts and authored styles match ordinary resolved HTML.');
    $assert(!str_contains(implode('', array_column($plan['menus'], 'block_markup')), 'blocks-engine-current-navigation-item'), 'A shared navigation entity does not freeze the captured current route.');
    $shared = $compiler->prepareShared($artifact);
    $assert($shared['analysis']['page_ids'] === array($root . 'about.html', $root . 'index.html'), 'Component source and its assets are shared-owned, never page plans.');
    $staged = $compiler->compose($shared, $compiler->compilePreparedPages($shared, $compiler->preparePages($artifact, $shared)))->toArray();
    $assert($staged['source_reports']['wordpress_site_plan'] === $plan, 'Whole and terminal staged plans agree exactly.');
    $referenced = $artifact; $referenced['files'] = array(); $payloads = array();
    foreach ($artifact['files'] as $path => $content) {
        $payloads[$path] = $content;
        $referenced['files'][] = array('path' => $path, 'payload_reference' => array('schema' => 'blocks-engine/payload-reference/v1', 'id' => $path, 'bytes' => strlen($content), 'sha256' => hash('sha256', $content)));
    }
    $reader = new class($payloads) implements PayloadReader {
        public function __construct(private array $payloads) {}
        public function read(array $reference): string { return $this->payloads[$reference['id']]; }
    };
    $referenceShared = $compiler->prepareShared($referenced, $reader);
    $referencePages = $compiler->preparePages($referenced, $referenceShared, $reader);
    $referenceResult = $compiler->compose($referenceShared, $compiler->compilePreparedPages($referenceShared, $referencePages, $reader), $reader)->toArray();
    $assert($referenceResult['source_reports']['wordpress_site_plan'] === $plan, 'Reference-backed text compilation agrees exactly.');
    $assert($referenceShared['analysis']['canonical_source_hash'] === $shared['analysis']['canonical_source_hash'], 'Text hydration and expansion produce the same canonical source digest.');
    $edited = $artifact;
    $edited['files'][$root . 'parts/header-a1.html'] = str_replace('Canonical brand', 'Edited once', $header);
    $editedResult = $compiler->compile($edited)->toArray();
    $editedPlan = $editedResult['source_reports']['wordpress_site_plan'];
    $assert(count($editedPlan['template_parts']) === count($plan['template_parts']) && str_contains(implode('', array_column($editedPlan['template_parts'], 'canonical_block_markup')), 'Edited once'), 'One component edit propagates into the one shared part.');
    $assert((new ArtifactNormalizer())->normalize($edited)['source_hash'] !== $normalized['source_hash'], 'Component edits change canonical source identity.');
}

// Independent header appearances use existing route-variant parts and bindings.
$variantArtifact = array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!--#include virtual="/parts/header-light.html" --><main>Home</main>',
    'team.html' => '<!--#include virtual="/parts/header-light.html" --><main>Team</main>',
    'about.html' => '<!--#include virtual="/parts/header-dark.html" --><main>About</main>',
    'events.html' => '<!--#include virtual="/parts/header-dark.html" --><main>Events</main>',
    'parts/header-light.html' => '<header class="light"><p>Brand</p><nav><a href="/index.html">Home</a></nav></header>',
    'parts/header-dark.html' => '<header class="dark"><p>Brand</p><nav><a href="/index.html">Home</a></nav></header>',
));
$variantPlan = $compiler->compile($variantArtifact)->toArray()['source_reports']['wordpress_site_plan'];
$assert(count($variantPlan['pages']) === 4 && count($variantPlan['template_parts']) === 2, 'Canonical component variants become exactly two native header parts.');
foreach ($variantPlan['pages'] as $route) $assert(substr_count($route['canonical_block_markup'], '<!-- wp:template-part') === 1 && !str_contains($route['canonical_block_markup'], 'Brand'), 'Each route binds its variant exactly once.');
$variantShared = $compiler->prepareShared($variantArtifact);
$assert($compiler->compose($variantShared, $compiler->compilePreparedPages($variantShared, $compiler->preparePages($variantArtifact, $variantShared)))->toArray()['source_reports']['wordpress_site_plan'] === $variantPlan, 'Whole/staged variant bindings agree.');

$nested = array('entrypoint' => 'index.html', 'files' => array('index.html' => "Before\r\n<!--#include virtual=\"/parts/header.html\" -->\r\nAfter", 'parts/header.html' => '<header><!--#include virtual="/parts/Shared_Brand-1.html" --></header>', 'parts/Shared_Brand-1.html' => "Brand\r\n"));
$nestedFiles = array_column((new ArtifactNormalizer())->normalize($nested)['files'], 'content', 'path');
$assert($nestedFiles['index.html'] === "Before\r\n<header>Brand\r\n</header>\r\nAfter", 'Nested local fragments preserve CRLF source bytes without HTML reserialization.');
$nestedRefs = $nested; $nestedRefs['files'] = array(); $nestedPayloads = array();
foreach ($nested['files'] as $path => $content) { $nestedPayloads[$path] = $content; $nestedRefs['files'][] = array('path' => $path, 'payload_reference' => array('schema' => 'blocks-engine/payload-reference/v1', 'id' => $path, 'bytes' => strlen($content), 'sha256' => hash('sha256', $content))); }
$nestedReader = new class($nestedPayloads) implements PayloadReader {
    public array $reads = array();
    public function __construct(private array $payloads) {}
    public function read(array $reference): string { $this->reads[] = $reference['id']; return $this->payloads[$reference['id']]; }
};
$nestedShared = $compiler->prepareShared($nestedRefs, $nestedReader);
$nestedPrepared = $compiler->preparePage($nestedRefs, $nestedShared, 'index.html', $nestedReader);
$assert(in_array('parts/Shared_Brand-1.html', $nestedReader->reads, true), 'Reference-backed stages hydrate nested canonical fragments.');
$assert($compiler->compose($nestedShared, array($compiler->compilePreparedPage($nestedShared, $nestedPrepared, $nestedReader)), $nestedReader)->toArray()['source_reports']['wordpress_site_plan'] === $compiler->compile($nested)->toArray()['source_reports']['wordpress_site_plan'], 'Nested reference-backed fragments agree with whole compilation.');

foreach (array(
    '<!--#include virtual="/parts/missing.html" -->' => 'missing_path',
    '<!--#include virtual="/../secret.html" -->' => 'invalid_directive',
    '<!--#include virtual="//host/a.html" -->' => 'invalid_directive',
    '<!--#include virtual="/parts/%2e%2e/a.html" -->' => 'invalid_directive',
    "<!--#include virtual='/parts/header.html' -->" => 'invalid_directive',
    '<!--#include  virtual="/parts/header.html" -->' => 'invalid_directive',
    '<!--#include virtual ="/parts/header.html" -->' => 'invalid_directive',
    '<!-- #include virtual="/parts/header.html" -->' => 'invalid_directive',
    '<!--#INCLUDE virtual="/parts/header.html" -->' => 'invalid_directive',
    '<!--#include virtual="/fragments/header.html" -->' => 'invalid_directive',
    '<!--#include virtual="/parts/nested/header.html" -->' => 'invalid_directive',
    '<!--#include virtual="/parts/header.htm" -->' => 'invalid_directive',
    '<!--#include virtual="/parts/.html" -->' => 'invalid_directive',
    '<!--#include virtual="/parts/header.html"-->' => 'invalid_directive',
    '<!--#include file="parts/header.html" -->' => 'invalid_directive',
    '<!--#include virtual="/parts/header.html" -->' => 'cycle',
) as $directive => $reason) {
    try {
        $compiler->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $directive, 'parts/header.html' => '<!--#include virtual="/parts/header.html" -->')));
        throw new RuntimeException('Invalid include unexpectedly compiled: ' . $reason);
    } catch (InvalidArgumentException $error) { $assert(str_contains($error->getMessage(), 'html_include_' . $reason), 'Invalid includes fail visibly with the expected reason.'); }
}
$literal = '<script>const literal = \'<!--#include virtual="/missing.html" -->\';</script><style>/* <!--#exec cmd="date" --> */</style><!--#exec cmd="date" --><!--#ordinary comment -->';
$assert((new ArtifactNormalizer())->normalize(array('files' => array('index.html' => $literal)))['files'][0]['content'] === $literal, 'Raw-text script/style directive literals are inert and byte-preserved.');
foreach (array('max_file_bytes' => 500, 'max_total_bytes' => 700) as $limit => $value) {
    try {
        $compiler->compile(array('entrypoint' => 'index.html', 'compiler_limits' => array($limit => $value), 'files' => array('index.html' => str_repeat('<!--#include virtual="/parts/a.html" -->', 10), 'parts/a.html' => str_repeat('a', 100))));
        throw new RuntimeException('Expansion budget unexpectedly accepted.');
    } catch (InvalidArgumentException $error) { $assert(str_contains($error->getMessage(), 'budget_exceeded'), 'Expanded parsed-source budgets are hard bounds.'); }
}
foreach (array('depth', 'count') as $bound) {
    $files = array('index.html' => '<!--#include virtual="/parts/a0.html" -->');
    if ('depth' === $bound) {
        for ($i = 0; $i < 17; ++$i) $files['parts/a' . $i . '.html'] = $i === 16 ? 'End' : '<!--#include virtual="/parts/a' . ($i + 1) . '.html" -->';
    } else { $files['index.html'] = str_repeat($files['index.html'], 4097); $files['parts/a0.html'] = 'X'; }
    try { (new ArtifactNormalizer())->normalize(array('entrypoint' => 'index.html', 'files' => $files)); throw new RuntimeException('Include bound unexpectedly accepted.'); }
    catch (InvalidArgumentException $error) { $assert(str_contains($error->getMessage(), 'html_include_' . $bound . '_exceeded'), 'Depth/count bounds fail visibly.'); }
}
$brand = "<p>Brand</p>\n";
$brandInclude = '<!--#include virtual="/parts/brand.html" -->';
$footerInclude = '<!--#include virtual="/parts/footer.html" -->';
$multipage = array('entrypoint' => 'page-01.html', 'files' => array());
foreach (range(1, 8) as $page) $multipage['files'][sprintf('page-%02d.html', $page)] = str_repeat($footerInclude, 30) . '<main>Page</main>';
$multipage['files']['parts/footer.html'] = str_repeat($brandInclude, 30);
$multipage['files']['parts/brand.html'] = $brand;
$multiNormalized = (new ArtifactNormalizer())->normalize($multipage);
$multiResolved = array_column($multiNormalized['files'], 'content', 'path');
$multiMetadata = array_column($multiNormalized['files'], 'metadata', 'path');
$expandedFooter = str_replace($brandInclude, $brand, $multipage['files']['parts/footer.html']);
$assert($multiResolved['page-01.html'] === str_replace($footerInclude, $expandedFooter, str_repeat($footerInclude, 30)) . '<main>Page</main>', '930 logical uses per page and 7470 artifact-wide expand with surrounding bytes intact.');
$assert('shared' === $multiMetadata['parts/footer.html']['compilation']['scope'] && true === $multiMetadata['parts/footer.html']['compilation']['included_component'], 'Repeatedly included fragments keep shared ownership metadata.');
$multiPlan = $compiler->compile($multipage)->toArray()['source_reports']['wordpress_site_plan'];
$assert(count($multiPlan['pages']) === 8, 'Every reused-fragment document remains a page.');
$multiShared = $compiler->prepareShared($multipage);
$multiStaged = $compiler->compose($multiShared, $compiler->compilePreparedPages($multiShared, $compiler->preparePages($multipage, $multiShared)))->toArray()['source_reports']['wordpress_site_plan'];
$assert($multiPlan === $multiStaged, 'Whole and staged compilation agree across multi-page fragment reuse.');
$boundary = array('entrypoint' => 'boundary-1.html', 'files' => array(
    'boundary-1.html' => '<!--#include virtual="/parts/wide.html" -->',
    'boundary-2.html' => '<!--#include virtual="/parts/wide.html" -->',
    'parts/wide.html' => str_repeat('<!--#include virtual="/parts/unit.html" -->', 4095),
    'parts/unit.html' => 'B',
));
$boundaryResolved = array_column((new ArtifactNormalizer())->normalize($boundary)['files'], 'content', 'path');
$assert($boundaryResolved['boundary-1.html'] === str_repeat('B', 4095) && $boundaryResolved['boundary-2.html'] === str_repeat('B', 4095), 'Each document resolves exactly the include bound while the artifact total passes it.');
try {
    (new ArtifactNormalizer())->normalize(array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => str_repeat('<!--#include virtual="/parts/twin.html" -->', 4095),
        'parts/twin.html' => '<!--#include virtual="/parts/unit-a.html" --><!--#include virtual="/parts/unit-b.html" -->',
        'parts/unit-a.html' => 'A',
        'parts/unit-b.html' => 'B',
    )));
    throw new RuntimeException('Per-document include bound unexpectedly accepted.');
} catch (InvalidArgumentException $error) { $assert(str_contains($error->getMessage(), 'html_include_count_exceeded'), 'One document reusing fragments past the bound still fails.'); }
$depthReuse = array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!--#include virtual="/parts/x.html" --><!--#include virtual="/parts/g1.html" -->',
    'parts/g1.html' => '<!--#include virtual="/parts/x.html" -->',
    'parts/x.html' => '<!--#include virtual="/parts/y1.html" -->',
));
for ($i = 1; $i < 14; ++$i) $depthReuse['files']['parts/y' . $i . '.html'] = '<!--#include virtual="/parts/y' . ($i + 1) . '.html" -->';
$depthReuse['files']['parts/y14.html'] = 'Deep';
try { (new ArtifactNormalizer())->normalize($depthReuse); throw new RuntimeException('Depth bound unexpectedly bypassed through fragment reuse.'); }
catch (InvalidArgumentException $error) { $assert(str_contains($error->getMessage(), 'html_include_depth_exceeded'), 'Fragment reuse through a cached traversal still honors the depth bound.'); }
$cycleReuse = array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $brandInclude . '<!--#include virtual="/parts/loop1.html" -->',
    'parts/brand.html' => $brand,
    'parts/loop1.html' => '<!--#include virtual="/parts/loop2.html" -->',
    'parts/loop2.html' => '<!--#include virtual="/parts/loop1.html" -->',
));
try { (new ArtifactNormalizer())->normalize($cycleReuse); throw new RuntimeException('Cycle behind a reused fragment unexpectedly accepted.'); }
catch (InvalidArgumentException $error) { $assert(str_contains($error->getMessage(), 'html_include_cycle'), 'Cycle rejection holds behind reused fragments.'); }
$twinInclude = '<!--#include virtual="/parts/twin.html" -->';
$twinFiles = array('parts/twin.html' => '<!--#include virtual="/parts/unit-a.html" --><!--#include virtual="/parts/unit-b.html" -->', 'parts/unit-a.html' => 'A', 'parts/unit-b.html' => 'B');
$twins = static fn(int $occurrences): array => array('entrypoint' => 'index.html', 'files' => array('index.html' => str_repeat($twinInclude, $occurrences)) + $twinFiles);
$exactBound = array_column((new ArtifactNormalizer())->normalize($twins(1365))['files'], 'content', 'path');
$assert($exactBound['index.html'] === str_repeat('AB', 1365), '1365 twin reuses cost 4095 logical uses, exactly under the bound, and expand to AB pairs.');
try { (new ArtifactNormalizer())->normalize($twins(1366)); throw new RuntimeException('One additional twin occurrence past the bound unexpectedly accepted.'); }
catch (InvalidArgumentException $error) { $assert(str_contains($error->getMessage(), 'html_include_count_exceeded'), 'Charging every reuse keeps a 4098-logical-use document over the bound.'); }
foreach (array('warm-first' => array('warm.html', 'index.html'), 'reversed-order' => array('index.html', 'warm.html')) as $order => $documentOrder) {
    $orderFiles = $twinFiles;
    foreach ($documentOrder as $document) $orderFiles[$document] = 'index.html' === $document ? str_repeat($twinInclude, 4095) : $twinInclude;
    try { (new ArtifactNormalizer())->normalize(array('entrypoint' => 'index.html', 'files' => $orderFiles)); throw new RuntimeException("Cache-warmed bound bypass unexpectedly accepted ($order)."); }
    catch (InvalidArgumentException $error) { $assert(str_contains($error->getMessage(), 'html_include_count_exceeded'), "Each reuse charges the fragment's 12285 logical uses regardless of $order."); }
}
$multipageReversed = $multipage;
$multipageReversed['files'] = array('parts/brand.html' => $brand, 'parts/footer.html' => $multipage['files']['parts/footer.html']) + $multipage['files'];
$multiResolvedReversed = array_column((new ArtifactNormalizer())->normalize($multipageReversed)['files'], 'content', 'path');
$multiResolvedSorted = $multiResolved;
ksort($multiResolvedReversed);
ksort($multiResolvedSorted);
$assert($multiResolvedReversed === $multiResolvedSorted, 'Fragment-first file order resolves byte-identically to page-first order.');
try {
    (new ArtifactNormalizer())->normalize(array('entrypoint' => 'index.html', 'compiler_limits' => array('max_total_bytes' => 200000), 'files' => array(
        'index.html' => str_repeat('<!--#include virtual="/parts/body.html" -->', 300),
        'parts/body.html' => str_repeat('B', 1000),
    )));
    throw new RuntimeException('Expanded total byte budget unexpectedly accepted.');
} catch (InvalidArgumentException $error) { $assert(str_contains($error->getMessage(), 'html_include_total_budget_exceeded'), 'The total byte guard charges 301000 fully expanded bytes, not 14800 unresolved source bytes.'); }
echo "Canonical HTML includes contract passed.\n";
