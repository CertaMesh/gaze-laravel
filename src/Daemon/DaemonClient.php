<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Daemon;

use CertaMesh\Gaze\Daemon\Contracts\DaemonClientContract;
use CertaMesh\Gaze\Exceptions\GazeDaemonException;
use CertaMesh\Gaze\Exceptions\GazeDaemonFeatureUnsupportedException;
use CertaMesh\Gaze\Exceptions\GazeDaemonTimeoutException;
use CertaMesh\Gaze\Exceptions\GazeDaemonTransportException;

/**
 * Long-lived JSONL stdio client for `gaze daemon`.
 *
 * Spawns one `gaze daemon` subprocess per instance and frames one JSON
 * line in, one JSON line out. Owns:
 *
 *  - stdin / stdout pipes (proc_open managed)
 *  - per-request mutex on the Process handle (concurrency safety)
 *  - per-request millisecond timeout (per `gaze.daemon.request_timeout_ms`)
 *  - fail-closed posture on broken-pipe / EOF (no auto-reconnect)
 *
 * Concurrency: instances bind via `app()->scoped()` so each Octane request
 * gets its own client. Within a request, the `$busy` mutex serialises
 * concurrent fiber-resident callers — a second `request()` invocation
 * waits for the first to finish before writing to the shared pipe, which
 * prevents cross-tenant payload leak via interleaved JSONL frames.
 */
final class DaemonClient implements DaemonClientContract
{
    /** Grace period between SIGTERM and SIGKILL escalation, in seconds. */
    private const TERM_GRACE_SECONDS = 2.0;

    /** Poll interval while waiting for child exit, in microseconds. */
    private const TERM_POLL_INTERVAL_US = 50_000;

    /** @var resource|null */
    private $process = null;

    /** @var resource|null */
    private $stdin = null;

    /** @var resource|null */
    private $stdout = null;

    private bool $busy = false;

    private bool $closed = false;

    private string $readBuffer = '';

    /**
     * @param  list<string>  $flags  Argv tail passed after `gaze daemon` (e.g. `--policy=...`, `--idle-timeout=...`).
     */
    public function __construct(
        private readonly string $binary,
        private readonly array $flags = [],
        private readonly int $requestTimeoutMs = 5000,
        private readonly ?string $stderrPath = null,
    ) {}

    /**
     * Bind the client to pre-existing streams instead of spawning a process.
     * Used by tests so framing / fail-closed / timeout behaviour can be
     * exercised without a real binary.
     *
     * @param  resource  $stdin
     * @param  resource  $stdout
     */
    public static function withStreams(
        $stdin,
        $stdout,
        int $requestTimeoutMs = 5000,
    ): self {
        $client = new self(binary: '/dev/null', flags: [], requestTimeoutMs: $requestTimeoutMs);
        $client->stdin = $stdin;
        $client->stdout = $stdout;

        return $client;
    }

    public function connect(): void
    {
        if ($this->closed) {
            throw new GazeDaemonTransportException('daemon client previously closed; resolve a fresh instance');
        }

        if ($this->stdin !== null && $this->stdout !== null) {
            return;
        }

        $argv = array_merge([$this->binary, 'daemon'], $this->flags);

        $descriptors = self::descriptorSpec($this->stderrPath);

        $pipes = [];
        $process = @proc_open($argv, $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new GazeDaemonFeatureUnsupportedException(
                "could not spawn `{$this->binary} daemon`; verify binary path and feature build flags"
            );
        }

        $this->process = $process;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
    }

    /**
     * proc_open descriptor spec for the daemon child.
     *
     * Child stderr is never wired to a pipe: nothing in the request loop
     * reads stderr, so a chatty daemon would fill the ~64KB pipe buffer,
     * block on its next stderr write, and stall every subsequent request
     * until the deadline. Instead stderr goes to `stderrPath` when
     * configured (`gaze.daemon.stderr_path`), otherwise to /dev/null —
     * neither can exert backpressure on the child.
     *
     * @internal exposed as a pure function so tests can pin the spec.
     *
     * @return array<int, array{0: string, 1: string, 2?: string}>
     */
    public static function descriptorSpec(?string $stderrPath): array
    {
        return [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', $stderrPath ?? '/dev/null', 'a'],
        ];
    }

    public function request(string $sessionId, string $text): CleanResponse
    {
        if ($this->busy) {
            throw new GazeDaemonException(
                'concurrent daemon request rejected; use scoped binding or a pool',
                $sessionId,
                [],
                DaemonErrorVariant::Transport,
            );
        }

        $this->busy = true;
        try {
            $this->connect();

            if (! is_resource($this->stdin) || ! is_resource($this->stdout)) {
                throw new GazeDaemonTransportException('daemon stdio not available', $sessionId);
            }

            $payload = json_encode(
                ['session_id' => $sessionId, 'text' => $text],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            )."\n";

            $this->writeRequest($payload, $sessionId);

            $line = $this->readLine($sessionId);

            $parsed = DaemonEnvelopeParser::parse($line, $sessionId);

            if ($parsed instanceof GazeDaemonException) {
                throw $parsed;
            }

            $response = $parsed;

            if ($response->sessionId !== $sessionId) {
                throw new GazeDaemonException(
                    "daemon echoed mismatched session_id (sent={$sessionId}, got={$response->sessionId})",
                    $sessionId,
                    $response->raw,
                    DaemonErrorVariant::Transport,
                );
            }

            return $response;
        } finally {
            $this->busy = false;
        }
    }

