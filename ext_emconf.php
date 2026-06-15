<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Robbi Copy',
    'description' => 'Enterprise CLI Exporter für TYPO3 v13 & GSB 11',
    'category' => 'module',
    'author' => 'ITZBund',
    'author_email' => 'gsb-projekt@itzbund.de',
    'state' => 'stable',
    'version' => '5.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
