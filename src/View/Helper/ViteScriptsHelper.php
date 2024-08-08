<?php
declare(strict_types=1);

namespace ViteHelper\View\Helper;

use Cake\Collection\CollectionInterface;
use Cake\Utility\Text;
use Cake\View\Helper;
use ViteHelper\Exception\ConfigurationException;
use ViteHelper\Exception\InvalidArgumentException;
use ViteHelper\Utilities\ConfigDefaults;
use ViteHelper\Utilities\ManifestRecord;
use ViteHelper\Utilities\ManifestRecords;
use ViteHelper\Utilities\ViteHelperConfig;
use ViteHelper\Utilities\ViteManifest;

/**
 * ViteScripts helper
 *
 * @property \Cake\View\Helper\HtmlHelper $Html
 */
class ViteScriptsHelper extends Helper
{
    public array $helpers = ['Html'];

    /**
     * Check if the app is currently in development state.
     *
     * Production mode can be forced in config through `forceProductionMode`,
     *   or by setting a cookie or a url-parameter.
     *
     * Otherwise, it will look for a hint that the app
     *   is in development mode through the  `developmentHostNeedles`
     *
     * @param \ViteHelper\Utilities\ViteHelperConfig|string|null $config config key or instance to use
     * @return bool
     */
    public function isDev(ViteHelperConfig|string|null $config = null): bool
    {
        $config = $this->createConfig($config);
        if ($config->read('forceProductionMode', ConfigDefaults::FORCE_PRODUCTION_MODE)) {
            return false;
        }

        $productionHint = $config->read('productionHint', ConfigDefaults::PRODUCTION_HINT);
        $hasCookieOrQuery = $this->getView()->getRequest()->getCookie($productionHint) || $this->getView()->getRequest()->getQuery($productionHint);
        if ($hasCookieOrQuery) {
            return false;
        }

        $needles = $config->read('development.hostNeedles', ConfigDefaults::DEVELOPMENT_HOST_NEEDLES);
        foreach ($needles as $needle) {
            if (str_contains((string)$this->getView()->getRequest()->host(), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adds scripts to the script view block
     *
     * Options:
     * * block (string): name of the view block to render the scripts in
     * * files (string[]): files to serve in development and production - overrides prodFilter and devEntries
     * * prodFilter (string, array, callable): to filter manifest entries in production mode
     * * devEntries (string[]): entry files in development mode
     * * other options are rendered as attributes to the html tag
     *
     * @param array|string $options file entrypoint or script options
     * @param \ViteHelper\Utilities\ViteHelperConfig|string|null $config config key or instance to use
     * @return void
     * @throws \ViteHelper\Exception\ConfigurationException
     * @throws \ViteHelper\Exception\ManifestNotFoundException|\ViteHelper\Exception\InvalidArgumentException
     */
    public function script(array|string $options = [], ViteHelperConfig|string|null $config = null): void
    {
        $config = $this->createConfig($config);
        if (is_string($options)) {
            $options = ['files' => [$options]];
        }
        $options['block'] = $options['block'] ?? $config->read('viewBlocks.script', ConfigDefaults::VIEW_BLOCK_SCRIPT);
        $options['cssBlock'] = $options['cssBlock'] ?? $config->read('viewBlocks.css', ConfigDefaults::VIEW_BLOCK_CSS);
        $options = $this->updateOptionsForFiltersAndEntries($options);

        if ($this->isDev($config)) {
            $this->devScript($options, $config);

            return;
        }

        $this->productionScript($options, $config);
    }

    /**
     * Convenience method to render a plugin's scripts
     *
     * @param string $pluginName e.g. MyPlugin
     * @param bool $devMode set to true during development
     * @param array $options helper options
     * @param \ViteHelper\Utilities\ViteHelperConfig|string|null $config config key or instance to use
     * @return void
     * @throws \ViteHelper\Exception\ConfigurationException
     * @throws \ViteHelper\Exception\InvalidArgumentException
     * @throws \ViteHelper\Exception\ManifestNotFoundException
     */
    public function pluginScript(string $pluginName, bool $devMode = false, array $options = [], ViteHelperConfig|string|null $config = null): void
    {
        $config = $this->createConfig($config);
        $config = $config->merge(ViteHelperConfig::create([
            'plugin' => $pluginName,
            'forceProductionMode' => !$devMode,
        ]));

        $this->script($options, $config);
    }

    /**
     * @param array $options passed to script tag
     * @param \ViteHelper\Utilities\ViteHelperConfig $config config instance
     * @return void
     * @throws \ViteHelper\Exception\ConfigurationException
     */
    private function devScript(array $options, ViteHelperConfig $config): void
    {
        $this->Html->script(
            $config->read('development.url', ConfigDefaults::DEVELOPMENT_URL)
            . '/@vite/client',
            [
                'type' => 'module',
                'block' => $options['cssBlock'],
            ]
        );

        $files = $this->getFilesForDevelopment($options, $config, 'scriptEntries');

        unset($options['cssBlock']);
        unset($options['prodFilter']);
        unset($options['devEntries']);
        $options['type'] = 'module';

        foreach ($files as $file) {
            $this->Html->script(Text::insert(':host/:file', [
                'host' => $config->read('development.url', ConfigDefaults::DEVELOPMENT_URL),
                'file' => ltrim($file, DS),
            ]), $options);
        }
    }

    /**
     * @param array $options will be passed to script tag
     * @param \ViteHelper\Utilities\ViteHelperConfig $config config instance
     * @return void
     * @throws \ViteHelper\Exception\ManifestNotFoundException
     * @throws \ViteHelper\Exception\InvalidArgumentException
     */
    private function productionScript(array $options, ViteHelperConfig $config): void
    {
        $pluginPrefix = $config->read('plugin');
        $pluginPrefix = $pluginPrefix ? $pluginPrefix . '.' : null;

        $manifestRecords = ViteManifest::getRecords($config);

        $entryRecords = $this->getFilteredRecords($manifestRecords, $options);
        // check chunk.imports
        $shouldImports = $entryRecords->reduce(
            static function (array $carry, ManifestRecord $record) {
                $imports = $record->getChunk('imports') ?? [];

                return array_merge($carry, $imports);
            },
            []
        );
        // add chunk.imports files to prodFilter
        if (is_array($options['prodFilter'])) {
            $options['prodFilter'] = array_merge($options['prodFilter'], $shouldImports);
        }

        $manifestRecords->rewind();
        $records = $this->getFilteredRecords($manifestRecords, $options);
        $cssBlock = $options['cssBlock'];
        unset($options['prodFilter']);
        unset($options['cssBlock']);
        unset($options['devEntries']);

        foreach ($records as $record) {
            /** @var \ViteHelper\Utilities\ManifestRecord $record */
            if (!$record->isEntryScript() && !in_array($record->getKey(), $shouldImports, true)) {
                continue;
            }

            unset($options['type']);
            unset($options['nomodule']);
            if ($record->isModuleEntryScript()) {
                $options['type'] = 'module';
            } else {
                $options['nomodule'] = 'nomodule';
            }

            $this->Html->script($pluginPrefix . $record->getFileUrl(), $options);

            // the js files has css dependency ?
            $cssFiles = $record->getCss();
            if (!count($cssFiles)) {
                continue;
            }

            foreach ($cssFiles as $cssFile) {
                $this->Html->css($pluginPrefix . $cssFile, [
                    'block' => $cssBlock,
                ]);
            }
        }
    }

    /**
     * Adds CSS tags to the configured block
     *
     * Note: This method might be unnecessary if you import your css in javascript.
     *
     * Options:
     * * block (string): name of the view block to render the html tags in
     * * files (string[]): files to serve in development and production - overrides prodFilter and devEntries
     * * prodFilter (string, array, callable): to filter manifest entries in production mode
     * * devEntries (string[]): entry files in development mode
     * * other options are rendered as attributes to the html tag
     *
     * @param array|string $options file entrypoint or css options
     * @param \ViteHelper\Utilities\ViteHelperConfig|string|null $config config key or instance to use
     * @return void
     * @throws \ViteHelper\Exception\ManifestNotFoundException
     * @throws \ViteHelper\Exception\ConfigurationException
     * @throws \ViteHelper\Exception\InvalidArgumentException
     */
    public function css(array|string $options = [], ViteHelperConfig|string|null $config = null): void
    {
        $config = $this->createConfig($config);
        if (is_string($options)) {
            $options = ['files' => [$options]];
        }
        // TODO the default should be css. This is a bug but might break in production.
        // So this should be replaced in a major release.
        $options['block'] = $options['block'] ?? $config->read('viewBlocks.css', ConfigDefaults::VIEW_BLOCK_SCRIPT);
        $options = $this->updateOptionsForFiltersAndEntries($options);

        if ($this->isDev($config)) {
            $files = $this->getFilesForDevelopment($options, $config, 'styleEntries');
            unset($options['devEntries']);
            foreach ($files as $file) {
                $this->Html->css(Text::insert(':host/:file', [
                    'host' => $config->read('development.url', ConfigDefaults::DEVELOPMENT_URL),
                    'file' => ltrim($file, '/'),
                ]), $options);
            }

            return;
        }

        $pluginPrefix = $config->read('plugin');
        $pluginPrefix = $pluginPrefix ? $pluginPrefix . '.' : null;
        $records = $this->getFilteredRecords(ViteManifest::getRecords($config), $options);
        unset($options['prodFilter']);
        unset($options['devEntries']);
        foreach ($records as $record) {
            if (!$record->isEntry() || !$record->isStylesheet() || $record->isLegacy()) {
                continue;
            }

            $this->Html->css($pluginPrefix . $record->getFileUrl(), $options);
        }
    }

    /**
     * Convenience method to render a plugin's styles
     *
     * @param string $pluginName e.g. MyPlugin
     * @param bool $devMode set to true during development
     * @param array $options helper options
     * @param \ViteHelper\Utilities\ViteHelperConfig|string|null $config config key or instance to use
     * @return void
     * @throws \ViteHelper\Exception\ConfigurationException
     * @throws \ViteHelper\Exception\InvalidArgumentException
     * @throws \ViteHelper\Exception\ManifestNotFoundException
     */
    public function pluginCss(string $pluginName, bool $devMode = false, array $options = [], ViteHelperConfig|string|null $config = null): void
    {
        $config = $this->createConfig($config);
        $config = $config->merge(ViteHelperConfig::create([
            'plugin' => $pluginName,
            'forceProductionMode' => !$devMode,
        ]));

        $this->css($options, $config);
    }

    /**
     * @param array $options entries can be passed through `devEntries`
     * @param \ViteHelper\Utilities\ViteHelperConfig $config config instance
     * @param string $configOption key of the config
     * @return array
     * @throws \ViteHelper\Exception\ConfigurationException
     */
    private function getFilesForDevelopment(array $options, ViteHelperConfig $config, string $configOption): array
    {
        $files = $options['devEntries'] ?: $config->read('development.' . $configOption, ConfigDefaults::DEVELOPMENT_SCRIPT_ENTRIES);

        if (empty($files)) {
            throw new ConfigurationException(
                'There are no valid entry points for the dev server. '
                . 'Be sure to set the ViteHelper.development.' . $configOption . ' config or pass entries to the helper.'
            );
        }

        if (!array_is_list($files)) {
            throw new ConfigurationException(sprintf(
                'Expected entryPoints to be a List (array with int-keys) with at least one entry, but got %s.',
                gettype($files) === 'array' ? 'a relational array' : gettype($files),
            ));
        }

        return $files;
    }

    /**
     * Filter records from vite manifest for production
     *
     * @param \ViteHelper\Utilities\ManifestRecords $records records to filter
     * @param array $options method looks at the `prodFilter`key
     * @return \ViteHelper\Utilities\ManifestRecords|\Cake\Collection\CollectionInterface
     * @throws \ViteHelper\Exception\InvalidArgumentException
     */
    private function getFilteredRecords(ManifestRecords $records, array $options): ManifestRecords|CollectionInterface
    {
        $filter = $options['prodFilter'];
        if (empty($filter)) {
            return $records;
        }

        if (is_callable($filter)) {
            return $records->filter($filter);
        }

        if (is_string($filter)) {
            $filter = (array)$filter;
        }

        if (!is_array($filter)) {
            throw new InvalidArgumentException('$options["prodFilter"] must be empty or of type string, array, or callable.');
        }

        return $records->filter(function (ManifestRecord $record) use ($filter) {
            foreach ($filter as $property => $file) {
                $property = is_string($property) ? $property : 'src';
                if ($record->match($file, $property) || $record->getKey() === $file) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * @param array $options options with `prodFilter`, `devEntries`, or `files` key
     * @return array
     */
    private function updateOptionsForFiltersAndEntries(array $options): array
    {
        $options['prodFilter'] = $options['prodFilter'] ?? null;
        $options['devEntries'] = $options['devEntries'] ?? null;
        $files = $options['files'] ?? null;
        if ($files) {
            if (!empty($options['devEntries'])) {
                trigger_error('"devEntries" passed to ViteHelper will be overridden by "files".');
            }
            if (!empty($options['prodFilter'])) {
                trigger_error('"prodFilter" passed to ViteHelper will be overridden by "files".');
            }
            $options['devEntries'] = $files;
            $options['prodFilter'] = $files;
        }

        return $options;
    }

    /**
     * Helper method to create a new config or the defined config
     *
     * @param \ViteHelper\Utilities\ViteHelperConfig|string|null $config can be a config key, a config instance or null for the default
     * @return \ViteHelper\Utilities\ViteHelperConfig
     */
    private function createConfig(ViteHelperConfig|string|null $config): ViteHelperConfig
    {
        if ($config instanceof ViteHelperConfig) {
            return $config;
        }

        return ViteHelperConfig::create($config);
    }
}
