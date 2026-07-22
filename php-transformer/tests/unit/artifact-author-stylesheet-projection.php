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

$richText = ( new ArtifactCompiler() )->compile(array(
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<!doctype html><html><head><link rel="stylesheet" href="a.css"><link rel="stylesheet" href="b.css"></head><body><p><span class="quote-mark">&quot;</span>Testimonial</p></body></html>' ),
        array( 'path' => 'a.css', 'kind' => 'css', 'content' => '.quote-mark{color:#e8a020}' ),
        array( 'path' => 'b.css', 'kind' => 'css', 'content' => 'p{margin:0}' ),
    ),
) )->toArray();
$richTextAssets = $richText['assets'] ?? array();
$assert(str_starts_with((string) ($richTextAssets[0]['content'] ?? ''), ':where(mark)[style*="--blocks-engine-richtext-marker:"]{background-color:transparent;color:inherit}') && str_contains((string) ($richTextAssets[0]['content'] ?? ''), '{color:#e8a020}') && ! str_contains((string) ($richTextAssets[1]['content'] ?? ''), 'background-color:transparent;color:inherit'), 'artifact projection emits one marker reset before the first projected author stylesheet');

$types = ( new ArtifactCompiler() )->compile(array(
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<style type="TEXT/CSS; charset=UTF-8">.style-ok{color:red}</style><style type="text/css-not-a-mime">.style-bad{color:red}</style><link rel="stylesheet" href="ok.css" type="text/css; charset=utf-8"><link rel="stylesheet" href="bad.css" type="text/css-not-a-mime"><main><p>Types</p></main>' ),
        array( 'path' => 'ok.css', 'kind' => 'css', 'content' => '.link-ok{color:green}' ),
        array( 'path' => 'bad.css', 'kind' => 'css', 'content' => '.link-bad{color:blue}' ),
    ),
) )->toArray();
$typeAssets = $types['assets'] ?? array();
$typeContents = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $typeAssets));
$assert(str_contains($typeContents, '.style-ok{color:red}') && str_contains($typeContents, '.link-ok{color:green}') && ! str_contains($typeContents, '.style-bad{color:red}') && ! str_contains($typeContents, '.link-bad{color:blue}'), 'CSS MIME parsing accepts case-insensitive text/css parameters and rejects non-MIME prefixes for style and link occurrences');

$image = ( new ArtifactCompiler() )->compile(array(
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="image.css"><img class="root-photo" src="photo.jpg" alt="Root photo"><main><img class="photo relative-photo" src="photo.jpg" alt="Photo"></main>' ),
        array( 'path' => 'image.css', 'kind' => 'css', 'content_base64' => base64_encode('img{display:block;max-width:100%}.photo{position:absolute;width:123px;height:106px;object-fit:cover}img.photo{display:block}.relative-photo{width:86.356%;height:auto;aspect-ratio:727.431 / 593.583}body>.root-photo{height:80px}') ),
        array( 'path' => 'photo.jpg', 'kind' => 'image', 'content' => 'image-bytes' ),
    ),
) )->toArray();
$imageCss = (string) (($image['assets'][0]['content'] ?? ''));
$assert(str_contains($imageCss, '.photo{position:absolute;width:123px;height:106px;object-fit:cover}') && str_contains($imageCss, '.relative-photo{width:86.356%;height:auto;aspect-ratio:727.431 / 593.583}'), 'source image geometry remains on the canonical core/image wrapper');
$assert(str_contains($imageCss, '{display:block;width:100%;height:100%;max-width:100%;object-fit:inherit;object-position:inherit;border-radius:inherit}') && ! str_contains($imageCss, '> img{width:123px') && ! str_contains($imageCss, '> img{width:86.356%'), 'canonical nested images fill explicitly owned wrapper geometry instead of applying source dimensions twice');
$assert(str_contains($imageCss, '{display:block;max-width:100%;object-fit:inherit;object-position:inherit;border-radius:inherit}') && ! preg_match('/source-tag-img[^,{]*\.wp-block-image > img\{[^}]*width:100%/', $imageCss), 'generic image presentation selectors do not impose nested image geometry');
$assert(preg_match('/where\(figure\).*\.photo\.wp-block-image > img\{display:block;max-width:100%/', $imageCss) && preg_match('/blocks-engine-root-child-.*\.wp-block-image > img\{display:block;max-width:100%/', $imageCss), 'type and root-child image selectors project the canonical nested-image bridge without inventing dimensions');
$assert($imageCss === base64_decode((string) ($image['assets'][0]['content_base64'] ?? ''), true), 'stylesheet projection keeps text and base64 payload representations consistent');
$assert(1 === preg_match('/<!-- wp:image [\s\S]*<figure[^>]*photo[^>]*><img/', (string) ($image['serialized_blocks'] ?? '')), 'image projection preserves canonical core/image figure markup');

$multiPage = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        array( 'path' => 'index.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="shared.css"><main><p><span class="quote-mark">&quot;</span>Home</p></main>' ),
        array( 'path' => 'about.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="shared.css"><main><p><span class="quote-mark">&quot;</span>About</p></main>' ),
        array( 'path' => 'shared.css', 'kind' => 'css', 'content' => '.quote-mark{color:#e8a020}' ),
    ),
) )->toArray();
$multiPageAuthorAssets = array_values(array_filter($multiPage['assets'] ?? array(), static fn (array $asset): bool => 'author-css' === ($asset['source'] ?? '')));
$assert(1 === count($multiPageAuthorAssets), 'identical generated author stylesheets are emitted once across HTML routes');
$assert('blocks-engine/wordpress-site-plan/v2' === ($multiPage['source_reports']['wordpress_site_plan']['schema'] ?? null), 'deduplicated multi-route assets produce a canonical WordPress site plan');

if ( $failures > 0 ) {
    exit(1);
}
fwrite(STDOUT, "Artifact author stylesheet projection unit tests passed\n");
