<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\Css\CssIdent;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// The header's layout lives behind an attribute selector, so conversion rewrites
// it into a generated, document-namespaced class. The declaration is inline on
// every page, which makes each copy page-scoped.
$css = '[data-chrome=grid]{display:grid;grid-template-columns:200px 1fr;height:120px}.route-grid{display:grid;grid-template-columns:1fr 1fr}@media (max-width:700px){.route-grid{grid-template-columns:1fr}}';
$header = static function (string $home, string $about): string {
    return '<header id="site-chrome" class="site-header" data-chrome="grid">'
        . '<nav><a href="' . $home . '">Home</a><a href="' . $about . '">About</a></nav>'
        . '</header>';
};
$document = static function (string $header, string $main) use ($css): string {
    return '<!doctype html><html><head><style>' . $css . '</style><link rel="stylesheet" href="route.css"></head><body>'
        . '<div id="site-root"><div id="masterPage">' . $header
        . '<div id="PAGES_CONTAINER"><div class="route-grid">' . $main . '</div></div></div></div>'
        . '</body></html>';
};

$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => $document($header('index.html', 'guides/about.html'), '<main id="content"><h1>Home</h1></main>'),
        'guides/about.html' => $document($header('../index.html', 'about.html'), '<main id="content"><h1>About</h1></main>'),
        'guides/team.html' => $document($header('../index.html', 'about.html'), '<main id="content"><h1>Team</h1></main>'),
        'route.css' => array('path' => 'route.css', 'kind' => 'css', 'content' => '.route-only{color:#123456}'),
    ),
))->toArray()['source_reports']['wordpress_site_plan'];

$shared = array_values(array_filter(
    $plan['template_parts'],
    static fn (array $part): bool => in_array($part['placement']['kind'] ?? '', array('shared_shell', 'inline_shared_shell'), true)
));
$assert(array() !== $shared, 'The identical header across pages is extracted as a shared template part.');

$classes = array();
foreach ($shared as $part) {
    preg_match_all('/blocks-engine-[a-z-]+-[0-9a-f]{12}-\d+/', (string) $part['canonical_block_markup'], $matches);
    foreach ($matches[0] as $class) {
        $classes[$class] = true;
    }
}
$assert(array() !== $classes, 'Shared chrome markup carries generated, document-namespaced classes.');

$pageScoped = array();
$globalCount = 0;
$globalCss = '';
$routeScoped = false;
$routeMediaScoped = false;
foreach ($plan['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? null)) {
        continue;
    }
    $content = (string) ($asset['content'] ?? '');
    if (str_contains($content, '.route-only')) {
        foreach ($asset['scopes'] ?? array() as $scope) if ('global' !== ($scope['kind'] ?? null)) $routeScoped = true;
    }
    if (str_contains($content, '.route-grid') && str_contains($content, '@media (max-width:700px)')) {
        foreach ($asset['scopes'] ?? array() as $scope) if ('global' !== ($scope['kind'] ?? null)) $routeMediaScoped = true;
    }
    $defines = false;
    foreach (array_keys($classes) as $class) {
        if (str_contains($content, '.' . $class)) {
            $defines = true;
            break;
        }
    }
    if (! $defines) {
        continue;
    }
    $isGlobal = false;
    foreach ($asset['scopes'] ?? array() as $scope) {
        if ('global' === ($scope['kind'] ?? null)) $isGlobal = true;
    }
    if ($isGlobal) {
        ++$globalCount;
        $globalCss .= $content;
        continue;
    }
    $pageScoped[] = (string) $asset['target_path'];
}

// Shared chrome receives a global projection, while route-owned source rules
// remain page-scoped and therefore cannot leak into unrelated routes.
$assert(0 < $globalCount, 'Shared chrome receives a global projected stylesheet.');
$assert(!str_contains($globalCss, '.route-grid'), 'The global shared projection contains no route-owned layout rule.');
$assert($routeScoped, 'Route-only CSS retains non-global applicability.');

