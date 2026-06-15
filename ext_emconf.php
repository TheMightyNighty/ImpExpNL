<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Robbi Copy',
    'description' => 'Enterprise CLI Exporter für TYPO3 & GSB 11',
    'category' => 'module',
    'author' => 'ITZBund',
    'author_email' => 'gsb-projekt@itzbund.de',
    'state' => 'stable',
    'version' => '4.14.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-14.3.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
