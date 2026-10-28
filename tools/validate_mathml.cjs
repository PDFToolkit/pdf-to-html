'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium, firefox } = require('playwright');

// Browser geometry is an independent oracle for the generated PHP output:
// matching strings alone would not detect a CSS class disabling math layout.
class MathmlBrowserValidation {
  constructor(cases, output) {
    this.cases = cases;
    this.output = output;
  }

  async run() {
    assert(Object.keys(this.cases).length > 0, 'No MathML cases were exported');
    await fs.mkdir(this.output, { recursive: true });
    const report = {
      playwright: require('playwright/package.json').version,
      casesSha256: crypto.createHash('sha256').update(JSON.stringify(this.cases)).digest('hex'),
      browsers: [],
    };
    try {
      for (const [name, type] of Object.entries({ chromium, firefox })) {
        const browser = await type.launch({ headless: true });
        const result = { name, version: browser.version(), cases: [] };
        report.browsers.push(result);
        try {
          const context = await browser.newContext({
            viewport: { width: 1000, height: 700 },
            javaScriptEnabled: false,
            serviceWorkers: 'block',
          });
          // Derivation validation must not fetch input-controlled resources.
          await context.route('**/*', route => route.abort());
          const reference = await context.newPage();
          await reference.setContent('<!doctype html><math id="inline"><mi>x</mi></math><math id="block" display="block"><mi>x</mi></math>');
          // Firefox versions may serialize native MathML display without the
          // CSS "math" token. Compare with this engine's unstyled native roots,
          // then verify actual exponent/fraction geometry independently.
          result.nativeDisplay = await reference.evaluate(() => Object.fromEntries(
            ['inline', 'block'].map(mode => [mode, getComputedStyle(document.getElementById(mode)).display]),
          ));
          await reference.close();
          for (const [caseName, fixture] of Object.entries(this.cases)) {
            assert.match(caseName, /^[a-z0-9-]+$/);
            assert(['inline', 'block'].includes(fixture.expectedDisplay));
            const page = await context.newPage();
            await page.setContent(fixture.html, { waitUntil: 'load' });
            const observed = await page.evaluate(() => {
              const rect = element => {
                const box = element.getBoundingClientRect();
                return { top: box.top, bottom: box.bottom, width: box.width, height: box.height };
              };
              return Array.from(document.querySelectorAll('math'), math => ({
                namespace: math.namespaceURI,
                display: getComputedStyle(math).display,
                rect: rect(math),
                superscripts: Array.from(math.querySelectorAll('msup'), item => ({
                  base: rect(item.children[0]),
                  exponent: rect(item.children[1]),
                })),
                fractions: Array.from(math.querySelectorAll('mfrac'), item => ({
                  numerator: rect(item.children[0]),
                  denominator: rect(item.children[1]),
                })),
              }));
            });
            await page.screenshot({ path: path.join(this.output, `${name}-${caseName}.png`), fullPage: true });
            const htmlDisplays = await page.evaluate(selectors => Object.fromEntries(selectors.map(selector => {
              const elements = Array.from(document.querySelectorAll(selector));
              return [selector, elements.map(element => getComputedStyle(element).display)];
            })), Object.keys(fixture.htmlDisplays || {}));
            const record = { name: caseName, expectedDisplay: fixture.expectedDisplay, math: observed, htmlDisplays, passed: false };
            result.cases.push(record);
            const label = `${name}/${caseName}`;
            assert(observed.length > 0, `${label}: no MathML root`);
            for (const math of observed) {
              assert.equal(math.namespace, 'http://www.w3.org/1998/Math/MathML', label);
              assert.equal(math.display, result.nativeDisplay[fixture.expectedDisplay], `${label}: display differs from native ${fixture.expectedDisplay} MathML`);
              assert(math.rect.width > 0 && math.rect.height > 0, `${label}: invisible math`);
              assert(math.superscripts.length > 0 || math.fractions.length > 0, `${label}: no layout-sensitive expression`);
              for (const { base, exponent } of math.superscripts) {
                assert(exponent.bottom < base.bottom - 1, `${label}: exponent is not raised`);
                assert(exponent.height < base.height, `${label}: exponent is not smaller`);
              }
              for (const { numerator, denominator } of math.fractions) {
                assert(numerator.bottom <= denominator.top + 0.5, `${label}: fraction is not vertically stacked`);
              }
            }
            for (const [selector, expected] of Object.entries(fixture.htmlDisplays || {})) {
              assert(htmlDisplays[selector].length > 0, `${label}: missing HTML control ${selector}`);
              for (const display of htmlDisplays[selector]) {
                assert.equal(display, expected, `${label}: HTML CSS precedence at ${selector}`);
              }
            }
            record.passed = true;
            await page.close();
          }
        } finally {
          await browser.close();
        }
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
  const input = process.argv[2];
  const output = process.argv[3];
  assert(input && output, 'Usage: node tools/validate_mathml.cjs cases.json output-directory');
  const cases = JSON.parse(await fs.readFile(input, 'utf8'));
  await new MathmlBrowserValidation(cases, output).run();
}

main().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
