'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium, firefox } = require('playwright');

// Independent browser parsing and computed CSS verify 4.3.7.3/9/11 semantics.
class AttributeConformanceValidation {
  constructor(html, output) {
    this.html = html;
    this.output = output;
  }

  async run() {
    const report = {
      playwright: require('playwright/package.json').version,
      htmlSha256: crypto.createHash('sha256').update(this.html).digest('hex'),
      browsers: [],
      passed: false,
    };
    await fs.mkdir(this.output, { recursive: true });
    try {
      for (const [name, type] of Object.entries({ chromium, firefox })) {
        const browser = await type.launch({ headless: true });
        const record = { name, version: browser.version(), passed: false };
        report.browsers.push(record);
        try {
          const context = await browser.newContext({ javaScriptEnabled: false, serviceWorkers: 'block' });
          await context.route('**/*', route => route.abort());
          const page = await context.newPage();
          await page.setContent(this.html, { waitUntil: 'load' });
          record.observed = await page.evaluate(() => {
            const element = document.getElementById('attributes');
            const style = getComputedStyle(element);
            return {
              upper: element.getAttribute('data-pdf-up-Énergie-v'),
              lower: element.getAttribute('data-pdf-up-énergie-v'),
              temperature: element.getAttribute('data-pdf-up-温度+°c-v'),
              accent: style.getPropertyValue('--Accent'),
              lowerAccent: style.getPropertyValue('--accent'),
              color: style.color,
              sharedClassDisplay: getComputedStyle(document.getElementById('cascade')).display,
              listLabelDisplay: getComputedStyle(document.getElementById('list-label')).display,
            };
          });
          assert.deepEqual(record.observed, {
            upper: '0', lower: '1', temperature: '20',
            accent: 'red', lowerAccent: 'blue', color: 'rgb(255, 0, 0)',
            sharedClassDisplay: 'flex', listLabelDisplay: 'inline',
          }, `${name}: HTML attribute names and case-sensitive CSS variables`);
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

async function main() {
  const [input, output] = process.argv.slice(2);
  assert(input && output, 'Usage: node tools/validate_attribute_conformance.cjs input.html output-directory');
  await new AttributeConformanceValidation(await fs.readFile(input, 'utf8'), output).run();
}

main().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
