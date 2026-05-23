<?php

declare(strict_types=1);

use Ferdiunal\AiDevApi\Support\KeyMasker;

it('masks provider keys without leaking short secrets', function () {
    expect(KeyMasker::mask('abc'))->toBe('****')
        ->and(KeyMasker::mask('sk-1234567890abcdef'))->toBe('sk-1...cdef');
});
