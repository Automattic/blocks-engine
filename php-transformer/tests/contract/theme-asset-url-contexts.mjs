import assert from 'node:assert/strict';

const cssReference = 'font.woff2';
for (const origin of [ 'https://build.example.test', 'https://moved.example.test' ]) {
  const stylesheet = `${origin}/wp-content/themes/site/assets/assets/fonts.css`;
  assert.equal(new URL(cssReference, stylesheet).href, `${origin}/wp-content/themes/site/assets/assets/font.woff2`);
}

const templateReference = 'https://build.example.test/wp-content/themes/site/assets/assets/font.woff2';
assert.equal(new URL(templateReference, 'https://build.example.test/nested/page/').href, templateReference);
assert.equal(new URL(templateReference, 'https://moved.example.test/nested/page/').href, templateReference);

console.log('theme asset URL context contract passed');
