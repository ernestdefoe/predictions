<?php

namespace ErnestDefoe\Predictions\Api;

use ErnestDefoe\Predictions\Refusal;
use Flarum\Foundation\ErrorHandling\HandledError;

/** A Refusal becomes `{errors: [{status, code}]}`; the browser turns the code into a sentence. */
class RefusalHandler
{
    public function handle(Refusal $refusal): HandledError
    {
        return new HandledError($refusal, $refusal->reason, $refusal->status);
    }
}
