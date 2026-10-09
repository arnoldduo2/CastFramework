<?php

// How passwords are hashed (Cast\Support\Hash, hashPassword(), Auth::hash()). Old bcrypt hashes keep working; users get a stronger one at their next login.
return [
    'driver' => env('HASH_DRIVER', 'auto'),   // auto (argon2id when this PHP has it, else bcrypt) | argon2id | bcrypt
    'bcrypt' => ['cost' => 12],               // 10 to 14; each +1 doubles the time
    'argon' => ['memory' => 65536, 'time' => 4, 'threads' => 1],   // memory in KiB
];
