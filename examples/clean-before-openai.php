<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Clean before OpenAI — the trust contract
|--------------------------------------------------------------------------
|
| Gaze pseudonymizes PII/PHI/secrets BEFORE any text crosses the model
| boundary. The contract this example demonstrates:
|
|   • Only $session->cleanText is ever sent to the LLM. It carries
|     placeholder tokens (e.g. <PERSON_1>, <EMAIL_1>) instead of real values.
|   • $session->ciphertext (an encrypted blob) and $session->entries (the
|     token <-> real-value map) NEVER leave your server. Keep them server-side.
|   • Restore is owner-side: only code holding the session can turn the
|     model's tokenized reply back into real values.
|
| Detection logic lives upstream in the `gaze` binary; this package only
| exposes it through Laravel surfaces. A real round-trip needs the binary.
*/

use CertaMesh\Gaze\Facades\Gaze;

// A real provider call would go here. We never send raw PII — only the
// already-pseudonymized $clean text reaches this boundary. The reply quotes
// the cleaned text back, so the same tokens return for us to restore.
function fakeOpenAi(string $clean): string
{
    return "Drafted your message:\n\n\"{$clean}\"\n\nReady to send?";
}

$prompt = 'Email Jane Doe at jane.doe@example.com and confirm her SSN 123-45-6789 is on file.';

$session = Gaze::clean($prompt);            // -> CertaMesh\Gaze\GazeSession
$reply = fakeOpenAi($session->cleanText);   // only cleanText crosses the boundary
$final = Gaze::restore($session, $reply);   // owner-side: tokens -> real values

echo "ORIGINAL : {$prompt}\n\n";
echo "CLEANED  : {$session->cleanText}\n";        // safe to send to the model
echo "DETECTED : {$session->detections} value(s)\n\n";
echo "MODEL    : {$reply}\n\n";                   // the model only ever saw tokens
echo "RESTORED : {$final}\n";                     // real values back, owner-side
