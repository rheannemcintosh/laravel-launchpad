#!/usr/bin/env node

// Gives a new app built from the template its own name, tagline and icon, without
// editing any code by hand.
//
//   npm run brand -- --name "Acme Tracker" --tagline "Track everything." --icon brain
//
// Each option is optional, but at least one is needed. Running it again with different
// values changes them again, and a run that changes nothing leaves the files as they
// were. Nothing is written until every input has been checked.
//
// With --package it also gives the app its own Composer package name and README title,
// which the setup command (deploy/bootstrap.sh) does. Without it composer.json and the
// README are never touched, so running this on the template itself changes neither.
//
// It uses only what is already installed: the Lucide package for the icon shapes, and
// rsvg-convert (when it is on the PATH) for the favicon.ico and apple-touch-icon.png
// picture files, which cannot be made without an image converter.

import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { parseArgs } from 'node:util';

const usage = `Usage: npm run brand -- [--name "My App"] [--tagline "What it does"] [--icon rocket] [--package vendor/app]

  --name      The app name, written to APP_NAME in .env and .env.example.
  --tagline   A short line about the app, written to APP_TAGLINE.
  --icon      A Lucide icon name such as rocket, brain or heart-pulse. Browse them at
              https://lucide.dev/icons. Sets the icon used in the app and the browser tab.
  --package   A Composer package name such as acme/acme-tracker. Renames the package in
              composer.json, keeps composer.lock in step, and gives the README the --name
              as its title and the app's name in its example commands.

At least one option is needed.`;

const packageRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const lucideDirectory = join(packageRoot, 'node_modules/@lucide/vue/dist/esm');

function fail(message) {
    console.error(`Error: ${message}`);
    process.exit(1);
}

let options;

try {
    ({ values: options } = parseArgs({
        options: {
            name: { type: 'string' },
            tagline: { type: 'string' },
            icon: { type: 'string' },
            package: { type: 'string' },
            // Where the files to change live. Only the tests need this.
            root: { type: 'string' },
            help: { type: 'boolean', short: 'h' },
        },
        strict: true,
    }));
} catch (error) {
    fail(`${error.message}\n\n${usage}`);
}

if (options.help) {
    console.log(usage);
    process.exit(0);
}

if (!options.name && !options.tagline && !options.icon && !options.package) {
    fail(
        `Give at least one of --name, --tagline, --icon or --package.\n\n${usage}`,
    );
}

const root = resolve(options.root ?? packageRoot);
const inRoot = (path) => join(root, path);

// ---------------------------------------------------------------------------
// Check the inputs

// Values go inside double quotes in the .env file, where these characters would
// end the value early or be read as something else.
function checkText(label, value) {
    const text = value.trim();

    if (text === '') {
        fail(`--${label} cannot be empty.`);
    }

    if (
        /["\\$]/.test(text) ||
        [...text].some((character) => character.charCodeAt(0) < 32)
    ) {
        fail(
            `--${label} cannot contain a double quote, backslash, dollar sign or line break, because they break the .env file.`,
        );
    }

    return text;
}

const name =
    options.name === undefined ? undefined : checkText('name', options.name);
const tagline =
    options.tagline === undefined
        ? undefined
        : checkText('tagline', options.tagline);

// A Composer package name, as Composer checks it: lowercase, vendor/name.
const composerNamePattern =
    /^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/;

function checkPackage(value) {
    if (!composerNamePattern.test(value)) {
        fail(
            `"${value}" is not a Composer package name. Use lowercase letters, numbers and ., _ or - in the form vendor/app, such as acme/acme-tracker.`,
        );
    }

    return value;
}

const packageName =
    options.package === undefined
        ? undefined
        : checkPackage(options.package.trim());

function findIcon(input) {
    // "HeartPulse" is accepted as well as "heart-pulse".
    const kebab = /[A-Z]/.test(input)
        ? input.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase()
        : input;

    if (!existsSync(lucideDirectory)) {
        fail(
            'The Lucide icon package is not installed. Run npm install first.',
        );
    }

    const file = join(lucideDirectory, 'icons', `${kebab}.mjs`);

    if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(kebab) || !existsSync(file)) {
        fail(
            `"${input}" is not a Lucide icon. Browse the names at https://lucide.dev/icons and use one like "heart-pulse".`,
        );
    }

    // The name the icon is imported by, e.g. "heart-pulse" is imported as HeartPulse.
    const pascal = kebab
        .split('-')
        .map((part) => part[0].toUpperCase() + part.slice(1))
        .join('');
    const exportLine = readFileSync(
        join(lucideDirectory, 'lucide-vue.mjs'),
        'utf8',
    )
        .split('\n')
        .find((line) => line.includes(`'./icons/${kebab}.mjs'`));
    const names = [...(exportLine ?? '').matchAll(/default as (\w+)/g)].map(
        (match) => match[1],
    );
    const component =
        names.find((exported) => exported === pascal) ??
        names.find(
            (exported) =>
                !exported.startsWith('Lucide') && !exported.endsWith('Icon'),
        );

    if (!component) {
        fail(
            `Could not work out how "${input}" is imported from the Lucide package.`,
        );
    }

    return { file, component };
}

