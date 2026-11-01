<?php
declare(strict_types=1);

/**
 * An uncaptured local <link> in the head (icon, canonical, hreflang alternate,
 * preload, stylesheet) names nothing the plan can bind. It is omitted through
 * the existing omitted-link-declaration finding instead of failing the site
 * plan; the strict plan validator remains the integrity backstop.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$compile = static fn (string $head, array $extra = array()): array => ( new ArtifactCompiler() )->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><meta charset="utf-8"><title>Home</title>' . $head . '</head><body><main><h1>Kept heading</h1></main></body></html>',
) + $extra))->toArray();
$omissions = static fn (array $result): array => array_values(array_filter(
    $result['source_reports']['wordpress_site_plan']['diagnostics'] ?? array(),
    static fn (array $diagnostic): bool => 'wordpress_site_plan_omitted_link_declaration' === ($diagnostic['code'] ?? null)
));

foreach (array(
    'icon' => '<link rel="icon" href="/favicon.ico">',
    'canonical' => '<link rel="canonical" href="/about/">',
    'hreflang alternate' => '<link rel="alternate" hreflang="fr" href="/fr/">',
    'preload' => '<link rel="preload" as="font" href="/fonts/missing.woff2">',
    'stylesheet' => '<link rel="stylesheet" href="assets/missing.css">',
    'relative stylesheet with a head context id' => '<link rel="stylesheet" id="theme-css" href="missing.css">',
    'canonical with a head context id' => '<link rel="canonical" id="canon" href="/about/">',
) as $label => $link) {
    $result = $compile($link);
    $plan = $result['source_reports']['wordpress_site_plan'] ?? null;
    $assert(is_array($plan) && 'failed' !== $result['status'] && !isset($result['source_reports']['wordpress_site_plan_diagnostics']), "An unresolved {$label} link no longer fails the site plan.");
    WordPressSitePlan::assertValid($plan);
    $assert(array() === ($plan['pages'][0]['document_metadata']['links'] ?? null), "The unresolved {$label} link is not declared.");
    $assert(str_contains((string) $plan['pages'][0]['canonical_block_markup'], 'Kept heading'), "The page body survives the omitted {$label} link.");
    $findings = $omissions($result);
    $assert(1 === count($findings), "The omitted {$label} link is reported once.");
    $assert('warning' === $findings[0]['severity'] && 'index.html' === $findings[0]['source_path'] && 'unresolved_local_url' === $findings[0]['reason_code'], "The {$label} finding is a warning with page attribution and reason.");
    $assert(in_array('wordpress_site_plan_omitted_link_declaration', $plan['reporting']['diagnostic_codes'], true), "The {$label} finding is linked to plan reporting.");
}

// A captured stylesheet next to an unresolved one stays declared.
$mixed = $compile('<link rel="stylesheet" href="assets/site.css"><link rel="stylesheet" href="assets/missing.css">', array('assets/site.css' => 'body{color:#111}'));
$mixedLinks = $mixed['source_reports']['wordpress_site_plan']['pages'][0]['document_metadata']['links'] ?? array();
$assert(1 === count($mixedLinks) && str_starts_with((string) ($mixedLinks[0]['asset_reference'] ?? ''), WordPressSitePlan::TOKEN_PREFIX) && 1 === count($omissions($mixed)) && 'assets/missing.css' === $omissions($mixed)[0]['value'], 'A captured stylesheet stays declared while the uncaptured one is omitted.');

// The validator is still the backstop: a plan that does declare an unresolved
// local link is rejected.
$plan = $mixed['source_reports']['wordpress_site_plan'];
$plan['pages'][0]['document_metadata']['links'][] = array('order' => 1, 'placement' => 'head', 'rel' => 'icon', 'url' => '/favicon.ico');
$plan['plan_identity'] = WordPressSitePlan::planIdentity($plan);
$rejected = false;
try {
    WordPressSitePlan::assertValid($plan);
} catch ( InvalidArgumentException $error ) {
    $rejected = true;
}
$assert($rejected, 'The strict plan validator still rejects a declared unresolved local link.');

echo "unresolved-local-head-links contract passed\n";
