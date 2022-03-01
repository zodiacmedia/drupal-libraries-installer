<?php

namespace Zodiacmedia\DrupalLibrariesInstaller;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\ConsoleIO;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\CompletePackageInterface;
use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;
use React\Promise\PromiseInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\Glob;
use Composer\Plugin\Capability\CommandProvider;

/**
 * The Drupal libraries installer plugin.
 */
class Plugin implements PluginInterface, Capable, EventSubscriberInterface {

  /**
   * The installed-libraries.json lock file schema version.
   *
   * @var string
   */
  const SCHEMA_VERSION = '1.1';

  /**
   * The composer package name.
   */
  const PACKAGE_NAME = 'zodiacmedia/drupal-libraries-installer';

  /**
   * The composer instance.
   *
   * @var \Composer\Composer
   */
  protected $composer;

  /**
   * The input/output controller instance.
   *
   * @var \Composer\IO\IOInterface
   */
  protected $io;

  /**
   * The download manager instance.
   *
   * @var \Composer\Downloader\DownloadManager
   */
  protected $downloadManager;

  /**
   * The installation manager instance.
   *
   * @var \Composer\Installer\InstallationManager
   */
  protected $installationManager;

  /**
   * The file system utility instance.
   *
   * @var \Composer\Util\Filesystem
   */
  protected $fileSystem;

  /**
   * Called when the composer plugin is activated.
   *
   * @param \Composer\Composer $composer
   *   The composer instance.
   * @param \Composer\IO\IOInterface $io
   *   The input/output controller.
   * @param \Composer\Util\Filesystem|null $filesystem
   *   The filesystem utility helper.
   */
  public function activate(Composer $composer, IOInterface $io, Filesystem $filesystem = NULL) {
    $this->composer = $composer;

    $this->io = $io;
    $this->fileSystem = $filesystem ?? new Filesystem();
    $this->downloadManager = $composer->getDownloadManager();
    $this->installationManager = $composer->getInstallationManager();
  }

