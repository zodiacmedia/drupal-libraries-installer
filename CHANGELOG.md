1.x.x / xxxx-xx-xx
========================

1.5.0 / 2022-03-01
========================
* Improve support for detecting various versions and archive types.
* [#5](https://github.com/zodiacmedia/drupal-libraries-installer/pull/5) - Support composer/installers version 2.

1.4.1 / 2021-02-22
========================
* Add better Composer 2 support.

  * Fix issue where libraries could not be downloaded on an empty cache, creating an empty folder instead.

    Composer 2 introduces additional steps to [`DownloaderInterface`][composer-2-upgrade], which 
    needed integration as well as support for resolving the promises properly. [Additional reference][composer-2-download-support].
  * Support parallel library downloads on Composer 2, while keeping existing synchronous download support on Composer 1.
* Fix issue with the plugin failing early if the plugin package is an `AliasPackage`. 

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

[composer-2-upgrade]: https://getcomposer.org/upgrade/UPGRADE-2.0.md
[composer-2-download-support]: https://github.com/composer/composer/issues/9209
[ckeditor-downloads]: https://github.com/balbuf/drupal-libraries-installer/issues/6
