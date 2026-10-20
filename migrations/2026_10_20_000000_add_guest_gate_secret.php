<?php

/*
 * This file is part of ernestdefoe/guest-gate.
 */

use Illuminate\Database\Schema\Builder;

// The key that signs guests' allowance cookies, made once per forum.
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();
        $key = 'ernestdefoe-guest-gate.secret';

        if (! $db->table('settings')->where('key', $key)->exists()) {
            $db->table('settings')->insert(['key' => $key, 'value' => bin2hex(random_bytes(32))]);
        }
    },
    'down' => function (Builder $schema) {
        $schema->getConnection()->table('settings')->where('key', 'ernestdefoe-guest-gate.secret')->delete();
    },
];
