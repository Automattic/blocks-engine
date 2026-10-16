import test from 'node:test';
import assert from 'node:assert/strict';
import { familyNames, generateCases, generateFamily } from './catalog.mjs';
test('seeded generation deterministic and bounded',()=>{const a=generateCases({seed:1,count:40}),b=generateCases({seed:1,count:40});assert.deepEqual(a,b);for(const c of a)assert.ok(c.ids.length<=15);});
test('each family generates',()=>{for(const name of familyNames){const c=generateFamily(name);assert.ok(c.html&&c.css&&c.ids.length&&c.tags.length);}});
test('fixed bug class families are represented',()=>{assert.match(generateFamily('selectors').css,/:where/);assert.match(generateFamily('cascade').css,/--MiXeD/);assert.match(generateFamily('cascade').css,/@media/);assert.match(generateFamily('layout').css,/grid/);});
