<?php


namespace MetaFox\PackageBundlerPlugin;

use Composer\Composer;


class PluginState
{
    /**
     * @var Composer $composer
     */
    protected $composer;

    /**
     * @var array $includes
     */
    protected $includes = [];

    /**
     * @var bool $devMode
     */
    protected $devMode = false;

    /**
     * @var bool $recurse
     */
    protected $recurse = true;

    /**
     * @var bool $replace
     */
    protected $replace = false;

    /**
     * @var bool $ignore
     */
    protected $ignore = false;

    /**
     * Whether to merge the -dev sections.
     * @var bool $mergeDev
     */
    protected $mergeDev = true;


    /**
     * Whether to merge the scripts section.
     *
     * @var bool $mergeScripts
     */
    protected $mergeScripts = false;

    /**
     * @var bool $firstInstall
     */
    protected $firstInstall = false;

    /**
     * @var bool $locked
     */
    protected $locked = false;

    /**
     * @var bool $dumpAutoloader
     */
    protected $dumpAutoloader = false;

    /**
     * @var bool $optimizeAutoloader
     */
    protected $optimizeAutoloader = false;

    /**
     * @param  Composer  $composer
     */
    public function __construct(Composer $composer)
    {
        $this->composer = $composer;
    }


    /**
     * Load plugin settings
     */
    public function loadSettings()
    {
        $this->includes = [
            'packages/*/composer.json',
            'packages/*/*/composer.json',
            'packages/*/*/*/composer.json',
        ];

        $this->recurse = false;
        $this->ignore = true;
        $this->replace = false;
        $this->mergeDev = true;
        $this->mergeScripts = false;
    }

    /**
     * Get list of filenames and/or glob patterns to include
     *
     * @return array
     */
    public function getIncludes()
    {
        return $this->includes;
    }


    /**
     * Set the first install flag
     *
     * @param  bool  $flag
     */
    public function setFirstInstall($flag)
    {
        $this->firstInstall = (bool) $flag;
    }

    /**
     * Is this the first time that the plugin has been installed?
     *
     * @return bool
     */
    public function isFirstInstall()
    {
        return $this->firstInstall;
    }

    /**
     * Set the locked flag
     *
     * @param  mixed  $flag
     */
    public function setLocked($flag)
    {
        $this->locked = (bool) $flag;
    }

    /**
     * Was a lockfile present when the plugin was installed?
     *
     * @return bool
     */
    public function isLocked()
    {
        return $this->locked;
    }

    /**
     * Should an update be forced?
     *
     * @return true If packages are not locked
     */
    public function forceUpdate()
    {
        return !$this->locked;
    }

    /**
     * Set the devMode flag
     *
     * @param  mixed  $flag
     */
    public function setDevMode($flag)
    {
        $this->devMode = (bool) $flag;
    }

    /**
     * Should devMode settings be processed?
     *
     * @return bool
     */
    public function isDevMode()
    {
        return $this->shouldMergeDev() && $this->devMode;
    }

    /**
     * Should devMode settings be merged?
     *
     * @return bool
     */
    public function shouldMergeDev()
    {
        return $this->mergeDev;
    }

    /**
     * Set the dumpAutoloader flag
     *
     * @param  bool  $flag
     */
    public function setDumpAutoloader($flag)
    {
        $this->dumpAutoloader = (bool) $flag;
    }

    /**
     * Is the autoloader file supposed to be written out?
     *
     * @return bool
     */
    public function shouldDumpAutoloader()
    {
        return $this->dumpAutoloader;
    }

    /**
     * Set the optimizeAutoloader flag
     *
     * @param  mixed  $flag
     */
    public function setOptimizeAutoloader($flag)
    {
        $this->optimizeAutoloader = (bool) $flag;
    }

    /**
     * Should the autoloader be optimized?
     *
     * @return bool
     */
    public function shouldOptimizeAutoloader()
    {
        return $this->optimizeAutoloader;
    }

    /**
     * Should includes be recursively processed?
     *
     * @return bool
     */
    public function recurseIncludes()
    {
        return $this->recurse;
    }

    /**
     * Should duplicate links be replaced in a 'last definition wins' order?
     *
     * @return bool
     */
    public function replaceDuplicateLinks()
    {
        return $this->replace;
    }

    /**
     * Should duplicate links be ignored?
     *
     * @return bool
     */
    public function ignoreDuplicateLinks()
    {
        return $this->ignore;
    }


    /**
     * Should the scripts section be merged?
     *
     * By default, the scripts section is not merged.
     *
     * @return bool
     */
    public function shouldMergeScripts()
    {
        return $this->mergeScripts;
    }
}
