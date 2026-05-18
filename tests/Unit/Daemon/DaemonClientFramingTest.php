<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Daemon\DaemonClient;

it('frames one JSON request + newline and decodes one response', function () {
    $stdin = gl_memoryStream();
    $stdout = gl_memoryStream(
        gl_jsonEncode(['session_id' => 's1', 'clean_text' => 'masked', 'manifest' => [], 'tokens' => []])."\n"
    );

    $client = DaemonClient::withStreams($stdin, $stdout);

    $response = $client->request('s1', 'hello');

    expect($response->sessionId)->toBe('s1');
    expect($response->cleanText)->toBe('masked');

    rewind($stdin);
    $sent = stream_get_contents($stdin);
    expect($sent)->toEndWith("\n");
    $decoded = json_decode(rtrim((string) $sent), true);
    expect($decoded)->toBe(['session_id' => 's1', 'text' => 'hello']);
});

it('preserves UTF-8 multibyte characters in the request payload', function () {
    $stdin = gl_memoryStream();
    $stdout = gl_memoryStream(
        gl_jsonEncode([
            'session_id' => 'utf',
            'clean_text' => 'パスワード <Email_1> 秘密',
            'manifest' => [],
            'tokens' => [],
        ], JSON_UNESCAPED_UNICODE)."\n"
    );

    $client = DaemonClient::withStreams($stdin, $stdout);
    $response = $client->request('utf', 'メール: ascii@example.com 文末');

    rewind($stdin);
    $sent = stream_get_contents($stdin);
    $decoded = json_decode(rtrim((string) $sent), true);

    expect($decoded['text'])->toBe('メール: ascii@example.com 文末');
    expect($response->cleanText)->toBe('パスワード <Email_1> 秘密');
});

it('does not trim newlines or whitespace embedded inside the request text', function () {
    $stdin = gl_memoryStream();
    $stdout = gl_memoryStream(
        gl_jsonEncode(['session_id' => 'nl', 'clean_text' => 'ok', 'manifest' => [], 'tokens' => []])."\n"
    );

    $client = DaemonClient::withStreams($stdin, $stdout);
    $client->request('nl', "line1\nline2\n  trailing  ");

    rewind($stdin);
    $sent = stream_get_contents($stdin);
    $decoded = json_decode(rtrim((string) $sent), true);

    expect($decoded['text'])->toBe("line1\nline2\n  trailing  ");
});
