<?php

declare(strict_types=1);

use Packman\Context\Contracts\ActorResolverInterface;
use Packman\Context\Contracts\ContextResolverInterface;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Context\Data\ActorRef;
use Packman\Context\Data\ScopeRef;
use Packman\Context\Exceptions\ScopeUnavailableException;
use Packman\Errors\Enums\ErrorCategory;

it('constructs immutable ScopeRef and ActorRef value objects', function (): void {
    $scope = new ScopeRef('tenant-42', ['region' => 'eu']);
    $actor = new ActorRef('user', 'usr-7');

    expect($scope)->toBeInstanceOf(ContextScopeInterface::class)
        ->and($scope->id())->toBe('tenant-42')
        ->and($scope->attributes)->toBe(['region' => 'eu'])
        ->and($actor->type)->toBe('user')
        ->and($actor->id)->toBe('usr-7');
});

it('rejects empty scope or actor identifiers', function (): void {
    expect(fn (): ScopeRef => new ScopeRef(''))->toThrow(InvalidArgumentException::class)
        ->and(fn (): ActorRef => new ActorRef(''))->toThrow(InvalidArgumentException::class);
});

it('rejects non-scalar or non-finite attributes and detaches array references on ScopeRef', function (): void {
    $mutable = 'initial';
    $input = ['env' => &$mutable];
    $scope = new ScopeRef('tenant-1', $input);
    $mutable = new stdClass;

    expect($scope->attributes)->toBe(['env' => 'initial'])
        ->and(fn (): ScopeRef => new ScopeRef('tenant-1', ['bad' => new stdClass]))->toThrow(InvalidArgumentException::class)
        ->and(fn (): ScopeRef => new ScopeRef('tenant-1', ['bad' => INF]))->toThrow(InvalidArgumentException::class);
});

it('resolves context and actor contracts purely in PHP', function (): void {
    $resolver = new class implements ContextResolverInterface
    {
        public function current(): ?ContextScopeInterface
        {
            return new ScopeRef('acct-1');
        }
    };
    $actorResolver = new class implements ActorResolverInterface
    {
        public function current(): ActorRef
        {
            return new ActorRef('system');
        }
    };

    expect($resolver->current()?->id())->toBe('acct-1')
        ->and($actorResolver->current()->type)->toBe('system')
        ->and($actorResolver->current()->id)->toBeNull();
});

it('exposes coded descriptor on ScopeUnavailableException', function (): void {
    $exception = new ScopeUnavailableException;

    expect($exception->errorDescriptor()->code)->toBe('packman.scope_unavailable')
        ->and($exception->errorDescriptor()->category)->toBe(ErrorCategory::Conflict);
});