$generatedClass = array_key_first($classes);
$classToken = is_string($generatedClass) ? ltrim($generatedClass, '.') : '';
$classPattern = '/' . CssIdent::classSelectorRegex($classToken) . '(?![\w-])/';
$assert(1 === preg_match($classPattern, '.' . $classToken . '0,.' . $classToken . ':is(.x)'), 'Generated class matching respects CSS identifier boundaries and selector lists.');
$assert(0 === preg_match($classPattern, '.' . $classToken . '0'), 'A generated class does not match its prefix sibling.');
$assert($routeMediaScoped, 'Route media rules retain their non-global cascade boundary.');

$malformed = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<html><head><style>[data-chrome=grid]{display:flex}:is(.valid,.broken[foo="bar"){color:red}</style></head><body><header data-chrome="grid"><nav>Brand</nav></header><main>Home</main></body></html>',
        'about.html' => '<html><head><style>[data-chrome=grid]{display:flex}:is(.valid,.broken[foo="bar"){color:red}</style></head><body><header data-chrome="grid"><nav>Brand</nav></header><main>About</main></body></html>',
    ),
))->toArray()['source_reports']['wordpress_site_plan'] ?? array();
$malformedGlobal = implode('\n', array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), array_filter($malformed['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null) && 'global' === ($asset['scopes'][0]['kind'] ?? null))));
$malformedRoute = implode('\n', array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), array_filter($malformed['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null) && 'global' !== ($asset['scopes'][0]['kind'] ?? null))));
$assert(!str_contains($malformedGlobal, '.broken') && str_contains($malformedRoute, '.broken'), 'Unparseable selector lists remain on the route asset instead of being dropped or promoted.');

// A shared chrome rule from a linked stylesheet in a subdirectory keeps its
// relative url() resolvable after projection into the generated global
// stylesheet, whose synthetic identity is not the rule's source location.
$linkedDocument = static fn(string $header, string $main): string => '<!doctype html><html><head><link rel="stylesheet" href="/css/chrome.css"></head><body>'
    . '<div id="site-root"><div id="masterPage">' . $header
    . '<div id="PAGES_CONTAINER"><div class="route-grid">' . $main . '</div></div></div></div></body></html>';
$linked = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => $linkedDocument($header('index.html', 'guides/about.html'), '<main id="content"><h1>Home</h1></main>'),
        'guides/about.html' => $linkedDocument($header('../index.html', 'about.html'), '<main id="content"><h1>About</h1></main>'),
        'guides/team.html' => $linkedDocument($header('../index.html', 'about.html'), '<main id="content"><h1>Team</h1></main>'),
        'css/chrome.css' => array('path' => 'css/chrome.css', 'kind' => 'css', 'content' => '[data-chrome=grid]{display:grid;grid-template-columns:200px 1fr;height:120px;background-image:url(../img/texture.png)}'),
        'img/texture.png' => array('path' => 'img/texture.png', 'kind' => 'image', 'mime_type' => 'image/png', 'content_base64' => base64_encode("\x89PNG\r\n\x1a\n")),
    ),
))->toArray();
$linkedPlan = $linked['source_reports']['wordpress_site_plan'] ?? array();
$assert('failed' !== ($linked['status'] ?? null), 'A linked shared chrome stylesheet with a relative url() compiles: ' . json_encode(array_values(array_filter($linked['diagnostics'] ?? array(), static fn(array $d): bool => 'unresolved_local_browser_reference' === ($d['reason'] ?? null)))));
$sharedWrites = array_values(array_filter($linkedPlan['writes'] ?? array(), static fn(array $write): bool => str_contains((string) ($write['target_path'] ?? ''), 'shared-chrome-')));
$assert(array() !== $sharedWrites, 'The linked shared chrome rule is projected into a generated shared stylesheet.');
foreach ($sharedWrites as $write) {
    $payload = (string) ($write['payload']['data'] ?? '');
    $assert(1 === preg_match('/background-image:url\(\{\{wordpress-site-plan:asset:asset-[a-f0-9]{16}\}\}\)/', $payload) && !str_contains($payload, 'texture.png'), 'The projected shared stylesheet tokenizes its url() reference instead of shipping a raw local path.');
}
foreach ($linkedPlan['assets'] ?? array() as $asset) {
    $assert(!array_key_exists('reference_origin', $asset), 'Reference origins are write-time transport, not part of the plan asset contract.');
}

// A generated class named only inside :not() excludes that element; the rule
// still styles the page's own content and must keep its source cascade order.
$exclusion = new ReflectionMethod(WordPressSitePlan::class, 'selectorTargetsGeneratedClass');
$assert(false === $exclusion->invoke(null, '.text-sm:not(:where(.blocks-engine-control-abc-6))', array('blocks-engine-control-abc-6' => true)), 'A selector that only excludes a shared-chrome class stays page-owned.');
$assert(true === $exclusion->invoke(null, '.blocks-engine-control-abc-6 .text-sm:not(.x)', array('blocks-engine-control-abc-6' => true)), 'A selector that targets a shared-chrome class is projected.');
$assert(true === $exclusion->invoke(null, ':not(.a) .blocks-engine-control-abc-6', array('blocks-engine-control-abc-6' => true)), 'A class outside :not() still counts after an earlier :not().');

// Two different stylesheets can project the same shared-chrome rules. The
// content-addressed target must be emitted once, with one token and one
// reconciliation identity, and relative url() must still resolve.
$splitChrome = '[data-chrome=grid]{display:grid;height:80px;background-image:url(../img/mark.png)}';
$splitHeader = static function (string $home, string $about): string {
    return '<header id="site-chrome" class="site-header" data-chrome="grid"><nav><a href="' . $home . '">Home</a><a href="' . $about . '">About</a></nav></header>';
};
$splitDocument = static function (string $headerHtml, string $main) use ($splitHeader): string {
    return '<!doctype html><html><head><link rel="stylesheet" href="css/home.css"><link rel="stylesheet" href="css/about.css"></head><body>'
        . '<div id="site-root"><div id="masterPage">' . $headerHtml
        . '<div id="PAGES_CONTAINER">' . $main . '</div></div></div></body></html>';
};
$split = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => $splitDocument($splitHeader('index.html', 'about.html'), '<main><h1>Home</h1></main>'),
        'about.html' => $splitDocument($splitHeader('index.html', 'about.html'), '<main><h1>About</h1></main>'),
        'team.html' => $splitDocument($splitHeader('index.html', 'about.html'), '<main><h1>Team</h1></main>'),
        'css/home.css' => array('path' => 'css/home.css', 'kind' => 'css', 'content' => $splitChrome . '.home-only{color:#111111}'),
        'css/about.css' => array('path' => 'css/about.css', 'kind' => 'css', 'content' => $splitChrome . '.about-only{color:#222222}'),
        'img/mark.png' => array('path' => 'img/mark.png', 'kind' => 'image', 'mime_type' => 'image/png', 'content_base64' => base64_encode("\x89PNG\r\n\x1a\n")),
    ),
))->toArray();
$splitPlan = $split['source_reports']['wordpress_site_plan'] ?? null;
$splitDiagnostics = array_values(array_filter($split['diagnostics'] ?? array(), static fn(array $diagnostic): bool => 'wordpress_site_plan_not_self_contained' === ($diagnostic['code'] ?? null)));
$assert(is_array($splitPlan), 'Identical shared-chrome projections from two stylesheets compile: ' . (string) ($splitDiagnostics[0]['message'] ?? $split['status'] ?? ''));
$splitShared = array_values(array_filter($splitPlan['assets'] ?? array(), static fn(array $asset): bool => str_contains((string) ($asset['target_path'] ?? ''), 'shared-chrome-') && !str_contains((string) ($asset['target_path'] ?? ''), 'shared-chrome-context')));
$assert(1 === count($splitShared), 'Byte-identical shared-chrome projections are one asset, not a colliding pair.');
$splitAsset = $splitShared[0];
$assert(array(array('kind' => 'global')) === ($splitAsset['scopes'] ?? null), 'The coalesced shared-chrome asset stays globally scoped for every page.');
$assert(1 === preg_match('/^css\/(?:home|about)(?:\.page-[a-f0-9]+)?\.css\.shared-chrome$/', (string) ($splitAsset['source_path'] ?? '')), 'The coalesced asset keeps one synthetic source identity from a contributing stylesheet: ' . (string) ($splitAsset['source_path'] ?? ''));
$assert($splitAsset['token'] === 'asset-' . substr(hash('sha256', (string) $splitAsset['target_path']), 0, 16), 'The coalesced token is the content-addressed target, so every page reference agrees.');
$assert($splitAsset['reconciliation_identity'] === WordPressSitePlan::identity('asset', (string) $splitAsset['source_path'], (string) $splitAsset['target_path']), 'The coalesced reconciliation identity matches the single emitted source and target.');
$splitTokens = array_values(array_filter($splitPlan['reference_tokens'] ?? array(), static fn(array $token): bool => ($token['target_path'] ?? null) === ($splitAsset['target_path'] ?? null)));
$assert(1 === count($splitTokens) && ($splitTokens[0]['token'] ?? null) === ($splitAsset['token'] ?? null) && ($splitTokens[0]['source_path'] ?? null) === ($splitAsset['source_path'] ?? null), 'Reference tokens declare the coalesced asset once, with the same token and source.');
$splitRemainder = implode("\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), $splitPlan['assets'] ?? array()));
$assert(str_contains($splitRemainder, '.home-only') && str_contains($splitRemainder, '.about-only'), 'Page-owned rules stay on their source stylesheets after the shared projection is coalesced.');
$splitWrites = array_values(array_filter($splitPlan['writes'] ?? array(), static fn(array $write): bool => ($write['target_path'] ?? null) === ($splitAsset['target_path'] ?? null)));
$assert(1 === count($splitWrites) && 1 === preg_match('/background-image:url\(\{\{wordpress-site-plan:asset:asset-[a-f0-9]{16}\}\}\)/', (string) ($splitWrites[0]['payload']['data'] ?? '')) && !str_contains((string) ($splitWrites[0]['payload']['data'] ?? ''), 'mark.png'), 'The single shared stylesheet still tokenizes url() against a contributing reference origin.');

$divergent = new ReflectionMethod(WordPressSitePlan::class, 'projectSharedChromeStylesheets');
$divergentClass = 'blocks-engine-control-abc123def456-1';
$divergentShared = '.' . $divergentClass . '{background-image:url(mark.png)}';
$divergentAsset = static function (string $path, string $extra) use ($divergentShared): array {
    $content = $divergentShared . $extra;
    return array('kind' => 'css', 'content' => $content, 'source_path' => $path, 'path' => $path, 'target_path' => 'assets/' . $path, 'source' => 'files', 'role' => 'stylesheet', 'mime_type' => 'text/css', 'stylesheet_target' => 'both', 'bytes' => strlen($content), 'hash' => hash('sha256', $content), 'content_hash' => hash('sha256', $content), 'token' => 'asset-0000000000000001', 'reconciliation_identity' => hash('sha256', $path));
};
$divergentProjected = $divergent->invoke(null, array(
    $divergentAsset('css/home.css', '.home-only{color:#111111}'),
    $divergentAsset('nested/about.css', '.about-only{color:#222222}'),
), array(array('placement' => array('kind' => 'shared_shell'), 'canonical_block_markup' => '<div class="' . $divergentClass . '"></div>')));
$divergentSharedAssets = array_values(array_filter($divergentProjected, static fn(array $asset): bool => str_contains((string) ($asset['target_path'] ?? ''), 'shared-chrome-')));
$assert(2 === count($divergentSharedAssets), 'Identical shared text whose relative url() resolves differently is not coalesced.');

$conflictChrome = '[data-chrome=grid]{display:grid;height:80px;background-image:url(mark.png)}';
$conflictHeader = '<header id="site-chrome" class="site-header" data-chrome="grid"><nav><a href="index.html">Home</a><a href="about.html">About</a></nav></header>';
$conflictDocument = static fn(string $main): string => '<!doctype html><html><head><link rel="stylesheet" href="css/home.css"><link rel="stylesheet" href="nested/about.css"></head><body><div id="site-root"><div id="masterPage">' . $conflictHeader . '<div id="PAGES_CONTAINER">' . $main . '</div></div></div></body></html>';
$conflictPng = base64_encode("\x89PNG\r\n\x1a\n");
$conflict = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => $conflictDocument('<main><h1>Home</h1></main>'),
        'about.html' => $conflictDocument('<main><h1>About</h1></main>'),
        'team.html' => $conflictDocument('<main><h1>Team</h1></main>'),
        'css/home.css' => array('path' => 'css/home.css', 'kind' => 'css', 'content' => $conflictChrome . '.home-only{color:#111111}'),
        'nested/about.css' => array('path' => 'nested/about.css', 'kind' => 'css', 'content' => $conflictChrome . '.about-only{color:#222222}'),
        'css/mark.png' => array('path' => 'css/mark.png', 'kind' => 'image', 'mime_type' => 'image/png', 'content_base64' => $conflictPng),
        'nested/mark.png' => array('path' => 'nested/mark.png', 'kind' => 'image', 'mime_type' => 'image/png', 'content_base64' => $conflictPng),
    ),
))->toArray();
$conflictMessage = (string) (array_values(array_filter($conflict['diagnostics'] ?? array(), static fn(array $diagnostic): bool => str_contains((string) ($diagnostic['message'] ?? ''), 'colliding asset targets')))[0]['message'] ?? '');
$assert('failed' === ($conflict['status'] ?? null) && str_contains($conflictMessage, 'shared-chrome-') && str_contains($conflictMessage, 'css/home') && str_contains($conflictMessage, 'nested/about') && strlen($conflictMessage) <= 256, 'Divergent shared-chrome url() origins still fail and name the target and both sources: ' . $conflictMessage);

