1.4.0 / 2020-11-23
========================
* Add Composer 2 support.

1.3.0 / 2020-07-27
========================
* Add a `rename` property to the library definition, providing the ability to
    rename a library asset to match a particular folder pattern.
* Add support for libraries with vendor namespaces like [ckeditor][ckeditor-downloads].
* Add a convenience `install-drupal-libraries` composer command. It typically
    requires your composer dependencies to have already been resolved.

1.2.0 / 2020-07-20
========================
* Address a `LogicException` being thrown when the package is uninstalled.

1.1.1 / 2020-07-13
========================

* Quality of life improvements such as an alternative configuration
definition for supporting:
    * Different file extensions.
    * SHA1 checksum verification.
    * Removing example/demo/test files from the libraries (see [PSA-2011-002](https://www.drupal.org/node/1189632)).
* Ability to pull in library dependencies declared in subpackages through the
`drupal-libraries-dependencies` extra option.
* Basic unit tests.

1.1.0 / 2020-05-01
========================
* Add better support for sub-packages.

1.0.1 / 2018-01-25 
========================
* Relax composer-installers version requirement.

1.0.0 / 2018-01-25 
========================
* Initial MVP plugin.

[ckeditor-downloads]: https://github.com/balbuf/drupal-libraries-installer/issues/6
