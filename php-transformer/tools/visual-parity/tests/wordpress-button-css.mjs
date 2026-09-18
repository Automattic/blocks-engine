/**
 * WordPress core button cascade shared by geometry tests.
 *
 * Hand-pasted per-test snippets drifted (padding:0 vs calc(.667em + 2px),
 * missing display:flex on .wp-block-buttons, missing min-height). Loading
 * the real block-library stylesheet is heavier than these tests need — they
 * already transform in-process and render a fragment, not a WP install —
 * so one complete snippet is the simpler shared cascade.
 */
export const wordpressButtonCss = [
    '.wp-block-buttons{box-sizing:border-box;display:flex;flex-wrap:wrap;gap:.5em}',
    '.wp-block-button{box-sizing:border-box;display:inline-block}',
    '.wp-block-button__link{box-sizing:border-box;cursor:pointer;display:inline-block;min-height:10px;padding:calc(.667em + 2px) calc(1.333em + 2px);text-align:center;word-break:break-word}',
].join('');
