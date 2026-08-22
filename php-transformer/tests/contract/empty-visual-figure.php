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

foreach (array(1, 11) as $count) {
    $geometryGroups = ( new HtmlTransformer() )->transform(str_repeat('<div style="height:12px;overflow:hidden;width:100%"></div>', $count))->toArray();
    $geometryMetrics = $geometryGroups['source_reports']['editability_report']['metrics'] ?? array();
    $geometryPolicy = (new \Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityPolicy())->evaluate($geometryGroups['source_reports']['editability_report'] ?? array());
    $geometryMarkup = (string) ($geometryGroups['serialized_blocks'] ?? '');
    preg_match('/be-inline-geometry-[a-f0-9]{64}/', $geometryMarkup, $geometryClass);
    $geometryCss = implode("\n", array_map(static fn (array $asset): string => 'engine-support' === ($asset['source'] ?? '') ? (string) ($asset['content'] ?? '') : '', $geometryGroups['assets'] ?? array()));
    $assert($count === ($geometryMetrics['empty_visual_group_count'] ?? null) && 0 === ($geometryMetrics['empty_wrapper_count'] ?? null) && 'passed' === ($geometryPolicy['status'] ?? null) && isset($geometryClass[0]) && str_contains($geometryCss, '.' . $geometryClass[0] . '{'), 'Direct transformer verifies generated carrier CSS for ' . $count . ' empty height/geometry group(s) before editability policy evaluation.');
}

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

$inlineCss = '.gallery{display:grid;grid-template-columns:repeat(2,1fr)}.gallery .photo{min-height:var(--h)}.gallery .photo::before{content:"";display:block;height:100%;background:linear-gradient(135deg,var(--a),var(--b))}.gallery .empty{min-height:var(--h)}.tour-card{background:linear-gradient(135deg,var(--tone),#fff)}';
$inlineHtml = '<main><div class="gallery"><figure class="photo" style="--h:280px;--a:#27485f;--b:#87d8ff" aria-label="Alpine lake"></figure><figure class="photo" style="--h:390px;--a:#6f493e;--b:#ff8762" aria-label="Desert canyon"></figure><figure class="empty" style="--h:240px"></figure></div><div class="tour-card" style="--tone:#315b74;border-color:#d8dee9;border-width:1px;border-style:solid;border-radius:16px;padding:1.2rem;min-height:430px">Card</div></main>';
$inlineCompiled = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files'      => array(
        'index.html' => '<link rel="stylesheet" href="assets/site.css">' . $inlineHtml,
        'assets/site.css' => $inlineCss,
    ),
))->toArray();
$inlineMarkup = (string) ($inlineCompiled['serialized_blocks'] ?? '');
$inlineValidity = ( new BlockValidityValidator() )->validateBlocks($inlineCompiled['blocks'] ?? array());
$inlineCssAssets = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $inlineCompiled['assets'] ?? array()));
$assert(2 === substr_count($inlineMarkup, 'wp-block-group photo') && str_contains($inlineMarkup, 'min-height:var(--h)') && ! str_contains($inlineMarkup, '--a:') && str_contains($inlineCssAssets, '--h:280px !important;--a:#27485f !important;--b:#87d8ff !important') && str_contains($inlineCssAssets, '--h:390px !important;--a:#6f493e !important;--b:#ff8762 !important'), 'Artifact compiler carries fixture87 gallery custom properties in generated CSS while core owns the saved style attribute.');
$assert(! str_contains($inlineMarkup, '--tone:') && str_contains($inlineCssAssets, '--tone:#315b74 !important') && 'pass' === ($inlineValidity['status'] ?? ''), 'Fixture87 card custom paint survives in a generated carrier class without diverging from core style serialization.');
$assert(! str_contains($inlineMarkup, 'class="wp-block-group empty'), 'Final native blocks retain the pseudo paint contract while nonvisual empty figures remain pruned.');

$legacyFont = ( new HtmlTransformer() )->transform('<h2><font color="#ffffff" size="6">Native heading</font></h2>')->toArray();
$legacyFontMarkup = (string) ($legacyFont['serialized_blocks'] ?? '');
$legacyFontReport = (new \Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityReport())->fromBlocks($legacyFont['blocks'] ?? array());
$legacyFontValidity = ( new BlockValidityValidator() )->validateBlocks($legacyFont['blocks'] ?? array());
$assert(str_contains($legacyFontMarkup, '<mark ') && ! str_contains($legacyFontMarkup, '<font') && 0 === ($legacyFontReport['metrics']['structural_rich_text_attribute_count'] ?? -1) && 'pass' === ($legacyFontValidity['status'] ?? ''), 'Legacy inline font presentation lowers to a valid native RichText mark without structural HTML.');

