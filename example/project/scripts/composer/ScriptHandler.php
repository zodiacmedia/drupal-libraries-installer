<?php

namespace ExampleDrupalLibrariesProject\composer;

use Composer\Script\Event;
use Composer\Util\Filesystem;
use Composer\Util\Platform;

/**
 * The drupal-libraries-installer example project script handler.
 *
 * Attempts to symlink to the parent repository during development when
 * "extra.symlink-root-project" is true.
 *
 * Mainly useful for testing certain behaviours without having to duplicate
 * code or commit every time in order for composer to pull it in.
 *
 * Composer restricts symlinking to a path containing the current project.
 * So we have to handle it manually with some hacky code.
 *
 * It's definitely not adviced as you could accidentally discard changes in
 * your root project during an install/update if the appropriate event hooks
 * are not triggered.
 */
class ScriptHandler {

  protected const PACKAGE_NAME = 'zodiacmedia/drupal-libraries-installer';

  /**
   * Attempt to prepare for the post install/update.
   *
   * @param \Composer\Script\Event $event
   *   The composer event.
   */
  public static function preInstall(Event $event) {
    $composer = $event->getComposer();
    $root_package = $composer->getPackage();
    $locker = $composer->getLocker();
    // Always attempt to clean up if:
    // - the lock file is not present.
    // - the lock file is outdated.
    // - in the pre-update hook.
    $originating_event = $event->getOriginatingEvent();
    $originating_event_name = $originating_event ? $originating_event->getName() : NULL;
    $needs_updating = $originating_event_name === 'pre-update-cmd' || !$locker->isLocked() || !$locker->isFresh();
    $should_symlink = empty($root_package->getExtra()['symlink-root-project']);
    if ($needs_updating || $should_symlink) {
      // Don't symlink the root project. Attempt to remove any existing symlink.
      $filesystem = new Filesystem();
      $destination = static::getPackageDestination();
      $destination = $filesystem->normalizePath($destination);

      $is_junction = $filesystem->isJunction($destination);
      $is_symlink = is_link($destination);
      if ($is_junction || $is_symlink) {
        $io = $event->getIO();
        if ($is_junction) {
          $io->writeError(sprintf('Removing existing junction for <info>%s</info>', static::PACKAGE_NAME));
        }
        elseif ($is_symlink) {
          $io->writeError(sprintf('Removing existing symlink for <info>%s</info>', static::PACKAGE_NAME));
        }
        $filesystem->removeDirectory($destination);
      }
    }
  }

  /**
   * Attempt to symlink to the parent repository during development.
   *
   * @param \Composer\Script\Event $event
   *   The composer event.
   */
  public static function postInstall(Event $event) {
    $root_package = $event->getComposer()->getPackage();
    if (empty($root_package->getExtra()['symlink-root-project'])) {
      // Do nothing.
      return;
    }

    $io = $event->getIO();
    $filesystem = new Filesystem();
    $link = implode(DIRECTORY_SEPARATOR, ['..', '..', '..', '..']);
    $destination = static::getPackageDestination();
    $destination = $filesystem->normalizePath($destination);
    if (Platform::isWindows()) {
      if (!$filesystem->isJunction($destination)) {
        $io->writeError(sprintf('Creating junction for <info>%s</info>', static::PACKAGE_NAME));
        $filesystem->removeDirectory($destination);
        $filesystem->junction($link, $destination);
      }
    }
    else {
      if (!$filesystem->isSymlinkedDirectory($destination)) {
        $io->writeError(sprintf('Creating symlink for <info>%s</info>', static::PACKAGE_NAME));
        // Attempt to remove the existing directory.
        $filesystem->removeDirectory($destination);
        if (!static::createSymlink($link, $destination)) {
          throw new \RuntimeException(sprintf('Failed to create a symlink for "%s"', static::PACKAGE_NAME));
        }
      }
    }
  }

  /**
   * Returns the destination package directory.
   */
  protected static function getPackageDestination() {
    return implode(DIRECTORY_SEPARATOR, [
      getcwd(),
      'vendor',
      'zodiacmedia',
      'drupal-libraries-installer',
    ]);
  }

  /**
   * Creates a symlink to the root project.
   */
  protected static function createSymlink($target, $link) {
    if (!function_exists('symlink')) {
      return FALSE;
    }

    return @symlink($target, $link);
  }

}
