<?php
declare(strict_types=1);
require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
$document = static fn(string $head): string => '<html><head>' . $head . '</head><body><nav><ul class="nav"><li><a href="/">One</a></li></ul></nav></body></html>';
$link = static fn(string $file, string $media = ''): string => '<link rel="stylesheet" href="' . $file . '"' . ('' === $media ? '' : ' media="' . $media . '"') . '>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $document($link('a.css') . $link('b.css') . $link('a.css')),
    'reverse.html' => $document($link('b.css') . $link('a.css') . $link('b.css')),
    'conditions.html' => $document($link('a.css', '(max-width:700px)') . $link('b.css') . $link('a.css', '(min-width:1200px)')),
    'a.css' => '@layer named{.nav>li>a{color:red!important}}',
    'b.css' => '@layer named{.nav>li>a{color:blue!important}}',
)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$resources = array_values(array_filter($plan['assets'], static fn(array $asset): bool => in_array($asset['source_path'], array('a.css', 'b.css'), true)));
if (2 !== count($resources)) throw new RuntimeException('Stylesheet resources remain unique across occurrence and condition changes.');
$a = array_values(array_filter($resources, static fn(array $asset): bool => 'a.css' === $asset['source_path']))[0];
$conditions = array_values(array_filter($a['stylesheet_instances'] ?? array(), static fn(array $instance): bool => 'conditions.html' === $instance['source_path']));
if (array('(max-width:700px)', '(min-width:1200px)') !== array_column($conditions, 'media')) throw new RuntimeException('Each shared-resource occurrence retains its own media.');
foreach (array('index.html' => array('a.css', 'b.css', 'a.css'), 'reverse.html' => array('b.css', 'a.css', 'b.css')) as $source => $expected) {
    $sequence = array();
    foreach ($resources as $resource) foreach ($resource['stylesheet_instances'] ?? array() as $instance) if ($source === $instance['source_path']) $sequence[$instance['order']] = $resource['source_path'];
    ksort($sequence);
    if (array_values($sequence) !== $expected) throw new RuntimeException('A document retains repeated and conflicting stylesheet instance order.');
}
$bootstrap = (string) array_column($plan['writes'], null, 'target_path')['functions.php']['payload']['data'];
if (!preg_match('/if \( is_front_page\(\) \) \{\n((?:        [^\n]+\n)+)    \}/', $bootstrap, $block) || !preg_match_all("/wp_enqueue_style\\( '([^']+)', get_theme_file_uri\\( '([^']+)' \\)/", $block[1], $calls)) throw new RuntimeException('The front-page route publishes one ordered stylesheet block.');
if (array('a.css', 'b.css', 'a.css') !== array_map('basename', $calls[2]) || 3 !== count(array_unique($calls[1])) || $calls[2][0] !== $calls[2][2]) throw new RuntimeException('A,B,A publishes three distinct handles, with both A instances on one resource URI.');
// Hosts without the generated bootstrap replay the same per-document sequence.
$published = WordPressSitePlan::documentStylesheets($plan, 'index.html');
$authored = array_values(array_filter($published, static fn(array $row): bool => in_array(basename($row['target_path']), array('a.css', 'b.css'), true)));
if (array('a.css', 'b.css', 'a.css') !== array_map(static fn(array $row): string => basename($row['target_path']), $authored) || $calls[1] !== array_column($authored, 'handle')) throw new RuntimeException('documentStylesheets() replays the bootstrap route sequence and handles.');
$conditionRows = array_values(array_filter(WordPressSitePlan::documentStylesheets($plan, 'conditions.html'), static fn(array $row): bool => 'a.css' === basename($row['target_path'])));
if (array('(max-width:700px)', '(min-width:1200px)') !== array_column($conditionRows, 'media')) throw new RuntimeException('documentStylesheets() carries each occurrence media.');
if (2 !== count(array_filter($plan['writes'], static fn(array $write): bool => in_array(basename($write['target_path']), array('a.css', 'b.css'), true)))) throw new RuntimeException('Instances never duplicate resource writes.');
$invalid = $plan;
foreach ($invalid['assets'] as &$asset) if (!empty($asset['stylesheet_instances'])) { $asset['stylesheet_instances'][0]['source_path'] = 'absent.html'; break; }
unset($asset);
$invalid['plan_identity'] = WordPressSitePlan::planIdentity($invalid);
$rejected = false;
try { WordPressSitePlan::assertValid($invalid); } catch (InvalidArgumentException) { $rejected = true; }
if (!$rejected) throw new RuntimeException('An instance must identify a declared document.');
// Document positions are unique across resources, not only within one.
$collision = $plan;
$aIndex = array_search('a.css', array_column($collision['assets'], 'source_path'), true);
$bIndex = array_search('b.css', array_column($collision['assets'], 'source_path'), true);
$collision['assets'][$bIndex]['stylesheet_instances'][0] = $collision['assets'][$aIndex]['stylesheet_instances'][0];
$collision['plan_identity'] = WordPressSitePlan::planIdentity($collision);
$collisionMessage = '';
try { WordPressSitePlan::assertValid($collision); } catch (InvalidArgumentException $exception) { $collisionMessage = $exception->getMessage(); }
if ('Two stylesheet instances claim one document position.' !== $collisionMessage) throw new RuntimeException('Two resources cannot claim one document position: ' . $collisionMessage);
echo "WordPress stylesheet instances: unique resources, ordered routes, media and validation passed\n";
