<?php

namespace Zodiacmedia\DrupalLibrariesInstaller;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The composer plugin command provider.
 */
class PluginCommand extends BaseCommand {

  /**
   * {@inheritDoc}
   */
  protected function configure() {
    $this->setName('install-drupal-libraries');
    $this->setDescription(
      'Download and install the drupal libraries.'
    );
  }

  /**
   * {@inheritDoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $composer = $this->getComposer();

    $install_libraries_event = new InstallLibrariesEvent(
      InstallLibrariesEvent::INSTALL_LIBRARIES,
      $composer,
      $this->getIO(),
      FALSE
    );
    return $composer->getEventDispatcher()->dispatch(
      $install_libraries_event->getName(),
      $install_libraries_event
    );
  }

}
