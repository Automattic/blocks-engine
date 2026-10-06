<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) throw new RuntimeException($message);
};
$fact = static function (string $id, string $source, string $metric, string $aggregation, array $slugs, string $fallback, array $format): array {
    $binding = '<!-- wp:paragraph --><p>' . htmlspecialchars($fallback, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p><!-- /wp:paragraph -->';
    return array(
        'id' => $id,
        'provider' => array('schema' => 'generic/external-metric-provider/v1', 'id' => 'wordpress.org', 'source' => $source, 'slugs' => $slugs),
        'metric' => $metric,
        'aggregation' => $aggregation,
        'format' => $format,
        'provenance' => array('kind' => 'source_corroboration', 'repository' => 'ndiego/nickdiego.com', 'revision' => '5747c794bbbd0d2b2dfeb999210ab5d4f2e6a3fc', 'source_path' => 'src/components/wp-plugin-stat.tsx'),
        'fallback' => array('text' => $fallback, 'hash' => hash('sha256', $fallback)),
        'bindings' => array(array('schema' => 'generic/block-binding/v1', 'role' => 'paragraph', 'source_path' => 'projects.html', 'search_block_markup' => $binding, 'occurrence' => 1, 'leaf' => array('block' => 'core/paragraph', 'attribute' => 'content'))),
    );
};
$declaration = static function (array $facts): array {
    return array(array('kind' => 'entity_collection', 'type' => 'external_metrics', 'source_path' => 'projects.html', 'payload' => array('schema' => 'generic/external-metric/v1', 'entities' => $facts)));
};
$rejected = static function (array $candidate): bool {
    try { RuntimeDeclarations::normalizeList($candidate); return false; }
    catch (\InvalidArgumentException) { return true; }
};

$facts = array(
    $fact('plugin-count', 'plugin_information', 'plugin_response_count', 'success_count', array('block-visibility', 'icon-block'), '2', array('locale' => 'en-US', 'grouping' => true, 'prefix' => '', 'suffix' => '', 'decimals' => 0)),
    $fact('installs', 'plugin_information', 'active_installs', 'sum', array('block-visibility', 'icon-block'), '10,000+', array('locale' => 'en-US', 'grouping' => true, 'prefix' => '', 'suffix' => '+', 'decimals' => 0)),
    $fact('downloads', 'plugin_download_history', 'downloads_all_time', 'sum', array('block-visibility'), '20,000+', array('locale' => 'en-US', 'grouping' => true, 'prefix' => '', 'suffix' => '+', 'decimals' => 0)),
    $fact('version', 'plugin_information', 'version', 'identity', array('block-visibility'), 'v1.2.3', array('locale' => 'en-US', 'grouping' => false, 'prefix' => 'v', 'suffix' => '', 'decimals' => 0)),
    $fact('ratings', 'plugin_information', 'num_ratings', 'identity', array('block-visibility'), '42', array('locale' => 'en-US', 'grouping' => true, 'prefix' => '', 'suffix' => '', 'decimals' => 0)),
);
$normalized = RuntimeDeclarations::normalizeList($declaration($facts));
$assert(5 === count($normalized[0]['payload']['entities']), 'accepts source-proven multi-metric WordPress.org declarations');
$assert($normalized === RuntimeDeclarations::normalizeList($normalized), 'external metric identities and hashes round-trip canonically');
$githubFact = $facts[0];
$githubFact['id'] = 'repo-stars';
$githubFact['provider'] = array('schema' => 'generic/external-metric-provider/v1', 'id' => 'github', 'owner' => 'Automattic', 'repository' => '.github');
$githubFact['metric'] = 'stargazers_count';
$githubFact['aggregation'] = 'identity';
$githubFact['format'] = array('locale' => 'en-US', 'grouping' => true, 'prefix' => '', 'suffix' => '', 'decimals' => 0);
$githubFact['provenance']['source_path'] = 'src/components/gh-repo-card.tsx';
$github = RuntimeDeclarations::normalizeList($declaration(array($githubFact)));
$assert('github' === $github[0]['payload']['entities'][0]['provider']['id'] && '.github' === $github[0]['payload']['entities'][0]['provider']['repository'], 'accepts the source-backed Automattic/.github repository with identity aggregation');
$forkFact = $githubFact; $forkFact['id'] = 'repo-forks'; $forkFact['metric'] = 'forks_count'; $forkFact['fallback'] = array('text' => '9', 'hash' => hash('sha256', '9')); $forkFact['bindings'][0]['search_block_markup'] = '<!-- wp:paragraph --><p>9</p><!-- /wp:paragraph -->';
$forks = RuntimeDeclarations::normalizeList($declaration(array($forkFact)));
$assert('forks_count' === $forks[0]['payload']['entities'][0]['metric'], 'accepts forks_count as the sole GitHub field selector');
foreach (array('_github', 'repo.', 'repo_', 'repo-') as $repository) {
    $bounded = $githubFact; $bounded['provider']['repository'] = $repository;
    $assert(!$rejected($declaration(array($bounded))), "accepts bounded GitHub repository character spelling {$repository}");
}
$badGithub = $githubFact; $badGithub['provider']['owner'] = '../Automattic';
$assert($rejected($declaration(array($badGithub))), 'rejects malformed GitHub owner selectors');
$badGithub = $githubFact; $badGithub['metric'] = 'private_token';
$assert($rejected($declaration(array($badGithub))), 'rejects unsupported GitHub metric fields');
$badGithub = $githubFact; $badGithub['provider']['field'] = 'stargazers_count';
$assert($rejected($declaration(array($badGithub))), 'rejects redundant provider.field rather than retaining an alias');
$badGithub = $githubFact; $badGithub['provider']['source'] = 'https://attacker.invalid/';
$assert($rejected($declaration(array($badGithub))), 'rejects an arbitrary GitHub provider source override');
$badGithub = $githubFact; $badGithub['provider']['endpoint'] = 'https://attacker.invalid/';
$assert($rejected($declaration(array($badGithub))), 'rejects an arbitrary GitHub endpoint override');
foreach (array('.', '..', 'Automattic/blocks-engine', 'https://github.com/Automattic/.github', 'repo?query', str_repeat('a', 101), '') as $repository) {
    $badGithub = $githubFact; $badGithub['provider']['repository'] = $repository;
    $assert($rejected($declaration(array($badGithub))), 'rejects malformed GitHub repository selector ' . $repository);
}
$badGithub = $githubFact; $badGithub['aggregation'] = 'sum';
$assert($rejected($declaration(array($badGithub))), 'rejects non-identity aggregation for individual repository counts');
$badGithub = $githubFact; $badGithub['format']['suffix'] = '+';
$assert($rejected($declaration(array($badGithub))), 'rejects non-plain GitHub count formatting');
$badGithub = $githubFact; $badGithub['provenance'] = array('kind' => 'captured_html');
$assert($rejected($declaration(array($badGithub))), 'rejects unsupported GitHub provenance');

$bad = $facts; $bad[0]['provider']['source'] = 'https://attacker.invalid/';
$assert($rejected($declaration($bad)), 'rejects arbitrary provider URLs');
$bad = $facts; $bad[1]['aggregation'] = 'average';
$assert($rejected($declaration($bad)), 'rejects unsupported aggregation operators');
$bad = $facts; $bad[0]['provenance'] = array('kind' => 'captured_html');
$assert($rejected($declaration($bad)), 'rejects capture-only claims of server-side provenance');
$bad = $facts; $bad[2]['provider']['slugs'] = array('../outside');
$assert($rejected($declaration($bad)), 'rejects unsafe entity selectors');
$bad = $facts; $bad[3]['fallback']['hash'] = str_repeat('0', 64);
$assert($rejected($declaration($bad)), 'rejects stale captured fallback hashes');
$bad = $facts; $bad[4]['bindings'][0]['leaf']['block'] = 'core/html';
$assert($rejected($declaration($bad)), 'rejects opaque or unsupported native text leaves');

echo 'External metric declarations passed: ' . $assertions . " assertions\n";
