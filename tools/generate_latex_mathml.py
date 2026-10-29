#!/usr/bin/env python3
"""Regenerate the original LaTeX interoperability fixtures with an explicit local engine."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile


class LatexMathMlFixtures:
    NAMES = ('mathml-se', 'mathml-se-af', 'latex-layout')
    EPOCH = '1793232000'
    PACKAGES = ('latex.ltx', 'tagpdf.sty', 'latex-lab-testphase-math.sty',
                'luamml.sty', 'unicode-math.sty', 'fontspec.sty', 'amsmath.sty')

    def __init__(self, engine: Path, output: Path):
        self.engine = engine.absolute()
        self.output = output.resolve()
        self.environment = dict(os.environ, SOURCE_DATE_EPOCH=self.EPOCH,
                                FORCE_SOURCE_DATE='1', TZ='UTC')

    def command(self, arguments: list[str], cwd: Path) -> str:
        result = subprocess.run(arguments, cwd=cwd, env=self.environment,
                                stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                                timeout=120, check=False)
        text = result.stdout.decode('utf-8', errors='replace')
        if result.returncode:
            raise RuntimeError('LaTeX fixture command failed:\n' + text[-12000:])
        return text

    @staticmethod
    def identity(data: bytes) -> dict:
        return {'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()}

    def compile(self, source: Path) -> tuple[bytes, str]:
        with tempfile.TemporaryDirectory(prefix='pdftoolkit-latex-mathml-') as directory:
            root = Path(directory)
            shutil.copyfile(source, root / source.name)
            arguments = [str(self.engine), '--no-shell-escape', '--halt-on-error',
                         '--interaction=nonstopmode', source.name]
            self.command(arguments, root)
            self.command(arguments, root)
            return ((root / (source.stem + '.pdf')).read_bytes(),
                    (root / (source.stem + '.log')).read_text(errors='replace'))

    def run(self) -> dict:
        engine_version = self.command([str(self.engine), '--version'], self.output).splitlines()[0]
        kpsewhich = self.engine.parent / 'kpsewhich'
        packages = {}
        for name in self.PACKAGES:
            path = Path(self.command([str(kpsewhich), name], self.output).strip())
            packages[name] = self.identity(path.read_bytes())
        manifest = {
            'schemaVersion': 1,
            'author': 'PDFToolkit',
            'sourceLicense': 'MIT (repository LICENSE)',
            'embeddedFonts': {'family': 'Latin Modern / Latin Modern Math',
                              'license': 'GUST Font License 1.0',
                              'url': 'https://www.gust.org.pl/projects/e-foundry/licenses/GUST-FONT-LICENSE.txt'},
            'compiler': engine_version,
            'sourceDateEpoch': int(self.EPOCH),
            'timeZone': 'UTC',
            'command': ['lualatex', '--no-shell-escape', '--halt-on-error',
                        '--interaction=nonstopmode', '<source.tex>'],
            'passes': 2,
            'packages': packages,
            'reference': 'https://latex3.github.io/tagging-project/documentation/usage-instructions#mathml-structure-element-tagging',
            'fixtures': {},
        }
        for name in self.NAMES:
            source = self.output / (name + '.tex')
            pdf, log = self.compile(source)
            second, _ = self.compile(source)
            if pdf != second:
                raise RuntimeError(name + ': independent clean builds produced different PDF bytes')
            (self.output / (name + '.pdf')).write_bytes(pdf)
            # Keep the actual package versions and warnings without machine-specific paths.
            versions = [line.strip() for line in log.splitlines()
                        if line.startswith(('LaTeX2e ', 'L3 programming layer ', 'Package: ',
                                            'Document Class: ', 'LaTeX Warning:', 'Package tagpdf Warning:'))]
            manifest['fixtures'][name] = {
                'source': {'path': source.name, **self.identity(source.read_bytes())},
                'pdf': {'path': name + '.pdf', **self.identity(pdf)},
                'mode': 'mathml-SE' if name == 'mathml-se' else 'mathml-SE,mathml-AF',
                'independentCleanBuildsIdentical': True,
                'logVersionsAndWarnings': versions,
            }
        (self.output / 'provenance.json').write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + '\n')
        return manifest


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--lualatex', type=Path, required=True)
    parser.add_argument('--output', type=Path, default=Path(__file__).resolve().parents[1] / 'tests/Fixtures/mathml-latex')
    arguments = parser.parse_args()
    print(json.dumps(LatexMathMlFixtures(arguments.lualatex, arguments.output).run(), indent=2))
