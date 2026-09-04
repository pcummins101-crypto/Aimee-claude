#!/usr/bin/env node
/*
 * Bundles the simulator into a single self-contained HTML file (dist/index.html).
 * Three.js (UMD build) is embedded inline; every source module is inlined into
 * one module script (each file wrapped in its own block so its top-level consts
 * stay private) and the cockpit photograph is embedded as a data URI.
 */
import { readFileSync, writeFileSync, mkdirSync, readdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(fileURLToPath(import.meta.url));
// The engine is embedded as a classic script (UMD build) rather than fetched:
// the artifact viewer blocks module imports from CDNs, and this also makes the
// single file work offline.
const THREE_UMD = readFileSync(join(root, 'vendor', 'three.min.js'), 'utf8');
const ENGINE_LOADER = `const THREE = window.THREE;
if (!THREE) { const s = document.getElementById('status'); if (s) { s.textContent = 'The embedded 3D engine failed to initialise.'; s.classList.add('is-error'); } throw new Error('THREE missing'); }`;

const files = readdirSync(join(root, 'src')).filter((f) => f.endsWith('.js')).sort();
let code = '';
for (const f of files) {
  const src = readFileSync(join(root, 'src', f), 'utf8').replace(/^import \* as THREE from 'three';\s*$/m, '');
  code += `\n/* ---- ${f} ---- */\n{\n${src}\n}\n`;
}
const style = readFileSync(join(root, 'style.css'), 'utf8');
const png = readFileSync(join(root, 'assets', 'cockpit.png')).toString('base64');
let html = readFileSync(join(root, 'index.html'), 'utf8');
html = html.replace(/<script type="importmap">[\s\S]*?<\/script>\s*/, '');
// replacer functions: the engine source contains `$` sequences that a string
// replacement would interpret as patterns.
html = html.replace(/<!-- EVO_STYLE --><link[^>]*>/, () => `<style>\n${style}\n</style>`);
html = html.replace(/<!-- EVO_SCRIPTS -->[\s\S]*?<!-- \/EVO_SCRIPTS -->/, () =>
  `<script>window.EVO_COCKPIT_URL = "data:image/png;base64,${png}";</script>\n<script>${THREE_UMD}</script>\n<script type="module">\n${ENGINE_LOADER}\n${code}\n</script>`);
mkdirSync(join(root, 'dist'), { recursive: true });
writeFileSync(join(root, 'dist', 'index.html'), html);
// Artifact flavour: the host wraps the file in its own document skeleton, so
// strip ours and keep title + style + body content + scripts.
const artifact = html
  .replace(/^[\s\S]*?<title>/, '<title>')
  .replace(/<\/head>\s*<body>/, '')
  .replace(/<\/body>\s*<\/html>\s*$/, '');
writeFileSync(join(root, 'dist', 'artifact.html'), artifact);
console.log(`dist/index.html and dist/artifact.html written (${(html.length / 1024 / 1024).toFixed(2)} MB, ${files.length} modules)`);
