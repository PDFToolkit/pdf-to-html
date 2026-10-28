'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium, firefox } = require('playwright');

class SignaturePopoverValidation {
  constructor(cases, output) {
    this.cases = cases;
    this.output = output;
  }

  async run() {
    await fs.mkdir(this.output, { recursive: true });
    const report = { playwright: require('playwright/package.json').version, casesSha256: crypto.createHash('sha256').update(JSON.stringify(this.cases)).digest('hex'), browsers: [] };
    try {
      assert(Object.keys(this.cases).length > 0);
      for (const [name, type] of Object.entries({ chromium, firefox })) {
        const browser = await type.launch({ headless: true });
        const result = { name, version: browser.version(), cases: [] };
        report.browsers.push(result);
        try {
          let requests = 0;
          for (const [caseName, fixture] of Object.entries(this.cases)) {
            assert.match(caseName, /^[a-z0-9-]+$/);
            const context = await browser.newContext({ viewport: { width: 1000, height: 700 }, javaScriptEnabled: fixture.activeRuntime === true, serviceWorkers: 'block' });
            await context.route('**/*', route => { requests++; return route.abort(); });
            const page = await context.newPage();
            await page.setContent(fixture.html);
            const button = page.locator('button[data-pdf-signature-status]');
            assert.equal(await button.count(), 1, caseName);
            assert.equal(await button.getAttribute('data-pdf-signature-status'), fixture.status, caseName);
            const target = await button.getAttribute('popovertarget');
            assert(target, `${caseName}: no native popover target`);
            const status = page.locator(`[id="${target}"]`);
            assert.equal(await status.count(), 1, caseName);
            assert.equal(await status.textContent(), fixture.message, caseName);
            assert.equal(await status.locator('img,script').count(), 0, caseName);
            assert.equal(await page.locator('script').count(), fixture.activeRuntime ? 1 : 0, caseName);
            const valueBefore = await button.evaluate(element => element.value);
            if (fixture.activeRuntime) {
              assert.equal(await button.getAttribute('data-pdf-readonly'), 'true', caseName);
            }
            assert.equal(await status.evaluate(element => element.matches(':popover-open')), false, caseName);
            await button.click();
            assert.equal(await status.evaluate(element => element.matches(':popover-open')), true, `${caseName}: pointer did not show the message`);
            assert.equal(await status.isVisible(), true, caseName);
            await page.screenshot({ path: path.join(this.output, `${name}-${caseName}.png`), fullPage: true });
            await button.click();
            assert.equal(await status.evaluate(element => element.matches(':popover-open')), false, caseName);
            await button.focus();
            await page.keyboard.press('Space');
            assert.equal(await status.evaluate(element => element.matches(':popover-open')), true, `${caseName}: keyboard did not show the message`);
            await page.keyboard.press('Escape');
            assert.equal(await status.evaluate(element => element.matches(':popover-open')), false, caseName);
            assert.equal(await button.evaluate(element => element.value), valueBefore, `${caseName}: status inspection mutated the field`);
            assert.equal(await button.getAttribute('data-pdf-signature-status'), fixture.status, caseName);
            result.cases.push({ name: caseName, status: fixture.status, activeRuntime: fixture.activeRuntime === true, escapedText: true, pointer: true, keyboard: true, passed: true });
            await context.close();
          }
          assert.equal(requests, 0, `${name}: signature status caused a resource request`);
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
  const [input, output] = process.argv.slice(2);
  assert(input && output, 'Usage: node tools/validate_signature_popovers.cjs cases.json output-directory');
  await new SignaturePopoverValidation(JSON.parse(await fs.readFile(input, 'utf8')), output).run();
}

main().catch(error => { console.error(error); process.exitCode = 1; });
