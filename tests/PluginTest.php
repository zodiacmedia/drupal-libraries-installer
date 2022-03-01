<?php

namespace Zodiacmedia\DrupalLibrariesInstaller;

use Composer\Composer;
use Composer\Downloader\DownloadManager;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Plugin\PluginInterface;
use Composer\Repository\RepositoryManager;
use Composer\Repository\WritableRepositoryInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;
use Composer\Util\Loop;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Zodiacmedia\DrupalLibrariesInstaller\Plugin
 *
 * The plugin test case.
 *
 * @todo Achieve 100% code coverage.
 */
class PluginTest extends TestCase {

  /**
   * The root directory.
   *
   * @var \org\bovigo\vfs\vfsStreamDirectory
   */
  private $rootDirectory;

  /**
   * The composer instance.
   *
   * @var \Composer\Composer
   */
  protected $composer;

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
   * The file system instance.
   *
   * @var \Composer\Util\Filesystem
   */
  protected $fileSystem;

  /**
   * The input-output interface.
   *
   * @var \Composer\IO\IOInterface
   */
  protected $io;

  /**
   * The plugin interface fixture.
   *
   * @var \Zodiacmedia\DrupalLibrariesInstaller\Plugin
   */
  protected $fixture;

  /**
   * The virtual installed libraries json lock file.
   *
   * @var string
   */
  protected $installedLibrariesJsonFile;

  /**
   * The current fixture directory.
   *
   * @var string
   */
  protected $fixtureDirectory;

  /**
   * Removed files.
   *
   * @var array
   */
  protected $removedFiles;

  /**
   * Downloaded files.
   *
   * @var array
   */
  protected $downloadedFiles;

  /**
   * The libraries directory.
   *
   * @var string
   */
  const LIBRARIES_DIRECTORY = 'temp-libraries';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $composer_2 = version_compare(PluginInterface::PLUGIN_API_VERSION, '2.0', '>=');

    $this->downloadedFiles = [];
    $this->removedFiles = [];

    $this->setupVirtualFilesystem();

    $this->installationManager = $this->createMock(InstallationManager::class);
    $this->installationManager
      ->method('getInstallPath')
      ->willReturnCallback([$this, 'getInstallPathCallback']);

    $this->downloadManager = $this->createMock(DownloadManager::class);
    $this->downloadManager->method('download')->willReturnCallback(
      [$this, 'downloadManagerDownloadCallback']
    );

    // Create a partial mock, keeping the normalize functionality.
    $this->fileSystem = $this->createPartialMock(Filesystem::class, ['remove']);
    $this->fileSystem->method('remove')->willReturnCallback(
      [$this, 'fileSystemRemoveCallback']
    );

    $this->composer = $this
      ->createPartialMock(
        Composer::class,
        [
          'getPackage',
          'getInstallationManager',
          'getDownloadManager',
          'getRepositoryManager',
          'getLoop',
        ]
      );
    $this->composer
      ->method('getInstallationManager')
      ->willReturn($this->installationManager);

    $this->composer
      ->method('getDownloadManager')
      ->willReturn($this->downloadManager);

    $repository_manager = $this->createMock(RepositoryManager::class);
    $local_repository = $this->createMock(WritableRepositoryInterface::class);
    $local_repository
      ->method('getCanonicalPackages')
      ->willReturnCallback([$this, 'getCanonicalPackagesCallback']);
    $repository_manager
      ->method('getLocalRepository')
      ->willReturn($local_repository);

    $this->composer
      ->method('getRepositoryManager')
      ->willReturn($repository_manager);

    if ($composer_2) {
      // Add mock for the Composer loop.
      $loop = $this->createMock(Loop::class);
      $loop->method('wait');
      $this->composer
        ->method('getLoop')
        ->willReturn($loop);
    }

    $this->io = $this->createMock(IOInterface::class);

