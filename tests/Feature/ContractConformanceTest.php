<?php

declare(strict_types=1);

use CertaMesh\Gaze\Audit\AuditService;
use CertaMesh\Gaze\Audit\PurgeBuilder;
use CertaMesh\Gaze\Audit\QueryBuilder;
use CertaMesh\Gaze\Contracts\AuditRunner as AuditRunnerContract;
use CertaMesh\Gaze\Contracts\AuditService as AuditServiceContract;
use CertaMesh\Gaze\Contracts\DaemonManager as DaemonManagerContract;
use CertaMesh\Gaze\Contracts\DaemonSession as DaemonSessionContract;
use CertaMesh\Gaze\Contracts\Gaze as GazeContract;
use CertaMesh\Gaze\Contracts\PurgeBuilder as PurgeBuilderContract;
use CertaMesh\Gaze\Contracts\QueryBuilder as QueryBuilderContract;
use CertaMesh\Gaze\Daemon\DaemonManager;
use CertaMesh\Gaze\Daemon\DaemonSession;
use CertaMesh\Gaze\Facades\Gaze as GazeFacade;
use CertaMesh\Gaze\Gaze;
use CertaMesh\Gaze\Testing\FakeAuditService;
use CertaMesh\Gaze\Testing\FakeDaemonManager;
use CertaMesh\Gaze\Testing\FakeDaemonSession;
use CertaMesh\Gaze\Testing\FakeGaze;
use CertaMesh\Gaze\Testing\FakePurgeBuilder;
use CertaMesh\Gaze\Testing\FakeQueryBuilder;

// --- concrete services implement their contracts -------------------------

it('concrete Gaze implements Contracts\Gaze and Contracts\AuditRunner', function () {
    expect(is_a(Gaze::class, GazeContract::class, true))->toBeTrue()
        ->and(is_a(Gaze::class, AuditRunnerContract::class, true))->toBeTrue();
});

it('concrete audit services implement their contracts', function () {
    expect(is_a(AuditService::class, AuditServiceContract::class, true))->toBeTrue()
        ->and(is_a(PurgeBuilder::class, PurgeBuilderContract::class, true))->toBeTrue()
        ->and(is_a(QueryBuilder::class, QueryBuilderContract::class, true))->toBeTrue();
});

it('concrete daemon services implement their contracts', function () {
    expect(is_a(DaemonManager::class, DaemonManagerContract::class, true))->toBeTrue()
        ->and(is_a(DaemonSession::class, DaemonSessionContract::class, true))->toBeTrue();
});

// --- container wiring -----------------------------------------------------

it('resolves Contracts\Gaze and the concrete Gaze to the same singleton', function () {
    $viaContract = $this->app->make(GazeContract::class);
    $viaConcrete = $this->app->make(Gaze::class);

    expect($viaContract)->toBeInstanceOf(Gaze::class)
        ->and($viaContract)->toBe($viaConcrete);
});

it('resolves Contracts\AuditService and the concrete AuditService to the same singleton', function () {
    $viaContract = $this->app->make(AuditServiceContract::class);
    $viaConcrete = $this->app->make(AuditService::class);

    expect($viaContract)->toBeInstanceOf(AuditService::class)
        ->and($viaContract)->toBe($viaConcrete);
});

it('resolves Contracts\DaemonManager and the concrete DaemonManager to the same scoped instance', function () {
    config(['gaze.daemon.policy_path' => '/fake/policy.toml', 'gaze.binary' => '/fake/gaze']);

    $viaContract = $this->app->make(DaemonManagerContract::class);
    $viaConcrete = $this->app->make(DaemonManager::class);

    expect($viaContract)->toBeInstanceOf(DaemonManager::class)
        ->and($viaContract)->toBe($viaConcrete);
});

it('resolves the facade root through the contract binding', function () {
    $direct = $this->app->make(GazeContract::class);

    expect(GazeFacade::getFacadeRoot())->toBe($direct);
});

// --- fake swap path -------------------------------------------------------

it('Gaze::fake() swaps both the contract and the concrete resolution', function () {
    $fake = GazeFacade::fake();

    expect($this->app->make(GazeContract::class))->toBe($fake)
        ->and($this->app->make(Gaze::class))->toBe($fake);
});

// --- fakes implement contracts, no longer extend concretes ----------------

it('every fake implements its contract', function () {
    expect(new FakeGaze)->toBeInstanceOf(GazeContract::class)
        ->and(new FakeAuditService)->toBeInstanceOf(AuditServiceContract::class)
        ->and(new FakeDaemonManager)->toBeInstanceOf(DaemonManagerContract::class)
        ->and((new FakeDaemonManager)->session('s'))->toBeInstanceOf(DaemonSessionContract::class)
        ->and((new FakeAuditService)->purge())->toBeInstanceOf(PurgeBuilderContract::class)
        ->and((new FakeAuditService)->query())->toBeInstanceOf(QueryBuilderContract::class);
});

it('fakes no longer extend the concrete service classes', function () {
    expect(new FakeGaze)->not->toBeInstanceOf(Gaze::class)
        ->and(new FakeAuditService)->not->toBeInstanceOf(AuditService::class)
        ->and(new FakeDaemonManager)->not->toBeInstanceOf(DaemonManager::class)
        ->and((new FakeDaemonManager)->session('s'))->not->toBeInstanceOf(DaemonSession::class)
        ->and((new FakeAuditService)->purge())->not->toBeInstanceOf(PurgeBuilder::class)
        ->and((new FakeAuditService)->query())->not->toBeInstanceOf(QueryBuilder::class);
});

// --- previously-fatal inherited surface now behaves sanely ----------------

it('FakeGaze no longer inherits the @internal audit process runners', function () {
    // Before the contract extraction these inherited methods fataled with an
    // uninitialized-typed-property Error deep inside Gaze::run(). Now the
    // fake's surface is exactly Contracts\Gaze — the methods do not exist.
    $reflection = new ReflectionClass(FakeGaze::class);

    expect($reflection->hasMethod('runForAuditPurge'))->toBeFalse()
        ->and($reflection->hasMethod('runForAuditQuery'))->toBeFalse()
        ->and(new FakeGaze)->not->toBeInstanceOf(AuditRunnerContract::class);
});

it('FakeDaemonManager::client() fails with an explicit LogicException instead of an uninitialized-property Error', function () {
    expect(fn () => (new FakeDaemonManager)->client())
        ->toThrow(LogicException::class, 'FakeDaemonManager holds no real daemon client');
});

it('FakeQueryBuilder keeps the fluent onlyRestoreEvents() toggle', function () {
    $builder = new FakeQueryBuilder([['row']]);

    expect($builder->onlyRestoreEvents())->toBe($builder)
        ->and($builder->wasRestrictedToRestoreEvents())->toBeTrue()
        ->and($builder->execute())->toBe([['row']]);
});

it('FakePurgeBuilder still requires before() like the real builder', function () {
    $builder = new FakePurgeBuilder(new FakeAuditService);

    expect(fn () => $builder->execute())->toThrow(LogicException::class);
});

it('FakeDaemonSession keeps the non-serializable guard of the real DaemonSession', function () {
    $session = (new FakeDaemonManager)->session('queue-leak');

    expect(fn () => serialize($session))->toThrow(LogicException::class);
});

it('FakeDaemonSession exposes id() like the real DaemonSession', function () {
    $session = (new FakeDaemonManager)->session('agent-a');

    expect($session)->toBeInstanceOf(FakeDaemonSession::class)
        ->and($session->id())->toBe('agent-a');
});
