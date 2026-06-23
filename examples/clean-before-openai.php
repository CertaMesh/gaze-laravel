<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Clean before OpenAI — the trust contract (clean → draft → restore → send)
|--------------------------------------------------------------------------
|
| Gaze pseudonymizes PII/PHI/secrets BEFORE any text crosses the model
| boundary. The contract this example demonstrates:
|
|   • Only $session->cleanText is ever sent to the LLM. It carries
|     pseudonymous tokens — real gaze tokens look like <ea0200d1:Email_1>
|     (a per-session hex prefix + class + counter), never the literal value.
|   • $session->ciphertext (an encrypted blob) and $session->entries (the
|     token <-> real-value map) NEVER leave your server. Keep them server-side.
|   • Restore is owner-side: only code holding the session can turn the
|     model's tokenized reply back into real values — and only THEN can you
|     act on them (send the email, confirm the SSN). The model never could:
|     it only ever saw tokens, so it can neither leak nor act on the PII.
|
| That last point is the whole value proposition: reversibility. The model
| drafts in tokens; restore() + the owner-side session re-enable the real,
| owner-side action. This file shows that end-to-end with a stubbed send.
|
| Detection logic lives upstream in the `gaze` binary; this package only
| exposes it through Laravel surfaces. A real round-trip needs the binary,
| so these snippets run inside a booted Laravel app (tinker / route / job),
| not as bare `php file.php` scripts — see examples/README.md.
*/

use CertaMesh\Gaze\Entry;
use CertaMesh\Gaze\Facades\Gaze;
use CertaMesh\Gaze\GazeSession;

/**
 * Find the first detected entry whose PII class matches $needle (case-
 * insensitive substring). Class strings come from upstream and vary with the
 * active policy — built-in email is "Email", a name is "Name", a custom SSN
 * recognizer might be "custom:us_ssn" — so we match loosely instead of pinning
 * one exact string. Returns null when that class wasn't detected.
 */
function entryForClass(GazeSession $session, string $needle): ?Entry
{
    foreach ($session->entries as $entry) {
        if (str_contains(strtolower($entry->class), strtolower($needle))) {
            return $entry;
        }
    }

    return null;
}

/**
 * Stand-in for a real provider call (OpenAI, Anthropic, …). A real model only
 * ever receives $session->cleanText. We pass the whole $session here for ONE
 * reason: so the stub can fish out the exact tokens gaze minted and echo them
 * back, mimicking a model that quotes the tokens it was given. The stub never
 * reads $entry->raw — only $entry->token — so it sees no real PII, just like
 * the real model.
 *
 * CRITICAL: the draft references the session's REAL tokens (e.g.
 * <ea0200d1:Email_1>), not hardcoded placeholders like <EMAIL_1>. Only the
 * real tokens survive restore() — a hardcoded placeholder would pass straight
 * through untouched. We degrade gracefully when a class wasn't detected.
 */
function fakeOpenAi(GazeSession $session): string
{
    $person = entryForClass($session, 'name') ?? entryForClass($session, 'person');
    $ssn = entryForClass($session, 'ssn');
    $email = entryForClass($session, 'email');

    $lines = ['Subject: Your details on file', ''];
    $lines[] = $person !== null ? "Hi {$person->token}," : 'Hi there,';
    if ($ssn !== null) {
        $lines[] = "We're confirming the SSN on file as {$ssn->token}.";
    }
    if ($email !== null) {
        $lines[] = "We'll follow up with you at {$email->token}.";
    }
    $lines[] = '';
    $lines[] = 'Best,';
    $lines[] = 'Support';

    return implode("\n", $lines);
}

/**
 * Owner-side action that only makes sense AFTER restore. $to must be a real
 * address; a token like <ea0200d1:Email_1> is not a deliverable destination.
 */
function sendEmail(string $to, string $body): void
{
    echo "→ sending to {$to}\n"; /* real transport (Mail::raw(), an API call, …) goes here */
}

$prompt = 'Email Jane Doe at jane.doe@example.com and confirm her SSN 123-45-6789 is on file.';

$session = Gaze::clean($prompt);     // -> CertaMesh\Gaze\GazeSession (entries + ciphertext stay server-side)
$draft = fakeOpenAi($session);       // the model drafts using ONLY tokens
$final = Gaze::restore($session, $draft); // owner-side: tokens -> real values

echo "ORIGINAL : {$prompt}\n\n";
echo "CLEANED  : {$session->cleanText}\n";        // safe to send to the model
echo "DETECTED : {$session->detections} value(s)\n\n";
echo "MODEL    : {$draft}\n\n";                   // the model only ever saw tokens
echo "RESTORED : {$final}\n\n";                   // real values back, owner-side

// The send only works AFTER restore. The recipient is the Email entry's real
// `raw` value — held server-side in the session, NEVER sent to the model.
// Pre-restore, the draft addressed the recipient only as a token
// (<...Email_1>), which you cannot email. restore() + the owner-side session
// are what turn the tokenized draft back into a sendable message: that is
// reversibility, and it is the trust contract this package exists to keep.
$emailEntry = entryForClass($session, 'email');
if ($emailEntry !== null) {
    sendEmail($emailEntry->raw, $final);
} else {
    echo "→ no email address detected — nothing to send\n";
}
