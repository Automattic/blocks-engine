<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) throw new RuntimeException($message);
};
$source = static function (string $id, string $urlTemplate, array $query, array $queryVariables, array $variableSchemas, array $resources, int $freshness = 3600, array $headers = array('Accept' => 'application/json')): array {
    return array(
        'schema' => 'generic/external-metric-source/v1', 'id' => $id, 'intent' => 'external_public_json',
        'request' => array('method' => 'GET', 'url_template' => $urlTemplate, 'query' => $query, 'query_variables' => $queryVariables, 'headers' => $headers, 'response_media_type' => 'application/json', 'max_response_bytes' => 1048576, 'timeout_seconds' => 5),
        'resource_variables' => $variableSchemas, 'resources' => $resources, 'freshness' => array('max_age_seconds' => $freshness),
    );
};
$fact = static function (string $id, array $source, string $metric, ?array $extraction, string $aggregation, string $fallback, array $format): array {
    $binding = '<!-- wp:paragraph --><p>' . htmlspecialchars($fallback, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p><!-- /wp:paragraph -->';
    $fact = array(
        'id' => $id,
        'source' => $source,
        'metric' => $metric,
        'aggregation' => $aggregation,
        'format' => $format,
        'provenance' => array('kind' => 'source_corroboration', 'repository' => 'ndiego/nickdiego.com', 'revision' => '5747c794bbbd0d2b2dfeb999210ab5d4f2e6a3fc', 'source_path' => 'src/components/wp-plugin-stat.tsx'),
        'fallback' => array('text' => $fallback, 'hash' => hash('sha256', $fallback)),
        'bindings' => array(array('schema' => 'generic/block-binding/v1', 'role' => 'paragraph', 'source_path' => 'projects.html', 'search_block_markup' => $binding, 'occurrence' => 1, 'leaf' => array('block' => 'core/paragraph', 'attribute' => 'content'))),
    );
    if (null !== $extraction) $fact['extraction'] = $extraction;
    return $fact;
};
$declaration = static function (array $facts): array {
    return array(array('kind' => 'entity_collection', 'type' => 'external_metrics', 'source_path' => 'projects.html', 'payload' => array('schema' => 'generic/external-metric/v1', 'entities' => $facts)));
};
$rejected = static function (array $candidate): bool {
    try { RuntimeDeclarations::normalizeList($candidate); return false; }
    catch (\InvalidArgumentException) { return true; }
};

$slugConstraint = array('location' => 'query', 'min_length' => 1, 'max_length' => 100, 'allowed_characters' => 'abcdefghijklmnopqrstuvwxyz0123456789-', 'prohibited_values' => array());
$pluginInformation = static fn(array $slugs): array => $source('wordpress.org.plugin-information', 'https://api.wordpress.org/plugins/info/1.2/', array('action' => 'plugin_information'), array('slug'), array('slug' => $slugConstraint), array_map(static fn(string $slug): array => array('slug' => $slug), $slugs));
$pluginDownloads = static fn(array $slugs): array => $source('wordpress.org.plugin-download-history', 'https://api.wordpress.org/stats/plugin/1.0/downloads.php', array('historical_summary' => 1), array('slug'), array('slug' => $slugConstraint), array_map(static fn(string $slug): array => array('slug' => $slug), $slugs));
$facts = array(
    $fact('plugin-count', $pluginInformation(array('block-visibility', 'icon-block')), 'plugin_response_count', null, 'success_count', '2', array('locale' => 'en-US', 'grouping' => true, 'prefix' => '', 'suffix' => '', 'decimals' => 0)),
    $fact('installs', $pluginInformation(array('block-visibility', 'icon-block')), 'active_installs', array('kind' => 'json_pointer', 'pointer' => '/active_installs', 'value_type' => 'nonnegative_integer'), 'sum', '10,000+', array('locale' => 'en-US', 'grouping' => true, 'prefix' => '', 'suffix' => '+', 'decimals' => 0)),
    $fact('downloads', $pluginDownloads(array('block-visibility')), 'downloads_all_time', array('kind' => 'json_pointer', 'pointer' => '/all_time', 'value_type' => 'nonnegative_integer'), 'sum', '20,000+', array('locale' => 'en-US', 'grouping' => true, 'prefix' => '', 'suffix' => '+', 'decimals' => 0)),
    $fact('version', $pluginInformation(array('block-visibility')), 'version', array('kind' => 'json_pointer', 'pointer' => '/version', 'value_type' => 'string', 'max_length' => 64), 'identity', 'v1.2.3', array('locale' => 'en-US', 'grouping' => false, 'prefix' => 'v', 'suffix' => '', 'decimals' => 0)),
    $fact('ratings', $pluginInformation(array('block-visibility')), 'num_ratings', array('kind' => 'json_pointer', 'pointer' => '/num_ratings', 'value_type' => 'nonnegative_integer'), 'identity', '42', array('locale' => 'en-US', 'grouping' => true, 'prefix' => '', 'suffix' => '', 'decimals' => 0)),
);
$normalized = RuntimeDeclarations::normalizeList($declaration($facts));
$assert(5 === count($normalized[0]['payload']['entities']), 'accepts source-proven multi-metric WordPress.org declarations');
$assert($normalized === RuntimeDeclarations::normalizeList($normalized), 'external metric identities and hashes round-trip canonically');
$alphaNumeric = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
$githubSource = $source('github.repository-information', 'https://api.github.com/repos/{owner}/{repository}', array(), array(), array(
    'owner' => array('location' => 'path', 'min_length' => 1, 'max_length' => 39, 'allowed_characters' => $alphaNumeric . '-', 'first_characters' => $alphaNumeric, 'last_characters' => $alphaNumeric, 'prohibited_values' => array()),
    'repository' => array('location' => 'path', 'min_length' => 1, 'max_length' => 100, 'allowed_characters' => $alphaNumeric . '._-', 'prohibited_values' => array('.', '..')),
), array(array('owner' => 'Automattic', 'repository' => '.github')), 86400, array('Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'));
$githubFact = $fact('repo-stars', $githubSource, 'stargazers_count', array('kind' => 'json_pointer', 'pointer' => '/stargazers_count', 'value_type' => 'nonnegative_integer'), 'identity', '7', array('locale' => 'en-US', 'grouping' => true, 'prefix' => '', 'suffix' => '', 'decimals' => 0));
$githubFact['provenance']['source_path'] = 'src/components/gh-repo-card.tsx';
$github = RuntimeDeclarations::normalizeList($declaration(array($githubFact)));
$assert('github.repository-information' === $github[0]['payload']['entities'][0]['source']['id'] && '.github' === $github[0]['payload']['entities'][0]['source']['resources'][0]['repository'], 'accepts source-backed Automattic/.github GitHub recipe with identity extraction');
$forkFact = $fact('repo-forks', $githubSource, 'forks_count', array('kind' => 'json_pointer', 'pointer' => '/forks_count', 'value_type' => 'nonnegative_integer'), 'identity', '9', array('locale' => 'en-US', 'grouping' => true, 'prefix' => '', 'suffix' => '', 'decimals' => 0));
$forks = RuntimeDeclarations::normalizeList($declaration(array($forkFact)));
$assert('forks_count' === $forks[0]['payload']['entities'][0]['metric'], 'accepts forks_count with top-level metric and declarative JSON Pointer extraction');
$neutralSource = $source('neutral.example-records', 'https://metrics.example.com/v1/records/{record}', array(), array(), array('record' => array('location' => 'path', 'min_length' => 1, 'max_length' => 64, 'allowed_characters' => $alphaNumeric . '-_', 'prohibited_values' => array('.', '..'))), array(array('record' => 'sample')), 600);
$neutralFact = $fact('neutral-score', $neutralSource, 'score', array('kind' => 'json_pointer', 'pointer' => '/measurements/score', 'value_type' => 'nonnegative_integer'), 'identity', '31', array('locale' => 'en-US', 'grouping' => false, 'prefix' => '', 'suffix' => '', 'decimals' => 0));
$neutralFact['provenance'] = array('kind' => 'operator_mapping', 'author' => 'operator:chubes4', 'source_relationship' => 'The captured score leaf is explicitly mapped to the configured neutral JSON source score value.');
$neutral = RuntimeDeclarations::normalizeList($declaration(array($neutralFact)));
$assert('neutral.example-records' === $neutral[0]['payload']['entities'][0]['source']['id'], 'accepts a third neutral JSON source recipe without a source-specific core enum');
foreach (array('_github', 'repo.', 'repo_', 'repo-') as $repository) {
    $bounded = $githubFact; $bounded['source']['resources'][0]['repository'] = $repository;
    $assert(!$rejected($declaration(array($bounded))), "accepts bounded GitHub repository character spelling {$repository}");
}
$badGithub = $githubFact; $badGithub['source']['resources'][0]['repository'] = '../.github';
$assert($rejected($declaration(array($badGithub))), 'rejects slash and dot-segment path resource values');
$bad = $neutralFact; $bad['source']['resource_variables']['record']['prohibited_values'] = array(); $bad['source']['resources'][0]['record'] = '..';
$assert($rejected($declaration(array($bad))), 'rejects path dot-segments even if a recipe omits them from its value constraints');
foreach (array('http://metrics.example.com/v1/{record}', 'https://user:secret@metrics.example.com/v1/{record}', 'https://metrics.example.com/v1/{missing}?url=x', 'https://metrics.example.com/v1/{record}/../private') as $url) {
    $badSource = $neutralSource; $badSource['request']['url_template'] = $url;
    $bad = $neutralFact; $bad['source'] = $badSource;
    $assert($rejected($declaration(array($bad))), 'rejects unsupported or unsafe HTTP/URL templates');
}
$badSource = $neutralSource; $badSource['request']['headers']['Authorization'] = 'Bearer secret';
$bad = $neutralFact; $bad['source'] = $badSource;
$assert($rejected($declaration(array($bad))), 'rejects credential-bearing declarative headers');
$badSource = $neutralSource; unset($badSource['request']['headers']['Accept']);
$bad = $neutralFact; $bad['source'] = $badSource;
$assert($rejected($declaration(array($bad))), 'requires an explicit JSON Accept header');
$badSource = $neutralSource; $badSource['freshness']['max_age_seconds'] = 2592001;
$bad = $neutralFact; $bad['source'] = $badSource;
$assert($rejected($declaration(array($bad))), 'rejects unbounded source freshness');
$bad = $neutralFact; $bad['extraction']['pointer'] = '/measurements/~2score';
$assert($rejected($declaration(array($bad))), 'rejects malformed JSON Pointer escapes');
$bad = $neutralFact; $bad['extraction']['value_type'] = 'object';
$assert($rejected($declaration(array($bad))), 'rejects unsupported JSON output types');
$bad = $neutralFact; $bad['aggregation'] = 'average';
$assert($rejected($declaration(array($bad))), 'rejects unsupported aggregation operators');
$bad = $neutralFact; $bad['provenance'] = array('kind' => 'captured_html');
$assert($rejected($declaration(array($bad))), 'rejects unsupported generic source provenance');
$bad = $neutralFact; $bad['format']['prefix'] = '<strong>';
$assert($rejected($declaration(array($bad))), 'rejects markup in generic format literals');
$bad = $neutralFact; $bad['provider'] = array('schema' => 'generic/external-metric-provider/v1', 'id' => 'github', 'owner' => 'Automattic', 'repository' => '.github'); unset($bad['source']);
$assert($rejected($declaration(array($bad))), 'rejects the retired provider-specific draft shape without a compatibility reader');
foreach (array('.', '..', 'Automattic/blocks-engine', 'https://github.com/Automattic/.github', 'repo?query', str_repeat('a', 101), '') as $repository) {
    $badGithub = $githubFact; $badGithub['source']['resources'][0]['repository'] = $repository;
    $assert($rejected($declaration(array($badGithub))), 'rejects malformed GitHub repository selector ' . $repository);
}
$badOwner = $githubFact; $badOwner['source']['resources'][0]['owner'] = '-Automattic';
$assert($rejected($declaration(array($badOwner))), 'rejects GitHub owner that violates its declarative edge constraints');
$duplicateResource = $neutralFact; $duplicateResource['source']['resources'][] = $duplicateResource['source']['resources'][0];
$assert($rejected($declaration(array($duplicateResource))), 'rejects duplicate generic resources that would double-count an identity');
foreach (array('-automattic', 'automattic-', str_repeat('a', 40)) as $owner) {
    $badGithub = $githubFact; $badGithub['source']['resources'][0]['owner'] = $owner;
    $assert($rejected($declaration(array($badGithub))), 'rejects malformed GitHub owner selector ' . $owner);
}
$genericSum = $githubFact; $genericSum['aggregation'] = 'sum';
$assert(!$rejected($declaration(array($genericSum))), 'accepts the generic sum operator independently of a source-specific metric enum');
$badGithub = $githubFact; $badGithub['format']['suffix'] = str_repeat('x', 17);
$assert($rejected($declaration(array($badGithub))), 'rejects overlong generic metric format literals');
$badGithub = $githubFact; $badGithub['provenance'] = array('kind' => 'captured_html');
$assert($rejected($declaration(array($badGithub))), 'rejects unsupported GitHub provenance');

$bad = $facts; $bad[1]['aggregation'] = 'average';
$assert($rejected($declaration($bad)), 'rejects unsupported aggregation operators');
$bad = $facts; $bad[0]['provenance'] = array('kind' => 'captured_html');
$assert($rejected($declaration($bad)), 'rejects capture-only claims of server-side provenance');
$bad = $facts; $bad[2]['source']['resources'][0]['slug'] = '../outside';
$assert($rejected($declaration($bad)), 'rejects unsafe source resource values');
$bad = $facts; $bad[3]['fallback']['hash'] = str_repeat('0', 64);
$assert($rejected($declaration($bad)), 'rejects stale captured fallback hashes');
$bad = $facts; $bad[4]['bindings'][0]['leaf']['block'] = 'core/html';
$assert($rejected($declaration($bad)), 'rejects opaque or unsupported native text leaves');

echo 'External metric declarations passed: ' . $assertions . " assertions\n";
