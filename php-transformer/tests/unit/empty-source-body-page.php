<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/*
 * A captured route whose declared body renders nothing (a provider withheld it
 * behind a login overlay the capture removed) compiles to empty block markup.
 * That is the faithful result, not a loss: the route becomes an empty-content
 * page under the shared template instead of failing the whole site. Documents
 * that do not prove an empty body still fail closed.
 */
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$shell = static fn(string $main): string => '<!doctype html><html><head><title>Site</title></head><body><header><nav><a href="index.html">Home</a> <a href="about.html">About</a> <a href="members.html">Members</a></nav></header><main>' . $main . '</main><footer><p>Footer note</p></footer></body></html>';
$emptyBody = '<!doctype html><html><head><title>Site</title><link rel="stylesheet" href="site.css"></head><body class="blocked" style="overflow:hidden"><!-- body start --><svg data-store style="display:none"><defs></defs></svg><div id="container" style="margin-top:0"><div id="root"><div id="site"></div></div></div></body></html>';
$files = array(
    'index.html' => $shell('<h1>Home</h1><p>Welcome.</p>'),
    'about.html' => $shell('<h1>About</h1><p>About us.</p>'),
    'members.html' => $emptyBody,
    'site.css' => 'header{padding:8px}footer{color:#333}',
);
$compile = static fn(array $files): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$identityErrors = static fn(array $result): array => array_values(array_filter($result['diagnostics'], static fn(array $diagnostic): bool => 'wordpress_site_plan_not_self_contained' === ($diagnostic['code'] ?? null)));

$result = $compile($files);
$assert(array() === $identityErrors($result), 'A page whose declared body renders nothing does not fail site-plan identity: ' . json_encode($identityErrors($result)));
$plan = $result['source_reports']['wordpress_site_plan'] ?? null;
$assert(is_array($plan), 'The site plan is assembled when one page has an empty source body.');
$pages = array_column($plan['pages'], null, 'source_path');
$assert(isset($pages['members.html']) && '' === $pages['members.html']['canonical_block_markup'] && true === ($pages['members.html']['empty_source_body'] ?? null), 'The empty-body route stays a page with empty content and carries its proof.');
$assert(in_array('members.html', array_column($plan['routes'], 'source_path'), true), 'The empty-body page keeps its route, so navigation to it still resolves.');
$assert(isset($pages['about.html']) && '' !== trim($pages['about.html']['canonical_block_markup']) && !isset($pages['about.html']['empty_source_body']), 'Pages with content are unaffected.');

// Bodies that could carry content keep failing closed when they compile to nothing.
foreach (array(
    'an unbuilt app shell' => '<!doctype html><html><head><script type="module" src="app.js"></script></head><body><div id="root"></div></body></html>',
    'a document without a declared body' => '<link rel="stylesheet" href="site.css">',
    'an empty file' => '',
) as $label => $html) {
    $failed = $compile(array_merge($files, array('members.html' => $html)));
    $paths = array_column($identityErrors($failed), 'source_path');
    $assert('failed' === $failed['status'] && in_array('members.html', $paths, true), ucfirst($label) . ' still fails closed with empty block markup.');
}

// The site-plan contract admits empty page content only with the proof.
$tampered = $result;
foreach ($tampered['source_reports']['compiled_site']['pages'] as &$page) {
    if ('about.html' === $page['source_path']) $page['block_markup'] = '';
}
unset($page);
try {
    (new \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan())->fromResult($tampered);
    $assert(false, 'A page with source content but empty compiled markup fails closed.');
} catch (\Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentIdentityException $exception) {
    $assert(array('about.html') === array_column($exception->diagnostics(), 'source_path'), 'Only the page that lost its content is reported.');
}

echo "Empty source body page passed\n";
