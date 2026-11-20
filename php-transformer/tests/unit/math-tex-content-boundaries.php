<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$filter = <<<'HTML'
<div class="filter-group__items filter-group--3 toggle-target">
  <div class="toggle-target-container">
    <div class="cc-price-range">
      <div class="cc-price-range__input-row">
        <div class="cc-price-range__input-container">
          <span class="cc-price-range__input-currency-symbol">$</span>
          <input class="cc-price-range__input" name="filter.v.price.gte" placeholder="0" type="text">
        </div>
        <div class="cc-price-range__input-container">
          <span class="cc-price-range__input-currency-symbol">$</span>
          <input class="cc-price-range__input" name="filter.v.price.lte" placeholder="1250" type="text">
        </div>
      </div>
      <div class="cc-price-range__bar">…</div>
    </div>
  </div>
</div>
HTML;

$cases = array(
    array($filter, 0),
    array('<div>$ $</div><p>$  $</p><span>$$ $$</span><p>\(  \)</p><p>\[  \]</p>', 0),
    array('<div>$<input name="price">x$</div>', 0),
    array('<div>$<div>x</div>$</div>', 0),
    array('<p>$x^2$</p><span>\(a+b\)</span><div>$$\sum_i x_i$$</div><p>\[x+y\]</p>', 4),
    array('<p>$<em>x</em>^2$</p>', 1),
);
$transformer = new HtmlTransformer();
foreach ( $cases as [$html, $expectedMathCount] ) {
    $result = $transformer->transform($html)->toArray();
    $countMath = static function (array $blocks) use (&$countMath): int {
        $count = 0;
        foreach ( $blocks as $block ) {
            $count += 'core/math' === $block['blockName'] ? 1 : 0;
            $count += $countMath($block['innerBlocks'] ?? array());
        }
        return $count;
    };
    if ( $expectedMathCount !== $countMath($result['blocks']) ) {
        throw new RuntimeException('Unexpected math recognition for: ' . $html);
    }
}

echo "TeX content boundaries passed\n";
