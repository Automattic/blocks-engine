<?php
declare(strict_types=1);

$page = static fn(string $self, string $title): string =>
    '<html><head><title>' . $title . ' | Acme</title></head><body>'
    . '<header class="site-header"><p>Acme</p><nav><a href="' . $self . '#about">About</a><a href="' . $self . '#contact">Contact</a></nav></header>'
    . '<main><h1>' . $title . '</h1><section id="about"><h2>About</h2><p>About ' . $title . '.</p></section>'
    . '<section id="contact"><h2>Contact</h2><p>Call us.</p></section></main>'
    . '<footer class="site-footer"><p>© Acme</p></footer></body></html>';

return array(
    'entrypoint' => 'website/index.html',
    'files' => array(
        array('path' => 'website/index.html', 'content' => $page('/index.html', 'Home')),
        array('path' => 'website/services/index.html', 'content' => $page('/services/index.html', 'Services')),
    ),
);
