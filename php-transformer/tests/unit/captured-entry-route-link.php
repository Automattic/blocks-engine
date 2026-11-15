<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Public compiler coverage: the deployment URL is not an artifact directory.
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'website/index.html',
    'provenance' => array('source_url' => 'https://source.example/portfolio/'),
    'files' => array(
        'website/index.html' => '<main><h1>Home</h1><a href="/portfolio">Home</a></main>',
        'website/about.html' => '<main><h1>About</h1><a href="https://source.example/portfolio/?ref=nav#top">Home</a><a href="/portfolio/">Root</a><a href="https://other.example/portfolio/">External</a><a href="/portfolio-extra">Other</a></main>',
    ),
))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$links = array();
foreach ($plan['pages'] as $page) {
    $dom = new DOMDocument();
    @$dom->loadHTML($page['canonical_block_markup']);
    foreach ($dom->getElementsByTagName('a') as $anchor) {
        $links[$page['source_path']][] = $anchor->getAttribute('href');
    }
}
if (array('/') !== ($links['website/index.html'] ?? null)
    || array('/?ref=nav#top', '/', 'https://other.example/portfolio/', '/portfolio-extra') !== ($links['website/about.html'] ?? null)) {
    throw new RuntimeException('Captured entry URLs must resolve to the entry route, preserving suffixes and foreign origins: ' . json_encode($links));
}
echo "Captured entry route link passed\n";
