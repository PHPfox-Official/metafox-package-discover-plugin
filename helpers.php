<?php


if (!function_exists('discover_metafox_packages')) {


    function discover_metafox_version(){
        if(file_exists("packages/platform/src/MetaFoxConstant.php")){
            require_once("packages/platform/src/MetaFoxConstant.php");
        }
        return \MetaFox\Platform\MetaFoxConstant::VERSION;
    }


    function discover_metafox_packages(
        string $basePath,
        bool $writeToConfig = false,
        ?string $configFilename = null,
        ?array $patterns = null
    ): array {
        $files = [];
        $packageArray = [];
        $patterns = $patterns ?? [
                'packages/*/composer.json',
                'packages/*/*/composer.json',
                'packages/*/*/*/composer.json',
            ];

        array_walk($patterns, function ($pattern) use (&$files, $basePath) {
            $dir = rtrim($basePath, DIRECTORY_SEPARATOR,).DIRECTORY_SEPARATOR.$pattern;
            foreach (glob($dir) as $file) {
                $files[] = $file;
            }
        });

        $current_core_version = discover_metafox_version();

        array_walk($files, function ($file) use (&$packageArray, $basePath, $current_core_version) {
            try {
                $data = json_decode(file_get_contents($file), true);
                if (!isset($data['extra']) ||
                    !isset($data['extra']['metafox'])
                    || !is_array($data['extra']['metafox'])) {
                    return;
                }

                $extra = $data['extra']['metafox'];
                $namespace = $extra['namespace'] ?? $data['autoload']['psr-4'] ?? '';

                if (is_array($namespace)) {
                    $namespace = array_key_first($namespace);
                }
                $max_core_version = null;
                $min_core_version = null;
                $require_core_version = null;
                if(array_key_exists('require',$extra) && array_key_exists('metafox/core', $extra['require'])) {
                    $require_core_version  = $extra['require']['metafox/core'];
                    if($require_core_version){
                        $require_core_version_arr = explode('-', $require_core_version);
                        if(count($require_core_version_arr)>1){
                            $max_core_version = trim($require_core_version_arr[1]);
                            $min_core_version = trim($require_core_version_arr[0]);
                        } else {
                            $max_core_version = trim($require_core_version_arr[0]);
                            $min_core_version = trim($require_core_version_arr[0]);
                        }
                    }
                }

                if($require_core_version){
                    if(version_compare($current_core_version, $max_core_version, '>') || version_compare($current_core_version, $min_core_version, '<')) {
                        // disable package if not avaiable
                        echo "Ignore Package {$data['name']} requires metafox/core:$require_core_version". PHP_EOL;
                        return false;
                    }
                }

                $packageArray[] = [
                    'namespace' => trim($namespace, '\\'),
                    'name'      => $data['name'],
                    'alias'     => $extra['alias'],
                    'frontendAlias'=> $extra['frontendAlias'] ?? $extra['alias'],
                    'mobileAlias'=> $extra['mobileAlias'] ?? $extra['alias'],
                    'core'      => (bool) ($extra['core'] ?? false),
                    'priority'  => (int) ($extra['priority'] ?? 99),
                    'version'   => $data['version'],
                    'asset'     => isset($extra['asset']) ? $extra['asset'] : $extra['alias'],
                    'path'      => trim(substr(dirname($file), strlen($basePath)), DIRECTORY_SEPARATOR),
                    'providers' => $extra['providers'] ?? [],
                    'aliases'   => $extra['aliases'] ?? [],
                    'type'      => $extra['type'] ?? 'app',
                    'category'  => $extra['category'] ?? null,
                ];
            } catch (Exception $exception) {
                echo $exception->getMessage(), PHP_EOL;
            }
        });

        usort($packageArray, function ($a, $b) {
            if ($a['core'] && $b['core']) {
                return $a['priority'] - $b['priority'];
            } elseif ($a['core']) {
                return -1;
            } elseif ($b['core']) {
                return 1;
            } else {
                return $a['core'] - $b['core'];
            }
        });

        $packages = [];
        $aliases  = [];
        // export to keys value.
        array_walk($packageArray, function ($item) use (&$packages, &$aliases) {
            $packages[$item['name']] = $item;
            $aliases[$item['alias']] = $item['name'];
        });

        if ($writeToConfig) {
            $filename = $basePath.DIRECTORY_SEPARATOR.($configFilename ?? "config/metafox.php");

            /** @noinspection PhpIncludeInspection */
            $data = file_exists($filename) ? require $filename : [];
            $data['packages'] = $packages;
            $data['aliases'] = $aliases;

            if (false === file_put_contents($filename, sprintf('<?php return %s;', var_export($data, true)))) {
                echo "Could not write to file $filename";
            }

        }
        return $packages;
    }
}
