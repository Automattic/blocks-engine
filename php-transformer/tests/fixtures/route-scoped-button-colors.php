<?php
declare(strict_types=1);

// Reduced from https://gist.github.com/chubes4/10cc7f4f7bb30a7a6f6679d1f5abb609.
// Two sibling link buttons trigger shared-class extraction; the other route
// lowers a native button and projects that class separately for controls.
return array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="/style.css"></head><body><main><a href="/contact/index.html" class="btn btn--outline">Next</a><a href="/contact/index.html" class="btn btn--outline">Next</a></main></body></html>',
        'contact/index.html' => '<!doctype html><html><head><title>Example</title><link rel="stylesheet" href="/style.css"></head><body><main><button type="button" class="btn btn--primary">Load</button></main></body></html>',
        'style.css' => <<<'CSS'
:root{--color-primary:#b9276b;--color-white:#ffffff;--color-primary-dark:#8e1d52}
.btn{padding:8px 16px;border-radius:4px;font-size:16px}
.btn--primary{background:var(--color-primary);color:var(--color-white)}
.btn--primary:hover{background:var(--color-primary-dark);color:var(--color-white)}
CSS,
    ),
);
