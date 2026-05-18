<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Daemon\CleanResponse;
use Naoray\GazeLaravel\Daemon\Contracts\DaemonClientContract;
use Naoray\GazeLaravel\Daemon\DaemonManager;
use Naoray\GazeLaravel\Daemon\DaemonSession;
use Naoray\GazeLaravel\Facades\Gaze;

beforeEach(function () {
    $this->fakeClient = new class implements DaemonClientContract
    {
        /** @var list<array{session_id:string,text:string}> */
        public array $calls = [];

        public function request(string $sessionId, string $text): CleanResponse
        {
            $this->calls[] = ['session_id' => $sessionId, 'text' => $text];

            return new CleanResponse(
                sessionId: $sessionId,
                cleanText: "CLEAN[{$sessionId}]:{$text}",
                manifest: [],
                tokens: [],
                raw: ['session_id' => $sessionId, 'clean_text' => "CLEAN[{$sessionId}]:{$text}"],
            );
        }

        public function connect(): void {}

        public function disconnect(): void {}
    };

    $this->app->instance(DaemonClientContract::class, $this->fakeClient);
    $this->app->instance(DaemonManager::class, new DaemonManager($this->fakeClient));
});

it('exposes Gaze::daemon() returning a DaemonManager', function () {
    expect(Gaze::daemon())->toBeInstanceOf(DaemonManager::class);
});

it('composes daemon()->session($id)->clean($text)', function () {
    $response = Gaze::daemon()->session('agent-a')->clean('hello');

    expect($response->sessionId)->toBe('agent-a');
    expect($response->cleanText)->toBe('CLEAN[agent-a]:hello');
});

it('calls daemon()->clean($id, $text) as a one-shot hot-path', function () {
    $response = Gaze::daemon()->clean('agent-b', 'world');

    expect($response->cleanText)->toBe('CLEAN[agent-b]:world');
    expect($this->fakeClient->calls)->toHaveCount(1);
});

it('returns identical responses from composition and direct entries for identical input', function () {
    $composed = Gaze::daemon()->session('eq')->clean('payload');
    $direct = Gaze::daemon()->clean('eq', 'payload');

    expect($composed->sessionId)->toBe($direct->sessionId);
    expect($composed->cleanText)->toBe($direct->cleanText);
});

it('memoises sessions per id within a manager instance', function () {
    $manager = Gaze::daemon();

    $a1 = $manager->session('mem');
    $a2 = $manager->session('mem');

    expect($a1)->toBeInstanceOf(DaemonSession::class);
    expect($a1)->toBe($a2);
});
