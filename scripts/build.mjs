// One-shot asset build. No shell is involved (safe with any characters in the project path).
//
//   node scripts/build.mjs            production build: Tailwind → minified CSS, minified JS, content-hashed names,
//                                     manifest, and pre-compressed .br / .gz copies that the web server can serve as-is
//   node scripts/build.mjs --watch    rebuild CSS on change (no minifying, no hashing — for development only)
//   node scripts/build.mjs --check    exit 1 if the committed CSS/JS is out of date with the sources
//                                     (a view uses a Tailwind class that was never compiled, or app.js changed)
//
// Pipeline: Tailwind CLI compiles the CSS (purged to the classes the views use) → esbuild minifies it again (shorter colours,
// merged rules, dropped comments) and minifies app.js (identifiers, whitespace, dead branches). Top-level names in app.js
// are not renamed (it is a plain script, not a module), so inline handlers and window.* hooks keep working.

import { execFileSync, spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { brotliCompressSync, gzipSync, constants as zc } from 'node:zlib';
import {
    readFileSync, writeFileSync, readdirSync, unlinkSync,
    existsSync, mkdirSync, statSync,
} from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const tailwindCli = join(root, 'node_modules', 'tailwindcss', 'lib', 'cli.js');
const inCss = join(root, 'resources', 'css', 'app.css');
const jsSrc = join(root, 'resources', 'js', 'app.js');
const buildDir = join(root, 'public', 'assets', 'build');
const outCss = join(buildDir, 'app.css');
const tmpCss = join(buildDir, '.tailwind.css');

if (!existsSync(buildDir)) mkdirSync(buildDir, { recursive: true });
if (!existsSync(tailwindCli)) {
    console.error('tailwindcss not installed — run: npm install');
    process.exit(1);
}

const watch = process.argv.includes('--watch');
const check = process.argv.includes('--check');
const sha = (buf) => createHash('sha256').update(buf).digest('hex');
const short = (buf) => sha(buf).slice(0, 10);

if (watch) {
    const p = spawn(process.execPath, [tailwindCli, '-i', inCss, '-o', outCss, '--watch'], { stdio: 'inherit' });
    p.on('exit', (c) => process.exit(c ?? 0));
} else {
    const esbuild = await import('esbuild').catch(() => null);
    if (!esbuild) {
        console.error('esbuild not installed — run: npm install');
        process.exit(1);
    }

    const built = await compile(esbuild);

    if (check) {
        const committedCss = existsSync(outCss) ? sha(readFileSync(outCss)) : '';
        let manifest = {};
        try { manifest = JSON.parse(readFileSync(join(buildDir, 'manifest.json'), 'utf8')); } catch { /* missing = stale */ }
        const staleCss = sha(built.css) !== committedCss;
        const staleJs = manifest['app.js'] !== `app.${short(built.js)}.js`;
        if (staleCss || staleJs) {
            console.error(`${staleCss ? 'public/assets/build/app.css' : 'public/assets/build/app.*.js'} is out of date — run: npm run build`);
            process.exit(1);
        }
        console.log('assets are up to date');
    } else {
        finalize(built);
    }
}

/** Compile both assets to their final (minified) bytes without touching the committed files. */
async function compile(esbuild) {
    execFileSync(process.execPath, [tailwindCli, '-i', inCss, '-o', tmpCss, '--minify'], { stdio: 'ignore' });
    const tailwind = readFileSync(tmpCss, 'utf8');
    unlinkSync(tmpCss);

    const css = await esbuild.transform(tailwind, { loader: 'css', minify: true, legalComments: 'none' });
    const jsRaw = readFileSync(jsSrc, 'utf8');
    const js = await esbuild.transform(jsRaw, { loader: 'js', minify: true, target: 'es2019', legalComments: 'none' });

    return { css: Buffer.from(css.code), js: Buffer.from(js.code), tailwindBytes: Buffer.byteLength(tailwind), jsRawBytes: Buffer.byteLength(jsRaw) };
}

function finalize(built) {
    const manifest = {};
    writeFileSync(outCss, built.css);

    const emit = (key, ext, buf) => {
        const name = `app.${short(buf)}.${ext}`;
        writeFileSync(join(buildDir, name), buf);
        writeFileSync(join(buildDir, `${name}.br`), brotliCompressSync(buf, { params: { [zc.BROTLI_PARAM_QUALITY]: 11, [zc.BROTLI_PARAM_SIZE_HINT]: buf.length } }));
        writeFileSync(join(buildDir, `${name}.gz`), gzipSync(buf, { level: 9 }));
        manifest[key] = name;

        return name;
    };
    const cssName = emit('app.css', 'css', built.css);
    const jsName = emit('app.js', 'js', built.js);

    const keep = new Set([cssName, jsName].flatMap((n) => [n, `${n}.br`, `${n}.gz`]));
    for (const file of readdirSync(buildDir)) {
        if (/^app\.[0-9a-f]{10}\.(css|js)(\.br|\.gz)?$/.test(file) && !keep.has(file)) {
            unlinkSync(join(buildDir, file));
        }
    }

    writeFileSync(join(buildDir, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n');

    const kb = (n) => (n / 1024).toFixed(1).padStart(6) + ' KB';
    const line = (label, before, name, buf) => {
        const br = statSync(join(buildDir, `${name}.br`)).size;
        console.log(`  ${label.padEnd(4)} ${kb(before)} → ${kb(buf.length)} minified → ${kb(br)} brotli  (${name})`);
    };
    console.log('built assets:');
    line('css', built.tailwindBytes, cssName, built.css);
    line('js', built.jsRawBytes, jsName, built.js);
}
