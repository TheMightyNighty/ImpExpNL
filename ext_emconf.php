<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'ImpExpNL',
    'description' => 'Enterprise CLI Exporter für TYPO3 v14',
    'category' => 'module',
    'author' => 'Robert Schleiermacher',
    'author_email' => 'robert.schleiermacher@gmail.com',
    'state' => 'stable',
    'version' => '2.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.0.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
