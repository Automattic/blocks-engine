<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

/**
 * A whole-page candidate is a fixed-size projection of one canonical page, and
 * at most one may claim each page. The candidate set is therefore bounded by
 * the page set, not by a count of its own: a site with more event detail pages
 * than any fixed number still plans every candidate, whole and staged.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$eventPages = 120;
$files = array('index.html' => '<main><h1>Events</h1></main>');
for ($event = 0; $event < $eventPages; ++$event) {
    $ld = array('@context' => 'https://schema.org', '@type' => 'Event', 'name' => 'Event ' . $event, 'startDate' => '2032-05-01T19:00:00Z', 'endDate' => '2032-05-01T22:00:00Z');
    $files[sprintf('events/event-%03d.html', $event)] = '<script type="application/ld+json">' . json_encode($ld, JSON_THROW_ON_ERROR) . '</script><main><h1>Event ' . $event . '</h1></main>';
}
$artifact = array('entrypoint' => 'index.html', 'files' => $files);

$whole = (new ArtifactCompiler())->compile($artifact)->toArray();
$plan = $whole['source_reports']['wordpress_site_plan'] ?? null;
$assert(is_array($plan), 'A site with more than 100 event detail pages produces a site plan: ' . json_encode(array_column(array_filter($whole['diagnostics'], static fn (array $row): bool => 'error' === ($row['severity'] ?? null)), 'message')));
$assert('success' === $plan['quality']['status'] && 'success' === $whole['status'], 'Every candidate is planned without a finding: ' . json_encode($plan['quality']['status']));

$candidates = array();
foreach ($plan['pages'] as $page) {
    foreach ($page['whole_page_candidates'] ?? array() as $candidate) {
        $assert($page['source_path'] === $candidate['source_path'] && $page['route']['path'] === $candidate['source_route'] && $page['reconciliation_identity'] === $candidate['page_reconciliation_identity'], 'Each candidate claims its own canonical page.');
        $candidates[] = $candidate['source_path'];
    }
}
$assert($eventPages === count($candidates) && $eventPages === count(array_unique($candidates)), 'Every event detail page carries exactly one candidate: ' . count($candidates));
$assert(!isset(array_column($plan['pages'], null, 'source_path')['index.html']['whole_page_candidates']), 'The entrypoint carries no candidate.');
WordPressSitePlan::assertValid($plan);

$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$staged = $compiler->compose($shared, $compiler->compilePreparedPages($shared, $compiler->preparePages($artifact, $shared)))->toArray();
$assert($plan === ($staged['source_reports']['wordpress_site_plan'] ?? null), 'Whole and staged compilation produce the same plan.');

fwrite(STDOUT, "whole-page candidate count: ok\n");