    $this->fixture = $this->getPluginMockInstance();
  }

  /**
   * Callback for the FileSystem's remove method.
   *
   * @param string $file
   *   The file/folder path.
   */
  public function fileSystemRemoveCallback($file) {
    $this->removedFiles[] = $file;
  }

  /**
   * Callback for the DownloadManager's download function.
   */
  public function downloadManagerDownloadCallback(Package $package, $file) {
    $this->downloadedFiles[] = $file;
  }

  /**
   * Normalize a libraries directory vfs file path for comparison.
   *
   * @param string $file
   *   The file path.
   *
   * @return string
   *   The normalized path.
   */
  protected function normalizeVfsFilePath(string $file) {
    $path_prefix = $this->rootDirectory->getChild(static::LIBRARIES_DIRECTORY)->url() . '/';
    $path_prefix_length = strlen($path_prefix);
    if (substr($file, 0, $path_prefix_length) === $path_prefix) {
      $file = substr($file, $path_prefix_length);
    }

    return $file;
  }

  /**
   * Prepares the virtual file system.
   */
  protected function setupVirtualFilesystem() {
    // Not all tests require this, but we want to ensure we never accidentally
    // touch our real composer.json.
    $project_dependencies = [
      'vendor' => [
        'zodiacmedia' => [
          'drupal-libraries-installer' => [
            // 'installed-libraries.json' => '{}',
          ],
          'drupal-libraries-test-dependency' => [
            'composer.json' => /* @lang JSON */
            <<<EOL
{
  "name": "zodiacmedia/drupal-libraries-test-dependency",
  "extra": {
    "drupal-libraries": {
      "test-library-dependency": "https://example.com/test-library-dependency.zip",
      "test-moment": {
        "url": "https://registry.npmjs.org/moment/-/moment-2.25.0.tgz",
        "shasum": "e961ab9a5848a1cf2c52b1af4e6c82a8401e7fe9",
        "ignore": [
          "*.md"
        ]
      }
    }
  }
}
EOL
          ],
        ],
      ],
    ];
    // Create the project structure.
    $this->rootDirectory = vfsStream::setup('root', NULL, $project_dependencies);

    $this->installedLibrariesJsonFile = vfsStream::url(
      $this->rootDirectory->path() . '/vendor/zodiacmedia/drupal-libraries-installer/installed-libraries.json'
    );
  }

  /**
   * Test to ensure the correct events are acted upon.
   *
   * @covers \Zodiacmedia\DrupalLibrariesInstaller\Plugin::getSubscribedEvents()
   */
  public function testGetSubscribedEvents() {
    $this->assertEquals(
      [
        ScriptEvents::POST_INSTALL_CMD => 'install',
        ScriptEvents::POST_UPDATE_CMD => 'install',
        InstallLibrariesEvent::INSTALL_LIBRARIES => 'install',
      ],
      Plugin::getSubscribedEvents()
    );
  }

  /**
   * Test various drupal libraries scenarios.
   *
   * @param string $fixture_name
   *   The fixture directory name.
   * @param array $expected_removed_files
   *   The list of removed files.
   * @param array $expected_downloaded_dirs
   *   The list of downloaded package directories.
   * @param bool $installed_libraries
   *   Whether to read an initial installed libraries.
   *
   * @dataProvider drupalLibrariesDownloadProvider
   *
   * @throws \Exception
   */
  public function testDrupalLibrariesDownload(
    $fixture_name,
    array $expected_removed_files,
    array $expected_downloaded_dirs,
    $installed_libraries = FALSE
  ) {
    $this->fixtureDirectory = $this->fixtureDirectory($fixture_name);
    $root_project = $this->rootFromJson("{$this->fixtureDirectory}/composer.json");

    if ($installed_libraries) {
      // Set the initial installed libraries content if any.
      copy("{$this->fixtureDirectory}/installed-libraries.initial.json", $this->installedLibrariesJsonFile);
    }

    // Create the libraries structure.
    $this->createLibrariesPackageStructures("{$this->fixtureDirectory}/libraries-structure.json");

    $this->triggerPlugin($root_project, $this->fixtureDirectory);

    $removed_files = array_map([$this, 'normalizeVfsFilePath'], $this->removedFiles);
    $downloaded_files = array_map([$this, 'normalizeVfsFilePath'], $this->downloadedFiles);
    $this->assertSame($expected_removed_files, $removed_files, 'Removed files');
    $this->assertSame($expected_downloaded_dirs, $downloaded_files, 'Downloaded files');

    // Compare the output JSON files.
    $expected_installed_libraries_file = "{$this->fixtureDirectory}/installed-libraries.expected.json";
    $this->assertFileExists($expected_installed_libraries_file);
    $this->assertFileExists($this->installedLibrariesJsonFile);
    $this->assertSame(
      json_decode(file_get_contents($expected_installed_libraries_file), TRUE),
      json_decode(file_get_contents($this->installedLibrariesJsonFile), TRUE),
      'installed-libraries.json output'
    );
  }

  /**
   * Fixtures data provider.
   */
  public function defaultUrlParserProvider() {
    return [
      'url-version-detection' => [
        'url-version-detection',
        [
          'package1' => ['1.0.0', 'zip'],
          'package2' => ['4.9.2', 'rar'],
          'package3' => ['0.11', 'tar'],
          'package4' => ['4.10.0', 'tar'],
          'package5' => ['1.17.2', 'tar'],
          'package6' => ['1.0.0', 'zip'],
          'package7' => ['1.0.0', 'zip'],
          'package8' => ['1.2.0', 'tar'],
          'package9' => ['1.2.0', 'tar'],
        ],
      ],
    ];
  }

  /**
   * Test default URL parser version detection.
   *
   * @param string $fixture_name
   *   The fixture directory name.
   * @param array $expected_metadata
   *   The list of expected parsed metadata.
   *
   * @dataProvider defaultUrlParserProvider
   *
   * @throws \Exception
   */
  public function testDefaultUrlParser(
    string $fixture_name,
    array $expected_metadata
  ) {
    $this->fixtureDirectory = $this->fixtureDirectory($fixture_name);
    $root_project = $this->rootFromJson("{$this->fixtureDirectory}/composer.json");

    $this->triggerPlugin($root_project, $this->fixtureDirectory);

    $installed_libraries = json_decode(file_get_contents($this->installedLibrariesJsonFile), TRUE);
    $output = [];
    foreach ($installed_libraries['installed'] as $package => $package_metadata) {
      $output[$package] = [$package_metadata['version'], $package_metadata['type']];
    }
    // ksort($expected_metadata); ksort($output);
    $this->assertEquals($expected_metadata, $output, 'Extracted version and type');
  }

  /**
   * Fixtures data provider.
   */
  public function drupalLibrariesDownloadProvider() {
    return [
      'download-and-ignore-library-files' => [
        /*
         * Given a root package with
         *   a set of different drupal library definitions.
         * When the plugin is run
         * Then the libraries are installed and certain files matching specific
         *   ignore patterns are removed.
         */
        'download-and-ignore-library-files',
        [
          'flexslider/bower_components',
          'flexslider/demo',
          'flexslider/node_modules',
          'select2/.git',
          'select2/.hidden_directory',
          'select2/docs',
          'select2/src',
          'select2/tests',
          'select2/.hidden.txt',
          'select2/Gruntfile.js',
          'select2/README.md',
        ],
        [
          'flexslider',
          'moment',
          'select2',
          'simple-color',
        ],
      ],
      'specific-dependency-libraries-with-override' => [
        /*
         * Given a root package with
         *   a drupal library override and a package dependency with a library
         *   definition.
         * When the plugin is run
         * Then the libraries are installed and the override kept.
         */
        'specific-dependency-libraries-with-override',
        [],
        [
          'test-moment',
          'test-library-dependency',
        ],
      ],
      'specific-dependency-libraries' => [
        /*
         * Given a root package with
         *   a package dependency with a library definition.
         * When the plugin is run
         * Then the libraries are installed and ignore patterns applied.
         */
        'specific-dependency-libraries',
        [
          'test-moment/README.md',
        ],
        [
          'test-library-dependency',
          'test-moment',
        ],
      ],
      'all-dependency-libraries' => [
        /*
         * Given a root package with
         *   a wildcard package dependency library definition.
         * When the plugin is run
         * Then the dependency libraries are installed and their ignore
         *    patterns applied.
         */
        'all-dependency-libraries',
        [
          'test-moment/README.md',
        ],
        [
          'test-library-dependency',
          'test-moment',
        ],
      ],
      'existing-lock-file' => [
        /*
         * Given a project with an existing lock file with the library.
         *   and the project already exists on disk.
         * When the plugin is run
         * Then nothing is installed/removed.
         */
        'existing-lock-file',
        [],
        [],
        TRUE,
      ],
    ];
  }

  /**
   * Test that a library's assets may be renamed to fit a directory structure.
   *
   * Given a library with a "rename" definition.
   * When the plugin is run
   * Then the files will be renamed.
   */
  public function testRenameLibraryAsset() {
    $this->testDrupalLibrariesDownload(
      'rename-library',
      [
        'select2/.git',
      ],
      [
        'select2',
      ]
    );
    $expected_library_structure_file = "{$this->fixtureDirectory}/libraries-structure-expected.json";
    $this->assertFileExists($expected_library_structure_file);
    $expected_library_structure = json_decode(file_get_contents($expected_library_structure_file), TRUE);
    $library_structure = $this->getLibraryStructure();
    $this->assertSame($expected_library_structure, $library_structure);
  }

  /**
   * Test that library vendors are installed to a custom location.
   *
   * Given a project with a vendor namespace and a custom install path.
   * When the plugin is run
   * Then the package library is installed inside it.
   */
  public function testLibraryVendors() {
    $this->testDrupalLibrariesDownload(
      'vendor-libraries',
      [],
      [
        'libraryname',
        'ckeditor-path/codesnippet',
        'ckeditor-path/contents',
        'ckeditor-path/notification',
        'ckeditor-path/wordcount',
      ]
    );
  }

  /**
   * Returns the mock plugin fixture instance.
   */
  protected function getPluginMockInstance() {
    /** @var \PHPUnit\Framework\MockObject\MockObject|\Zodiacmedia\DrupalLibrariesInstaller\Plugin $plugin */
    $plugin = $this->createPartialMock(Plugin::class, ['getInstalledJsonPath']);
    $plugin->method('getInstalledJsonPath')->willReturn(
      $this->installedLibrariesJsonFile
    );

    return $plugin;
  }

  /**
   * Trigger an installation of the specified plugin.
   *
   * @param \Composer\Package\RootPackage $package
   *   The package instance.
   * @param string $directory
   *   Working directory for composer run.
   *
   * @throws \Exception
   */
  protected function triggerPlugin(RootPackage $package, $directory) {
    chdir($directory);
    // Return the current package.
    $this->composer->method('getPackage')->willReturn($package);

    // Activate the plugin.
    $this->fixture->activate(
      $this->composer,
      $this->io,
      $this->fileSystem
    );

    $event = new Event(
      ScriptEvents::POST_INSTALL_CMD,
      $this->composer,
      $this->io,
    // Dev mode.
      FALSE,
      [],
      []
    );
    $this->fixture->install($event);
  }

  /**
   * Returns a fixture directory.
   *
   * @param string $sub_directory
   *   The subdirectory.
   *
   * @return string
   *   The fixture directory.
   */
  protected function fixtureDirectory($sub_directory) {
    return __DIR__ . "/fixtures/{$sub_directory}";
  }

  /**
   * Returns the root package definition.
   *
   * @param string $file
   *   The fixture composer.json.
   *
   * @return \PHPUnit\Framework\MockObject\MockObject|\Composer\Package\RootPackage
   *   The root package prophecy instance.
   */
  protected function rootFromJson($file) {
    $json = json_decode(file_get_contents($file), TRUE);
    $data = array_merge(
      [
        'name' => 'drupal-libraries-installer-test/root-package',
        'repositories' => [],
        'require' => [],
        'require-dev' => [],
        'suggest' => [],
        'extra' => [],
      ],
      $json
    );

    $root_name = $data['name'];

    foreach (['require', 'require-dev'] as $config) {
      foreach ($data[$config] as $dependency => $dependency_version) {
        $link = $this->createMock(Link::class);
        $link->method('getSource')->willReturn($root_name);
        $link->method('getTarget')->willReturn($dependency);
        $link->method('getPrettyConstraint')->willReturn($dependency_version);
        $data[$config][$dependency] = $link;
      }
    }

    $root_package = $this->createMock(RootPackage::class);
    $root_package->method('getRequires')->willReturn($data['require']);
    $root_package->method('getDevRequires')->willReturn($data['require-dev']);
    $root_package->method('getRepositories')->willReturn($data['repositories']);
    $root_package->method('getSuggests')->willReturn($data['suggest']);
    $root_package->method('getName')->willReturn($root_name);
    $root_package->expects($this->atLeastOnce())->method('getExtra')->willReturn($data['extra']);

    return $root_package;
  }

  /**
   * Returns the composer package directory path.
   *
   * @param \Composer\Package\Package $package
   *   The composer package.
   *
   * @return string
   *   The install path.
   *
   * @see \Composer\Installers\BaseInstaller::getInstallPath
   */
  public function getInstallPathCallback(Package $package): string {
    $pretty_name = $package->getPrettyName();
    if (strpos($pretty_name, '/') !== FALSE) {
      [$vendor, $name] = explode('/', $pretty_name);
    }
    else {
      $vendor = '';
      $name = $pretty_name;
    }

    // Use the package name as the destination by default.
    $package_path = $name;
    if (strpos($vendor, 'drupal-library_') === 0) {
      $extra = $this->composer->getPackage()->getExtra();
      if (!empty($extra['installer-paths'])) {
        $type = $package->getType();
        foreach ($extra['installer-paths'] as $pattern => $paths) {
          if (in_array("vendor:$vendor", $paths, TRUE)) {
            $package_path = str_replace(
              ['{$name}', '{$vendor}', '{$type}'],
              [$name, $vendor, $type],
              $pattern
            );
          }
        }
      }
    }

    return vfsStream::url(
      $this->rootDirectory->path() . '/' . static::LIBRARIES_DIRECTORY . '/' . $package_path
    );
  }

  /**
   * Get the libraries structure.
   *
   * @return array
   *   The library structure.
   */
  public function getLibraryStructure() {
    $library_path = vfsStream::url($this->rootDirectory->path() . '/' . static::LIBRARIES_DIRECTORY);
    if (file_exists($library_path)) {
      return $this->generateDirectoryTreeStructure($library_path);
    }
    return [];
  }

  /**
   * Callback for the LocalRepository getCanonicalPackages function.
   */
  public function getCanonicalPackagesCallback() {
    $root_package = $this->composer->getPackage();
    /**
     * @var $dependencies Link[]
     */
    $dependencies = array_merge($root_package->getRequires(), $root_package->getDevRequires());
    $return = [];

    foreach ($dependencies as $dependency) {
      /* @see setupVirtualFilesystem
       * Create pseudo dependency packages, reading from the virtual filesystem.
       */
      $composer_path = 'vendor/' . $dependency->getTarget() . '/composer.json';
      $this->assertTrue($this->rootDirectory->hasChild($composer_path));
      $json = json_decode(
        file_get_contents($this->rootDirectory->getChild($composer_path)->url()),
        TRUE
      );
      $data = array_merge(
        [
          'name' => 'undefined',
          'extra' => [],
        ],
        $json
      );

      $package = $this->createMock(Package::class);
      $package->expects($this->atLeastOnce())->method('getExtra')->willReturn($data['extra']);
      $package->method('getName')->willReturn($data['name']);
      $return[] = $package;
    }

    return $return;
  }

  /**
   * Create the libraries package structure.
   *
   * @param string $json_file
   *   The library structure JSON file.
   */
  protected function createLibrariesPackageStructures(string $json_file) {
    // Create the directory structure for the libraries so that they exist on
    // disk for Finder to work with.
    vfsStream::create(
      [
        static::LIBRARIES_DIRECTORY => json_decode(
          file_get_contents($json_file),
          TRUE
        ),
      ]
    );
  }

  /**
   * Creates a tree-structured array of directories and files.
   *
   * Adapted from: https://gist.github.com/jasonhofer/2368606
   *
   * @param string $dir
   *   Directory to scan.
   * @param string $regex
   *   Regex to use to filter the directory tree.
   * @param bool $ignore_empty
   *   Do not add empty directories to the tree.
   *
   * @return array
   *   The directory tree structure.
   */
  protected function generateDirectoryTreeStructure(
    $dir,
    $regex = '',
    $ignore_empty = FALSE
  ) {
    $structure = [];

    if (!$dir instanceof \DirectoryIterator) {
      $dir = new \DirectoryIterator((string) $dir);
    }

    foreach ($dir as $node) {
      if ($node->isDir() && !$node->isDot()) {
        $tree = $this->generateDirectoryTreeStructure(
          $node->getPathname(),
          $regex,
          $ignore_empty
        );
        if (!$ignore_empty || count($tree)) {
          $structure[$node->getFilename()] = $tree;
        }
      }
      elseif ($node->isFile()) {
        $name = $node->getFilename();
        if ('' === $regex || preg_match($regex, $name)) {
          $structure[$name] = TRUE;
        }
      }
    }

    return $structure;
  }

}
