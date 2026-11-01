<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\AuthorProjection;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$documents = array(
    array('source_path' => 'index.html', 'html' => '<h1>Home</h1>'),
    array('source_path' => 'author/adam/index.html', 'html' => '<h1>Author: <span>Adam Zieliński</span></h1><a href="/first/">First</a><a href="/second/">Second</a>'),
    array('source_path' => 'first.html', 'html' => '<h1>First</h1><a href="/author/adam/">Adam Zieliński</a>', 'metadata' => array('post_type' => 'post')),
    array('source_path' => 'second.html', 'html' => '<h1>Second</h1><a href="/author/adam/">Adam Zieliński</a>', 'metadata' => array('post_type' => 'post')),
);
$routes = array(
    array('source_path' => 'index.html', 'target_path' => '/'),
    array('source_path' => 'author/adam/index.html', 'target_path' => '/author/adam'),
    array('source_path' => 'first.html', 'target_path' => '/first'),
    array('source_path' => 'second.html', 'target_path' => '/second'),
);
$project = static fn(array $docs, array $map = array()): array => AuthorProjection::project($docs, $map ?: $routes, 'https://example.test:443');
$proven = $project($documents);
$entity = $proven['entities'][0];
$assert(1 === count($proven['entities']) && 'author' === $entity['kind'] && 'Adam Zieliński' === $entity['name'] && 'adam' === $entity['slug'], 'A prefixed heading names the author exactly.');
$assert(array('first.html', 'second.html') === $entity['membership_source_paths'] && array('post_type' => 'post', 'author' => 'adam') === $entity['archive']['query'], 'The consumer contract names sorted post sources and an author query.');
$single = $documents;
unset($single[3]);
$assert(array('first.html') === $project($single)['entities'][0]['membership_source_paths'], 'One reciprocal member proves an author.');
$mismatch = $documents;
foreach (array(2, 3) as $index) $mismatch[$index]['html'] = str_replace('Adam Zieliński', 'Someone else', $mismatch[$index]['html']);
$unproven = $project($mismatch);
$assert(array() === $unproven['entities'] && in_array('wordpress_site_plan_author_archive_unproven', array_column($unproven['diagnostics'], 'code'), true), 'Mismatched labels remain unproven.');
$duplicate = $documents;
$duplicate[] = array_merge($documents[1], array('source_path' => 'duplicate.html'));
$duplicateRoutes = array_merge($routes, array(array('source_path' => 'duplicate.html', 'target_path' => '/author/adam')));
$dupe = $project($duplicate, $duplicateRoutes);
$assert(array() === $dupe['entities'] && in_array('wordpress_site_plan_author_archive_ambiguous_slug', array_column($dupe['diagnostics'], 'code'), true), 'Duplicate archive captures do not guess a nicename owner.');
$conflict = $documents;
$conflict[] = array('source_path' => 'other.html', 'html' => '<h1>Other</h1><a href="/first">First</a>');
$conflict[2]['html'] .= '<a href="/author/other">Other</a>';
$conflictRoutes = array_merge($routes, array(array('source_path' => 'other.html', 'target_path' => '/author/other')));
$ambiguous = $project($conflict, $conflictRoutes);
$assert(array() === $ambiguous['entities'] && in_array('wordpress_site_plan_author_membership_ambiguous', array_column($ambiguous['diagnostics'], 'code'), true), 'Two reciprocal author claims withhold both projections, not just an arbitrary membership.');
$unsupported = $single;
$unsupportedRoutes = $routes;
$unsupportedRoutes[1]['target_path'] = '/author/' . str_repeat('a', 51);
$assert(array() === $project($unsupported, $unsupportedRoutes)['entities'], 'Overlong nicenames cannot become users.');
$external = $single;
$external[2]['html'] = '<a href="https://elsewhere.test/author/adam/">Adam Zieliński</a>';
$assert(array() === $project($external)['entities'], 'External bylines cannot prove local membership.');
$aliases = $single;
$aliases[1]['html'] = str_replace('/first/', '/first.html', $aliases[1]['html']);
$aliases[2]['html'] = str_replace('/author/adam/', '/author/adam/index.html', $aliases[2]['html']);
$assert(1 === count($project($aliases)['entities']), 'Source-file aliases use the shared reciprocal proof.');

