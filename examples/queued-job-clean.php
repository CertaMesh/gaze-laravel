<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Clean before the LLM inside a queued Job (agentic-first)
|--------------------------------------------------------------------------
|
| gaze pipelines are agentic-first: they run in queues and multi-turn loops,
| not just one HTTP request. This Job cleans the user's text, calls the LLM
| with the pseudonymized cleanText, then restores the reply — off the request
| thread.
|
| Trust rules this example encodes:
|   • ONE GazeSession per unit of work. The session is the reversible key
|     material for this job; don't reuse it across unrelated work.
|   • GazeSession is a readonly value object whose ciphertext is encrypted
|     at rest, so it serialises safely — you can hand it to a chained job
|     (it "travels with the job") without exposing real values.
|   • Never persist plaintext. If your queue backend stores job payloads,
|     prefer cleaning BEFORE dispatch and carrying only the session, so raw
|     PII is never written to the queue.
*/

use CertaMesh\Gaze\Facades\Gaze;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SummarizeUserNote implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $userText,
        public readonly int $userId,
    ) {}

    public function handle(): void
    {
        // One session per unit of work. cleanText is the only thing the LLM sees.
        $session = Gaze::clean($this->userText);

        $reply = $this->askLlm($session->cleanText);

        // Owner-side restore: tokens -> real values, back on our server.
        $answer = Gaze::restore($session, $reply);

        // Because GazeSession is serialisable (ciphertext encrypted at rest),
        // a follow-up could carry it forward, e.g.:
        //   Bus::chain([new TranslateSummary($session, $answer)])->dispatch();

        // Persist/notify with $answer — never write $this->userText to long-term store.
        logger()->info('note summarised', ['user_id' => $this->userId, 'summary' => $answer]);
    }

    // Stub stands in for the provider SDK. Only pseudonymized text crosses here.
    private function askLlm(string $clean): string
    {
        return "Summary: {$clean}";
    }
}
