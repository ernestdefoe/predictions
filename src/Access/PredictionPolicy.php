<?php

namespace ErnestDefoe\Predictions\Access;

use ErnestDefoe\Predictions\Prediction;
use ErnestDefoe\Predictions\Settings;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * The endpoint guards. Each is checked again under the row lock where the
 * answer can change between the check and the write (who else has picked,
 * whether it was settled meanwhile): see Editor, Picks and Settler.
 */
class PredictionPolicy extends AbstractPolicy
{
    public function update(User $actor, Prediction $prediction): ?string
    {
        return $prediction->canEdit($actor) ? $this->allow() : $this->deny();
    }

    public function delete(User $actor, Prediction $prediction): ?string
    {
        return $prediction->canModerate($actor) || $prediction->isOwner($actor) ? $this->allow() : $this->deny();
    }

    public function pick(User $actor, Prediction $prediction): ?string
    {
        return ! $actor->isGuest() && $actor->hasPermission(Settings::PICK) ? $this->allow() : $this->deny();
    }

    /** Who may settle at all; Settler says exactly why not (settled already, an ended season…). */
    public function settle(User $actor, Prediction $prediction): ?string
    {
        return $prediction->isOwner($actor) || $prediction->canModerate($actor) ? $this->allow() : $this->deny();
    }
}
