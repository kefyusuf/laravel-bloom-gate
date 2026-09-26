<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\AuthoritativeSetIdentity;
use Kefyusuf\BloomGate\Core\NormalizationIdentity;

it('accepts bounded visible-ascii normalization identities', function (string $value): void {
    $identity = NormalizationIdentity::fromString($value);

    expect($identity->value())->toBe($value);
})->with([
    'simple version' => 'exact-string@1',
    'semantic parameters' => 'tenant-sku@2;separator=-',
    'punctuation' => 'casefold@1;locale=tr_TR',
    'maximum length' => str_repeat('a', 256),
]);

it('accepts bounded visible-ascii authoritative-set identities', function (string $value): void {
    $identity = AuthoritativeSetIdentity::fromString($value);

    expect($identity->value())->toBe($value);
})->with([
    'logical source' => 'users.email-canonical@1',
    'qualified semantics' => 'users@2;predicate=active-email',
    'maximum length' => str_repeat('z', 256),
]);

it('rejects invalid normalization identities', function (string $value): void {
    NormalizationIdentity::fromString($value);
})->with([
    'empty' => '',
    'too long' => str_repeat('a', 257),
    'space' => 'exact string@1',
    'tab' => "exact\tstring@1",
    'newline' => "exact\nstring@1",
    'nul' => "exact\0string@1",
    'unicode' => 'normalize-ürün@1',
])->throws(InvalidArgumentException::class);

it('rejects invalid authoritative-set identities', function (string $value): void {
    AuthoritativeSetIdentity::fromString($value);
})->with([
    'empty' => '',
    'too long' => str_repeat('a', 257),
    'space' => 'users email@1',
    'carriage return' => "users\remail@1",
    'nul' => "users\0email@1",
    'unicode' => 'ürünler.sku@1',
])->throws(InvalidArgumentException::class);

it('compares semantic identities by exact case-sensitive value', function (): void {
    expect(
        NormalizationIdentity::fromString('Exact-String@1')
            ->equals(NormalizationIdentity::fromString('Exact-String@1')),
    )->toBeTrue()
        ->and(
            NormalizationIdentity::fromString('Exact-String@1')
                ->equals(NormalizationIdentity::fromString('exact-string@1')),
        )->toBeFalse()
        ->and(
            AuthoritativeSetIdentity::fromString('Users.Email@1')
                ->equals(AuthoritativeSetIdentity::fromString('Users.Email@1')),
        )->toBeTrue()
        ->and(
            AuthoritativeSetIdentity::fromString('Users.Email@1')
                ->equals(AuthoritativeSetIdentity::fromString('users.email@1')),
        )->toBeFalse();
});
