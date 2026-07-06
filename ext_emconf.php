<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'ImpExpNL – CLI Content Transfer',
    'description' => 'CLI-first content transfer for TYPO3: signed export/import (JSON/JSONL), delta sync with dry-run diff, conflict handling, rollback, FAL assets, and hardened transfers across security zones (bundle/push/inbox).',
    'category' => 'misc',
    'author' => 'Robert Schleiermacher',
    'author_email' => 'robert.schleiermacher@gmail.com',
    'state' => 'stable',
    'version' => '2.4.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.0.0-14.99.99',
            'php' => '8.2.0-8.4.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'workspaces' => '',
        ],
    ],
];
