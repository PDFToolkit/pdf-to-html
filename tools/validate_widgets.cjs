'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium, firefox } = require('playwright');

// Actual pointer states in two engines validate the CSS after PHP derivation and
// safe serialization. PDF JavaScript and all network requests remain disabled.
class WidgetBrowserValidation {
  constructor(cases, output) {
    this.cases = cases;
    this.output = output;
  }

  async observe(button) {
    return button.evaluate(element => {
      const style = getComputedStyle(element);
      const rect = element.getBoundingClientRect();
      const canvas = document.createElement('canvas');
      canvas.width = canvas.height = 1;
      const paint = canvas.getContext('2d');
      paint.fillStyle = style.borderTopColor;
      paint.fillRect(0, 0, 1, 1);
      return {
        active: element.matches(':active'),
        type: element.type,
        checked: element.checked,
        appearance: style.appearance,
        backgroundImage: style.backgroundImage,
        filter: style.filter,
        translate: style.translate,
        border: style.borderTopColor,
        borderChannels: Array.from(paint.getImageData(0, 0, 1, 1).data),
        background: style.backgroundColor,
        rect: { x: rect.x, y: rect.y, width: rect.width, height: rect.height },
        states: Object.fromEntries(Array.from(element.querySelectorAll('[data-pdf-widget-appearance]'), item => [item.dataset.pdfWidgetAppearance, getComputedStyle(item).opacity])),
      };
    });
  }

  async run() {
    await fs.mkdir(this.output, { recursive: true });
    const report = {
      playwright: require('playwright/package.json').version,
      casesSha256: crypto.createHash('sha256').update(JSON.stringify(this.cases)).digest('hex'),
      browsers: [],
    };
    try {
      assert(Object.keys(this.cases).length > 0, 'No widget cases');
      for (const [name, type] of Object.entries({ chromium, firefox })) {
        const browser = await type.launch({ headless: true });
        const result = { name, version: browser.version(), cases: [] };
        report.browsers.push(result);
        try {
          const context = await browser.newContext({ viewport: { width: 800, height: 600 }, javaScriptEnabled: false, serviceWorkers: 'block' });
          await context.route('**/*', route => route.abort());
          for (const [caseName, fixture] of Object.entries(this.cases)) {
            assert.match(caseName, /^[a-z0-9-]+$/);
            const page = await context.newPage();
            await page.setContent(fixture.html);
            const button = page.locator('[data-pdf-widget-style]');
            assert.equal(await button.count(), 1);
            const before = await this.observe(button);
            const box = before.rect;
            await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
            const hover = await this.observe(button);
            await page.mouse.down();
            const active = await this.observe(button);
            await page.screenshot({ path: path.join(this.output, `${name}-${caseName}-pressed.png`) });
            await page.mouse.up();
            await page.mouse.move(600, 400);
            const after = await this.observe(button);
            await page.screenshot({ path: path.join(this.output, `${name}-${caseName}-released.png`) });
            const record = { name: caseName, before, hover, active, after, passed: false };
            result.cases.push(record);
            const label = `${name}/${caseName}`;
            assert.equal(active.active, true, `${label}: native pressed state not reached`);
            assert.equal(after.active, false, label);
            assert.deepEqual(after.rect, before.rect, `${label}: release must restore geometry`);
            assert.equal(after.filter, before.filter, `${label}: release must restore filter`);
            assert.equal(after.border, before.border, `${label}: release must restore border`);
            if (['checkbox', 'radio'].includes(before.type)) {
              assert.equal(before.appearance, 'none', `${label}: native theme may mask the border`);
              assert.equal(before.checked, false, label);
              assert.equal(after.checked, true, `${label}: native input selection must still work`);
              assert.notEqual(after.backgroundImage, 'none', `${label}: selected state has no CSS indicator`);
            }
            if (fixture.override) {
              assert.equal(await button.getAttribute('id'), 'author-control');
              assert.equal(active.filter, 'sepia(1)', `${label}: source CSS must remain authoritative`);
              assert.equal(active.border, 'rgb(10, 20, 30)', label);
            } else if (fixture.mode === 'I') {
              assert.equal(active.filter, 'invert(1)', `${label}: content inversion not applied`);
            } else if (fixture.mode === 'O') {
              // The EC3 definition is channel-wise inversion of the border,
              // not an extra outline or inversion of the entire control.
              assert.equal(before.border, 'rgb(32, 64, 96)', label);
              assert.deepEqual(active.borderChannels, [223, 191, 159, 255], `${label}: border channels were not inverted`);
              assert.equal(active.filter, 'none', label);
              assert.equal(active.background, before.background, label);
            } else if (fixture.mode === 'N') {
              assert.equal(active.filter, 'none', label);
              assert.equal(active.background, before.background, label);
              assert.equal(active.border, before.border, label);
              assert.deepEqual(active.rect, before.rect, label);
            } else if (!fixture.appearances) {
              assert.equal(active.rect.x, before.rect.x + 1, `${label}: Push must offset the control`);
              assert.equal(active.rect.y, before.rect.y + 1, label);
            }
            if (fixture.appearances) {
              assert.equal(before.states.normal, '1', label);
              assert.equal(hover.states.normal, '0', label);
              assert.equal(hover.states.rollover, '1', label);
              if (['P', 'T'].includes(fixture.mode)) {
                assert.equal(active.states.normal, '0', label);
                assert.equal(active.states.down, '1', label);
                assert.deepEqual(active.rect, before.rect, `${label}: AP.D replaces offset fallback`);
              } else {
                assert.equal(active.states.normal, '1', label);
                assert.equal(active.states.down, undefined, `${label}: non-Push must ignore AP.D`);
              }
              assert.equal(after.states.normal, '1', label);
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
  const [input, output] = process.argv.slice(2);
  assert(input && output, 'Usage: node tools/validate_widgets.cjs cases.json output-directory');
  await new WidgetBrowserValidation(JSON.parse(await fs.readFile(input, 'utf8')), output).run();
}

main().catch(error => { console.error(error); process.exitCode = 1; });
