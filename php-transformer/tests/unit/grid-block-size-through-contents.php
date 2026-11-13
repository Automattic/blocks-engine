<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A boxless DOM parent does not interrupt stretch from the outer grid.
// The percentage-sized descendant's fractional row must still fill that size.
$result = (new HtmlTransformer())->transform('<style>
@media(min-width:600px){
.outer{display:grid;grid-template-rows:500px}
.bridge{display:contents}
.item{display:grid;height:auto;min-height:0;align-self:stretch;grid-template-rows:1fr}
.inner{display:grid;height:100%;grid-template-rows:1fr}
}
</style><div class="outer"><div class="bridge"><div class="item"><div class="inner"><img src="photo.jpg" alt="Photo"></div></div></div></div>')->toArray();
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
if (!preg_match('/\.inner\{[^}]*grid-template-rows:1fr/', $css)
    || preg_match('/\.inner\{[^}]*grid-template-rows:min-content/', $css)) {
    throw new RuntimeException('Boxless wrappers must preserve the definite grid block-size chain.');
}
echo "Grid block size through contents regression passed\n";
