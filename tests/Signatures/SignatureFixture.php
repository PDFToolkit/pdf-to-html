<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Signatures;

use PDF\Html\Signatures\SignatureOutputValidation;
use PDF\Html\Signatures\SignatureValidationRequest;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Security\CertificateChain;
use PDF\Security\OpenSslSignatureOutputVerifier;
use PDF\Security\X509Certificate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\StringObject\HexadecimalString;

/** Independent committed PDF/CMS bytes; no production writer or signer used. */
final readonly class SignatureFixture
{
    public function request(string $file = 'signed-padded.pdf'): SignatureValidationRequest
    {
        $document = (new DocumentReader())->read(new FileBytes($this->bytes($file)));
        $form = $document->resolve($document->catalog()->get('AcroForm'));
        if (!$form instanceof DictionaryType) {
            throw new \LogicException('The signature fixture requires an AcroForm.');
        }
        $fields = $document->resolve($form->get('Fields'));
        if (!$fields instanceof ArrayType) {
            throw new \LogicException('The signature fixture requires its field.');
        }
        $field = $document->resolve(iterator_to_array($fields)[0]);
        if (!$field instanceof DictionaryType) {
            throw new \LogicException('The signature fixture field is a dictionary.');
        }
        $signature = $document->resolve($field->get('V'));
        if (!$signature instanceof DictionaryType) {
            throw new \LogicException('The signature fixture requires a signature value.');
        }
        return new SignatureValidationRequest($document, $signature, 'signature');
    }

    public function certificate(string $name = 'signer'): CertificateChain
    {
        return new CertificateChain(new X509Certificate(new HexadecimalString($this->bytes($name . '.der'))));
    }

    public function provider(string $name = 'signer'): SignatureOutputValidation
    {
        return new SignatureOutputValidation(new OpenSslSignatureOutputVerifier(), $this->certificate($name));
    }

    public function bytes(string $file): string
    {
        $bytes = file_get_contents(dirname(__DIR__) . '/Fixtures/signatures/' . $file);
        return $bytes === false ? throw new \LogicException('Missing signature fixture.') : $bytes;
    }

    /** @return list<array{name: string, file: string, certificate: string, status: string}> */
    public function cases(): array
    {
        return json_decode($this->bytes('manifest.json'), true, flags: JSON_THROW_ON_ERROR)['cases'];
    }
}