$collidingPlan = $plan;
$collidingPlan['assets'][1]['target_path'] = $collidingPlan['assets'][0]['target_path'];
$collidingPlan['assets'][1]['token'] = $collidingPlan['assets'][0]['token'];
$collidingPlan['assets'][1]['reconciliation_identity'] = WordPressSitePlan::identity('asset', (string) $collidingPlan['assets'][1]['source_path'], (string) $collidingPlan['assets'][1]['target_path']);
$collidingPlan['reference_tokens'][1]['target_path'] = $collidingPlan['assets'][0]['target_path'];
$collidingPlan['reference_tokens'][1]['token'] = $collidingPlan['assets'][0]['token'];
$collidingPlan['reference_tokens'][1]['source_path'] = $collidingPlan['assets'][1]['source_path'];
$collisionMessage = '';
try {
    WordPressSitePlan::assertValid($collidingPlan);
} catch (InvalidArgumentException $exception) {
    $collisionMessage = $exception->getMessage();
}
$assert(str_contains($collisionMessage, 'colliding asset targets') && str_contains($collisionMessage, (string) $collidingPlan['assets'][0]['target_path']) && str_contains($collisionMessage, (string) $collidingPlan['assets'][0]['source_path']) && str_contains($collisionMessage, (string) $collidingPlan['assets'][1]['source_path']), 'A genuine asset-target collision names the target and both sources: ' . $collisionMessage);
$assert(strlen($collisionMessage) <= 256, 'The collision diagnostic stays bounded.');

fwrite(STDOUT, "shared-chrome-stylesheet-scope contract passed\n");
