<?php

/*
 * Bootstrap for plain PHPUnit (no Phalcon, no OPNsense framework).
 *
 * We test only the classes under mvc/app/library/OPNsense/Netboot/ here --
 * pure utility code with stdlib dependencies. Phalcon-bound tests are
 * deferred to Layer 4 of the test plan (see doc/tdd.md).
 *
 * Tests live at the REPO ROOT under tests/ -- NOT under
 * src/opnsense/mvc/tests/. That subtree of /usr/local/opnsense is owned
 * by the opnsense-core package itself, so any file we put there
 * collides with core at pkg install time (this was a real install
 * failure on a clean OPNsense box). Plugins put tests at tests/ at
 * the repo root and invoke phpunit directly; the OPNsense Mk system's
 * 'make test' TESTDIR convention is for opnsense/core, not for
 * individual plugins.
 *
 * Loader is a simple PSR-4-ish hand-roll mapping the OPNsense\Netboot\
 * namespace to the library directory.
 */

spl_autoload_register(function (string $class) {
    $prefix = 'OPNsense\\Netboot\\';
    // Tests live at <repo>/tests/; library is at
    // <repo>/src/opnsense/mvc/app/library/OPNsense/Netboot/.
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
