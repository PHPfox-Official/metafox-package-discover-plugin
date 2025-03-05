<?php

namespace MetaFox\PackageBundlerPlugin;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\EventDispatcher\Event as BaseEvent;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\Installer;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\RootPackageInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event as ScriptEvent;
use Composer\Script\ScriptEvents;
use RecursiveIteratorIterator;
use Exception;

class Plugin implements PluginInterface, EventSubscriberInterface
{

    /**
     * Official package name
     */
    public const PACKAGE_NAME = 'metafox/package-discover-plugin';

    /**
     * Priority that plugin uses to register callbacks.
     */
    private const CALLBACK_PRIORITY = 50000;

    /**
     * @var Composer $composer
     */
    protected $composer;


    /**
     * @var PluginState $state
     */
    protected $state;

    /**
     * @var Logger $logger
     */
    protected $logger;

    /**
     * Files that have already been fully processed
     *
     * @var array<string, bool> $loaded
     */
    protected $loaded = [];

    /**
     * Files that have already been partially processed
     *
     * @var array<string, bool> $loadedNoDev
     */
    protected $loadedNoDev = [];

    /**
     * Nested packages to restrict update operations.
     *
     * @var array<string, bool> $updateAllowList
     */
    protected $updateAllowList = [];

    /**
     * {@inheritdoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->state = new PluginState($this->composer);
        $this->logger = new Logger('package-discover-plugin', $io);
        $this->logger->debug('<comment>Discovering metafox</comment>');
    }

    /**
     * {@inheritdoc}
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            PluginEvents::INIT                  =>
                ['onInit', self::CALLBACK_PRIORITY],
            PackageEvents::POST_PACKAGE_INSTALL =>
                ['onPostPackageInstall', self::CALLBACK_PRIORITY],
            ScriptEvents::POST_INSTALL_CMD      =>
                ['onPostInstallOrUpdate', self::CALLBACK_PRIORITY],
            ScriptEvents::POST_UPDATE_CMD       =>
                ['onPostInstallOrUpdate', self::CALLBACK_PRIORITY],
            ScriptEvents::PRE_AUTOLOAD_DUMP     =>
                ['onInstallUpdateOrDump', self::CALLBACK_PRIORITY],
            ScriptEvents::PRE_INSTALL_CMD       =>
                ['onInstallUpdateOrDump', self::CALLBACK_PRIORITY],
            ScriptEvents::PRE_UPDATE_CMD        =>
                ['onInstallUpdateOrDump', self::CALLBACK_PRIORITY],
        ];
    }

    /**
     * Get list of packages to restrict update operations.
     *
     * @return string[]
     * @see \Composer\Installer::setUpdateAllowList()
     */
    public function getUpdateAllowList()
    {
        return array_keys($this->updateAllowList);
    }

    /**
     * Handle an event callback for initialization.
     *
     * @param BaseEvent $event
     */
    public function onInit(BaseEvent $event)
    {
        $this->state->loadSettings();
        // It is not possible to know if the user specified --dev or --no-dev
        // so assume it is false. The dev section will be merged later when
        // the other events fire.
        $this->state->setDevMode(false);
        $this->mergeFiles();
    }

    /**
     * Handle an event callback for an install, update or dump command by
     * checking for "@metafox/package-discover-plugin" in the "extra" data and merging package
     * contents if found.
     *
     * @param ScriptEvent $event
     */
    public function onInstallUpdateOrDump(ScriptEvent $event)
    {
        $this->state->loadSettings();
        $this->state->setDevMode($event->isDevMode());
        $this->mergeFiles();

        if ($event->getName() === ScriptEvents::PRE_AUTOLOAD_DUMP) {
            $this->state->setDumpAutoloader(true);
            $flags = $event->getFlags();
            if (isset($flags['optimize'])) {
                $this->state->setOptimizeAutoloader($flags['optimize']);
            }
            $this->logger->debug('<info>::onInstallUpdateOrDump</info>');
        }
    }

    protected function removeDir($dir){
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
    }

