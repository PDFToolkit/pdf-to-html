'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium, firefox } = require('playwright');

// Original PDF-associated CSS is the browser oracle, not PHP's CSS parser.
// The reference adds the preserved original fragments after the filtered styles.
class AssociatedStylesValidation {
  constructor(cases, output) { this.cases = cases; this.output = output; }

  async observe(page, selectors) {
    return page.evaluate(selectors => Object.fromEntries(Object.entries(selectors).map(([selector, properties]) => [selector,
      Array.from(document.querySelectorAll(selector), element => ({
        properties: Object.fromEntries(properties.map(property => [property, getComputedStyle(element).getPropertyValue(property)])),
        rect: (() => { const box = element.getBoundingClientRect(); return { x: box.x, y: box.y, width: box.width, height: box.height }; })(),
      })),
    ])), selectors);
  }

  async run() {
    assert.equal(Object.keys(this.cases).length, 2);
    await fs.mkdir(this.output, { recursive: true });
    const report = { playwright: require('playwright/package.json').version, casesSha256: crypto.createHash('sha256').update(JSON.stringify(this.cases)).digest('hex'), browsers: [] };
    try {
      for (const [name, type] of Object.entries({ chromium, firefox })) {
        const browser = await type.launch({ headless: true });
        const entry = { name, version: browser.version(), cases: [] };
        report.browsers.push(entry);
        try {
          const context = await browser.newContext({ viewport: { width: 1200, height: 900 }, javaScriptEnabled: false, serviceWorkers: 'block' });
          const requests = [];
          await context.route('**/*', route => { requests.push(route.request().url()); return route.abort(); });
          for (const [caseName, fixture] of Object.entries(this.cases)) {
            assert.match(caseName, /^[a-z0-9-]+$/);
            assert(!fixture.html.includes('<script'), 'No source script should be emitted');
            const actualPage = await context.newPage();
            const referencePage = await context.newPage();
            await actualPage.setContent(fixture.html);
            await referencePage.setContent(fixture.html.replace('</head>', fixture.originalStyles + '</head>'));
            const actual = await this.observe(actualPage, fixture.selectors);
            const reference = await this.observe(referencePage, fixture.selectors);
            const result = { name: caseName, actual, reference, passed: false };
            entry.cases.push(result);
            for (const [selector, observed] of Object.entries(actual)) {
              assert(observed.length > 0, `${name}/${caseName}: missing ${selector}`);
              assert.equal(observed.length, reference[selector].length);
              for (let index = 0; index < observed.length; ++index) {
                assert.deepEqual(observed[index].properties, reference[selector][index].properties, `${name}/${caseName}/${selector}: CSS differs from original fragments`);
                assert(observed[index].rect.width > 0 && observed[index].rect.height > 0);
                for (const coordinate of ['x', 'y', 'width', 'height']) {
                  assert(Math.abs(observed[index].rect[coordinate] - reference[selector][index].rect[coordinate]) < 0.1, `${name}/${caseName}/${selector}: geometry differs at ${coordinate}`);
                }
                for (const [property, expected] of Object.entries(fixture.expected[selector] || {})) {
                  assert.equal(observed[index].properties[property], expected, `${name}/${caseName}/${property}`);
                }
              }
            }
            assert.deepEqual(requests, [], 'Associated styles must not cause network requests');
            await actualPage.screenshot({ path: path.join(this.output, `${name}-${caseName}.png`), fullPage: true });
            await actualPage.close();
            await referencePage.close();
            result.passed = true;
          }
          await context.close();
        } finally { await browser.close(); }
      }
      report.passed = true;
    } catch (error) {
      report.passed = false;
      report.error = error.message;
      throw error;
    } finally {
      await fs.writeFile(path.join(this.output, 'report.json'), JSON.stringify(report, null, 2) + '\n');
    }
    console.log(JSON.stringify({ passed: true, browsers: report.browsers.map(({ name, version, cases }) => ({ name, version, cases: cases.length })) }));
  }
}

async function main() {
  const [input, output] = process.argv.slice(2);
  assert(input && output, 'Usage: node tools/validate_associated_styles.cjs cases.json output-directory');
  await new AssociatedStylesValidation(JSON.parse(await fs.readFile(input, 'utf8')), output).run();
}
main().catch(error => { console.error(error); process.exitCode = 1; });
