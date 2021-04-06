<?php
/**
 * This file is part of the Composer Merge plugin.
 *
 * Copyright (C) 2015 Bryan Davis, Wikimedia Foundation, and contributors
 *
 * This software may be modified and distributed under the terms of the MIT
 * license. See the LICENSE file for details.
 */

namespace Fox5\PackageBundlerPlugin;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\EventDispatcher\Event as BaseEvent;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\Installer;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event as ScriptEvent;
use Composer\Script\ScriptEvents;

class Bundler implements PluginInterface, EventSubscriberInterface
{

    /**
     * Official package name
     */
    public const PACKAGE_NAME = 'fox5/package-bundler-plugin';

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
        $this->logger = new Logger('package-bundler-plugin', $io);
        $this->logger->info('<message>Collect Package</message>');
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
     * @param  BaseEvent  $event
     */
    public function onInit(BaseEvent $event)
    {
        $this->state->loadSettings();
        // It is not possible to know if the user specified --dev or --no-dev
        // so assume it is false. The dev section will be merged later when
        // the other events fire.
        $this->state->setDevMode(false);
        $this->mergeFiles($this->state->getIncludes(), false);
        $this->mergeFiles($this->state->getRequires(), true);
    }

    /**
     * Handle an event callback for an install, update or dump command by
     * checking for "package-bundler-plugin" in the "extra" data and merging package
     * contents if found.
     *
     * @param  ScriptEvent  $event
     */
    public function onInstallUpdateOrDump(ScriptEvent $event)
    {
        $this->state->loadSettings();
        $this->state->setDevMode($event->isDevMode());
        $this->mergeFiles($this->state->getIncludes(), false);
        $this->mergeFiles($this->state->getRequires(), true);

        if ($event->getName() === ScriptEvents::PRE_AUTOLOAD_DUMP) {
            $this->state->setDumpAutoloader(true);
            $flags = $event->getFlags();
            if (isset($flags['optimize'])) {
                $this->state->setOptimizeAutoloader($flags['optimize']);
            }
        }
    }

    /**
     * Find configuration files matching the configured glob patterns and
     * merge their contents with the master package.
     *
     * @param  array  $patterns  List of files/glob patterns
     * @param  bool   $required  Are the patterns required to match files?
     *
     * @throws MissingFileException when required and a pattern returns no
     *      results
     */
    protected function mergeFiles(array $patterns, $required = false)
    {
        $root = $this->composer->getPackage();

        $files = array_map(
            static function ($files, $pattern) use ($required) {
                if ($required && !$files) {
                    throw new MissingFileException(
                        "package-bundler-plugin: No files matched required '{$pattern}'"
                    );
                }
                return $files;
            },
            array_map('glob', $patterns),
            $patterns
        );

        foreach (array_reduce($files, 'array_merge', []) as $path) {
            $this->mergeFile($root, $path);
        }
    }

    /**
     * Read a JSON file and merge its contents
     *
     * @param  RootPackageInterface  $root
     * @param  string                $path
     */
    protected function mergeFile(RootPackageInterface $root, $path)
    {
        if (isset($this->loaded[$path]) ||
            (isset($this->loadedNoDev[$path]) && !$this->state->isDevMode())
        ) {
            $this->logger->debug(
                "Already merged <comment>$path</comment> completely"
            );
            return;
        }

        $package = new ExtraPackage($path, $this->composer, $this->logger);

        if (isset($this->loadedNoDev[$path])) {
            $this->logger->info(
                "Loading -dev sections of <comment>{$path}</comment>..."
            );
            $package->mergeDevInto($root, $this->state);
        } else {
            $this->logger->info("Loading <comment>{$path}</comment>...");
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

        if ($this->state->recurseIncludes()) {
            $this->mergeFiles($package->getIncludes(), false);
            $this->mergeFiles($package->getRequires(), true);
        }
    }

    /**
     * Handle an event callback following installation of a new package by
     * checking to see if the package that was installed was our plugin.
     *
     * @param  PackageEvent  $event
     */
    public function onPostPackageInstall(PackageEvent $event)
    {
        $op = $event->getOperation();
        if ($op instanceof InstallOperation) {
            $package = $op->getPackage()->getName();
            if ($package === self::PACKAGE_NAME) {
                $this->logger->info('package-bundler-plugin installed');
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
     * @param  ScriptEvent  $event
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

            $this->logger->log("\n".'<info>Running composer update to apply merge settings</info>');

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
                    "\n".'<error>'.
                    'Update to apply merge settings failed, reverting '.$lock.' to its original content.'.
                    '</error>'
                );
                file_put_contents($lock, $lockBackup);
            }
        }
        // @codeCoverageIgnoreEnd
    }
}
// vim:sw=4:ts=4:sts=4:et:
