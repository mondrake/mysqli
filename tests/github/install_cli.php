<?php

/**
 * @file
 * Initiates a command line installation of Drupal.
 */

// Some minimal values for $_SERVER.
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = 80;
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// Store the Drupal root path.
$root_path = realpath('');

// Initialize the autoloader.
$class_loader = require_once $root_path . '/autoload.php';

$settings = [
  'parameters' => [
    'profile' => 'standard',
    'locale' => 'en',
  ],
  'forms' => [
    'install_settings_form' => [
      'driver' => 'mysqli',
      'mysqli' => [
        'database' => 'drupal_db',
        'username' => 'root',
        'password' => '',
        'host' => '0.0.0.0',
        'port' => 3306,
        'prefix' => 'd10t',
      ],
    ],
    'install_configure_form' => [
      'site_name' => 'mysqli',
      'site_mail' => 'mysqli@mysqli.com',
      'account' => [
        'name' => 'admin',
        'mail' => 'mysqli@mysqli.com',
        'pass' => [
          'pass1' => 'adminpass',
          'pass2' => 'adminpass',
        ],
      ],
      'update_status_module' => [
        1 => TRUE,
        2 => TRUE,
      ],
    ],
  ],
];

// Start the installer.
require_once $root_path . '/core/includes/install.core.inc';
install_drupal($class_loader, $settings);
