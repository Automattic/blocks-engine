<?php
declare(strict_types=1);

// Entries dated only on their listing cards are posts, dated from the card's
// visible text; a repeated datetime placeholder is ignored (#4167).

require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message, mixed $context = null): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n" . (null === $context ? '' : json_encode($context, JSON_UNESCAPED_SLASHES) . "\n")); exit(1); } };
$root = dirname(__DIR__) . '/fixtures/listing-card-dates-site';
$load = static function (string $dir, string $prefix = '') use (&$load): array {
    $files = array();
    foreach (scandir($dir) as $name) {
        if ('.' === $name || '..' === $name) continue;
        is_dir($dir . '/' . $name) ? $files += $load($dir . '/' . $name, $prefix . $name . '/') : $files[$prefix . $name] = (string) file_get_contents($dir . '/' . $name);
    }
    return $files;
};
$files = $load($root);
$plan = static function (array $files): array {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    return array_column((new WordPressSitePlan())->fromCompilerResult($result)['pages'], null, 'source_path');
};

$listing = $files['news/index.html'];
$cases = array(
    'index.html and absolute hrefs' => array($listing, array('alpha' => '2026-08-18T00:00:00Z', 'beta' => '2019-07-01T00:00:00Z', 'gamma' => '2020-03-03T00:00:00Z')),
    'trailing-slash and relative hrefs' => array(
        str_replace(array('/news/alpha/index.html', 'beta/', 'https://example.test/news/gamma/'), array('/news/alpha/', 'beta/index.html', 'gamma/'), $listing),
        array('alpha' => '2026-08-18T00:00:00Z', 'beta' => '2019-07-01T00:00:00Z', 'gamma' => '2020-03-03T00:00:00Z'),
    ),
    'unparseable visible date keeps no timestamp' => array(
        str_replace('March 3, 2020', 'sometime soon', $listing),
        array('alpha' => '2026-08-18T00:00:00Z', 'beta' => '2019-07-01T00:00:00Z', 'gamma' => null),
    ),
);
foreach ($cases as $name => [$html, $expected]) {
    $pages = $plan(array_merge($files, array('news/index.html' => $html)));
    $assert('page' === $pages['news/index.html']['post_type'], "{$name}: the listing stays a page", $pages['news/index.html']['content_decision'] ?? null);
    foreach ($expected as $slug => $timestamp) {
        $page = $pages["news/{$slug}/index.html"];
        $assert('post' === $page['post_type'], "{$name}: {$slug} is a post", $page['content_decision'] ?? null);
        $assert(in_array('listing:card-date', array_column($page['content_decision']['evidence'], 'source'), true), "{$name}: {$slug} cites card evidence", $page['content_decision']);
        $assert($timestamp === ($page['publication_timestamp'] ?? null), "{$name}: {$slug} timestamp", $page['publication_timestamp'] ?? null);
    }
}

$bare = $files;
$bare['news/index.html'] = '<main><h1>News</h1><a href="/news/alpha/">Alpha</a><a href="/news/beta/">Beta</a></main>';
foreach ($plan($bare) as $path => $page) $assert('page' === $page['post_type'], "no card evidence: {$path} is unchanged", $page['content_decision'] ?? null);

