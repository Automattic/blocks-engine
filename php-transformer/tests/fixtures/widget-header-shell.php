<?php
// Reduced from operaenmovimiento.com's GoDaddy widget-nav: separate mobile
// and desktop navigation, a linked logo heading, and a trailing linked CTA.
$fixture = require __DIR__ . '/route-subset-shell.php';
$header = '<div class="widget widget-nav"><div data-ux="Block"><a href="/"><h3>Shared brand</h3></a><nav><ul><li><a href="/">Inicio</a></li><li><a href="/about.html">Nosotros</a></li></ul></nav></div><div data-ux="Container"><div><a href="/"><h3>Shared brand</h3></a><nav><ul><li><a href="/">Inicio</a></li><li><a href="/about.html">Nosotros</a></li><li><a href="/contact.html">Academia</a></li></ul></nav></div><div><a data-ux="ButtonPrimary" href="/contact.html">Inscripción en línea</a></div></div></div>';
foreach ($fixture['files'] as $path => &$html) {
    if (!str_ends_with($path, '.html')) continue;
    $html = preg_replace('#<div class="widget widget-nav">.*?</div>#s', $header, $html);
    if (!str_starts_with($path, 'posts/')) {
        // Builder pages have no main landmark. Two single-child carriers hide
        // the actual chrome/content split from the document's leading sibling.
        $html = preg_replace('#<p>[^<]* utility</p>#', '', $html);
        $html = str_replace(array('<main>', '</main>'), array('<section>', '</section>'), $html);
        $html = str_replace('<div class="page-frame">', '<div id="outer" style="padding:10px"><div id="inner" style="padding:10px"><div class="page-frame">', $html);
        $html = str_replace('</body>', '</div></div><p>Route utility ' . $path . '</p></body>', $html);
    }
}
unset($html);
return $fixture;
