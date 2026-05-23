<?php

it('will not use debugging functions')
    // @phpstan-ignore-next-line Pest architecture expectations are registered at runtime.
    ->expect(['dd', 'dump', 'ray'])
    ->not->toBeUsed();
