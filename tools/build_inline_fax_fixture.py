"""Independent libtiff fax samples in reproducible pypdf files; --refresh needs Pillow only."""
from pathlib import Path
import argparse
import base64
import hashlib
import io
import json
import random

from pypdf import PdfWriter
from pypdf.generic import ArrayObject as A, BooleanObject as Bool, DictionaryObject as D, NameObject as N, NumberObject as I, TextStringObject as T, DecodedStreamObject
from build_reading_fixture import HELVETICA_ASCII_WIDTHS


ROOT = Path(__file__).resolve().parents[1] / 'tests/Fixtures'


def refresh():
    from PIL import Image, TiffImagePlugin, features
    import PIL
    cases = []
    rng = random.Random(20261014)
    for name, width, height, pattern, compression, mixed in [
        ('group4-checkerboard', 64, 8, 'checker', 'group4', False),
        ('group4-runs-and-pass', 256, 16, 'runs', 'group4', False),
        ('group4-random', 41, 9, 'random', 'group4', False),
        ('group3-one-dimensional', 64, 8, 'checker', 'group3', False),
        ('group3-mixed-dimensional', 64, 8, 'runs', 'group3', True),
    ]:
        image = Image.new('1', (width, height), 1)
        pixels = image.load()
        for y in range(height):
            for x in range(width):
                pixels[x, y] = ((x // 4 + y) % 2) if pattern == 'checker' else (int(x < 16 + y * 3 or x >= width - 20 - y) if pattern == 'runs' else rng.randint(0, 1))
        buffer = io.BytesIO()
        info = TiffImagePlugin.ImageFileDirectory_v2()
        if mixed:
            info[292] = 1
        image.save(buffer, format='TIFF', compression=compression, tiffinfo=info)
        encoded = buffer.getvalue()
        decoded = Image.open(io.BytesIO(encoded))
        assert decoded.tobytes() == image.tobytes()
        offsets, counts = decoded.tag_v2[273], decoded.tag_v2[279]
        assert len(offsets) == len(counts) == 1
        data = encoded[offsets[0]:offsets[0] + counts[0]]
        # libtiff compressed these TIFFs with PhotometricInterpretation=MinIsBlack.
        # PDF's fax decoded sample polarity therefore needs BlackIs1=true.
        params = {'K': -1 if compression == 'group4' else (2 if mixed else 0), 'Columns': width, 'Rows': height, 'EndOfBlock': compression == 'group4', 'BlackIs1': True}
        if compression == 'group3':
            params['EndOfLine'] = True
        cases.append({'name': name, 'width': width, 'height': height, 'params': params, 'encoded': base64.b64encode(data).decode(), 'bitmap': base64.b64encode(image.tobytes()).decode(), 'encodedSha256': hashlib.sha256(data).hexdigest()})
    group4 = dict(cases[2])
    group4['name'] = 'group4-rows-without-eofb'
    bits = ''.join(f'{byte:08b}' for byte in base64.b64decode(group4['encoded']))
    marker = bits.rfind('000000000001000000000001')
    assert marker > 0
    bits = bits[:marker]
    data = int(bits.ljust((len(bits) + 7) // 8 * 8, '0'), 2).to_bytes((len(bits) + 7) // 8, 'big')
    group4.update(encoded=base64.b64encode(data).decode(), encodedSha256=hashlib.sha256(data).hexdigest(), params={**group4['params'], 'EndOfBlock': False})
    cases.append(group4)
    group3 = dict(cases[3])
    group3['name'] = 'group3-return-to-control'
    rtc = int('000000000001' * 6, 2).to_bytes(9, 'big')
    data = base64.b64decode(group3['encoded']) + rtc
    group3.update(encoded=base64.b64encode(data).decode(), encodedSha256=hashlib.sha256(data).hexdigest(), params={**group3['params'], 'Rows': 1, 'EndOfBlock': True})
    cases.append(group3)
    manifest = {'license': 'MIT', 'encoder': f'Pillow {PIL.__version__} / libtiff {features.version("libtiff")}', 'cases': cases}
    (ROOT / 'inline-fax-cases.json').write_text(json.dumps(manifest, indent=2) + '\n', encoding='utf-8')


def fixture():
    manifest = json.loads((ROOT / 'inline-fax-cases.json').read_text())
    writer = PdfWriter()
    writer.pdf_header = '%PDF-2.0'
    writer.add_metadata({'/Producer': 'Independent libtiff/pypdf inline CCITT fixture'})
    tree = D({N('/Type'): N('/StructTreeRoot')})
    tree_ref = writer._add_object(tree)
    document = D({N('/Type'): N('/StructElem'), N('/S'): N('/Document'), N('/P'): tree_ref})
    document_ref = writer._add_object(document)
    tree[N('/K')] = document_ref
    children, nums = [], []
    for index, case in enumerate(manifest['cases']):
        page = writer.add_blank_page(width=case['width'], height=case['height'])
        page[N('/Resources')] = D()
        element = writer._add_object(D({N('/Type'): N('/StructElem'), N('/S'): N('/Figure'), N('/P'): document_ref, N('/Pg'): page.indirect_reference, N('/K'): I(0), N('/Alt'): T(case['name'])}))
        children.append(element)
        nums.extend([I(index), A([element])])
        params = ' '.join(f'/{key} {str(value).lower()}' for key, value in case['params'].items())
        data = base64.b64decode(case['encoded'])
        assert hashlib.sha256(data).hexdigest() == case['encodedSha256']
        stream = DecodedStreamObject()
        stream.set_data(f'/Figure <</MCID 0>> BDC q {case["width"]} 0 0 {case["height"]} 0 0 cm BI /W {case["width"]} /H {case["height"]} /BPC 1 /CS /G /F /CCF /DP <<{params}>> ID '.encode() + data + b' EI Q EMC\n')
        page[N('/Contents')] = writer._add_object(stream)
        page[N('/StructParents')] = I(index)
    page = writer.add_blank_page(width=220, height=30)
    descriptor = writer._add_object(D({N('/Type'): N('/FontDescriptor'), N('/FontName'): N('/Helvetica'), N('/Flags'): I(32), N('/FontBBox'): A([I(-166), I(-225), I(1000), I(931)]), N('/ItalicAngle'): I(0), N('/Ascent'): I(718), N('/Descent'): I(-207), N('/CapHeight'): I(718), N('/StemV'): I(88)}))
    font = writer._add_object(D({N('/Type'): N('/Font'), N('/Subtype'): N('/Type1'), N('/BaseFont'): N('/Helvetica'), N('/Encoding'): N('/WinAnsiEncoding'), N('/FirstChar'): I(32), N('/LastChar'): I(126), N('/Widths'): A([I(width) for width in HELVETICA_ASCII_WIDTHS]), N('/FontDescriptor'): descriptor}))
    page[N('/Resources')] = D({N('/Font'): D({N('/F1'): font})})
    index = len(manifest['cases'])
    element = writer._add_object(D({N('/Type'): N('/StructElem'), N('/S'): N('/P'), N('/P'): document_ref, N('/Pg'): page.indirect_reference, N('/K'): I(0)}))
    children.append(element)
    nums.extend([I(index), A([element])])
    page[N('/StructParents')] = I(index)
    stream = DecodedStreamObject()
    stream.set_data(b'/P <</MCID 0>> BDC BT /F1 12 Tf 2 12 Td (Following the fax images) Tj ET EMC')
    page[N('/Contents')] = writer._add_object(stream)
    document[N('/K')] = A(children)
    tree[N('/ParentTree')] = writer._add_object(D({N('/Nums'): A(nums)}))
    tree[N('/ParentTreeNextKey')] = I(index + 1)
    writer._root_object.update({N('/StructTreeRoot'): tree_ref, N('/MarkInfo'): D({N('/Marked'): Bool(True)}), N('/Lang'): T('en')})
    output = io.BytesIO()
    writer.write(output)
    return output.getvalue()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true')
    parser.add_argument('--refresh', action='store_true', help='Regenerate independently encoded samples with the installed Pillow/libtiff.')
    args = parser.parse_args()
    if args.refresh:
        refresh()
    data = fixture()
    path = ROOT / 'inline-fax.pdf'
    if args.check:
        assert path.read_bytes() == data, 'The inline fax PDF differs from its pypdf generator'
    else:
        path.write_bytes(data)
    print('Verified independent fax fixture' if args.check else 'Built independent fax fixture')


if __name__ == '__main__':
    main()
