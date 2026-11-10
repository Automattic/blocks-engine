<?php
declare(strict_types=1);

// Six-page brochure site: shared chrome, two product families, unique content,
// responsive author CSS, and an uncovered anchor requiring editor repair.
$files = array('site.css' => 'body{margin:0;color:#243040;font-family:sans-serif}.site-nav{padding:24px;width:960px}.site-footer{padding:32px;width:960px}.hero{width:920px;padding:40px}.product-card{width:420px;padding:20px}.service-card{width:620px;padding:28px}#details{padding:13px}@media(max-width:700px){.hero{width:90%;padding:20px}}');
foreach (array('index', 'products', 'pricing', 'services', 'team', 'contact') as $index => $slug) {
    $unique = 'feature-' . $slug;
    $files['site.css'] .= '.' . $unique . '{width:' . (700 + $index) . 'px;padding:' . (16 + $index) . 'px}';
    $family = $index < 3 ? 'product-card' : 'service-card';
    $files[$slug . '.html'] = '<!doctype html><html><head><title>' . ucfirst($slug) . '</title><link rel="stylesheet" href="site.css"></head><body><header class="site-nav"><nav><a href="index.html">Home</a><a href="products.html">Products</a><a href="services.html">Services</a><a href="contact.html">Contact</a></nav></header><main><section class="hero"><h1>' . ucfirst($slug) . '</h1><p>Practical products and services for growing teams.</p></section><section class="' . $family . '"><h2>Built for your business</h2><p>Discover our approach and explore the options.</p></section><section class="' . $unique . '"><h2>More information</h2><a href="#details">Read details</a><div id="details"><p>Talk to our team about your next project.</p></div></section></main><footer class="site-footer"><p>Example Company</p><a href="contact.html">Get in touch</a></footer></body></html>';
}
return $files;
