<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Daemon;

use Naoray\GazeLaravel\Daemon\Contracts\DaemonClientContract;
use Naoray\GazeLaravel\Exceptions\GazeDaemonException;
use Naoray\GazeLaravel\Exceptions\GazeDaemonFeatureUnsupportedException;
use Naoray\GazeLaravel\Exceptions\GazeDaemonTimeoutException;
use Naoray\GazeLaravel\Exceptions\GazeDaemonTransportException;

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
    /** @var resource|null */
    private $process = null;

    /** @var resource|null */
    private $stdin = null;

    /** @var resource|null */
    private $stdout = null;

    /** @var resource|null */
    private $stderr = null;

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

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => $this->stderrPath !== null
                ? ['file', $this->stderrPath, 'a']
                : ['pipe', 'w'],
        ];

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
        $this->stderr = $pipes[2] ?? null;
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

            $written = @fwrite($this->stdin, $payload);
            if ($written === false || $written !== strlen($payload)) {
                throw new GazeDaemonTransportException(
                    'broken pipe writing daemon request',
                    $sessionId,
                );
            }
            @fflush($this->stdin);

            $line = $this->readLine($sessionId);

            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new GazeDaemonException(
                    'daemon response was not a JSON object',
                    $sessionId,
                    ['raw_line' => $line],
                    DaemonErrorVariant::JsonMalformed,
                );
            }

            if (isset($decoded['error'])) {
                $variant = is_string($decoded['error'])
                    ? DaemonErrorVariant::fromWire($decoded['error'])
                    : DaemonErrorVariant::Unknown;
                $detail = isset($decoded['detail']) && is_string($decoded['detail'])
                    ? $decoded['detail']
                    : 'daemon returned typed error';
                throw new GazeDaemonException(
                    $detail,
                    isset($decoded['session_id']) && is_string($decoded['session_id']) ? $decoded['session_id'] : null,
                    $decoded,
                    $variant,
                );
            }

            $response = CleanResponse::fromArray($decoded);

            if ($response->sessionId !== $sessionId) {
                throw new GazeDaemonException(
                    "daemon echoed mismatched session_id (sent={$sessionId}, got={$response->sessionId})",
                    $sessionId,
                    $decoded,
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
        if (is_resource($this->stderr)) {
            @fclose($this->stderr);
        }
        if (is_resource($this->process)) {
            @proc_terminate($this->process);
            @proc_close($this->process);
        }

        $this->stdin = null;
        $this->stdout = null;
        $this->stderr = null;
        $this->process = null;
    }

    public function __destruct()
    {
        $this->disconnect();
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
