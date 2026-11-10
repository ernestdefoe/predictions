<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

// Everyone can look, members can open and pick, moderators can settle, edit and remove anyone's.
return Migration::addPermissions([
    'predictions.view' => Group::GUEST_ID,
    'predictions.create' => Group::MEMBER_ID,
    'predictions.pick' => Group::MEMBER_ID,
    'predictions.moderate' => Group::MODERATOR_ID,
]);
