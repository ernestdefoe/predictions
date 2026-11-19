<?php

namespace ErnestDefoe\Predictions;

use RuntimeException;

/** A request we turn down, with the code the browser turns into a sentence (errorText() in js/src/forum/data.ts, from forum.errors.*). */
class Refusal extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status = 422)
    {
        parent::__construct($reason);
    }
}
