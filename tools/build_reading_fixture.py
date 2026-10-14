"""Original MIT tagged PDF 2.0 fixture, generated independently with pypdf."""

from pathlib import Path
import argparse
import hashlib
import io
import json

import pypdf
from pypdf import PdfWriter
from pypdf.generic import ArrayObject as A, DictionaryObject as D, NameObject as N, NumberObject as I, TextStringObject as T, ByteStringObject as B, DecodedStreamObject


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true', help='Compare reproducible in-memory output with committed bytes without writing.')
    arguments = parser.parse_args()
    root = Path(__file__).resolve().parents[1] / 'tests' / 'Fixtures'
    if not arguments.check:
        root.mkdir(parents=True, exist_ok=True)
    writer = PdfWriter()
    writer.pdf_header = '%PDF-2.0'
    writer.add_metadata({'/Producer': 'Independent pypdf tagged HTML derivation fixture'})
    page = writer.add_blank_page(width=300, height=220)
    def obj(value): return writer._add_object(value)
    def stream(data, entries=None):
        value = DecodedStreamObject()
        value.set_data(data)
        if entries: value.update(entries)
        return obj(value)
    font = obj(D({N('/Type'): N('/Font'), N('/Subtype'): N('/Type1'), N('/BaseFont'): N('/Helvetica'), N('/Encoding'): N('/WinAnsiEncoding')}))
    cmap = stream(b'/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CMapName /Fixture def\n/CMapType 2 def\n1 begincodespacerange\n<00> <ff>\nendcodespacerange\n2 beginbfchar\n<01> <00E9>\n<02> <00660069>\nendbfchar\n1 beginbfrange\n<41> <43> <0041>\nendbfrange\nendcmap\nend\nend\n')
    unicode_font = obj(D({N('/Type'): N('/Font'), N('/Subtype'): N('/Type1'), N('/BaseFont'): N('/Helvetica'), N('/ToUnicode'): cmap}))
    resources = D({N('/Font'): D({N('/F1'): font, N('/F2'): unicode_font})})
    form = stream(b'/P <</MCID 0>> BDC BT /F2 12 Tf <0141424302> Tj ET EMC\n', {N('/Type'): N('/XObject'), N('/Subtype'): N('/Form'), N('/BBox'): A([I(0), I(0), I(100), I(20)]), N('/Resources'): resources, N('/StructParents'): I(1)})
    image = stream(b'\xff\x00\x00', {N('/Type'): N('/XObject'), N('/Subtype'): N('/Image'), N('/Width'): I(1), N('/Height'): I(1), N('/BitsPerComponent'): I(8), N('/ColorSpace'): N('/DeviceRGB')})
    page[N('/Resources')] = D({N('/Font'): resources[N('/Font')], N('/XObject'): D({N('/Fm'): form, N('/Im'): image}), N('/Properties'): D({N('/Replacement'): D({N('/MCID'): I(1), N('/ActualText'): B(b'\xef\xbb\xbfTexte rempla\xc3\xa7ant'), N('/Lang'): T('fr')})})})
    page[N('/StructParents')] = I(0)
    page[N('/Contents')] = stream(b'/H1 <</MCID 0>> BDC BT /F1 14 Tf 20 180 Td (External tagged PDF) Tj ET EMC\n/P /Replacement BDC BT /F1 12 Tf (visual text) Tj ET EMC\n/Fm Do\n/Figure <</MCID 2>> BDC q 20 0 0 20 20 80 cm /Im Do Q EMC\n/Link <</MCID 3>> BDC BT /F1 12 Tf 20 50 Td (Read more) Tj ET EMC\n')
    root_dict = D({N('/Type'): N('/StructTreeRoot')})
    root_ref = obj(root_dict)
    document = D({N('/Type'): N('/StructElem'), N('/S'): N('/Document'), N('/P'): root_ref})
    document_ref = obj(document)
    root_dict[N('/K')] = document_ref
    standard_ns = obj(D({N('/Type'): N('/Namespace'), N('/NS'): T('http://iso.org/pdf2/ssn')}))
    custom_ns = obj(D({N('/Type'): N('/Namespace'), N('/NS'): T('https://example.test/custom'), N('/RoleMapNS'): D({N('/StyledP'): A([N('/P'), standard_ns])})}))
    root_dict[N('/Namespaces')] = A([standard_ns, custom_ns])
    root_dict[N('/RoleMap')] = D({N('/CustomHeading'): N('/H1')})
    root_dict[N('/ClassMap')] = D({N('/lead'): D({N('/O'): N('/Layout'), N('/SpaceAfter'): I(8)})})
    kids = []
    def element(kind, content, **entries):
        value = D({N('/Type'): N('/StructElem'), N('/S'): N('/' + kind), N('/P'): document_ref, N('/Pg'): page.indirect_reference, N('/K'): content})
        for key, entry in entries.items(): value[N('/' + key)] = entry
        ref = obj(value)
        kids.append(ref)
        return ref
    heading = element('CustomHeading', I(0), ID=T('heading'))
    element('StyledP', I(1), NS=custom_ns, C=N('/lead'), A=D({N('/O'): N('/Layout'), N('/TextAlign'): N('/Center')}))
    element('P', D({N('/Type'): N('/MCR'), N('/Pg'): page.indirect_reference, N('/Stm'): form, N('/MCID'): I(0)}))
    element('Figure', I(2), Alt=T('A red pixel'))
    link = D({N('/Type'): N('/Annot'), N('/Subtype'): N('/Link'), N('/Rect'): A([I(20), I(45), I(100), I(60)]), N('/P'): page.indirect_reference, N('/A'): D({N('/S'): N('/GoTo'), N('/D'): T('start')})})
    link_ref = obj(link)
    element('Link', A([I(3), D({N('/Type'): N('/OBJR'), N('/Obj'): link_ref, N('/Pg'): page.indirect_reference})]))
    parent_field = D({N('/FT'): N('/Tx'), N('/T'): T('Person'), N('/Ff'): I(2), N('/V'): T('Ada'), N('/MaxLen'): I(50)})
    parent_field_ref = obj(parent_field)
    widget = D({N('/Type'): N('/Annot'), N('/Subtype'): N('/Widget'), N('/Parent'): parent_field_ref, N('/T'): T('Name'), N('/Rect'): A([I(20), I(10), I(180), I(30)]), N('/P'): page.indirect_reference, N('/F'): I(4), N('/AA'): D({N('/V'): D({N('/S'): N('/JavaScript'), N('/JS'): T('event.rc = true;')})})})
    widget_ref = obj(widget)
    parent_field[N('/Kids')] = A([widget_ref])
    element('Form', D({N('/Type'): N('/OBJR'), N('/Obj'): widget_ref, N('/Pg'): page.indirect_reference}))
    document[N('/K')] = A(kids)
    link[N('/StructParent')] = I(2)
    widget[N('/StructParent')] = I(3)
    root_dict[N('/ParentTree')] = obj(D({N('/Nums'): A([
        I(0), A([kids[0], kids[1], kids[3], kids[4]]),
        I(1), A([kids[2]]),
        I(2), kids[4],
        I(3), kids[5],
    ])}))
    root_dict[N('/ParentTreeNextKey')] = I(4)
    root_dict[N('/IDTree')] = obj(D({N('/Names'): A([T('heading'), heading])}))
    page[N('/Annots')] = A([link_ref, widget_ref])
    page[N('/AA')] = D({N('/O'): D({N('/S'): N('/JavaScript'), N('/JS'): T('console.println("page");')})})
    css = stream(b'.lead { color: #123456; }', {N('/Type'): N('/EmbeddedFile'), N('/Subtype'): N('/text/css')})
    associated = obj(D({N('/Type'): N('/Filespec'), N('/F'): T('theme.css'), N('/UF'): T('theme.css'), N('/AFRelationship'): N('/Supplement'), N('/EF'): D({N('/F'): css})}))
    writer.root_object.update({
        N('/StructTreeRoot'): root_ref,
        N('/Lang'): T('fr-FR'),
        N('/MarkInfo'): D({N('/Marked'): __import__('pypdf').generic.BooleanObject(True)}),
        N('/AcroForm'): obj(D({N('/Fields'): A([parent_field_ref])})),
        N('/AF'): A([associated]),
        N('/PageLabels'): obj(D({N('/Nums'): A([I(0), D({N('/S'): N('/r'), N('/P'): T('Intro-')})])})),
        N('/Names'): D({
            N('/Dests'): D({N('/Names'): A([T('start'), A([page.indirect_reference, N('/Fit')])])}),
            N('/JavaScript'): D({N('/Names'): A([T('init'), D({N('/S'): N('/JavaScript'), N('/JS'): T('var sourceOnly = true;')})])}),
        }),
    })
    xmp = b'<?xpacket begin=""?><x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title><rdf:Alt><rdf:li xml:lang="x-default">Independent tagged source</rdf:li></rdf:Alt></dc:title></rdf:Description></rdf:RDF></x:xmpmeta>'
    writer.root_object[N('/Metadata')] = stream(xmp, {N('/Type'): N('/Metadata'), N('/Subtype'): N('/XML')})
    output = io.BytesIO()
    writer.write(output)
    data = output.getvalue()
    manifest = (json.dumps({'producer': f'pypdf {pypdf.__version__}; original MIT fixture', 'filename': 'tagged.pdf', 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest(), 'pages': 1, 'header': '%PDF-2.0', 'text': 'External tagged PDF', 'formText': '\u00e9ABCfi', 'title': 'Independent tagged source'}, indent=2) + '\n').encode()
    for filename, content in [('tagged.pdf', data), ('reading-manifest.json', manifest)]:
        if arguments.check:
            if (root / filename).read_bytes() != content:
                raise ValueError('Fixture generation does not reproduce committed bytes: ' + filename)
        else:
            (root / filename).write_bytes(content)
    print(('Reproduced' if arguments.check else 'Wrote') + ' tagged fixture ' + hashlib.sha256(data).hexdigest())


if __name__ == '__main__':
    main()