const icon =
    options.icon === undefined ? undefined : findIcon(options.icon.trim());

// ---------------------------------------------------------------------------
// Work out every change first, so nothing is written if any part fails

const changes = [];

function plan(path, content) {
    changes.push({ path, content });
}

function readRequired(path) {
    const file = inRoot(path);

    if (!existsSync(file)) {
        fail(
            `${path} was not found in ${root}. Run this from the root of the app.`,
        );
    }

    return readFileSync(file, 'utf8');
}

// Sets KEY="value", adding the line if the file does not have one yet.
function setEnvValue(contents, key, value) {
    const line = `${key}="${value}"`;
    const pattern = new RegExp(`^${key}=.*$`, 'm');

    if (pattern.test(contents)) {
        return contents.replace(pattern, () => line);
    }

    return `${contents}${contents === '' || contents.endsWith('\n') ? '' : '\n'}${line}\n`;
}

function updateEnvFile(path, required) {
    if (!required && !existsSync(inRoot(path))) {
        console.log(`Skipped ${path}, which does not exist yet.`);

        return;
    }

    let contents = readRequired(path);

    if (name !== undefined) {
        contents = setEnvValue(contents, 'APP_NAME', name);
    }

    if (tagline !== undefined) {
        contents = setEnvValue(contents, 'APP_TAGLINE', tagline);
    }

    plan(path, contents);
}

if (name !== undefined || tagline !== undefined) {
    updateEnvFile('.env.example', true);
    updateEnvFile('.env', false);
}

let iconNote = '';

