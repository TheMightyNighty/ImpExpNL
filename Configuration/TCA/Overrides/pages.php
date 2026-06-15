<?php
defined('TYPO3') or die();

$tempColumns = [
    'tx_robbicopy_remote_uid' => [
        'exclude' => true,
        'label' => 'Robbi Copy: Original UID',
        'config' => [
            'type' => 'number',
            'readOnly' => true,
            'default' => 0,
        ],
    ],
];

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('pages', $tempColumns);
