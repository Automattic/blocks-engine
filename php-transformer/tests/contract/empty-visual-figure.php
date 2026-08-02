<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$css = ':root{--figure-height:12rem;--figure-border:rgba(20,40,60,.5);--figure-gradient:linear-gradient(135deg,#123,#456)}.border-figure{min-height:var(--figure-height);border:1px solid var(--figure-border)}.gradient-figure{height:4rem;background:var(--figure-gradient)}.pseudo-figure{min-height:3rem}.pseudo-figure::before{content:"";display:block;height:100%;background:#345}.empty-figure{min-height:4rem}';
$html = '<main><figure class="border-figure"></figure><figure class="gradient-figure"></figure><figure class="pseudo-figure"></figure><figure class="empty-figure"></figure></main>';

$transformed = ( new HtmlTransformer() )->transform('<style>' . $css . '</style>' . $html)->toArray();
$transformMarkup = (string) ($transformed['serialized_blocks'] ?? '');
$assert(1 === substr_count($transformMarkup, 'wp-block-group border-figure') && 1 === substr_count($transformMarkup, 'wp-block-group gradient-figure') && 1 === substr_count($transformMarkup, 'wp-block-group pseudo-figure'), 'Transformer keeps bounded empty figures with border, custom-property gradient, or pseudo paint as native groups.');
$assert(str_contains($transformMarkup, 'border-figure') && str_contains($transformMarkup, 'gradient-figure') && str_contains($transformMarkup, 'pseudo-figure'), 'Transformer retains each visual figure identity for projected CSS.');
$assert(! str_contains($transformMarkup, 'empty-figure') && ! str_contains($transformMarkup, '<!-- wp:html'), 'Transformer prunes nonvisual empty figures without HTML fallback.');

$compiled = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files'      => array(
        'index.html' => '<link rel="stylesheet" href="assets/site.css">' . $html,
        'assets/site.css' => $css,
    ),
))->toArray();
$compiledMarkup = (string) ($compiled['serialized_blocks'] ?? '');
$assert(1 === substr_count($compiledMarkup, 'wp-block-group border-figure') && 1 === substr_count($compiledMarkup, 'wp-block-group gradient-figure') && 1 === substr_count($compiledMarkup, 'wp-block-group pseudo-figure'), 'Artifact compiler passes painted empty figures into native transformation.');
$assert(str_contains($compiledMarkup, 'border-figure') && str_contains($compiledMarkup, 'gradient-figure') && str_contains($compiledMarkup, 'pseudo-figure'), 'Artifact compiler preserves direct, custom-property, and pseudo-element visual evidence.');
$assert(! str_contains($compiledMarkup, 'empty-figure') && ! str_contains($compiledMarkup, '<!-- wp:html'), 'Artifact compiler continues pruning genuinely nonvisual empty figures.');

$inlineCss = '.gallery{display:grid;grid-template-columns:repeat(2,1fr)}.gallery .photo{min-height:var(--h)}.gallery .photo::before{content:"";display:block;height:100%;background:linear-gradient(135deg,var(--a),var(--b))}.gallery .empty{min-height:var(--h)}';
$inlineHtml = '<main><div class="gallery"><figure class="photo" style="--h:280px;--a:#27485f;--b:#87d8ff" aria-label="Alpine lake"></figure><figure class="photo" style="--h:390px;--a:#6f493e;--b:#ff8762" aria-label="Desert canyon"></figure><figure class="empty" style="--h:240px"></figure></div></main>';
$inlineCompiled = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files'      => array(
        'index.html' => '<link rel="stylesheet" href="assets/site.css">' . $inlineHtml,
        'assets/site.css' => $inlineCss,
    ),
))->toArray();
$inlineMarkup = (string) ($inlineCompiled['serialized_blocks'] ?? '');
$inlineValidity = ( new BlockValidityValidator() )->validateBlocks($inlineCompiled['blocks'] ?? array());
$inlineStylesheet = '';
foreach ( $inlineCompiled['assets'] ?? array() as $asset ) {
    if ( 'css' === ($asset['kind'] ?? '') ) {
        $inlineStylesheet = (string) ($asset['content'] ?? '');
        break;
    }
}
$assert(2 === substr_count($inlineMarkup, 'wp-block-group photo') && str_contains($inlineMarkup, 'min-height:var(--h);--h:280px;--a:#27485f;--b:#87d8ff') && str_contains($inlineMarkup, 'min-height:var(--h);--h:390px;--a:#6f493e;--b:#ff8762'), 'Artifact compiler preserves per-element custom properties on pseudo-painted empty figures as native groups.');
$assert(str_contains($inlineStylesheet, '.gallery .photo::before{content:"";display:block;height:100%;background:linear-gradient(135deg,var(--a),var(--b))}') && ! str_contains($inlineMarkup, 'class="wp-block-group empty') && 'pass' === ($inlineValidity['status'] ?? ''), 'Final native blocks retain the pseudo paint contract and WordPress validity while nonvisual empty figures remain pruned.');

fwrite(STDOUT, "Empty visual figure contracts passed.\n");
