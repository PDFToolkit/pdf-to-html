"""Independent checks of PDF codec interpretation, exact PNG samples and ICC provenance."""
import argparse
import base64
import hashlib
import io
import json
from pathlib import Path
import struct
import subprocess
import tempfile
import zlib

import html5lib
from PIL import Image, ImageCms
from pypdf import PdfReader

ROOT = Path(__file__).resolve().parents[1]


def run(command):
    result = subprocess.run([str(part) for part in command], cwd=ROOT, capture_output=True, text=True, timeout=120)
    assert result.returncode == 0, (command, result.stdout, result.stderr)
    return result


def chunks(data):
    assert data.startswith(b'\x89PNG\r\n\x1a\n')
    offset = 8
    result = {}
    while offset < len(data):
        size = struct.unpack_from('>I', data, offset)[0]
        name = data[offset + 4:offset + 8]
        body = data[offset + 8:offset + 8 + size]
        assert len(body) == size
        assert zlib.crc32(name + body) == struct.unpack_from('>I', data, offset + 8 + size)[0]
        result[name] = result.get(name, b'') + body
        offset += size + 12
    assert offset == len(data)
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--bootstrap', type=Path)
    parser.add_argument('--qpdf', default='qpdf')
    parser.add_argument('--report', type=Path, required=True)
    parser.add_argument('--export', type=Path)
    args = parser.parse_args()
    fixture = ROOT / 'tests/Fixtures/codec-images.pdf'
    manifest = json.loads(fixture.with_suffix('.json').read_text())
    assert hashlib.sha256(fixture.read_bytes()).hexdigest() == manifest['sha256']
    run([args.qpdf, '--check', fixture])
    pdf = PdfReader(fixture, strict=True)
    assert len(pdf.pages) == len(manifest['cases']) == 23
    (ROOT / '_build').mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='codec-images-', dir=ROOT / '_build') as temporary:
        directory = args.export.resolve() if args.export else Path(temporary)
        php = [args.php] + (['-d', 'auto_prepend_file=' + str(args.bootstrap.resolve())] if args.bootstrap else [])
        run([*php, ROOT / 'tools/export-codec-images.php', directory])
        exported = json.loads((directory / 'report.json').read_text())
        html_parser = html5lib.HTMLParser(namespaceHTMLElements=False)
        html = html_parser.parse((directory / 'derived.html').read_text())
        assert not html_parser.errors, html_parser.errors
        images = list(html.iter('img'))
        assert len(images) == 23
        assert sum(item['code'] == 'image-placeholder' for item in exported['diagnostics']) == 4
        report = {'sourceSha256': manifest['sha256'], 'htmlSha256': exported['htmlSha256'], 'cases': [], 'pixelPolicy': 'JPX samples exact against OpenJPEG goldens. JPEG IDCT tolerance one level against Pillow/libjpeg YCbCr output. No claim of ICC display/pixel equivalence.'}
        browser_cases = []
        for page, case, element in zip(pdf.pages, manifest['cases'], images):
            image = page['/Resources']['/XObject']['/I']
            assert hashlib.sha256(image._data).hexdigest() == case['sourceSha256']
            assert image['/Width'] == case['width'] and image['/Height'] == case['height']
            assert element.attrib['alt'] == case['name']
            location = element.attrib['src']
            item = {'name': case['name'], 'pdfSourceHash': True, 'html5': True}
            if case['fallback']:
                assert location.startswith('data:image/gif;base64,')
                assert any(case['fallback'] in item['message'] for item in exported['diagnostics'] if item['code'] == 'source-interpretation'), case['name']
                item['explicitFallback'] = case['fallback']
                report['cases'].append(item)
                browser_cases.append({'name': case['name'], 'fallback': case['fallback'], 'width': 1, 'height': 1})
                continue
            if location.startswith('data:'):
                media, value = location.split(';base64,', 1)
                assert media == 'data:' + case['mediaType']
                data = base64.b64decode(value, validate=True)
            else:
                data = (directory / location).read_bytes()
            if case['mediaType'] == 'image/jpeg':
                assert data == image._data
                item['jpegPreservedExactly'] = True
            else:
                content = chunks(data)
                assert content[b'IHDR'][:8] == struct.pack('>II', case['width'], case['height'])
                assert content[b'IHDR'][8:10] == bytes([case['bits'], (0 if case['components'] == 1 else 2) + (4 if case['alpha'] else 0)])
                rows = zlib.decompress(content[b'IDAT'])
                stride = (case['width'] * (case['components'] + int(case['alpha'])) * case['bits'] + 7) // 8
                assert len(rows) == (stride + 1) * case['height']
                assert all(rows[y * (stride + 1)] == 0 for y in range(case['height']))
                pixels = b''.join(rows[y * (stride + 1) + 1:(y + 1) * (stride + 1)] for y in range(case['height']))
                if case['samples'] is not None:
                    assert pixels == base64.b64decode(case['samples'], validate=True), case['name']
                    item['openJpegSamplesExact'] = True
                else:
                    source = Image.open(ROOT / 'tests/Fixtures/codec-images/rgb.jpg')
                    source.draft('RGB' if case['name'] == 'jpeg-identifiers-priority' else 'YCbCr', source.size)
                    assert source.mode == ('RGB' if case['name'] == 'jpeg-identifiers-priority' else 'YCbCr')
                    source.load()
                    difference = max(abs(a - b) for a, b in zip(source.tobytes(), pixels))
                    assert difference <= 1, (case['name'], difference)
                    item['pillowIdctMaximumDifference'] = difference
                if case['iccSha256']:
                    name, profile = content[b'iCCP'].split(b'\0', 1)
                    assert name == b'PDF ICC' and profile[0] == 0
                    raw = zlib.decompress(profile[1:])
                    assert hashlib.sha256(raw).hexdigest() == case['iccSha256']
                    assert raw == (ROOT / 'tests/Fixtures/codec-images/gray.icc').read_bytes()
                    item['iccBytesExact'] = True
                else:
                    assert b'iCCP' not in content
                item['pngSha256'] = hashlib.sha256(data).hexdigest()
            with Image.open(io.BytesIO(data)) as decoded:
                decoded.load()
                assert decoded.size == (case['width'], case['height'])
                item['pillowReadable'] = True
                witness = None
                if case['iccSha256']:
                    independent = Image.frombytes('L', (case['width'], case['height']), base64.b64decode(case['samples']))
                    independent_png = io.BytesIO(); independent.save(independent_png, format='PNG', icc_profile=raw)
                    witness = 'data:image/png;base64,' + base64.b64encode(independent_png.getvalue()).decode()
                    preview = ImageCms.profileToProfile(decoded, ImageCms.ImageCmsProfile(io.BytesIO(raw)), ImageCms.createProfile('sRGB'), outputMode='RGB')
                elif case['bits'] == 16 and case['components'] == 1:
                    preview = Image.new('RGB', decoded.size)
                    preview.putdata([(value >> 8,) * 3 for value in decoded.get_flattened_data()])
                else:
                    preview = decoded.convert('RGBA')
                canvas = Image.new('RGBA', preview.size, 'white')
                canvas.alpha_composite(preview.convert('RGBA'))
                browser_cases.append({'name': case['name'], 'fallback': None, 'width': case['width'], 'height': case['height'], 'rgba': base64.b64encode(canvas.tobytes()).decode(), 'tolerance': 2, 'iccProfile': case['iccSha256'] is not None, 'witness': witness})
            report['cases'].append(item)
        report.update(ok=True, nativeCases=19, explicitFallbacks=4)
        (directory / 'browser-cases.json').write_text(json.dumps({'htmlSha256': exported['htmlSha256'], 'sourceSha256': manifest['sha256'], 'cases': browser_cases}, indent=2) + '\n')
        args.report.parent.mkdir(parents=True, exist_ok=True)
        args.report.write_text(json.dumps(report, indent=2) + '\n')
    print('Validated 23 codec image cases: exact JPX/ICC, JPEG PDF colour transforms, four explicit rendering fallbacks')


if __name__ == '__main__':
    main()
