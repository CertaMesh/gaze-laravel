<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Naoray\GazeLaravel\BinaryResolver;
use Naoray\GazeLaravel\Console\Proxy\ProxyCommand;
use Naoray\GazeLaravel\Exceptions\GazeBinaryMissingException;

function gpc_stubCommand(string $verb, array $flags): ProxyCommand
{
    return new class($verb, $flags) extends ProxyCommand
    {
        public function __construct(
            private readonly string $stubVerb,
            /** @var list<string> */
            private readonly array $stubFlags,
        ) {
            parent::__construct();
        }

        protected function verb(): string
        {
            return $this->stubVerb;
        }

        protected function flags(ConfigRepository $config): array
        {
            return $this->stubFlags;
        }
    };
}

it('builds argv as [binary, proxy, verb, ...flags] in order', function () {
    $resolver = new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none');
    $command = gpc_stubCommand('start', ['--bind=127.0.0.1:8787', '--rulepack=core']);

    $argv = $command->buildArgv($resolver, $this->app->make(ConfigRepository::class));

    expect($argv)->toBe([
        '/fake/gaze',
        'proxy',
        'start',
        '--bind=127.0.0.1:8787',
        '--rulepack=core',
    ]);
});

it('emits an empty flag list verbatim when subclass returns []', function () {
    $resolver = new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none');
    $command = gpc_stubCommand('status', []);

    expect($command->buildArgv($resolver, $this->app->make(ConfigRepository::class)))
        ->toBe(['/fake/gaze', 'proxy', 'status']);
});

it('lets BinaryResolver failures bubble out of buildArgv', function () {
    $resolver = new BinaryResolver(explicitPath: null, vendorBinPath: '/nonexistent-gaze-binary-'.bin2hex(random_bytes(4)));
    $command = gpc_stubCommand('start', []);

    expect(fn () => $command->buildArgv($resolver, $this->app->make(ConfigRepository::class)))
        ->toThrow(GazeBinaryMissingException::class);
})->skip(
    fn (): bool => (new \Symfony\Component\Process\ExecutableFinder)->find('gaze') !== null,
    'gaze binary on PATH — resolver will succeed.',
);
