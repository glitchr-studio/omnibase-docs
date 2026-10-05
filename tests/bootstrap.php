<?php

// Checked out on its own (its own vendor/), or installed in an application's
// vendor/omnibase/docs (a path repository's symlink): the application's
// autoloader then, which does not know this bundle's autoload-dev - the test
// namespace is registered here, and the classes under test are this checkout's.
foreach ([
    __DIR__.'/../vendor/autoload.php',
    getcwd().'/vendor/autoload.php',
    __DIR__.'/../../../autoload.php',
] as $autoload) {
    if (is_file($autoload)) {
        $loader = require $autoload;
        if ($loader instanceof \Composer\Autoload\ClassLoader) {
            $loader->addPsr4('Base\\Wikidoc\\Tests\\', __DIR__);
            $loader->addPsr4('Base\\Wikidoc\\', __DIR__.'/../src', true);
        }

        return;
    }
}

throw new RuntimeException('No composer autoloader found.');