    /**
     * Find configuration files matching the configured glob patterns and
     * merge their contents with the master package.
     *
     */
    protected function mergeFiles()
    {
        if (!function_exists('discover_metafox_packages')) {
            require_once __DIR__ . '/../helpers.php';
        }
        $root = getcwd();
        $composerJson = json_decode(file_get_contents($root."/composer.json"),true);

        if(array_key_exists('migratePaths',$composerJson)){
            $migratePaths =  $composerJson['migratePaths'];
            foreach($migratePaths as $from=>$to){
                $old = $root. '/'. $from;
                if(is_dir($old)){
                    if(!$to || is_dir($root. '/'.$to)){
                        $this->logger->info("Remove directory ". $from);
                        $this->removeDir($old);
                    }else {
                        $this->logger->info("Skip directory ". $from);
                    }
                }else if(is_file($old)){
                    if(!$to || is_file($root. '/'.$to)){
                        unlink($old);
                    }
                }
            }
        }

        $root = $this->composer->getPackage();
        $files = array_map(function ($package) {
            return sprintf('%s%s%s', $package['path'], DIRECTORY_SEPARATOR, 'composer.json');
        }, discover_metafox_packages(getcwd()));


        foreach ($files as $file) {
            $this->mergeFile($root, $file);
        }
    }

    /**
     * Read a JSON file and merge its contents
     *
     * @param RootPackageInterface $root
     * @param string               $path
     */
    protected function mergeFile(RootPackageInterface $root, string $path)
    {
        if (isset($this->loaded[$path]) ||
            (isset($this->loadedNoDev[$path]) && !$this->state->isDevMode())
        ) {
            $this->logger->debug(
                "Already merged <comment>$path</comment> completely"
            );
            return;
        }

        try {
            $file = new JsonFile($path);
            $json = $file->read();
            $package = new ExtraPackage($path, $json, $this->composer, $this->logger);
            $this->logger->info("Loading <comment>{$path}</comment>...");

            if (isset($this->loadedNoDev[$path])) {
                $package->mergeDevInto($root, $this->state);
            } else {
                $package->mergeInto($root, $this->state);
            }

            $requirements = $package->getMergedRequirements();
            if (!empty($requirements)) {
                $this->updateAllowList = array_replace(
                    $this->updateAllowList,
                    array_fill_keys($requirements, true)
                );
            }

            if ($this->state->isDevMode()) {
                $this->loaded[$path] = true;
            } else {
                $this->loadedNoDev[$path] = true;
            }
        } catch (Exception $exception) {
            $this->logger->warning($exception->getMessage());
        }

    }

    /**
     * Handle an event callback following installation of a new package by
     * checking to see if the package that was installed was our plugin.
     *
     * @param PackageEvent $event
     */
    public function onPostPackageInstall(PackageEvent $event)
    {
        $op = $event->getOperation();
        if ($op instanceof InstallOperation) {
            $package = $op->getPackage()->getName();
            if ($package === self::PACKAGE_NAME) {
                $this->logger->info('installed');
                $this->state->setFirstInstall(true);
                $this->state->setLocked(
                    $event->getComposer()->getLocker()->isLocked()
                );
            }
        }
    }

    /**
     * Handle an event callback following an install or update command. If our
     * plugin was installed during the run then trigger an update command to
     * process any merge-patterns in the current config.
     *
     * @param ScriptEvent $event
     */
    public function onPostInstallOrUpdate(ScriptEvent $event)
    {
        // @codeCoverageIgnoreStart
        if ($this->state->isFirstInstall()) {
            $this->state->setFirstInstall(false);

            $requirements = $this->getUpdateAllowList();
            if (empty($requirements)) {
                return;
            }

            $this->logger->log("\n" . '<info>Running composer update to apply merge settings</info>');

            $lockBackup = null;
            $lock = null;

            $config = $this->composer->getConfig();
            $preferSource = $config->get('preferred-install') == 'source';
            $preferDist = $config->get('preferred-install') == 'dist';

            $installer = Installer::create(
                $event->getIO(),
                // Create a new Composer instance to ensure full processing of
                // the merged files.
                Factory::create($event->getIO(), null, false)
            );

            $installer->setPreferSource($preferSource);
            $installer->setPreferDist($preferDist);
            $installer->setDevMode($event->isDevMode());
            $installer->setDumpAutoloader($this->state->shouldDumpAutoloader());
            $installer->setOptimizeAutoloader(
                $this->state->shouldOptimizeAutoloader()
            );

            $installer->setUpdate(true);

            $installer->setUpdateAllowList($requirements);

            $status = $installer->run();
            if (($status !== 0) && $lockBackup && $lock) {
                $this->logger->log(
                    "\n" . '<error>' .
                    'Update to apply merge settings failed, reverting ' . $lock . ' to its original content.' .
                    '</error>'
                );
                file_put_contents($lock, $lockBackup);
            }
        }

        if (is_dir(getcwd() . DIRECTORY_SEPARATOR . 'config')){
            discover_metafox_packages(getcwd(), true);
        }
    }
}
