<?php

it('will not use debugging functions', function (): void {
    expect(['dd', 'dump', 'ray'])->not->toBeUsed();
});
