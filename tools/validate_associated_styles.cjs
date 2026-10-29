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

  async listLayout(page) {
    return page.evaluate(() => ({
      viewport: innerWidth,
      width: document.documentElement.scrollWidth,
      nestedLists: document.querySelectorAll('li ul').length,
      items: Array.from(document.querySelectorAll('[data-pdf-list-layout]'), item => {
        const label = item.querySelector(':scope > [data-pdf-list-marker]');
        const paragraph = item.querySelector(':scope > [data-pdf-se-type="LBody"] [data-pdf-list-first]');
        const range = document.createRange();
        range.selectNodeContents(paragraph);
        const lines = Array.from(range.getClientRects(), rect => ({ top: rect.top, bottom: rect.bottom, left: rect.left, right: rect.right }));
        return { labelTop: label.getBoundingClientRect().top, text: paragraph.textContent, lines };
      }),
    }));
  }

  async run() {
    assert.deepEqual(Object.keys(this.cases).sort(), ['latex-catalogue-styles', 'nested-conditions', 'structure-stylesheet']);
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
          const resources = new Map(), assetRequests = [];
          await context.route('**/*', route => {
            const url = route.request().url(), resource = resources.get(url);
            if (resource && route.request().method() === 'GET') {
              if (resource.contentType === 'text/css') assetRequests.push(url);
              return route.fulfill(resource);
            }
            requests.push(url); return route.abort();
          });
          for (const [caseName, fixture] of Object.entries(this.cases)) {
            assert.match(caseName, /^[a-z0-9-]+$/);
            assert(!fixture.html.includes('<script'), 'No source script should be emitted');
            const actualPage = await context.newPage();
            const referencePage = await context.newPage();
            const referenceHtml = fixture.html.replace('</head>', fixture.originalStyles + '</head>');
            if (fixture.assets) {
              const base = `https://associated-styles.invalid/${caseName}/`;
              resources.set(base + 'actual.html', { contentType: 'text/html', body: fixture.html });
              resources.set(base + 'reference.html', { contentType: 'text/html', body: referenceHtml });
              for (const asset of fixture.assets) {
                assert.match(asset.filename, /^[A-Za-z0-9][A-Za-z0-9_.-]*$/);
                assert.equal(asset.mediaType, 'text/css');
                resources.set(base + asset.filename, { contentType: asset.mediaType, body: asset.bytes });
              }
              // Serve the unchanged HTML and its returned CSS in memory; no network is used.
              await actualPage.goto(base + 'actual.html');
              await referencePage.goto(base + 'reference.html');
            } else {
              await actualPage.setContent(fixture.html);
              await referencePage.setContent(referenceHtml);
            }
            const actual = await this.observe(actualPage, fixture.selectors);
            const reference = await this.observe(referencePage, fixture.selectors);
            const result = { name: caseName, actual, reference, passed: false };
            entry.cases.push(result);
            if (caseName === 'structure-stylesheet') {
              result.stylesheet = await actualPage.evaluate(() => {
                const paragraph = document.getElementById('structure-css'), link = paragraph.previousElementSibling;
                return { tag: link.tagName, rel: link.rel, href: link.getAttribute('href'), text: paragraph.textContent, body: link.parentElement.tagName };
              });
              assert.deepEqual(result.stylesheet, { tag: 'LINK', rel: 'stylesheet', href: 'structure.css', text: 'This child structure retains the linked stylesheet.', body: 'BODY' });
              assert.equal(assetRequests.length, 2, 'Both browser documents must load the actual returned stylesheet asset');
              result.assetRequests = [...assetRequests];
            }
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
            if (caseName === 'latex-catalogue-styles') {
              result.listLayouts = [];
              for (const width of [1200, 320]) {
                await actualPage.setViewportSize({ width, height: 900 });
                const layout = await this.listLayout(actualPage);
                result.listLayouts.push(layout);
                assert.equal(layout.items.length, 5, 'All five source list items must remain');
                assert.equal(layout.nestedLists, 1, 'Nested list hierarchy must remain');
                assert(layout.width <= layout.viewport, `${name}: list overflow at ${width}px`);
                for (const item of layout.items) {
                  assert(item.text.length > 0 && item.lines.length > 0);
                  assert(Math.abs(item.labelTop - item.lines[0].top) <= 4, `${name}: list marker detached from first line at ${width}px`);
                }
                if (width === 320) assert(layout.items.some(item => item.lines.length > 1), 'Narrow view must exercise wrapped list paragraphs');
                await actualPage.screenshot({ path: path.join(this.output, `${name}-${caseName}-${width}.png`), fullPage: true });
              }
              await actualPage.setViewportSize({ width: 1200, height: 900 });
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
