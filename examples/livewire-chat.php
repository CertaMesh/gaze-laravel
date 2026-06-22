<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Multi-turn Livewire chat — keep the session OUT of wire state
|--------------------------------------------------------------------------
|
| Trust boundary for Livewire: every PUBLIC property is serialised into the
| component's wire state and shipped to the browser on every request. So:
|
|   • The GazeSession (its encrypted ciphertext + the token <-> real-value
|     entries) is reversible key material. It must NEVER be a public property
|     — keep it as a local variable or a private/server-side store, so tokens
|     never leak into wire state.
|   • $messages holds the human-readable transcript shown back to the user
|     (their own data, their own browser). That is fine in wire state; the
|     boundary we protect is the MODEL, not the user's screen.
|   • ONE session boundary per conversation. Here clean+restore happen in the
|     same request, so the session lives only as a local for that turn. If
|     clean and restore span requests (async LLM), hold the session in a
|     server-side cache keyed by the conversation id — never in wire state.
|   • Want the SAME token for the same person across every turn? Use the gaze
|     daemon for a stable per-conversation token namespace (see docs/).
*/

use CertaMesh\Gaze\Facades\Gaze;
use Livewire\Component;

class GazeChat extends Component
{
    /** @var list<array{role: string, text: string}> Display transcript only — never tokens or sessions. */
    public array $messages = [];

    public string $draft = '';

    public function send(): void
    {
        $userText = trim($this->draft);

        if ($userText === '') {
            return;
        }

        // Clean this turn. $session is a LOCAL — it is never assigned to a
        // public property, so it can never enter wire state.
        $session = Gaze::clean($userText);

        // Show the user their own message (browser-side display is fine).
        $this->messages[] = ['role' => 'user', 'text' => $userText];

        // Only the pseudonymized cleanText crosses the model boundary.
        $reply = $this->askAssistant($session->cleanText);

        // Owner-side restore before display; tokens -> real values.
        $this->messages[] = ['role' => 'assistant', 'text' => Gaze::restore($session, $reply)];

        $this->draft = '';
        // $session goes out of scope here — nothing reversible is persisted.
    }

    // Stub stands in for the provider. It only ever sees tokenized text.
    private function askAssistant(string $clean): string
    {
        return "You said: {$clean}";
    }

    public function render()
    {
        // Blade view holds only $messages + the $draft input — no session, no tokens.
        return view('livewire.gaze-chat');
    }
}
