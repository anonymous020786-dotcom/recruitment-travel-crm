// One-shot asset build. Runs the Tailwind CLI via `node` (no shell — safe with
// any characters in the project path), then content-hashes + writes the manifest.
//
//   node scripts/build.mjs            production build (minified + hashed)
//   node scripts/build.mjs --watch    rebuild CSS on change (no hashing)

import { execFileSync, spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import {
    readFileSync, writeFileSync, readdirSync, unlinkSync,
    existsSync, mkdirSync,
} from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const tailwindCli = join(root, 'node_modules', 'tailwindcss', 'lib', 'cli.js');
const inCss = join(root, 'resources', 'css', 'app.css');
const buildDir = join(root, 'public', 'assets', 'build');
const outCss = join(buildDir, 'app.css');

if (!existsSync(buildDir)) mkdirSync(buildDir, { recursive: true });
if (!existsSync(tailwindCli)) {
    console.error('tailwindcss not installed — run: npm install');
    process.exit(1);
}

const watch = process.argv.includes('--watch');

if (watch) {
    const p = spawn(process.execPath, [tailwindCli, '-i', inCss, '-o', outCss, '--watch'], { stdio: 'inherit' });
    p.on('exit', (c) => process.exit(c ?? 0));
} else {
    execFileSync(process.execPath, [tailwindCli, '-i', inCss, '-o', outCss, '--minify'], { stdio: 'inherit' });
    finalize();
}

function finalize() {
    const hash = (buf) => createHash('sha256').update(buf).digest('hex').slice(0, 10);
    const manifest = {};

    if (existsSync(outCss)) {
        const css = readFileSync(outCss);
        const name = `app.${hash(css)}.css`;
        writeFileSync(join(buildDir, name), css);
        manifest['app.css'] = name;
    }

    const jsSrc = join(root, 'resources', 'js', 'app.js');
    if (existsSync(jsSrc)) {
        const js = readFileSync(jsSrc);
        const name = `app.${hash(js)}.js`;
        writeFileSync(join(buildDir, name), js);
        manifest['app.js'] = name;
    }

    const keep = new Set(Object.values(manifest));
    for (const file of readdirSync(buildDir)) {
        if (/^app\.[0-9a-f]{10}\.(css|js)$/.test(file) && !keep.has(file)) {
            unlinkSync(join(buildDir, file));
        }
    }

    writeFileSync(join(buildDir, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
    console.log('built assets:', JSON.stringify(manifest));
}
