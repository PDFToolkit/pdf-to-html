'use strict';
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium, firefox } = require('playwright');

class CodecImageValidation {
  constructor(input, output) { this.input = input; this.output = output; }
  async run() {
    const manifest = JSON.parse(await fs.readFile(path.join(this.input, 'browser-cases.json'), 'utf8'));
    const html = await fs.readFile(path.join(this.input, 'derived.html'), 'utf8');
    assert.equal(crypto.createHash('sha256').update(html).digest('hex'), manifest.htmlSha256);
    const report = { htmlSha256: manifest.htmlSha256, sourceSha256: manifest.sourceSha256,
      playwright: require('playwright/package.json').version, browsers: [], passed: false };
    await fs.mkdir(this.output, { recursive: true });
    try {
      for (const [name, type] of Object.entries({ chromium, firefox })) {
        const browser = await type.launch({ headless: true });
        const entry = { name, version: browser.version(), cases: [], passed: false };
        report.browsers.push(entry);
        try {
          const context = await browser.newContext({ javaScriptEnabled: true, serviceWorkers: 'block', deviceScaleFactor: 1 });
          await context.route('**/*', route => route.abort());
          const page = await context.newPage();
          const errors = [];
          page.on('pageerror', error => errors.push(error.message));
          await page.setContent(html, { waitUntil: 'load' });
          for (const fixture of manifest.cases) {
            const actual = await page.evaluate(async fixture => {
              const name = fixture.name;
              const images = [...document.images].filter(image => image.alt === name);
              if (images.length !== 1) throw new Error(`Expected one image: ${name}`);
              const image = images[0]; await image.decode();
              const canvas = document.createElement('canvas');
              canvas.width = image.naturalWidth; canvas.height = image.naturalHeight;
              const ctx = canvas.getContext('2d', { colorSpace: 'srgb' });
              ctx.fillStyle = 'white'; ctx.fillRect(0, 0, canvas.width, canvas.height); ctx.drawImage(image, 0, 0);
              const pixels = [...ctx.getImageData(0, 0, canvas.width, canvas.height).data];
              let witnessDifference = null;
              if (fixture.witness) {
                const witness = new Image(); witness.src = fixture.witness; await witness.decode();
                ctx.fillStyle = 'white'; ctx.fillRect(0, 0, canvas.width, canvas.height); ctx.drawImage(witness, 0, 0);
                const comparison = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
                witnessDifference = Math.max(...pixels.map((value, i) => Math.abs(value - comparison[i])));
              }
              return { width: canvas.width, height: canvas.height, pixels, witnessDifference };
            }, fixture);
            assert.equal(actual.width, fixture.width); assert.equal(actual.height, fixture.height);
            let maximumDifference = 0;
            if (fixture.fallback === null) {
              const expected = Buffer.from(fixture.rgba, 'base64');
              assert.equal(actual.pixels.length, expected.length);
              for (let i = 0; i < expected.length; ++i) maximumDifference = Math.max(maximumDifference, Math.abs(actual.pixels[i] - expected[i]));
              if (fixture.iccProfile) assert.equal(actual.witnessDifference, 0, `${name}/${fixture.name}: independent Pillow PNG with the same profile`);
              if (!fixture.iccProfile) assert(maximumDifference <= fixture.tolerance, `${name}/${fixture.name}: difference ${maximumDifference}, allowed ${fixture.tolerance}`);
            } else { assert.deepEqual(actual.pixels, [255, 255, 255, 255]); }
            entry.cases.push({ name: fixture.name, maximumDifference, fallback: fixture.fallback, iccWitnessDifference: actual.witnessDifference, iccDisplayComparison: fixture.iccProfile ? 'LittleCMS comparison reported separately; same-browser independent PNG equality is exact' : null, passed: true });
          }
          assert.deepEqual(errors, []);
          await page.screenshot({ path: path.join(this.output, `${name}.png`), fullPage: true });
          entry.passed = true;
        } finally { await browser.close(); }
      }
      report.passed = true;
    } catch (error) { report.error = error.message; throw error; }
    finally { await fs.writeFile(path.join(this.output, 'report.json'), JSON.stringify(report, null, 2) + '\n'); }
    console.log(JSON.stringify({ passed: true, browsers: report.browsers.map(item => ({ name: item.name, cases: item.cases.length })) }));
  }
}
const [input, output] = process.argv.slice(2);
assert(input && output, 'Usage: node tools/validate_codec_images.cjs export-directory output-directory');
new CodecImageValidation(path.resolve(input), path.resolve(output)).run().catch(error => { console.error(error); process.exitCode = 1; });
