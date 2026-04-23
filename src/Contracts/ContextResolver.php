<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Contracts;

use Naoray\GazeLaravel\Context;

/**
 * Builds a Context from an app-specific source (Eloquent model, DTO, queue
 * payload, whatever). Implement once per domain.
 *
 * gaze-laravel ships the contract only — no default implementation. PII
 * relations are domain knowledge: a ticketing app pulls customer data off
 * $ticket->customer, a music app off $song->artist. The wrapper cannot
 * derive that.
 *
 * Bind a concrete resolver in your application's service provider:
 *
 *   $this->app->bind(ContextResolver::class, TicketContextResolver::class);
 *
 * Or resolve per-domain via a tagged container binding if you have several.
 */
/**
 * @template T
 */
interface ContextResolver
{
    /**
     * Resolve the PII Context from a source object. PHP does not allow
     * parameter narrowing on implementations, so this stays `mixed`;
     * concrete resolvers validate at runtime and may declare a `@param T`
     * generic hint via PHPDoc for static analysis.
     *
     * @param  T  $source
     */
    public function resolve(mixed $source): Context;
}
