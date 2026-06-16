<?php
defined('TYPO3') or die();

$tempColumns = [
    'tx_impexpnl_remote_uid' => [
        'exclude' => true,
        'label' => 'ImpExpNL: Original UID',
        'config' => [
            'type' => 'number',
            'readOnly' => true,
            'default' => 0,
        ],
    ],
];

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('tt_content', $tempColumns);