// An unmarked builder date needs a varying source slot across sibling routes.
foreach (array(
    'id slot' => array('id="d1"', array('Friday, February 13, 2026', 'Saturday, February 14, 2026'), true),
    'builder slot' => array('data-element-id="d1"', array('Friday, February 13, 2026', 'Saturday, February 14, 2026'), true),
    'static chrome' => array('id="d1"', array('Friday, February 13, 2026', 'Friday, February 13, 2026'), false),
    'single date' => array('id="d1"', array('Friday, February 13, 2026', ''), false),
) as $name => [$identity, $dates, $inferred]) {
    $source = array('index.html' => '<main><a href="/blog/">Blog</a></main>', 'blog/index.html' => '<main><h1>Blog</h1><a href="/blog/alpha/">Alpha</a><a href="/blog/beta/">Beta</a></main>');
    foreach (array('alpha', 'beta') as $index => $slug) $source["blog/{$slug}/index.html"] = '<main><h1>' . $slug . '</h1><div ' . $identity . '>' . $dates[$index] . '</div><p>Article body.</p></main>';
    $pages = $plan($source);
    $assert('page' === $pages['blog/index.html']['post_type'], "{$name}: listing stays a page");
    foreach (array('alpha' => '2026-02-13T00:00:00Z', 'beta' => '2026-02-14T00:00:00Z') as $slug => $timestamp) {
        $page = $pages["blog/{$slug}/index.html"];
        $decision = $page['content_decision'];
        $assert(($inferred ? 'post' : 'page') === $page['post_type'] && ($inferred ? 'inferred' : 'defaulted') === $decision['state'], "{$name}: {$slug} decision", $decision);
        $assert(($inferred ? $timestamp : null) === ($page['publication_timestamp'] ?? null), "{$name}: {$slug} date");
        $assert(($inferred ? array('html:visible-date', 'listing:parent') : array()) === array_column($decision['evidence'], 'source'), "{$name}: {$slug} evidence", $decision);
    }
}

// A single article's datetime is the same sibling-slot primitive (#5794).
$articleFiles = $load(dirname(__DIR__) . '/fixtures/sibling-article-times-site');
foreach (array(
    'reduced page' => array('visible', true),
    'ISO visible text' => array('iso', true),
    'repeated instant' => array('repeated', false),
    'equivalent offsets' => array('offsets', false),
    'single route' => array('single', false),
    'outside article' => array('outside', false),
    'multiple articles' => array('articles', false),
    'multiple times' => array('times', false),
    'invalid datetime' => array('invalid', false),
) as $name => [$variant, $inferred]) {
    $source = $articleFiles;
    foreach (array('first-story', 'second-story', 'third-story') as $index => $slug) {
        $path = "news/{$slug}/index.html";
        $instant = '2026-11-11T15:30:' . (11 + $index) . '+00:00';
        if ('single' === $variant && $index > 0) { unset($source[$path]); continue; }
        $html = $source[$path];
        if ('iso' === $variant) $html = str_replace('November 11, 2026', $instant, $html);
        if ('repeated' === $variant) $html = str_replace($instant, '2026-11-11T15:30:11+00:00', $html);
        if ('offsets' === $variant) $html = str_replace($instant, 0 === $index ? '2026-11-11T15:30:11Z' : '2026-11-11T16:30:11+01:00', $html);
        if ('outside' === $variant) $html = str_replace(array('<article>', '</article>'), array('<section>', '</section>'), $html);
        if ('articles' === $variant) $html = str_replace('</main>', '<article>Another article.</article></main>', $html);
        if ('times' === $variant) $html = str_replace('</article>', '<time datetime="2026-01-01">Updated</time></article>', $html);
        if ('invalid' === $variant) $html = str_replace($instant, 'not-a-date', $html);
        $source[$path] = $html;
    }
    $pages = $plan($source);
    foreach ($source as $path => $html) {
        $page = $pages[$path];
        $isPost = $inferred && 'index.html' !== $path;
        $assert(($isPost ? 'post' : 'page') === $page['post_type'], "{$name}: {$path} type", $page['content_decision']);
        if (!$isPost) {
            $assert(!isset($page['publication_timestamp']), "{$name}: {$path} has no publication instant", $page);
            continue;
        }
        preg_match('/datetime="([^"]+)"/', $html, $match);
        $expected = str_replace('+00:00', 'Z', $match[1]);
        $assert($expected === ($page['publication_timestamp'] ?? null), "{$name}: {$path} full instant", $page);
        $assert(array('html:visible-date', 'listing:parent') === array_column($page['content_decision']['evidence'], 'source'), "{$name}: {$path} sibling evidence", $page['content_decision']);
    }
}

fwrite(STDOUT, "listing card date evidence tests: passed\n");
