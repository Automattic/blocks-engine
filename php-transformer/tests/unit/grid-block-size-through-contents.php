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
.boxed-inner{display:grid;height:100%;grid-template-rows:1fr}
}
</style><div class="outer"><div class="bridge"><div class="item"><div class="inner"><img src="photo.jpg" alt="Photo"></div></div></div></div>
<div class="outer"><div class="ordinary-wrapper"><div class="item"><div class="boxed-inner"><img src="other.jpg" alt="Other"></div></div></div></div>')->toArray();
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
if (!preg_match('/\.inner\{[^}]*grid-template-rows:1fr/', $css)
    || preg_match('/\.inner\{[^}]*grid-template-rows:min-content/', $css)) {
    throw new RuntimeException('Boxless wrappers must preserve the definite grid block-size chain.');
}
// Only display:contents is transparent to layout participation. An ordinary
// auto-height block must not pass the outer grid's definite size to its child.
if (!preg_match('/\.boxed-inner\{[^}]*grid-template-rows:min-content/', $css)) {
    throw new RuntimeException('Ordinary wrappers must still interrupt grid stretch sizing.');
}
// Definite height must come from the same condition domain too, even when
// an unconditional height would otherwise make the ancestor intrinsic.
$result = (new HtmlTransformer())->transform('<style>
.outer{height:auto}
@media(min-width:600px){
.outer{display:grid;height:500px}
.bridge{display:contents}
.item{display:grid;height:auto;min-height:0;grid-template-rows:1fr}
.inner{display:grid;height:100%;grid-template-rows:1fr}
}
</style><div class="outer"><div class="bridge"><div class="item"><div class="inner"><img src="photo.jpg" alt="Photo"></div></div></div></div>')->toArray();
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
if (preg_match('/\.inner\{[^}]*grid-template-rows:min-content/', $css)) {
    throw new RuntimeException('Conditional ancestor height must preserve definite grid tracks.');
}

// A conditional boxless wrapper is not boxless in an unconditional rule's
// domain. Unrelated viewport declarations must not prove definite sizing.
$result = (new HtmlTransformer())->transform('<style>
.outer{display:grid;grid-template-rows:500px}
@media(min-width:600px){.bridge{display:contents}}
.item{display:grid;height:auto;min-height:0;grid-template-rows:1fr}
.inner{display:grid;height:100%;grid-template-rows:1fr}
</style><div class="outer"><div class="bridge"><div class="item"><div class="inner"><img src="photo.jpg" alt="Photo"></div></div></div></div>')->toArray();
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
if (!preg_match('/\.inner\{[^}]*grid-template-rows:min-content/', $css)) {
    throw new RuntimeException('Conditional display:contents must not leak into an unconditional grid rule.');
}
// Ancestor sizing still resolves inherited custom properties after selecting
// declarations from the grid rule's condition domain.
$result = (new HtmlTransformer())->transform('<style>
.outer{--panel-height:500px;height:auto}
@media(min-width:600px){
.outer{display:grid;height:var(--panel-height)}
.bridge{display:contents}
.item{display:grid;height:auto;min-height:0;grid-template-rows:1fr}
.inner{display:grid;height:100%;grid-template-rows:1fr}
}
</style><div class="outer"><div class="bridge"><div class="item"><div class="inner"><img src="photo.jpg" alt="Photo"></div></div></div></div>')->toArray();
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
if (!preg_match('/\.inner\{[^}]*grid-template-rows:1fr/', $css)
    || preg_match('/\.inner\{[^}]*grid-template-rows:min-content/', $css)) {
    throw new RuntimeException('Conditional ancestor sizing must resolve custom properties.');
}
echo "Grid block size through contents regression passed\n";
