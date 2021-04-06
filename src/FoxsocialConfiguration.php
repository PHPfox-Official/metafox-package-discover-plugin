<?php
/**
 * @author  developer@phpfox.com
 * @license phpfox.com
 */

namespace Fox5\PackageBundlerPlugin;


class FoxsocialConfiguration
{
    /**
     * @code
     * [
     *       [
     *          "name"=> ,
     *          "space"=> ,
     *          "providers"=> [
     *                  "Modules\\Activity\\ActivityServiceProvider"
     *                  // ProviderClass
     *          ],
     *          "aliases" => [
     *                  // name=>class,
     *          ],
     *      ]
     * ]
     * @endcode
     * @var array
     */
    protected $config = [];

    public function addConfig(array $package)
    {
        $this->config[] = $package;
    }

    /**
     * @return array
     */
    public function getConfig()
    {
        usort($this->config, function ($a, $b) {
            if ($a['core'] && $b['core']) {
                return $a['priority'] - $b['priority'];
            } elseif ($a['core']) {
                return -1;
            } elseif ($b['core']) {
                return 1;
            } else {
                return $a['priority'] - $b['priority'];
            }
        });

        $result = [];

        foreach ($this->config as $config) {
            $result[$config['name']] = $config;
        }

        foreach ($result as $name => &$value) {
            unset($value['name']);
        }

        return $result;
    }

    public function writeToConfigFile()
    {
        $filename = "config/fox5.php";

        if (!is_dir("config")) {
            return;
        }

        $data = file_exists($filename) ? require $filename : [];
        $data['packages'] = $this->getConfig();

        file_put_contents($filename, sprintf('<?php return %s;', var_export($data, true)));

    }
}
