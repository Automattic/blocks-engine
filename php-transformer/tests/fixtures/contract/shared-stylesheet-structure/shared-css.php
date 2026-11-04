<?php
// Reduced 31-entry Wix replay shape: 22 large shared sheets and page-local CSS.
// Include nesting, comments, strings, semicolons and braces inside strings.
$sheets = array();
for ($sheet = 0; $sheet < 22; ++$sheet) {
    $sheets[] = '/* shared ' . $sheet . ' */@layer base{@media(min-width:600px){.shared-' . $sheet . '{color:#123456;--payload:"' . str_repeat('x;{y}', 8750) . '"}}}';
}
return $sheets;
