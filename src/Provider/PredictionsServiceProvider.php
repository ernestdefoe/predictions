<?php

namespace ErnestDefoe\Predictions\Provider;

use ErnestDefoe\Predictions\Award\NoOracleBadge;
use ErnestDefoe\Predictions\Award\OracleBadge;
use Flarum\Foundation\AbstractServiceProvider;

class PredictionsServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bind(OracleBadge::class, NoOracleBadge::class);
    }
}
