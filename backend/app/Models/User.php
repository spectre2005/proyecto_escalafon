<?php

namespace App\Models;

use Database\Factories\UserFactory;

class User extends Usuario
{
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
