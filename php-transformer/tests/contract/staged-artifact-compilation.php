<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$throws = static function (callable $callback, string $message) use ($assert): void { try { $callback(); } catch (InvalidArgumentException) { return; } $assert(false, $message); };
$normalized = static function (mixed $value) use (&$normalized): mixed { if (!is_array($value)) return $value; foreach ($value as $key => $item) { if (in_array($key, array('source_hash', 'provenance', 'input_keys', 'transform_duration_ms'), true)) { unset($value[$key]); continue; } $value[$key] = $normalized($item); } return $value; };
$artifact = array(
    'entrypoints' => array('index.html'),
    'files' => array(
        array('path' => 'assets/site.css', 'content' => 'main{color:#123}', 'metadata' => array('compilation' => array('scope' => 'shared'))),
        array('path' => 'about.html', 'content' => '<link rel="stylesheet" href="assets/site.css"><main><h1>About</h1></main>'),
        array('path' => 'contact.html', 'content' => '<link rel="stylesheet" href="assets/site.css"><main><h1>Contact</h1></main>'),
        array('path' => 'index.html', 'content' => '<link rel="stylesheet" href="assets/site.css"><main><h1>Home</h1></main>'),
    ),
);
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$assert(ArtifactCompiler::SHARED_PLAN_SCHEMA === $shared['schema'] && 1 === $shared['summary']['file_count'] && preg_match('/^[a-f0-9]{64}$/', $shared['digest']), 'Shared preparation emits a bounded immutable shared plan and digest.');
$pageIds = array('index.html', 'about.html', 'contact.html');
$pages = array();
foreach ($pageIds as $pageId) $pages[$pageId] = $compiler->preparePage($artifact, $shared, $pageId);
$assert(array('index.html', 'about.html', 'contact.html') === array_keys($pages), 'Three independent page plans are addressable by canonical page ownership ids.');

// Simulate interruption/resume and arbitrary parallel completion order.
$resumedShared = json_decode(json_encode($shared, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$resumedPages = json_decode(json_encode(array($pages['contact.html'], $pages['index.html'], $pages['about.html']), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$staged = $compiler->compose($resumedShared, $resumedPages)->toArray();
$whole = $compiler->compile($artifact)->toArray();
$assert($normalized($whole['source_reports']['wordpress_site_plan'] ?? array()) === $normalized($staged['source_reports']['wordpress_site_plan'] ?? array()), 'Whole and staged compilation yield byte-for-byte equivalent normalized canonical site plans.');
$assert($normalized($whole['source_reports']['materialization_plan'] ?? array()) === $normalized($staged['source_reports']['materialization_plan'] ?? array()), 'Whole and staged compilation yield byte-for-byte equivalent normalized materialization receipts.');

$differentShared = $shared;
$differentShared['artifact']['files'][0]['content'] = 'main{color:#456}';
$throws(static fn() => $compiler->compose($differentShared, $resumedPages), 'Composition rejects a serialized shared payload whose digest no longer matches.');
$wrongBinding = $resumedPages;
$wrongBinding[0]['shared_digest'] = str_repeat('0', 64);
$throws(static fn() => $compiler->compose($shared, $wrongBinding), 'Composition rejects a page plan bound to another shared digest.');

fwrite(STDOUT, "Staged artifact compilation contract passed\n");
