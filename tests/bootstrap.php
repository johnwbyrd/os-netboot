<?php

/*
 * Bootstrap for plain PHPUnit (no Phalcon, no OPNsense framework).
 *
 * We test only the classes under mvc/app/library/OPNsense/Netboot/ here --
 * pure utility code with stdlib dependencies. The full framework tests
 * (model validation, controller binding) happen on the OPNsense host via
 * `make test` once a release is installed, since they need configd, the
 * model loader, the autoloader, etc.
 *
 * Loader is a simple PSR-4-ish hand-roll mapping the OPNsense\Netboot\
 * namespace to the library directory.
 */

spl_autoload_register(function (string $class) {
    $prefix = 'OPNsense\\Netboot\\';
    $base = __DIR__ . '/../src/opnsense/mvc/app/library/OPNsense/Netboot/';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = $base . $relative . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
