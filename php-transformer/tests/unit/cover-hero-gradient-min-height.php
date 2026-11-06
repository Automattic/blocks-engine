<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Reduced coffee-shop reproduction from blocks-engine#4746.
$html = <<<'HTML'
<style>
.hero {position: relative;min-height: min(86vh, 760px);display: flex;align-items: flex-end;color:#fff;
  background: linear-gradient(180deg, rgba(20,20,22,.15) 0%, rgba(20,20,22,.78) 100%), url("images/coffee-bar.jpg") center/cover no-repeat;}
.hero .wrap { padding-bottom: 88px; width: 100%; }
.roastery{padding:120px 40px;color:#fff;text-align:center;
  background:linear-gradient(rgba(28,18,12,.72), rgba(28,18,12,.72)), url("images/roastery.jpg");background-size:cover}
</style>
<main id="top">
  <header class="site-header">…logo, nav…</header>
  <section class="hero"><div class="wrap"><p class="eyebrow">…</p><h1>…</h1><p>…</p><div class="hero-actions"><a class="btn" href="#story">…</a><a class="btn" href="#visit">…</a></div></div></section>
  <section id="story" class="story"><h2>Our Story</h2><p>…</p><img src="images/pour-over.jpg" alt="…"></section>
  <section id="roastery" class="roastery"><h2>The Roastery</h2><p>…</p></section>
  <section id="visit" class="visit"><h2>Visit</h2><p>…</p></section>
  <footer class="site-footer"><p>…</p></footer>
</main>
HTML;

$result = (new HtmlTransformer())->transform($html)->toArray();
$covers = array();
$collect = static function (array $blocks) use (&$collect, &$covers): void {
    foreach ( $blocks as $block ) {
        if ( 'core/cover' === ($block['blockName'] ?? '') ) {
            $covers[] = $block['attrs'];
        }
        $collect($block['innerBlocks'] ?? array());
    }
};
$collect($result['blocks']);
$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    }
};
$assert(2 === count($covers), 'Hero and roastery become Covers.');
$hero = $covers[0] ?? array();
$gradient = $hero['customGradient'] ?? '';
$compact = preg_replace('/\s+/', '', $gradient);
$assert('linear-gradient(180deg,rgba(20,20,22,.15)0%,rgba(20,20,22,.78)100%)' === $compact, 'Hero retains both authored gradient stops.');
$assert(100 === ($hero['dimRatio'] ?? null), 'Gradient uses full opacity so stop alpha is preserved.');
$assert(! isset($hero['customOverlayColor']), 'Gradient is not flattened to a solid overlay.');
$assert(86 === ($hero['minHeight'] ?? null) && 'vh' === ($hero['minHeightUnit'] ?? null), 'Functional min-height maps to the fluid Cover height.');
$assert(str_contains($hero['url'] ?? '', 'images/coffee-bar.jpg'), 'Hero retains its background image.');
$assert(str_contains($result['serialized_blocks'], 'min-height:86vh'), 'Mapped height reaches saved Cover markup.');
$assert(str_contains($result['serialized_blocks'], 'has-background-gradient'), 'Gradient reaches saved Cover markup.');
$roastery = $covers[1] ?? array();
$assert(72 === ($roastery['dimRatio'] ?? null) && '#1c120c' === ($roastery['customOverlayColor'] ?? null), 'Uniform gradient keeps its solid overlay contract.');
$assert(! isset($roastery['customGradient']), 'Uniform gradient is not promoted.');
exit($failures > 0 ? 1 : 0);
