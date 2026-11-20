<?php
// Neutral listing generator from issue #7044. Include with $n cards.
$cards = '';
for ($i = 1; $i <= $n; $i++) {
    $cards .= "<li class=\"product\"><article class=\"card\" data-product-id=\"$i\">"
        . "<figure class=\"card-figure\"><a href=\"/products/item-$i/\" class=\"card-figure__link\"><img src=\"/img/item-$i.jpg\" alt=\"Item $i\" class=\"card-image\"></a>"
        . "<div class=\"card-figcaption\" style=\"display:none\"><div class=\"card-figcaption-body\"><a href=\"#\" class=\"button button--small card-figcaption-button quickview\" data-product-id=\"$i\">Quick view</a></div></div>"
        . "</figure><div class=\"card-body\" style=\"\"><p class=\"card-text\" data-test-info-type=\"brandName\">Brand</p><h3 class=\"card-title\"><a href=\"/products/item-$i/\">Product item $i</a></h3>"
        . "<div class=\"card-text\" data-test-info-type=\"price\"><div class=\"price-section\" style=\"\"><span class=\"price-label\">Price:</span> <span class=\"price price--withoutTax\">\$" . (100 + $i) . ".00</span></div></div>"
        . "<div class=\"card-options\"><input type=\"hidden\" id=\"opt-$i\" value=\"$i\"><input type=\"hidden\" id=\"opt-r-$i\" value=\"0\"><span style=\"display:none\" data-option=\"$i\"></span></div>"
        . "<label class=\"form-label card-compare\" for=\"compare-$i\"><input type=\"checkbox\" name=\"products[]\" value=\"$i\" id=\"compare-$i\"> Compare</label>"
        . "<a href=\"/cart.php?action=add&amp;product_id=$i\" class=\"button button--primary\">Add to cart</a>"
        . "</div></article></li>\n";
}
return <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Products</title>
<style>
body{font-family:sans-serif;margin:0}
.productGrid{display:flex;flex-wrap:wrap;list-style:none;margin:0 -10px;padding:0}
.productGrid .product{width:25%;padding:0 10px;box-sizing:border-box}
.card-figure{position:relative;margin:0}.card-image{width:100%;height:auto}
.card-figcaption{position:absolute;inset:0}
.card-title{font-size:15px;margin:8px 0}.price{font-weight:700}
.button{padding:6px 12px;border:1px solid #333;background:#fff}.button--primary{background:#333;color:#fff}
@media (max-width:800px){.productGrid .product{width:50%}}
</style></head><body>
<header><nav class="navPages"><ul><li><a href="/">Home</a></li><li><a href="/shop/">Shop</a></li></ul></nav></header>
<main class="page"><h1 class="page-heading">Servers</h1>
<form class="actionBar" action="/shop/" method="get"><label for="sort">Sort By:</label><select id="sort" name="sort"><option value="featured">Featured</option><option value="newest">Newest</option></select></form>
<form action="/compare" method="post" data-product-compare><ul class="productGrid">
$cards</ul></form></main></body></html>
HTML;
