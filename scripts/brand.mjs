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
// It uses only what is already installed: the Lucide package for the icon shapes, and
// rsvg-convert (when it is on the PATH) for the favicon.ico and apple-touch-icon.png
// picture files, which cannot be made without an image converter.

import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { parseArgs } from 'node:util';

const usage = `Usage: npm run brand -- [--name "My App"] [--tagline "What it does"] [--icon rocket]

  --name      The app name, written to APP_NAME in .env and .env.example.
  --tagline   A short line about the app, written to APP_TAGLINE.
  --icon      A Lucide icon name such as rocket, brain or heart-pulse. Browse them at
              https://lucide.dev/icons. Sets the icon used in the app and the browser tab.

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

if (!options.name && !options.tagline && !options.icon) {
    fail(`Give at least one of --name, --tagline or --icon.\n\n${usage}`);
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
        '\nThe name and tagline are read when the app runs, so they need no rebuild. The deployed app takes its\n' +
            'name from APP_TITLE and its tagline from .env.example when deploy/azure-provision.sh runs (see the README).',
    );
}
