<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\AuthoritativeSetFingerprint;
use Kefyusuf\BloomGate\Core\AuthoritativeSetIdentity;
use Kefyusuf\BloomGate\Core\ConsistencyContract;
use Kefyusuf\BloomGate\Core\ConsistencyFingerprint;
use Kefyusuf\BloomGate\Core\NormalizationFingerprint;
use Kefyusuf\BloomGate\Core\NormalizationIdentity;
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;

it('pins deterministic domain-separated sha256 fingerprint vectors', function (): void {
    $calculator = new SemanticFingerprintCalculator;

    expect(
        $calculator
            ->normalization(NormalizationIdentity::fromString('exact-string@1'))
            ->value(),
    )->toBe('sha256:a31b939ff4c26f35751f0733a3eb7c0cedaa0be2ce28b31e773d2ebc476984c0')
        ->and(
            $calculator
                ->authoritativeSet(AuthoritativeSetIdentity::fromString('users.email-canonical@1'))
                ->value(),
        )->toBe('sha256:c7841cc0de57b544c34c7972319d2e0df079b94276d5abbf88fae99c8131de5b')
        ->and(
            $calculator
                ->consistency(ConsistencyContract::PreAddV1)
                ->value(),
        )->toBe('sha256:e4dcb79f5cd52d1aae2554849490f725987a555de4aa52d81c0ad5d858fb0cc3');
});

it('separates equal lexical identities by semantic fingerprint domain', function (): void {
    $calculator = new SemanticFingerprintCalculator;

    $normalization = $calculator
        ->normalization(NormalizationIdentity::fromString('preadd-v1'))
        ->value();
    $authoritativeSet = $calculator
        ->authoritativeSet(AuthoritativeSetIdentity::fromString('preadd-v1'))
        ->value();
    $consistency = $calculator
        ->consistency(ConsistencyContract::PreAddV1)
        ->value();

    expect($normalization)->not->toBe($authoritativeSet)
        ->and($normalization)->not->toBe($consistency)
        ->and($authoritativeSet)->not->toBe($consistency);
});

it('round trips canonical fingerprint values by domain type', function (): void {
    $normalization = NormalizationFingerprint::fromString(
        'sha256:'.str_repeat('a', 64),
    );
    $authoritativeSet = AuthoritativeSetFingerprint::fromString(
        'sha256:'.str_repeat('b', 64),
    );
    $consistency = ConsistencyFingerprint::fromString(
        'sha256:'.str_repeat('c', 64),
    );

    expect($normalization->value())->toBe('sha256:'.str_repeat('a', 64))
        ->and($authoritativeSet->value())->toBe('sha256:'.str_repeat('b', 64))
        ->and($consistency->value())->toBe('sha256:'.str_repeat('c', 64))
        ->and(
            $normalization->equals(
                NormalizationFingerprint::fromString('sha256:'.str_repeat('a', 64)),
            ),
        )->toBeTrue()
        ->and(
            $authoritativeSet->equals(
                AuthoritativeSetFingerprint::fromString('sha256:'.str_repeat('b', 64)),
            ),
        )->toBeTrue()
        ->and(
            $consistency->equals(
                ConsistencyFingerprint::fromString('sha256:'.str_repeat('c', 64)),
            ),
        )->toBeTrue();
});

it('rejects non-canonical fingerprint encodings', function (string $value): void {
    NormalizationFingerprint::fromString($value);
})->with([
    'empty' => '',
    'missing algorithm prefix' => str_repeat('a', 64),
    'wrong algorithm' => 'sha512:'.str_repeat('a', 64),
    'short digest' => 'sha256:'.str_repeat('a', 63),
    'long digest' => 'sha256:'.str_repeat('a', 65),
    'uppercase digest' => 'sha256:'.str_repeat('A', 64),
    'non hex digest' => 'sha256:'.str_repeat('g', 64),
])->throws(InvalidArgumentException::class);

it('uses the same canonical fingerprint validation for every semantic domain', function (): void {
    expect(fn (): AuthoritativeSetFingerprint => AuthoritativeSetFingerprint::fromString(
        'sha256:'.str_repeat('A', 64),
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): ConsistencyFingerprint => ConsistencyFingerprint::fromString(
            'sha256:'.str_repeat('g', 64),
        ))->toThrow(InvalidArgumentException::class);
});
