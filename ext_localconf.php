<?php
declare(strict_types=1);

defined('TYPO3') or die();

/*
 * Logging-Konfiguration für Robbi Copy.
 *
 * Alle Services loggen über den PSR-3 Logger unter dem Namespace Robbi\RobbiCopy.
 * Damit das Betriebsteam die Logs in ELK/Graylog/Kubernetes filtern kann,
 * wird hier ein eigener FileWriter für den Namespace konfiguriert.
 *
 * Überschreibbar in config/system/additional.php:
 *
 *   $GLOBALS['TYPO3_CONF_VARS']['LOG']['Robbi']['RobbiCopy']['writerConfiguration'] = [
 *       \TYPO3\CMS\Core\Log\LogLevel::INFO => [
 *           \TYPO3\CMS\Core\Log\Writer\SyslogWriter::class => [],
 *       ],
 *   ];
 */
$GLOBALS['TYPO3_CONF_VARS']['LOG']['Robbi']['RobbiCopy']['writerConfiguration'] = [
    \TYPO3\CMS\Core\Log\LogLevel::INFO => [
        \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
            'logFileInfix' => 'robbicopy',
        ],
    ],
];
