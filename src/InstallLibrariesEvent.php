<?php

namespace Zodiacmedia\DrupalLibrariesInstaller;

use Composer\Script\Event;

/**
 * The Install Drupal libraries event.
 */
class InstallLibrariesEvent extends Event {

  /**
   * The event is triggered when the 'install-drupal-libraries' command is ran.
   *
   * The event listener method receives a
   * \Zodiacmedia\DrupalLibrariesInstaller\DownloadLibraryEvent instance.
   *
   * @var string
   */
  const INSTALL_LIBRARIES = 'install-drupal-libraries';

}