// Exercise the entire category presentation path, including single-card archives.
foreach (array(1, 2) as $postCount) {
    $fixture = require dirname(__DIR__) . '/fixtures/taxonomy/corroborated-category.php';
    $fixture['files'][0]['metadata']['route_path'] = '/author/adam';
    $fixture['files'][0]['content'] = str_replace('<h1>Field Notes</h1>', '<h1>Author: <span>Adam Zieliński</span></h1>', $fixture['files'][0]['content']);
    foreach (array(1, 2) as $index) $fixture['files'][$index]['content'] = str_replace(array('/journal/category/field-notes', '>Field Notes</a>'), array('/author/adam', '>Adam Zieliński</a>'), $fixture['files'][$index]['content']);
    if (1 === $postCount) {
        unset($fixture['files'][2]);
        $fixture['files'][0]['content'] = str_replace('<article><h2><a href="/stories/second">Second story</a></h2><p>Second summary.</p></article>', '', $fixture['files'][0]['content']);
    }
    $plan = (new ArtifactCompiler())->compile($fixture)->toArray()['source_reports']['wordpress_site_plan'];
    $assert(1 === count($plan['author_entities']) && array() === $plan['taxonomy_entities'], 'Full compilation emits only the author entity.');
    $author = $plan['author_entities'][0];
    $templates = array_column($plan['templates'], null, 'slug');
    $assert(isset($templates['author'], $templates['author-adam']) && $templates['author-adam']['canonical_block_markup'] === $author['archive']['presentation_markup'] && str_contains($author['archive']['presentation_markup'], '"inherit":true'), 'Author presentation becomes a source-owned native author template.');
    WordPressSitePlan::assertValid($plan);
    $bootstrap = array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data'];
    $assert(!str_contains($bootstrap, 'index.php?author') && !str_contains($bootstrap, "add_rewrite_rule( '^author/"), 'Author archives rely on core rewrites, not a custom route.');
    $unprovenFixture = $fixture;
    foreach (array(1, 2) as $index) if (isset($unprovenFixture['files'][$index])) $unprovenFixture['files'][$index]['content'] = str_replace('Adam Zieliński</a>', 'Wrong name</a>', $unprovenFixture['files'][$index]['content']);
    $unprovenPlan = (new ArtifactCompiler())->compile($unprovenFixture)->toArray()['source_reports']['wordpress_site_plan'];
    $assert(array() === $unprovenPlan['author_entities'] && in_array('archives/field-notes.html', array_column($unprovenPlan['pages'], 'source_path'), true) && in_array('wordpress_site_plan_author_archive_unproven', array_column($unprovenPlan['diagnostics'], 'code'), true), 'Unproven author captures remain ordinary documents.');
    if (2 === $postCount) {
        $duplicateFixture = $fixture;
        $duplicateFixture['files'][] = array_merge($fixture['files'][0], array('path' => 'archives/duplicate.html'));
        $duplicatePlan = (new ArtifactCompiler())->compile($duplicateFixture)->toArray()['source_reports']['wordpress_site_plan'];
        $assert(array() === $duplicatePlan['author_entities'] && 2 === count(array_filter($duplicatePlan['diagnostics'], static fn(array $diagnostic): bool => 'wordpress_site_plan_author_archive_ambiguous_slug' === $diagnostic['code'])), 'Full compilation diagnoses duplicate author slug claims rather than failing route validation or picking one.');

        $twoAuthors = $fixture;
        $twoAuthors['files'][] = array('path' => 'archives/other.html', 'content' => '<main><h1>Other</h1><article><h2><a href="/stories/first">First story</a></h2><p>First summary.</p></article></main>', 'metadata' => array('route_path' => '/author/other'));
        $twoAuthors['files'][1]['content'] = str_replace('</article>', '<a href="/author/other">Other</a></article>', $twoAuthors['files'][1]['content']);
        $twoAuthorsPlan = (new ArtifactCompiler())->compile($twoAuthors)->toArray()['source_reports']['wordpress_site_plan'];
        $assert(array() === $twoAuthorsPlan['author_entities'] && in_array('wordpress_site_plan_author_membership_ambiguous', array_column($twoAuthorsPlan['diagnostics'], 'code'), true), 'Full compilation withholds conflicting author ownership.');

        $paginated = $fixture;
        $paginated['files'][] = array('path' => 'archives/page-two.html', 'content' => '<main><h1>Author: <span>Adam Zieliński</span></h1><article><h2><a href="/stories/third">Third story</a></h2><p>Third summary.</p></article></main>', 'metadata' => array('route_path' => '/author/adam/page/2'));
        $paginated['files'][] = array('path' => 'stories/third.html', 'content' => '<article><h1>Third story</h1><p>Third full story.</p><a href="/author/adam">Adam Zieliński</a></article>', 'metadata' => array('post_type' => 'post'));
        $paginatedPlan = (new ArtifactCompiler())->compile($paginated)->toArray()['source_reports']['wordpress_site_plan'];
        $assert(array('stories/first.html', 'stories/second.html', 'stories/third.html') === $paginatedPlan['author_entities'][0]['membership_source_paths'] && !in_array('archives/page-two.html', array_column($paginatedPlan['pages'], 'source_path'), true), 'Pagination contributes reciprocal members without becoming another page.');
    }
    $mutations = array(
        static function (array &$invalid): void { $invalid['author_entities'][0]['archive']['query']['author'] = 'wrong'; },
        static function (array &$invalid): void { $invalid['author_entities'][0]['slug'] = str_repeat('a', 51); },
        static function (array &$invalid): void { $invalid['author_entities'][0]['membership_source_paths'][] = 'index.html'; },
        static function (array &$invalid): void { $invalid['author_entities'][0]['membership_source_paths'][] = 'stories/first.html'; },
        static function (array &$invalid): void { $invalid['author_entities'] = array_fill(0, 257, $invalid['author_entities'][0]); },
        static function (array &$invalid): void { $invalid['author_entities'][0]['archive']['source_route'] = '/author/wrong'; },
    );
    foreach ($mutations as $mutate) {
        $invalid = $plan;
        $mutate($invalid);
        try {
            WordPressSitePlan::assertValid($invalid);
            throw new RuntimeException('Invalid author contract accepted.');
        } catch (InvalidArgumentException $expected) {
        }
    }
}
echo "author-archives-project-entities: passed\n";
