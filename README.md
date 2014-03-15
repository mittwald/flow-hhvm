HHVM compatibility for TYPO3 Flow
=================================

Copyright & Author
------------------

Copyright 2014 Martin Helmich, Mittwald CM Service GmbH & Co. KG

Synopsis
--------

This package aims at providing a stable configuration for running TYPO3 Flow
and Neos applications with the [HipHop virtual machine](https://github.com/facebook/hhvm).

What does it do?
----------------

While generally stable, HHVM still contains some incompatibilities with the
default PHP Zend engine behaviour (some of them intentional and some bugs).

On installation, this package applies a set of patches around these incompatibilities
to the TYPO3 Flow and Neos core packages (some of them are very dirty; that's why
they are applied as a patch and should not be merged into the upstream code base
of these packages).

In addition, this package adjusts the default Flow configuration in order to work
with HHVM and adds a turnkey configuration file for HHVM.

Requirements
------------

This package has the following requirements:

- Obviously, you will need a running HHVM installation. Please see the
  appropriate [vendor documentation](https://github.com/facebook/hhvm)
  for installation instructions.
- Currently, this package works with TYPO3 Flow 2.1 and TYPO3 Neos 1.0
  ONLY. Other branches might work, too, but the patches introduced by this
  package might not apply cleanly.

Installation
------------

### Fresh installation

When starting a new project, best create a new composer project from
`mittwald/flow-hhvm-distribution`. This package will be installed
automatically as a dependency:

    composer create-project mittwald/flow-hhvm-distribution

When creating a TYPO3 Neos project, use `mittwald/neos-hhvm-distribution`
instead:

    composer create-project mittwald/neos-hhvm-distribution

### Installing on existing projects

This is a bit more difficult; first require this Flow package, either by
adding `"mittwald/flow-hhvm": "dev-master"` to the `requires` section of
your `composer.json`, or simply type:

    composer require mittwald/flow-hhvm dev-master

Please note, that after installing the package, you will have to register and execute the
installation scripts. For this, add the following section to your composer.json
(merge the configuration with the already existing post-install scripts, when
necessary):

    "scripts": {
        "post-update-cmd": [
            "Mittwald\\HHVM\\Composer\\Installer::postInstall"
        ]
        "post-install-cmd": [
            "Mittwald\\HHVM\\Composer\\Installer::postInstall"
        ]
    }

After that, trigger the installation script by typing either `composer install` (again)
or simply:

    composer run-script post-install-cmd

Running
-------

### Web server

The installation script should create a `*.hdf` file in your `Configuration` directory.
Start HHVM with this configuration file:

    hhvm -m server -c Configuration/HipHopJit.hdf
    
### Command line

Easy. Just type:

    hhvm flow help
    
You can also replace the shebang in the `flow` script with `#!/usr/bin/hhvm`.
    
Configuration
-------------

On installation, this package adds the following configuration snippet to your
`Configuration/Settings.yaml` file (if this does not exist yet, it will be created,
otherwise already existing configurations will be merged):

    TYPO3:
      Flow:
        core:
          phpBinaryPathAndFilename: /usr/bin/hhvm  # May vary, is determined automatically
          subRequestPhpIniPathAndFilename: False   # HHVM does not have a php.ini
          
Additionally, the installer will create a HDF configuration file for HHVM in the
`Configuration` directory (have a look at the [configuration template](Configuration/HipHopJit.hdf)).
