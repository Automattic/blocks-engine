<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result = ( new ArtifactCompiler() )->compile(array(
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<!doctype html><html><head><style>.red{color:red}</style><link rel="stylesheet" href="a.css"><style>.hero p{color:green}</style><link rel="stylesheet" href="b.css"><link rel="stylesheet" href="a.css"></head><body><a class="cta" href="/go" style="padding:1px;background:#000">Go</a><div class="hero"><p>Copy</p></div></body></html>' ),
        array( 'path' => 'a.css', 'kind' => 'css', 'content' => 'a.cta:hover{padding:1rem}' ),
        array( 'path' => 'b.css', 'kind' => 'css', 'content' => '[href="/go"]{color:blue}' ),
        array( 'path' => 'a.occurrence-2.css', 'kind' => 'css', 'content' => '.authored-collision{color:purple}' ),
    ),
) )->toArray();

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};
$assets = $result['assets'] ?? array();
$assert(array( 'index.inline-1.css', 'a.css', 'index.inline-2.css', 'b.css', 'a.occurrence-2-generated-1.css', 'a.occurrence-2.css' ) === array_column($assets, 'path'), 'allocated repeated-link alias avoids authored path collisions while preserving source occurrence order');
foreach ( $assets as $asset ) {
    $content = (string) ($asset['content'] ?? '');
    $hash = hash('sha256', $content);
    $assert(strlen($content) === ($asset['bytes'] ?? null) && $hash === ($asset['hash'] ?? null), 'rewritten asset bytes and hashes describe emitted content');
}
$planAssets = $result['source_reports']['materialization_plan']['assets'] ?? array();
foreach ( $planAssets as $asset ) {
    $content = (string) ($asset['content'] ?? '');
    $hash = hash('sha256', $content);
    $assert(strlen($content) === ($asset['bytes'] ?? null) && $hash === ($asset['hash'] ?? null), 'materialization plan payload hashes describe rewritten content');
}
$assert(hash('sha256', 'a.cta:hover{padding:1rem}') === ($assets[1]['source_hash'] ?? null) && ($assets[1]['hash'] ?? '') !== ($assets[1]['source_hash'] ?? ''), 'source hash retains linked pre-projection provenance');
$assert(! str_contains((string) ($assets[1]['content'] ?? ''), 'a.cta:hover') && str_contains((string) ($assets[1]['content'] ?? ''), '> :where(.wp-block-button__link):hover'), 'linked button CSS is rewritten in place');
$assert(hash('sha256', '.hero p{color:green}') === ($assets[2]['source_hash'] ?? null) && ! str_contains((string) ($assets[2]['content'] ?? ''), '.hero p') && str_contains((string) ($assets[2]['content'] ?? ''), ':where(.blocks-engine-source-p-'), 'inline CSS is rewritten in place with original source provenance');
$assert(str_contains((string) ($assets[4]['content'] ?? ''), '> :where(.wp-block-button__link):hover') && '.authored-collision{color:purple}' === ($assets[5]['content'] ?? ''), 'allocated occurrence alias is referenced while authored collision CSS remains a deterministic orphan asset');

$types = ( new ArtifactCompiler() )->compile(array(
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<style type="TEXT/CSS; charset=UTF-8">.style-ok{color:red}</style><style type="text/css-not-a-mime">.style-bad{color:red}</style><link rel="stylesheet" href="ok.css" type="text/css; charset=utf-8"><link rel="stylesheet" href="bad.css" type="text/css-not-a-mime">' ),
        array( 'path' => 'ok.css', 'kind' => 'css', 'content' => '.link-ok{color:green}' ),
        array( 'path' => 'bad.css', 'kind' => 'css', 'content' => '.link-bad{color:blue}' ),
    ),
) )->toArray();
$typeAssets = $types['assets'] ?? array();
$typeContents = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $typeAssets));
$assert(str_contains($typeContents, '.style-ok{color:red}') && str_contains($typeContents, '.link-ok{color:green}') && ! str_contains($typeContents, '.style-bad{color:red}') && ! str_contains($typeContents, '.link-bad{color:blue}'), 'CSS MIME parsing accepts case-insensitive text/css parameters and rejects non-MIME prefixes for style and link occurrences');

if ( $failures > 0 ) {
    exit(1);
}
fwrite(STDOUT, "Artifact author stylesheet projection unit tests passed\n");
