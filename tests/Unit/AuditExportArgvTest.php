<?php

declare(strict_types=1);

use CertaMesh\Gaze\Audit\AuditExportResult;
use Illuminate\Support\Facades\Process;

it('runs gaze audit export with --format=jsonl by default, exporting to stdout', function () {
    Process::fake(['*' => Process::result(output: '{"class":"email"}'."\n")]);

    $result = $this->makeGaze()->audit('/tmp/audit.sqlite')->query()->export();

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'audit',
            'export',
            '--audit-db=/tmp/audit.sqlite',
            '--format=jsonl',
        ]);

        return true;
    });

    expect($result)->toBeInstanceOf(AuditExportResult::class)
        ->and($result->format)->toBe('jsonl')
        ->and($result->path)->toBeNull()
        ->and($result->rawOutput)->toBe('{"class":"email"}'."\n");
});

it('forwards --output when an export path is given', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $result = $this->makeGaze()->audit('/tmp/audit.sqlite')->query()->export('/tmp/export.jsonl');

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'audit',
            'export',
            '--audit-db=/tmp/audit.sqlite',
            '--format=jsonl',
            '--output=/tmp/export.jsonl',
        ]);

        return true;
    });

    expect($result->path)->toBe('/tmp/export.jsonl')
        ->and($result->rowCount())->toBeNull()
        ->and($result->rows())->toBe([]);
});

it('forwards the format verbatim', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $this->makeGaze()->audit('/tmp/audit.sqlite')->query()->export(null, 'jsonl');

    Process::assertRan(function ($process): bool {
        expect($process->command)->toContain('--format=jsonl');

        return true;
    });
});

it('reuses the accumulated query filter state on export, in upstream --help order', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $this->makeGaze()->audit('/tmp/audit.sqlite')->query()
        ->onlyRestoreEvents()
        ->whereSession('sess-1')
        ->hasAmbiguity()
        ->whereClass('email')
        ->export('/tmp/export.jsonl');

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'audit',
            'export',
            '--audit-db=/tmp/audit.sqlite',
            '--format=jsonl',
            '--output=/tmp/export.jsonl',
            '--class=email',
            '--session=sess-1',
            '--has-ambiguity',
            '--restore-events',
        ]);

        return true;
    });
});

it('derives rowCount() and keyed rows() from captured stdout JSONL', function () {
    $jsonl = '{"class":"email","source":"email.global"}'."\n".'{"class":"name","source":"ner"}'."\n";
    Process::fake(['*' => Process::result(output: $jsonl)]);

    $result = $this->makeGaze()->audit('/tmp/audit.sqlite')->query()->export();

    expect($result->rowCount())->toBe(2)
        ->and($result->rows())->toBe([
            ['class' => 'email', 'source' => 'email.global'],
            ['class' => 'name', 'source' => 'ner'],
        ]);
});

it('reports zero rows for an empty stdout export', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $result = $this->makeGaze()->audit('/tmp/audit.sqlite')->query()->export();

    expect($result->rowCount())->toBe(0)
        ->and($result->rows())->toBe([]);
});