  /**
   * Instruct the plugin manager to subscribe us to these events.
   */
  public static function getSubscribedEvents() {
    return [
      ScriptEvents::POST_INSTALL_CMD => 'install',
      ScriptEvents::POST_UPDATE_CMD => 'install',
      InstallLibrariesEvent::INSTALL_LIBRARIES => 'install',
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function getCapabilities() {
    return [
      CommandProvider::class => PluginCommandProvider::class,
    ];
  }

  /**
   * Upon running composer install or update, install the drupal libraries.
   *
   * @param \Composer\Script\Event|\Zodiacmedia\DrupalLibrariesInstaller\InstallLibrariesEvent $event
   *   The composer install/update/install-drupal-libraries event.
   *
   * @throws \Exception
   */
  public function install(Event $event) {
    $composer = $event->getComposer();

    $installed_json_file_path = $this->getInstalledJsonPath();
    if ($installed_json_file_path === FALSE) {
      if ($this->io->isDebug()) {
        $this->io->write(
          sprintf(
            "Could not resolve the '%s' package path. Skipping the installer processing.",
            static::PACKAGE_NAME
          )
        );
      }
      return;
    }
    $installed_json_file = new JsonFile($installed_json_file_path, NULL, $this->io);

    $installed = NULL;
    if ($installed_json_file->exists()) {
      $installed = $installed_json_file->read();
    }

    // Reset if the schema doesn't match the current version.
    if (!isset($installed['schema-version']) || $installed['schema-version'] !== static::SCHEMA_VERSION) {
      $installed = [
        'schema-version' => static::SCHEMA_VERSION,
        'installed' => [],
      ];
    }

    $applied_drupal_libraries = $installed['installed'];

    // Process the root package first.
    $root_package = $composer->getPackage();
    $processed_drupal_libraries = $this->processPackage([], $applied_drupal_libraries, $root_package);

    // Process libraries declared in dependencies.
    if (!empty($root_package->getExtra()['drupal-libraries-dependencies'])) {
      $allowed_dependencies = $root_package->getExtra()['drupal-libraries-dependencies'];
      $local_repo = $composer->getRepositoryManager()->getLocalRepository();
      foreach ($local_repo->getCanonicalPackages() as $package) {
        if (
          $allowed_dependencies === TRUE ||
          (is_array($allowed_dependencies) && in_array($package->getName(), $allowed_dependencies, TRUE))
        ) {
          if (!empty($package->getExtra()['drupal-libraries'])) {
            $processed_drupal_libraries += $this->processPackage(
              $processed_drupal_libraries,
              $applied_drupal_libraries,
              $package
            );
          }
        }
      }
    }

    // Remove unused libraries from disk before attempting to download new ones.
    // Avoids the edge-case where the removed folder happens to be the same as
    // the one where a new package dependency is being installed to.
    $removed_libraries = array_diff_key($applied_drupal_libraries, $processed_drupal_libraries);
    if ($removed_libraries) {
      $this->removeUnusedLibraries($removed_libraries);
    }

    // Attempt to download the libraries.
    $this->downloadLibraries($processed_drupal_libraries, $applied_drupal_libraries);

    // Write the lock file to disk.
    if ($this->io->isDebug()) {
      $this->io->write(static::PACKAGE_NAME . ':');
      $this->io->write(
        sprintf('  - Writing to %s', $this->fileSystem->normalizePath($installed_json_file->getPath()))
      );
    }

    $installed['installed'] = $processed_drupal_libraries;
    $installed_json_file->write($installed);
  }

  /**
   * Drupal library processor.
   *
   * Inspired by https://github.com/civicrm/composer-downloads-plugin.
   *
   * @param array $processed_drupal_libraries
   *   The currently processed drupal libraries.
   * @param array $drupal_libraries
   *   The currently installed drupal libraries.
   * @param \Composer\Package\PackageInterface $package
   *   The package instance.
   *
   * @return array
   *   The processed packages.
   */
  protected function processPackage(array $processed_drupal_libraries, array $drupal_libraries, PackageInterface $package) {
    $extra = $package->getExtra();

    if (empty($extra['drupal-libraries']) || !is_array($extra['drupal-libraries'])) {
      return $processed_drupal_libraries;
    }

    // Install each library.
    foreach ($extra['drupal-libraries'] as $library => $library_definition) {
      $ignore_patterns = [];
      $rename = [];
      $sha1checksum = NULL;
      if (is_string($library_definition)) {
        // Simple format.
        $url = $library_definition;
        [$version, $distribution_type] = $this->guessDefaultsFromUrl($url);
      }
      else {
        if (empty($library_definition['url'])) {
          throw new \LogicException("The drupal-library '$library' does not contain a valid URL.");
        }
        $url = $library_definition['url'];
        [$version, $distribution_type] = $this->guessDefaultsFromUrl($url);
        $version = $library_definition['version'] ?? $version;
        $distribution_type = $library_definition['type'] ?? $distribution_type;
        $ignore_patterns = $library_definition['ignore'] ?? $ignore_patterns;
        $rename = $library_definition['rename'] ?? $rename;
        $sha1checksum = $library_definition['shasum'] ?? $sha1checksum;
      }

      if (isset($processed_drupal_libraries[$library])) {
        // Only the first declaration of the library is ever used. This ensures
        // that the root package always acts as the source of truth over what
        // version of a library is installed.
        $old_definition = $processed_drupal_libraries[$library];
        if ($this->io->isDebug()) {
          $this->io->write(
            sprintf(
              '<warning>Library %s already declared by %s, (%s also attempts to declare one). Skipping...</warning>',
              $library . ' [' . $old_definition['url'] . ']',
              $old_definition['package'],
              "$library [$url]"
            )
          );
        }
      }
      else {
        // Track installed libraries in the package info in
        // installed-libraries.json.
        $applied_library = [
          'version' => $version,
          'url' => $url,
          'type' => $distribution_type,
          'ignore' => $ignore_patterns,
          'rename' => $rename,
          'package' => $package->getName(),
        ];
        if (empty($rename)) {
          unset($applied_library['rename']);
        }
        if (isset($sha1checksum)) {
          $applied_library['shasum'] = $sha1checksum;
        }

        $processed_drupal_libraries[$library] = $applied_library;
      }
    }

    return $processed_drupal_libraries;
  }

  /**
   * Remove old unused libraries from disk.
   *
   * @param array $old_libraries
   *   The old libraries to remove from disk.
   */
  protected function removeUnusedLibraries(array $old_libraries) {
    foreach ($old_libraries as $library_name => $library_definition) {
      $library_package = $this->getLibraryPackage($library_name, $library_definition);

      $this->downloadManager->remove(
        $library_package,
        $this->installationManager->getInstallPath($library_package)
      );
    }
  }

  /**
   * Download library assets if required.
   *
   * @param array $processed_libraries
   *   The processed libraries.
   * @param array $applied_drupal_libraries
   *   The currently installed libraries.
   */
  protected function downloadLibraries(array $processed_libraries, array $applied_drupal_libraries) {
    $download_promises = [];
    $install_promises = [];

    $libraries_to_install = [];
    foreach ($processed_libraries as $library_name => $processed_library) {
      $library_package = $this->getLibraryPackage($library_name, $processed_library);
      $install_path = $this->installationManager->getInstallPath($library_package);

      if (
        (
          !isset($applied_drupal_libraries[$library_name]) ||
          $applied_drupal_libraries[$library_name] !== $processed_library
        ) ||
        !file_exists($install_path)
      ) {
        // Download if the package:
        // - wasn't in the lock file.
        // - doesn't match what is in the lock file.
        // - doesn't exist on disk.
        $download_result = $this->downloadManager->download($library_package, $install_path);
        if ($download_result instanceof PromiseInterface) {
          // https://github.com/composer/composer/issues/9209
          /* @see \Composer\Util\SyncHelper::downloadAndInstallPackageSync */
          $download_promises[] = $download_result
            // Prepare for install.
            ->then(function () use ($library_package, $install_path) {
              return $this->downloadManager->prepare('install', $library_package, $install_path);
            })
            // Clean up after any download errors.
            ->then(NULL, function ($e) use ($library_package, $install_path) {
              $this->composer->getLoop()
                ->wait([
                  $this->downloadManager->cleanup('install', $library_package, $install_path),
                ]);
              throw $e;
            });

          // Install after the download resolves.
          $libraries_to_install[] = [
            $library_name,
            $library_package,
            $install_path,
          ];
        }
        else {
          // Attempt to install synchronously.
          $install_result = $this->installPackage($library_package, $install_path, $processed_library);
          if ($install_result instanceof PromiseInterface) {
            $install_promises[] = $install_result;
          }
        }
      }
    }

    if (count($download_promises)) {
      // Wait on the download asynchronous promises to resolve.
      $this->waitOnPromises($download_promises);
    }

    foreach ($libraries_to_install as $library_to_install) {
      [$library_name, $library_package, $install_path] = $library_to_install;
      $install_result = $this->installPackage($library_package, $install_path, $processed_libraries[$library_name]);
      if ($install_result instanceof PromiseInterface) {
        $install_promises[] = $install_result;
      }
    }

    if (count($install_promises)) {
      // Wait on the install promises to resolve.
      $this->composer->getLoop()->wait($install_promises);
    }
  }

  /**
   * Wait synchronously for an array of promises to resolve.
   *
   * @param array $promises
   *   Promises to await.
   */
  protected function waitOnPromises(array $promises) {
    $progress = NULL;
    if ($this->io instanceof ConsoleIO && !$this->io->isDebug() && count($promises) > 1 && !getenv('COMPOSER_DISABLE_PROGRESS_BAR')) {
      // Disable progress bar by setting COMPOSER_DISABLE_PROGRESS_BAR=1 as we
      // are unable to read composer's "--no-progress" option easily from here
      // without introducing extra complexity with the PluginEvents::COMMAND
      // event.
      $progress = $this->io->getProgressBar();
    }
    $this->composer->getLoop()->wait($promises, $progress);
    if ($progress) {
      $progress->clear();
    }
  }

  /**
   * Installs a library package to disk.
   *
   * @param \Composer\Package\Package $library_package
   *   The library package.
   * @param string $install_path
   *   The package install path.
   * @param array $processed_library
   *   The library definition.
   *
   * @return \React\Promise\PromiseInterface|void
   *   Returns a promise or void.
   */
  protected function installPackage(Package $library_package, $install_path, array $processed_library) {
    $ignore_patterns = $processed_library['ignore'];
    $rename = $processed_library['rename'] ?? NULL;

    $process_install = function () use ($library_package, $install_path, $ignore_patterns, $rename) {
      if ($ignore_patterns || $rename) {
        $package_name = $library_package->getName();
        $this->io->writeError("  - Processing <info>$package_name</info> files...");
      }

      // Delete files/folders according to the ignore pattern(s).
      if ($ignore_patterns) {
        $finder = new Finder();

        $patterns = [];
        foreach ($ignore_patterns as $ignore_pattern) {
          $patterns[$ignore_pattern] = Glob::toRegex($ignore_pattern);
        }

        $finder
          ->in($install_path)
          ->ignoreDotFiles(FALSE)
          ->ignoreVCS(FALSE)
          ->ignoreUnreadableDirs()
          // Custom filter pattern for matching files and folders.
          ->filter(
            function ($file) use ($patterns) {
              /** @var \SplFileInfo $file */
              $file_pathname = $file->getRelativePathname();
              if ('\\' === \DIRECTORY_SEPARATOR) {
                // Normalize the path name.
                $file_pathname = str_replace('\\', '/', $file_pathname);
              }
              foreach ($patterns as $pattern) {
                if (preg_match($pattern, $file_pathname)) {
                  return TRUE;
                }
              }

              return FALSE;
            }
          );

        foreach ($finder as $file) {
          $file_pathname = $this->fileSystem->normalizePath($file->getPathname());
          $this->io->writeError("    - Removing <info>$file_pathname</info>");
          $this->fileSystem->remove($file_pathname);
        }
      }

      if ($rename) {
        foreach ($rename as $original_file => $destination_file) {
          $original_file = $this->fileSystem->normalizePath("$install_path/$original_file");
          $destination_file = $this->fileSystem->normalizePath("$install_path/$destination_file");
          if (strpos($original_file, $install_path) !== 0) {
            $this->io->writeError("    - Could not rename <info>$original_file</info> as it is outside the library directory.");
          }
          elseif (strpos($destination_file, $install_path) !== 0) {
            $this->io->writeError("    - Could not rename <info>$destination_file</info> as it is outside the library directory.");
          }
          elseif (!file_exists($original_file)) {
            $this->io->writeError("    - Could not rename <info>$original_file</info> as it does not exist");
          }
          elseif (file_exists($destination_file)) {
            $this->io->writeError("    - Could not rename <info>$original_file</info> as the destination file <info>$destination_file</info> already exists");
          }
          else {
            $this->io->writeError("    - Renaming <info>$original_file</info> to <info>$destination_file</info>");
            // Attempt to move the file over.
            $this->fileSystem->rename($original_file, $destination_file);
          }
        }
      }
    };

    if (version_compare(PluginInterface::PLUGIN_API_VERSION, '2.0', '>=')) {
      // Install the package after downloading (Composer 2 only).
      $promise = $this->downloadManager->install($library_package, $install_path);

      if (!($promise instanceof PromiseInterface)) {
        // Not a promise, create one that can be cleaned up after.
        $promise = \React\Promise\resolve();
      }

      return $promise
        ->then($process_install)
        // Clean up after the install.
        ->then(function () use ($library_package, $install_path) {
          return $this->downloadManager->cleanup('install', $library_package, $install_path);
        }, function ($e) use ($library_package, $install_path) {
          // Clean up after any errors.
          $this->composer->getLoop()
            ->wait([
              $this->downloadManager->cleanup('install', $library_package, $install_path),
            ]);
          throw $e;
        });
    }

    // Execute as normal (Composer v1)
    return $process_install();
  }

  /**
   * Get a drupal-library package object from its definition.
   *
   * @param string $library_name
   *   The library name.
   * @param array $library_definition
   *   The library definition.
   *
   * @return \Composer\Package\Package
   *   The pseudo-package for the library.
   */
  protected function getLibraryPackage($library_name, array $library_definition) {
    if (strpos($library_name, '/')) {
      // The library name already contains a '/', add the "drupal-library_"
      // prefix to it so that it can be configured to a custom path through its
      // vendor name.
      $library_package_name = "drupal-library_$library_name";
    }
    else {
      $library_package_name = 'drupal-library/' . $library_name;
    }
    $library_package = new Package(
      $library_package_name, $library_definition['version'], $library_definition['version']
    );
    $library_package->setDistType($library_definition['type']);
    $library_package->setDistUrl($library_definition['url']);
    $library_package->setInstallationSource('dist');
    if (isset($library_definition['shasum'])) {
      $library_package->setDistSha1Checksum($library_definition['shasum']);
    }
    $library_package->setType('drupal-library');

    return $library_package;
  }

  /**
   * Guess the default version and distribution type from the URL.
   *
   * @param string $url
   *   The URL to process.
   *
   * @return array
   *   The version and distribution type.
   */
  protected function guessDefaultsFromUrl($url) {
    // Default to version 1.0.0 so it's considered stable.
    $version = '1.0.0';
    // Default to zips.
    $distribution_type = 'zip';
    // Attempt to guess the version number and type from the URL.
    $match = [];
    if (preg_match('/(v?[\d.]{2,}).+(zip|rar|tgz|tar(?:\.(gz|bz2))?)([?#\/].*)?$/', $url, $match)) {
      $version = $match[1];
      $distribution_type = explode('.', $match[2])[0];
      if ($distribution_type === 'tgz') {
        $distribution_type = 'tar';
      }
    }

    return [$version, $distribution_type];
  }

  /**
   * Get the current installed-libraries json file path.
   *
   * @return string|false
   *   The installed libraries json lock file path or FALSE if the package
   *   could not be resolved.
   */
  public function getInstalledJsonPath() {
    /** @var \Composer\Package\CompletePackage $installer_library_package */
    $installer_library_package = $this->composer->getRepositoryManager()->getLocalRepository()->findPackage(
      static::PACKAGE_NAME,
      '*'
    );

    if (!$installer_library_package || !$installer_library_package instanceof CompletePackageInterface) {
      // Could not resolve the package. The package is most likely being
      // uninstalled.
      return FALSE;
    }

    return $this->installationManager->getInstallPath($installer_library_package) . '/installed-libraries.json';
  }

  /**
   * {@inheritdoc}
   */
  public function deactivate(Composer $composer, IOInterface $io) {
  }

  /**
   * {@inheritdoc}
   */
  public function uninstall(Composer $composer, IOInterface $io) {
  }

}