$legacyFontCascade = ( new HtmlTransformer() )->transform('<style>.cascade{color:#456;font-family:CSS;font-size:19px}</style><p><font class="cascade" color="#123" face="Legacy" size="7">Cascade</font><font color="#123" face="Legacy" size="+2">Hints</font></p>')->toArray();
$legacyFontCascadeMarkup = (string) ($legacyFontCascade['serialized_blocks'] ?? '');
$assert(str_contains($legacyFontCascadeMarkup, 'color:#456;font-family:CSS;font-size:19px;background-color:transparent">Cascade') && str_contains($legacyFontCascadeMarkup, 'color:#123;font-family:Legacy;font-size:24px;background-color:transparent">Hints'), 'Resolved author CSS wins matching legacy font hints while absent cascade properties retain presentational fallback values.');

$legacyFontMatrix = ( new HtmlTransformer() )->transform('<p><font>Plain</font><font size="+2">Up</font><font size="-9">Down</font><font color="#123"><font size="-1">Nested</font></font><font size="+2"><font size="-1">Relative nested</font><font size="7"><font size="-9">Absolute nested</font></font></font></p>')->toArray();
$legacyFontMatrixMarkup = (string) ($legacyFontMatrix['serialized_blocks'] ?? '');
$legacyFontMatrixReport = (new \Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityReport())->fromBlocks($legacyFontMatrix['blocks'] ?? array());
$legacyFontMatrixValidity = ( new BlockValidityValidator() )->validateBlocks($legacyFontMatrix['blocks'] ?? array());
$assert(str_contains($legacyFontMatrixMarkup, 'Plain') && ! str_contains($legacyFontMatrixMarkup, '<font') && str_contains($legacyFontMatrixMarkup, 'font-size:24px;background-color:transparent;color:inherit">Up') && str_contains($legacyFontMatrixMarkup, 'font-size:10px;background-color:transparent;color:inherit">Down') && str_contains($legacyFontMatrixMarkup, 'font-size:13px;background-color:transparent;color:inherit">Nested') && str_contains($legacyFontMatrixMarkup, 'font-size:24px;background-color:transparent;color:inherit"><mark style="font-size:18px;background-color:transparent;color:inherit">Relative nested') && str_contains($legacyFontMatrixMarkup, 'font-size:48px;background-color:transparent;color:inherit"><mark style="font-size:10px;background-color:transparent;color:inherit">Absolute nested') && str_contains($legacyFontMatrixMarkup, 'color:#123') && 0 === ($legacyFontMatrixReport['metrics']['structural_rich_text_attribute_count'] ?? -1) && 'pass' === ($legacyFontMatrixValidity['status'] ?? ''), 'Plain, relative, clamped, nested, and mixed legacy font tags retain exact text-to-size correspondence in valid native RichText.');

$runtimeEmpty = ( new HtmlTransformer() )->transform('<div id="runtime-empty"></div>', array('runtime_dom_selectors' => array('#runtime-empty')))->toArray();
$runtimeEmptyMetrics = $runtimeEmpty['source_reports']['editability_report']['metrics'] ?? array();
$assert(1 === ($runtimeEmptyMetrics['empty_runtime_group_count'] ?? null) && 0 === ($runtimeEmptyMetrics['empty_wrapper_count'] ?? null) && ! str_contains(serialize($runtimeEmpty['blocks'] ?? array()), '_editability_runtime_owned') && ! str_contains((string) ($runtimeEmpty['serialized_blocks'] ?? ''), '_editability_runtime_owned'), 'Runtime ownership follows explicit selector provenance into direct editability reporting without leaking markers into public blocks.');

$fullBleedCss = '.hero{display:flex;position:relative}.hero-grid{position:absolute;inset:0;background-image:linear-gradient(#fff 1px,transparent 1px);background-size:64px 64px}';
$fullBleedHtml = '<header class="hero"><div class="hero-grid" aria-hidden="true"></div><p>Content</p></header>';
$fullBleed = ( new HtmlTransformer() )->transform('<style>' . $fullBleedCss . '</style>' . $fullBleedHtml)->toArray();
$fullBleedMarkup = (string) ($fullBleed['serialized_blocks'] ?? '');
$assert(str_contains($fullBleedMarkup, 'wp-block-group hero-grid') && ! str_contains($fullBleedMarkup, 'hero-grid blocks-engine-empty-flex-item'), 'Out-of-flow decorative layers remain full-bleed instead of receiving in-flow empty flex-item sizing.');

fwrite(STDOUT, "Empty visual figure contracts passed.\n");
