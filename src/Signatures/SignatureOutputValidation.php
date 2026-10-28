<?php

declare(strict_types=1);

namespace PDF\Html\Signatures;

use PDF\Document\Signatures\SignatureOutputVerifier;
use PDF\Security\CertificateChain;

/** Explicit pinned-signer mathematical verification; no implicit PKI/network decisions. */
final readonly class SignatureOutputValidation implements SignatureValidationProvider
{
    public function __construct(private SignatureOutputVerifier $verifier, private CertificateChain $certificate, private SignatureVerificationSource $source = new SignatureVerificationSource())
    {
    }

    public function validate(SignatureValidationRequest $request): SignatureValidationResult
    {
        try {
            $input = $this->source->input($request, $this->certificate);
            $this->verifier->verify($input)->validateBinding($input);
            return new SignatureValidationResult($request, SignatureValidationStatus::IntegrityVerified, 'The signed PDF bytes authenticate against the selected certificate. Certificate trust, revocation and later PDF changes have not been validated; the derived HTML is not signed.');
        } catch (\PDF\Reading\ParseException $error) {
            return new SignatureValidationResult($request, SignatureValidationStatus::Indeterminate, $error->getMessage());
        } catch (\InvalidArgumentException|\OverflowException $error) {
            return new SignatureValidationResult($request, SignatureValidationStatus::Invalid, 'PDF signature verification failed: ' . $error->getMessage());
        } catch (\LogicException $error) {
            return new SignatureValidationResult($request, SignatureValidationStatus::Indeterminate, $error->getMessage());
        }
    }
}
