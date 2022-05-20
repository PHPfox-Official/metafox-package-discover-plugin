<?php


if (!function_exists('discover_metafox_packages')) {
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

        array_walk($files, function ($file) use (&$packageArray, $basePath) {
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

                $packageArray[] = [
                    'namespace' => trim($namespace, '\\'),
                    'name'      => $data['name'],
                    'alias'     => $extra['alias'],
                    'core'      => (bool) ($extra['core'] ?? false),
                    'priority'  => (int) ($extra['priority'] ?? 99),
                    'version'   => $data['version'],
                    'assets'    => isset($extra['assets']) ? $extra['assets'] : $extra['alias'],
                    'path'      => trim(substr(dirname($file), strlen($basePath)), DIRECTORY_SEPARATOR),
                    'providers' => $extra['providers'] ?? [],
                    'aliases'   => $extra['aliases'] ?? [],
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
        // export to keys value.
        array_walk($packageArray, function ($item) use (&$packages) {
            $packages[$item['name']] = $item;
        });

        if ($writeToConfig) {
            $filename = $basePath.DIRECTORY_SEPARATOR.($configFilename ?? "config/metafox.php");

            /** @noinspection PhpIncludeInspection */
            $data = file_exists($filename) ? require $filename : [];
            $data['packages'] = $packages;

            if (false === file_put_contents($filename, sprintf('<?php return %s;', var_export($data, true)))) {
                echo "Could not write to file $filename";
            }

        }
        return $packages;
    }
}