if (icon) {
    // The component imports one Lucide icon and draws it. Swap that one name.
    const componentPath = 'resources/js/components/AppLogoIcon.vue';
    const component = readRequired(componentPath);
    const current = component.match(
        /import \{ (\w+) \} from '@lucide\/vue';/,
    )?.[1];

    if (!current || !component.includes(`<${current} `)) {
        fail(
            `${componentPath} does not look like the template's, so the icon was not changed. Edit the Lucide import there by hand.`,
        );
    }

    plan(
        componentPath,
        component
            .replace(
                `import { ${current} } from '@lucide/vue';`,
                `import { ${icon.component} } from '@lucide/vue';`,
            )
            .replace(`<${current} `, `<${icon.component} `),
    );

    // The browser tab icon is built from the same shapes.
    let shapes;

    try {
        const { createSSRApp, h } = await import('vue');
        const { renderToString } = await import('vue/server-renderer');
        const { default: Icon } = await import(pathToFileURL(icon.file).href);
        const svg = await renderToString(
            createSSRApp({ render: () => h(Icon) }),
        );

        // One shape per line, e.g. <path d="..."/>.
        shapes = svg
            .slice(svg.indexOf('>') + 1, svg.lastIndexOf('</svg>'))
            .replace(/<(\w+)([^>]*)><\/\1>/g, '<$1$2/>')
            .replace(/\/></g, '/>\n    <');
    } catch {
        fail(
            'Could not read the icon from the Lucide package. Run npm install and try again.',
        );
    }

    const tile = (
        radius,
    ) => `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32">
  <rect width="32" height="32"${radius ? ` rx="${radius}"` : ''} fill="#18181b"/>
  <g transform="translate(6 6) scale(0.8333)" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    ${shapes}
  </g>
</svg>
`;
    const roundedTile = tile(7);

    plan('public/favicon.svg', roundedTile);

    // The picture versions need an image converter. Without one they are left alone.
    const toPng = (svg, size) => {
        const result = spawnSync(
            'rsvg-convert',
            ['-w', String(size), '-h', String(size), '-f', 'png'],
            {
                input: svg,
                maxBuffer: 10 * 1024 * 1024,
            },
        );

        return result.status === 0 ? result.stdout : null;
    };

    // An .ico file can hold PNG images, so it is put together here from three sizes.
    const buildIco = (images) => {
        const header = Buffer.alloc(6);
        header.writeUInt16LE(1, 2);
        header.writeUInt16LE(images.length, 4);

        let offset = header.length + images.length * 16;
        const entries = images.map(({ size, png }) => {
            const entry = Buffer.alloc(16);
            entry[0] = size;
            entry[1] = size;
            entry.writeUInt16LE(1, 4);
            entry.writeUInt16LE(32, 6);
            entry.writeUInt32LE(png.length, 8);
            entry.writeUInt32LE(offset, 12);
            offset += png.length;

            return entry;
        });

        return Buffer.concat([
            header,
            ...entries,
            ...images.map(({ png }) => png),
        ]);
    };

    const small = [16, 32, 48].map((size) => ({
        size,
        png: toPng(roundedTile, size),
    }));
    const apple = toPng(tile(0), 180);

    if (apple && small.every(({ png }) => png)) {
        plan('public/favicon.ico', buildIco(small));
        plan('public/apple-touch-icon.png', apple);
    } else {
        iconNote =
            'favicon.ico and apple-touch-icon.png were left as they were, because rsvg-convert is not installed.\n' +
            'They are only used by old browsers and the iOS home screen. Install librsvg (brew install librsvg or\n' +
            'apt install librsvg2-bin) and run this again to update them.';
    }
}

// ---------------------------------------------------------------------------
// The Composer package name and the README

// What PHP's json_encode writes with no flags, which is what Composer fingerprints:
// slashes escaped, anything but plain ASCII as \uXXXX, and an empty array or object
// both written as [].
function phpEncode(value) {
    if (value === null) {
        return 'null';
    }

    if (typeof value === 'boolean' || typeof value === 'number') {
        return String(value);
    }

    if (typeof value === 'string') {
        const escapes = {
            '"': '\\"',
            '\\': '\\\\',
            '/': '\\/',
            '\b': '\\b',
            '\f': '\\f',
            '\n': '\\n',
            '\r': '\\r',
            '\t': '\\t',
        };
        let encoded = '"';

        for (const char of value) {
            const code = char.codePointAt(0);
            const unit = (number) =>
                `\\u${number.toString(16).padStart(4, '0')}`;

            if (char in escapes) {
                encoded += escapes[char];
            } else if (code > 0xffff) {
                const offset = code - 0x10000;
                encoded +=
                    unit(0xd800 + (offset >> 10)) +
                    unit(0xdc00 + (offset & 0x3ff));
            } else if (code < 0x20 || code >= 0x80) {
                encoded += unit(code);
            } else {
                encoded += char;
            }
        }

        return `${encoded}"`;
    }

    if (Array.isArray(value)) {
        return value.length === 0
            ? '[]'
            : `[${value.map(phpEncode).join(',')}]`;
    }

    const keys = Object.keys(value);

    return keys.length === 0
        ? '[]'
        : `{${keys.map((key) => `${phpEncode(key)}:${phpEncode(value[key])}`).join(',')}}`;
}

// The fingerprint Composer stores in composer.lock as "content-hash", from the parts
// of composer.json that decide what gets installed, including the package name.
function composerContentHash(composer) {
    const relevant = [
        'name',
        'version',
        'require',
        'require-dev',
        'conflict',
        'replace',
        'provide',
        'minimum-stability',
        'prefer-stable',
        'repositories',
        'extra',
    ];
    const content = {};

    for (const key of relevant) {
        if (key in composer) {
            content[key] = composer[key];
        }
    }

    if (composer.config?.platform) {
        content.config = { platform: composer.config.platform };
    }

    const sorted = Object.fromEntries(
        Object.keys(content)
            .sort()
            .map((key) => [key, content[key]]),
    );

    return createHash('md5').update(phpEncode(sorted)).digest('hex');
}

