import assert from 'node:assert/strict';

// Computed sRGB colours may serialize as rgb()/rgba() or color(srgb …).
// Compare exact 8-bit RGBA paint, not serialization or an error tolerance.
export function srgb8(value) {
    if (value === 'transparent') return [0, 0, 0, 0];
    const match = value.match(/^(rgba?|color)\((.*)\)$/i);
    if (!match) throw new Error(`Not a computed sRGB colour: ${value}`);
    const relative = match[1].toLowerCase() === 'color';
    let body = match[2].trim();
    if (relative) {
        if (!body.startsWith('srgb ')) throw new Error(`Not sRGB: ${value}`);
        body = body.slice(5);
    }
    const parts = body.split(/[\s,/]+/);
    if (parts.length < 3 || parts.length > 4) throw new Error(`Invalid colour: ${value}`);
    return [...parts.slice(0, 3).map(channel => Math.round(Math.max(0, Math.min(255,
        parseFloat(channel) * (channel.endsWith('%') ? 255 / 100 : relative ? 255 : 1))))),
    Math.round(Math.max(0, Math.min(255, parts[3] === undefined ? 255 : parseFloat(parts[3]) * (parts[3].endsWith('%') ? 255 / 100 : 255))))];
}

// Normalize snapshots too, including colours embedded in border shorthands.
export function computedColors(value) {
    if (typeof value === 'string') return value.replace(/(?:rgba?\([^()]*\)|color\(srgb\s+[^()]*\))/gi, color => `rgba8(${srgb8(color).join(',')})`);
    if (Array.isArray(value)) return value.map(computedColors);
    if (value && typeof value === 'object') return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, computedColors(item)]));
    return value;
}

export function assertComputedEqual(actual, expected, message) {
    assert.deepEqual(computedColors(actual), computedColors(expected), message);
}

export async function waitForComputedColor(page, selector, property, expected) {
    await page.waitForFunction(`JSON.stringify((${srgb8.toString()})(getComputedStyle(document.querySelector(${JSON.stringify(selector)}))[${JSON.stringify(property)}])) === ${JSON.stringify(JSON.stringify(srgb8(expected)))}`);
}
