#!/usr/bin/env node

/**
 * Run standalone PHP regression files without a host PHP binary.
 *
 * The audit environment provides WordPress Playground's PHP-WASM runtime in
 * /tmp/aimee-php-wasm. This runner stages the production policy sources and
 * requested test files into its virtual filesystem, then executes every test
 * in a fresh PHP request under PHP 8.3.
 *
 * Usage:
 *   node tests/run-php-wasm.mjs tests/intimacy-media-policy-regression.php
 */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { pathToFileURL } from 'node:url';

const pluginRoot = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const wasmRoot = process.env.AIMEE_PHP_WASM_ROOT || '/tmp/aimee-php-wasm/node_modules';
const universalPath = path.join(wasmRoot, '@php-wasm/universal/index.js');
const nodePath = path.join(wasmRoot, '@php-wasm/node/index.js');

if (!fs.existsSync(universalPath) || !fs.existsSync(nodePath)) {
  console.error(`PHP-WASM runtime not found under ${wasmRoot}`);
  process.exit(2);
}

const { PHP } = await import(pathToFileURL(universalPath).href);
const { loadNodeRuntime } = await import(pathToFileURL(nodePath).href);
const phpVersion = process.env.AIMEE_PHP_VERSION || '8.3';
const php = new PHP(await loadNodeRuntime(phpVersion, {
  emscriptenOptions: { processId: process.pid },
}));

for (const directory of ['/aimee', '/aimee/includes', '/aimee/templates', '/aimee/tests']) {
  php.mkdirTree(directory);
}

function stagePhpTree(hostDirectory, virtualDirectory) {
  for (const entry of fs.readdirSync(hostDirectory, { withFileTypes: true })) {
    const hostPath = path.join(hostDirectory, entry.name);
    const virtualPath = `${virtualDirectory}/${entry.name}`;
    if (entry.isDirectory()) {
      php.mkdirTree(virtualPath);
      stagePhpTree(hostPath, virtualPath);
    } else if (entry.isFile() && entry.name.endsWith('.php')) {
      php.writeFile(virtualPath, fs.readFileSync(hostPath));
    }
  }
}

function stageFixtureTree(hostDirectory, virtualDirectory) {
  if (!fs.existsSync(hostDirectory)) return;
  php.mkdirTree(virtualDirectory);
  for (const entry of fs.readdirSync(hostDirectory, { withFileTypes: true })) {
    const hostPath = path.join(hostDirectory, entry.name);
    const virtualPath = `${virtualDirectory}/${entry.name}`;
    if (entry.isDirectory()) {
      stageFixtureTree(hostPath, virtualPath);
    } else if (entry.isFile()) {
      php.writeFile(virtualPath, fs.readFileSync(hostPath));
    }
  }
}

php.writeFile('/aimee/aimee-global.php', fs.readFileSync(path.join(pluginRoot, 'aimee-global.php')));
stagePhpTree(path.join(pluginRoot, 'includes'), '/aimee/includes');
stagePhpTree(path.join(pluginRoot, 'templates'), '/aimee/templates');
stageFixtureTree(path.join(pluginRoot, 'tests', 'fixtures'), '/aimee/tests/fixtures');

const requested = process.argv.slice(2);
const tests = requested.length
  ? requested
  : ['tests/intimacy-media-policy-regression.php'];

let failed = 0;
for (const requestedPath of tests) {
  const hostPath = path.isAbsolute(requestedPath)
    ? requestedPath
    : path.resolve(pluginRoot, requestedPath.replace(/^tests\//, 'tests/'));
  if (!fs.existsSync(hostPath)) {
    console.error(`Test file not found: ${hostPath}`);
    failed += 1;
    continue;
  }

  const basename = path.basename(hostPath);
  php.writeFile(`/aimee/tests/${basename}`, fs.readFileSync(hostPath));
  const result = await php.runStream({
    code: `<?php require '/aimee/tests/${basename}';`,
  });
  const stdout = await result.stdoutText;
  const stderr = await result.stderrText;
  const exitCode = await result.exitCode;

  process.stdout.write(`\n=== ${basename} (PHP ${phpVersion}) ===\n`);
  if (stdout) process.stdout.write(stdout);
  if (stderr) process.stderr.write(stderr);
  if (Number(exitCode) !== 0) {
    process.stderr.write(`TEST FAILED (exit ${Number(exitCode)}): ${basename}\n`);
    failed += 1;
  }
}

process.exit(failed === 0 ? 0 : 1);
