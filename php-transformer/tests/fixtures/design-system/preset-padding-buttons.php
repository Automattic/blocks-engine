<?php
// A site-wide spacing scale includes both majority button padding values.
$fixture = require __DIR__ . '/per-field-button-looks.php';
foreach ($fixture['files'] as &$html) {
    $html = str_replace('</style>', '.spacing-sample{padding:7.5px 15px}.small{padding:5.625px}</style>', $html);
    $html = str_replace('</body>', '<p class="spacing-sample">Spacing scale sample</p><a class="btn small" href="/small">Small</a></body>', $html);
}
unset($html);
return $fixture;