    public function disconnect(): void
    {
        $this->closed = true;

        if (is_resource($this->stdin)) {
            @fclose($this->stdin);
        }
        if (is_resource($this->stdout)) {
            @fclose($this->stdout);
        }
        $process = $this->process;
        if (is_resource($process)) {
            @proc_terminate($process);
            $this->awaitExitOrKill($process);
            @proc_close($process);
        }

        $this->stdin = null;
        $this->stdout = null;
        $this->process = null;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * SIGTERM has already been sent; poll for child exit during a short
     * grace period, then escalate to SIGKILL.
     *
     * proc_close() blocks until the child is gone, so without escalation a
     * daemon that ignores SIGTERM would hang disconnect() — and with it
     * the Octane worker's request teardown — indefinitely.
     *
     * @param  resource  $process
     */
    private function awaitExitOrKill($process): void
    {
        $deadline = microtime(true) + self::TERM_GRACE_SECONDS;

        while (microtime(true) < $deadline) {
            $status = @proc_get_status($process);
            if ($status['running'] !== true) {
                return;
            }

            usleep(self::TERM_POLL_INTERVAL_US);
        }

        // SIGKILL cannot be caught or ignored — proc_close() will return.
        @proc_terminate($process, 9);
    }

    /**
     * Write one newline-terminated JSON frame to daemon stdin, honouring
     * the per-request millisecond deadline.
     *
     * A wedged daemon that stops draining stdin leaves the pipe buffer
     * full; a plain blocking fwrite() would then hang the PHP worker
     * indefinitely — past `request_timeout_ms`. Non-blocking writes plus
     * stream_select() bound every wait; on deadline the request fails
     * closed with GazeDaemonTimeoutException. Exceptions never carry
     * payload text (PII discipline).
     */
    private function writeRequest(string $payload, string $sessionId): void
    {
        $stdin = $this->stdin;
        if (! is_resource($stdin)) {
            throw new GazeDaemonTransportException('daemon stdin closed', $sessionId);
        }

        $deadline = microtime(true) + ($this->requestTimeoutMs / 1000);
        $length = strlen($payload);
        $offset = 0;

        @stream_set_blocking($stdin, false);
        try {
            while ($offset < $length) {
                $written = @fwrite($stdin, substr($payload, $offset));
                if ($written === false) {
                    throw new GazeDaemonTransportException(
                        'broken pipe writing daemon request',
                        $sessionId,
                    );
                }

                $offset += $written;
                if ($offset >= $length) {
                    break;
                }

                if ($written === 0 && feof($stdin)) {
                    throw new GazeDaemonTransportException(
                        'broken pipe writing daemon request',
                        $sessionId,
                    );
                }

                $remaining = max(0.0, $deadline - microtime(true));
                if ($remaining === 0.0) {
                    throw new GazeDaemonTimeoutException(
                        "daemon request exceeded {$this->requestTimeoutMs}ms",
                        $sessionId,
                    );
                }

                $write = [$stdin];
                $read = $except = null;
                $microsec = (int) ($remaining * 1_000_000);
                $sec = intdiv($microsec, 1_000_000);
                $usec = $microsec % 1_000_000;

                $ready = @stream_select($read, $write, $except, $sec, $usec);
                if ($ready === false) {
                    throw new GazeDaemonTransportException('select() on daemon stdin failed', $sessionId);
                }
                if ($ready === 0) {
                    throw new GazeDaemonTimeoutException(
                        "daemon request exceeded {$this->requestTimeoutMs}ms",
                        $sessionId,
                    );
                }
            }

            @fflush($stdin);
        } finally {
            if (is_resource($stdin)) {
                @stream_set_blocking($stdin, true);
            }
        }
    }

    /**
     * Read one newline-terminated JSON line, honouring the per-request
     * millisecond deadline. Throws on EOF (fail-closed) or timeout.
     */
    private function readLine(string $sessionId): string
    {
        $stdout = $this->stdout;
        if (! is_resource($stdout)) {
            throw new GazeDaemonTransportException('daemon stdout closed', $sessionId);
        }

        $deadline = microtime(true) + ($this->requestTimeoutMs / 1000);

        @stream_set_blocking($stdout, false);
        try {
            while (true) {
                $nl = strpos($this->readBuffer, "\n");
                if ($nl !== false) {
                    $line = substr($this->readBuffer, 0, $nl);
                    $this->readBuffer = substr($this->readBuffer, $nl + 1);

                    return $line;
                }

                $read = [$stdout];
                $write = $except = null;
                $remaining = max(0.0, $deadline - microtime(true));
                if ($remaining === 0.0) {
                    throw new GazeDaemonTimeoutException(
                        "daemon request exceeded {$this->requestTimeoutMs}ms",
                        $sessionId,
                    );
                }
                $microsec = (int) ($remaining * 1_000_000);
                $sec = intdiv($microsec, 1_000_000);
                $usec = $microsec % 1_000_000;

                $ready = @stream_select($read, $write, $except, $sec, $usec);
                if ($ready === false) {
                    throw new GazeDaemonTransportException('select() on daemon stdout failed', $sessionId);
                }
                if ($ready === 0) {
                    throw new GazeDaemonTimeoutException(
                        "daemon request exceeded {$this->requestTimeoutMs}ms",
                        $sessionId,
                    );
                }

                $chunk = @fread($stdout, 65536);
                if ($chunk === false) {
                    throw new GazeDaemonTransportException('read from daemon stdout failed', $sessionId);
                }
                if ($chunk === '' && feof($stdout)) {
                    throw new GazeDaemonTransportException(
                        'daemon closed stdout (EOF) before responding',
                        $sessionId,
                    );
                }

                $this->readBuffer .= $chunk;
            }
        } finally {
            if (is_resource($stdout)) {
                @stream_set_blocking($stdout, true);
            }
        }
    }
}
