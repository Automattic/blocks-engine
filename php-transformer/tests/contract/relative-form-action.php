<?php
declare(strict_types=1);

/**
 * A relative <form action> (submit.php) names no artifact route. It is pointed
 * at the absolute source URL when the artifact records one and neutralized
 * otherwise, with an unresolved-navigation-link finding, instead of failing the
 * site plan. The strict validator remains the integrity backstop.
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
$form = static fn (string $action): string => '<main><h1>Contact</h1><form action="' . $action . '" method="post"><label for="e">Email</label><input id="e" name="email" type="email"><button type="submit">Send</button></form></main>';
$compile = static fn (string $action, array $extra = array()): array => ( new ArtifactCompiler() )->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $form($action))) + $extra)->toArray();
$findings = static fn (array $result): array => array_values(array_filter(
    $result['source_reports']['wordpress_site_plan']['diagnostics'] ?? array(),
    static fn (array $diagnostic): bool => 'wordpress_site_plan_unresolved_navigation_link' === ($diagnostic['code'] ?? null)
));

$sourced = $compile('submit.php', array('provenance' => array('source_url' => 'https://example.test/contact/')));
$sourcedPlan = $sourced['source_reports']['wordpress_site_plan'] ?? null;
$assert(is_array($sourcedPlan) && 'failed' !== $sourced['status'] && !isset($sourced['source_reports']['wordpress_site_plan_diagnostics']), 'A relative form action no longer fails the site plan.');
WordPressSitePlan::assertValid($sourcedPlan);
$markup = (string) $sourcedPlan['pages'][0]['canonical_block_markup'];
$assert(str_contains($markup, 'action="https://example.test/contact/submit.php"') && !str_contains($markup, 'action="submit.php"'), 'With a recorded source URL the form action points at the absolute source URL.');
$sourcedFindings = $findings($sourced);
$assert(1 === count($sourcedFindings) && 'warning' === $sourcedFindings[0]['severity'] && 'index.html' === $sourcedFindings[0]['source_path'] && 'submit.php' === $sourcedFindings[0]['value'] && 'source_url' === $sourcedFindings[0]['resolution'] && 'https://example.test/contact/submit.php' === $sourcedFindings[0]['resolved_url'], 'The finding records the page, the original action and the source URL.');
$assert(in_array('wordpress_site_plan_unresolved_navigation_link', $sourcedPlan['reporting']['diagnostic_codes'], true), 'The finding is linked to plan reporting.');

$local = $compile('submit.php');
$localPlan = $local['source_reports']['wordpress_site_plan'] ?? null;
$assert(is_array($localPlan) && 'failed' !== $local['status'], 'A relative form action without a source URL still produces a plan.');
WordPressSitePlan::assertValid($localPlan);
$localMarkup = (string) $localPlan['pages'][0]['canonical_block_markup'];
$localFindings = $findings($local);
$assert(str_contains($localMarkup, 'action="#"') && !str_contains($localMarkup, 'submit.php') && 1 === count($localFindings) && 'neutralized' === $localFindings[0]['resolution'] && !isset($localFindings[0]['resolved_url']), 'Without a source URL the form action is neutralized and reported.');

foreach (array('/submit', 'https://forms.example.test/post') as $action) {
    $untouched = $compile($action);
    $assert(isset($untouched['source_reports']['wordpress_site_plan']) && array() === $findings($untouched) && str_contains((string) $untouched['source_reports']['wordpress_site_plan']['pages'][0]['canonical_block_markup'], 'action="' . $action . '"'), "A form action of {$action} is already resolved and is left alone.");
}

// The validator is still the backstop for a plan that does carry the reference.
$plan = $localPlan;
$plan['pages'][0]['canonical_block_markup'] .= '<form action="submit.php"><button>Back</button></form>';
$plan['pages'][0]['content_hash'] = WordPressSitePlan::contentHash($plan['pages'][0]['canonical_block_markup']);
$rejected = false;
try {
    WordPressSitePlan::assertValid($plan);
} catch ( InvalidArgumentException $error ) {
    $rejected = true;
}
$assert($rejected, 'The strict plan validator still rejects an unresolved local form action.');

echo "relative-form-action contract passed\n";
