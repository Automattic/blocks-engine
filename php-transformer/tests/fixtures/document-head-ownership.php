<?php
declare(strict_types=1);

return array('entrypoint' => 'index.html', 'files' => array('index.html' => <<<'HTML'
<!doctype html><html><head>
<title>Authored title</title>
<meta charset="utf-8"><meta charset="utf-8">
<meta name="viewport" content="width=980">
<meta name="viewport" content="width=device-width, initial-scale=1" data-selected-viewport="">
<meta name="robots" id="source-robots" content="noindex, nofollow">
<meta name="GOOGLEBOT" content="noindex">
<meta property="og:url" content="https://source.example/">
<meta name="generator" content="Source generator">
<meta name="description" content="Authored description">
<meta property="og:title" content="Authored social title">
<meta property="og:image" content="https://source.example/social.jpg">
<meta name="sentry-trace" content="source-trace">
<meta name="baggage" content="source-baggage">
<meta name="unknown-vendor-telemetry" content="source-unknown">
<link rel="CANONICAL" href="/">
<link rel="alternate canonical" href="https://source.example/">
<link rel="sitemap" href="https://source.example/sitemap.xml">
<link rel="manifest" href="/importer-internals/manifest.json">
<link rel="preconnect" href="https://fonts.example">
<meta name="theme-color" content="#123456">
<script type="application/ld+json">{"@context":"https://schema.org","@type":"WebSite","name":"Example"}</script>
</head><body><main><h1>Example</h1><p>Imported content.</p></main></body></html>
HTML
));