let projectNotes = [];

if (packageName !== undefined) {
    // composer.json: only the name line changes, so the file keeps its formatting.
    const composerText = readRequired('composer.json');
    let composer;

    try {
        composer = JSON.parse(composerText);
    } catch {
        fail('composer.json is not valid JSON, so it was not changed.');
    }

    const oldName = composer.name;
    const nameLine = new RegExp(
        `^(\\s*"name":\\s*)${JSON.stringify(oldName).replace(/[.*+?^${}()|[\]\\/]/g, '\\$&')}`,
        'm',
    );

    if (typeof oldName !== 'string' || !nameLine.test(composerText)) {
        fail(
            'composer.json has no package name line this command can change, so nothing was changed.',
        );
    }

    const renamedText = composerText.replace(
        nameLine,
        (_, lead) => `${lead}${JSON.stringify(packageName)}`,
    );

    plan('composer.json', renamedText);

    // composer.lock stores a fingerprint that includes the name. Left alone it would be
    // out of date, so it is brought in step, after checking that the current one is the
    // fingerprint of the current composer.json, which also proves this command reads
    // the file the way Composer does.
    if (existsSync(inRoot('composer.lock'))) {
        const lockText = readRequired('composer.lock');
        const stored = lockText.match(/"content-hash": "([0-9a-f]{32})"/)?.[1];

        if (!stored || composerContentHash(composer) !== stored) {
            fail(
                'composer.lock does not match composer.json, or this command cannot read it, so nothing was changed. Run composer update --lock, then run this again.',
            );
        }

        plan(
            'composer.lock',
            lockText.replace(
                /"content-hash": "[0-9a-f]{32}"/,
                `"content-hash": "${composerContentHash(JSON.parse(renamedText))}"`,
            ),
        );
    } else {
        projectNotes.push(
            'composer.lock does not exist, so only composer.json was renamed.',
        );
    }

    // The README: its title is the app's name, and the template's own name in its
    // example commands (inside code blocks only) becomes the app's.
    if (existsSync(inRoot('README.md'))) {
        const [, repository] = packageName.split('/');
        const lines = readRequired('README.md').split('\n');
        let inCode = false;

        for (let index = 0; index < lines.length; index++) {
            if (/^\s*```/.test(lines[index])) {
                inCode = !inCode;
            } else if (inCode) {
                lines[index] = lines[index]
                    .replaceAll(
                        'rheannemcintosh/laravel-launchpad',
                        packageName,
                    )
                    .replaceAll('laravel-launchpad', repository);
            }
        }

        if (name !== undefined && lines[0].startsWith('# ')) {
            lines[0] = `# ${name}`;
        } else if (name !== undefined) {
            projectNotes.push(
                'The README does not start with a title, so its title was not changed.',
            );
        }

        plan('README.md', lines.join('\n'));
    }
}

// ---------------------------------------------------------------------------
// Write what changed

for (const { path, content } of changes) {
    const file = inRoot(path);
    const next = Buffer.isBuffer(content) ? content : Buffer.from(content);
    const same = existsSync(file) && readFileSync(file).equals(next);

    if (!same) {
        writeFileSync(file, next);
    }

    console.log(`${same ? 'Unchanged' : 'Updated  '} ${path}`);
}

for (const note of projectNotes) {
    console.log(`\n${note}`);
}

if (iconNote) {
    console.log(`\n${iconNote}`);
}

if (icon) {
    console.log(
        '\nRebuild the front end for the new icon to show (npm run build), or let npm run dev pick it up.',
    );
}

if (name !== undefined || tagline !== undefined) {
    console.log(
        '\nThe name and tagline are read when the app runs, so they need no rebuild. The deployed app takes both\n' +
            'from .env.example when deploy/azure-provision.sh runs (see the README).',
    );
}
