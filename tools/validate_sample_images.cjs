'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium, firefox } = require('playwright');

class SampleImageValidation {
  constructor(input, output) {
    this.input = input;
    this.output = output;
  }

  expectedPixels(fixture) {
    const bytes = Buffer.from(fixture.samples, 'base64');
    const stride = Math.ceil(fixture.width * fixture.components * fixture.bits / 8);
    const maximum = 2 ** fixture.bits - 1;
    const pixels = [];
    for (let y = 0; y < fixture.height; ++y) {
      for (let x = 0; x < fixture.width; ++x) {
        const values = [];
        for (let channel = 0; channel < fixture.components; ++channel) {
          let bit = y * stride * 8 + (x * fixture.components + channel) * fixture.bits;
          let sample = 0;
          for (let i = 0; i < fixture.bits; ++i, ++bit) {
            sample = sample * 2 + ((bytes[Math.floor(bit / 8)] >> (7 - bit % 8)) & 1);
          }
          values.push(Math.round(sample * 255 / maximum));
        }
        pixels.push(...(values.length === 1 ? [values[0], values[0], values[0]] : values), 255);
      }
    }
    return pixels;
  }

  async run() {
    const manifest = JSON.parse(await fs.readFile(path.join(this.input, 'report.json'), 'utf8'));
    const html = await fs.readFile(path.join(this.input, 'derived.html'), 'utf8');
    const report = { playwright: require('playwright/package.json').version,
      sourceSha256: manifest.sourceSha256, htmlSha256: crypto.createHash('sha256').update(html).digest('hex'),
      browsers: [], passed: false };
    assert.equal(report.htmlSha256, manifest.htmlSha256, 'Manifest describes the HTML actually opened');
    await fs.mkdir(this.output, { recursive: true });
    try {
      for (const [name, type] of Object.entries({ chromium, firefox })) {
        const browser = await type.launch({ headless: true });
        const record = { name, version: browser.version(), cases: [], passed: false };
        report.browsers.push(record);
        try {
          const context = await browser.newContext({ javaScriptEnabled: true, serviceWorkers: 'block', deviceScaleFactor: 1 });
          await context.route('**/*', route => route.abort());
          const page = await context.newPage();
          const errors = [];
          page.on('pageerror', error => errors.push(error.message));
          await page.setContent(html, { waitUntil: 'load' });
          for (const fixture of manifest.cases) {
            const observed = await Promise.race([page.evaluate(async alt => {
              const matches = [...document.images].filter(image => image.alt === alt);
              if (matches.length !== 1) throw new Error(`Expected one image for ${alt}`);
              const image = matches[0];
              await image.decode();
              const rectangle = image.getBoundingClientRect();
              const canvas = document.createElement('canvas');
              canvas.width = image.naturalWidth;
              canvas.height = image.naturalHeight;
              const graphics = canvas.getContext('2d', { colorSpace: 'srgb' });
              graphics.drawImage(image, 0, 0);
              return { natural: [image.naturalWidth, image.naturalHeight], logical: [rectangle.width, rectangle.height],
                pixels: [...graphics.getImageData(0, 0, canvas.width, canvas.height).data],
                mediaType: image.src.slice(0, image.src.indexOf(';')) };
            }, fixture.name), new Promise((_, reject) => { const timeout = setTimeout(() => reject(new Error(`${name}/${fixture.name}: decode timed out`)), 15000); timeout.unref(); })]);
            assert.deepEqual(observed.logical, fixture.logicalCssPixels, `${name}/${fixture.name}: logical display dimensions`);
            let largestDifference = 0;
            if (fixture.fallback === null) {
              assert.equal(observed.mediaType, 'data:image/png');
              assert.deepEqual(observed.natural, [fixture.width, fixture.height]);
              const expected = this.expectedPixels(fixture);
              assert.equal(observed.pixels.length, expected.length);
              for (let i = 0; i < expected.length; ++i) {
                const difference = Math.abs(observed.pixels[i] - expected[i]);
                largestDifference = Math.max(largestDifference, difference);
                // PNG16 is reduced to canvas8 by the browser; rounding may differ by one level.
                assert(difference <= (fixture.bits === 16 && i % 4 !== 3 ? 1 : 0),
                  `${name}/${fixture.name}: component ${i}, expected ${expected[i]}, got ${observed.pixels[i]}`);
              }
            } else {
              assert.equal(observed.mediaType, 'data:image/gif');
              assert.deepEqual(observed.natural, [1, 1]);
              assert.equal(observed.pixels[3], 0, `${name}/${fixture.name}: fallback is transparent`);
            }
            record.cases.push({ name: fixture.name, fallback: fixture.fallback, natural: observed.natural,
              logical: observed.logical, largestDifference, passed: true });
          }
          assert.deepEqual(errors, []);
          await page.screenshot({ path: path.join(this.output, `${name}.png`), fullPage: true });
          record.passed = true;
        } finally {
          await browser.close();
        }
      }
      report.passed = true;
    } catch (error) {
      report.error = error.message;
      throw error;
    } finally {
      await fs.writeFile(path.join(this.output, 'report.json'), JSON.stringify(report, null, 2) + '\n');
    }
    console.log(JSON.stringify(report));
  }
}

const [input, output] = process.argv.slice(2);
assert(input && output, 'Usage: node tools/validate_sample_images.cjs input-directory output-directory');
new SampleImageValidation(path.resolve(input), path.resolve(output)).run().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
