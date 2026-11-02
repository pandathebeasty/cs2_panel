'use strict';

const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const destination = path.join(root, 'assets', 'vendor');
const distributions = [
    ['gsap', 'gsap.min.js'],
    ['lenis', 'lenis.min.js'],
    ['lenis', 'lenis.css'],
];

fs.mkdirSync(destination, { recursive: true });

for (const [name, file] of distributions) {
    const source = path.join(root, 'node_modules', name, 'dist', file);
    fs.copyFileSync(source, path.join(destination, file));
    console.log(`Vendored ${name}: ${file}`);
}

for (const name of ['gsap', 'lenis']) {
    const directory = path.join(root, 'node_modules', name);
    const license = ['LICENSE', 'LICENSE.md', 'LICENSE.txt'].find(file =>
        fs.existsSync(path.join(directory, file))
    );
    if (name === 'gsap' && !license) {
        const metadata = JSON.parse(fs.readFileSync(path.join(directory, 'package.json'), 'utf8'));
        if (typeof metadata.license !== 'string' || !metadata.license.includes('gsap.com/standard-license')) {
            throw new Error('The GSAP package does not declare its official license.');
        }
        fs.writeFileSync(path.join(destination, 'gsap-NOTICE.txt'), [
            `GSAP ${metadata.version}`,
            metadata.license,
            'Official source: https://github.com/greensock/GSAP',
            'The unmodified distribution retains its original copyright and license header.',
            'Used for website animation, not a visual animation authoring tool.',
            '',
        ].join('\n'));
        console.log('Preserved the GSAP license declaration and original distribution notices.');
        continue;
    }
    if (!license) {
        throw new Error(`The ${name} package is missing its license file.`);
    }
    fs.copyFileSync(path.join(directory, license), path.join(destination, `${name}-LICENSE.txt`));
    console.log(`Preserved ${name} license.`);
}
