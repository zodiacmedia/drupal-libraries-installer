<?php

namespace Zodiacmedia\DrupalLibrariesInstaller;

use Composer\Plugin\Capability\CommandProvider;

/**
 * The composer plugin command provider.
 */
class PluginCommandProvider implements CommandProvider {

  /**
   * {@inheritDoc}
   */
  public function getCommands() {
    return [
      new PluginCommand(),
    ];
  }

}
