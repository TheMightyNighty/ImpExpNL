<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'ImpExpNL',
    'description' => 'Enterprise CLI Exporter für TYPO3 v13 & GSB 11',
    'category' => 'module',
    'author' => 'Robert Schleiermacher',
    'author_email' => 'robert.schleiermacher@gmail.com',
    'state' => 'stable',
    'version' => '1.0.2',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
