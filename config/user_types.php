<?php

return [
    'supported' => [
        'super_admin' => ['name' => 'Super Admin', 'level' => 1],
        'admin' => ['name' => 'Admin', 'level' => 2],
        'teacher' => ['name' => 'Teacher', 'level' => 3],
        'student' => ['name' => 'Student', 'level' => 4],
        'parent' => ['name' => 'Parent', 'level' => 4],
        'accountant' => ['name' => 'Accountant', 'level' => 5],
        'librarian' => ['name' => 'Librarian', 'level' => 6],
    ],
    // Administrator accounts are created through school/platform setup workflows.
    'school_creatable' => ['librarian', 'accountant', 'teacher', 'student', 'parent'],
];
