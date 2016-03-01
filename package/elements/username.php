<?php

use Concrete\Core\User\User;
use Concrete\Core\User\UserInfo;

/* @var mixer $user */
$id = null;
$name = '';
if (isset($user) && $user) {
    if (is_int($user) || (is_string($user) && is_numeric($user))) {
        $user = \UserInfo::getByID($user);
    }
    if ($user instanceof User) {
        $id = $user->getUserID();
        $name = $user->getUserName();
    } elseif ($user instanceof UserInfo) {
        $id = $user->getUserID();
        $name = $user->getUserName();
    }
}
if ($id) {
    // Do some fancy stuff - just print the user name for now
    echo h($name);
} else {
    echo '?';
}
