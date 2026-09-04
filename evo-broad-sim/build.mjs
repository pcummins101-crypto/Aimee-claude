#!/usr/bin/env node
/*
 * Bundles the simulator into a single self-contained HTML file (dist/index.html).
 * Three.js stays on the CDN; every source module is inlined into one module
 * script (each file wrapped in its own block so its top-level consts stay
 * private) and the cockpit photograph is embedded as a data URI.
 */
import { readFileSync, writeFileSync, mkdirSync, readdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(fileURLToPath(import.meta.url));
const THREE_URL = 'https://cdnjs.cloudflare.com/ajax/libs/three.js/0.170.0/three.module.min.js';
const THREE_URL_FALLBACK = 'https://cdn.jsdelivr.net/npm/three@0.170.0/build/three.module.min.js';
// Loads the engine with a fallback CDN and reports failure on the start panel
// instead of dying silently.
const ENGINE_LOADER = `const __status = document.getElementById('status');
let THREE;
try { THREE = await import('${THREE_URL}'); }
catch (e1) {
  try { THREE = await import('${THREE_URL_FALLBACK}'); }
  catch (e2) { if (__status) { __status.textContent = 'Could not load the 3D engine from the network: ' + (e2 && e2.message || e2); __status.classList.add('is-error'); } throw e2; }
}`;

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
html = html.replace(/<!-- EVO_STYLE --><link[^>]*>/, `<style>\n${style}\n</style>`);
html = html.replace(/<!-- EVO_SCRIPTS -->[\s\S]*?<!-- \/EVO_SCRIPTS -->/,
  `<script>window.EVO_COCKPIT_URL = "data:image/png;base64,${png}";</script>\n<script type="module">\n${ENGINE_LOADER}\n${code}\n</script>`);
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
