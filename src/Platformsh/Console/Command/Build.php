<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Platformsh\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Platformsh\Environment;

/**
 * CLI command for build hook. Responsible for preparing the codebase before it's moved to the server.
 */
class Build extends Command
{
    /**
     * Options for build_options.ini
     */
    const BUILD_OPT_SKIP_DI_COMPILATION = 'skip_di_compilation';
    const BUILD_OPT_SKIP_DI_CLEARING = 'skip_di_clearing';
    const BUILD_OPT_SCD_EXCLUDE_THEMES = 'exclude_themes';
    const BUILD_OPT_SCD_THREADS = 'scd_threads';
    const BUILD_OPT_SKIP_SCD = 'skip_scd';

    /**
     * @var Environment
     */
    private $env;

    /**
     * @var array
     */
    private $buildOptions;

    private $cleanStaticViewFiles;
    private $staticContentStashLocation;
    private $staticDeployThreads;
    private $staticDeployExcludeThemes = [];
    private $verbosityLevel;

    /**
     * {@inheritdoc}
     * @throws \InvalidArgumentException
     */
    protected function configure()
    {
        $this->setName('platformsh:build')
            ->setDescription('Invokes set of steps to build source code for the Magento on Platform.sh');
        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->buildOptions = $this->parseBuildOptions();
        $this->env = new Environment();
        $this->build();
    }

    private function build()
    {
        $this->env->log("Start build.");
        $this->setEnvData();
        $this->applyMccPatches();
        $this->applyCommittedPatches();
        $this->compileDI();
        $this->composerDumpAutoload();
        $this->deployStaticContent();
        $this->clearInitDir();
        $this->env->execute('rm -rf app/etc/env.php');

        /**
         * Writable directories will be erased when the writable filesystem is mounted to them. This
         * step backs them up to ./init/
         */
        $this->env->log("Moving static content to init directory");
        $this->env->execute('mkdir -p ./init/pub/');
        if (file_exists('./init/pub/static')) {
            $this->env->log("Remove ./init/pub/static");
            unlink('./init/pub/static');
        }
        $this->env->execute('cp -R ./pub/static/ ./init/pub/static');
        copy(
            Environment::MAGENTO_ROOT . '/.static_content_deploy',
            Environment::MAGENTO_ROOT . 'init/' . '/.static_content_deploy'
        );

        $this->env->log("Copying writable directories to temp directory.");

        foreach ($this->env->writableDirs as $dir) {
            $this->env->execute(sprintf('mkdir -p init/%s', $dir));
            $this->env->execute(sprintf('mkdir -p %s', $dir));
            $this->env->execute(sprintf('/bin/bash -c "shopt -s dotglob; cp -R %s/* ./init/%s/"', $dir, $dir));
            $this->env->execute(sprintf('rm -rf %s', $dir));
            $this->env->execute(sprintf('mkdir %s', $dir));
        }
    }

    private function setEnvData()
    {
        $var = $this->env->getVariables();
        $this->cleanStaticViewFiles = isset($var["CLEAN_STATIC_FILES"]) && $var["CLEAN_STATIC_FILES"] == 'disabled' ? false : true;
        $this->staticContentStashLocation = isset($var["STATIC_CONTENT_STASH_LOCATION"]) ? $var["STATIC_CONTENT_STASH_LOCATION"] : false;
        $this->staticDeployExcludeThemes = isset($var["STATIC_CONTENT_EXCLUDE_THEMES"])
            ? explode(',', $var["STATIC_CONTENT_EXCLUDE_THEMES"])
            : [];
        if (isset($var["STATIC_CONTENT_THREADS"])) {
            $this->staticDeployThreads = (int)$var["STATIC_CONTENT_THREADS"];
        } else if (isset($_ENV["STATIC_CONTENT_THREADS"])) {
            $this->staticDeployThreads = (int)$_ENV["STATIC_CONTENT_THREADS"];
        } else if (isset($_ENV["PLATFORM_MODE"]) && $_ENV["PLATFORM_MODE"] === 'enterprise') {
            $this->staticDeployThreads = 3;
        } else { // if Paas environment
            $this->staticDeployThreads = 1;
        }
        $this->verbosityLevel = isset($var['VERBOSE_COMMANDS']) && $var['VERBOSE_COMMANDS'] == 'enabled' ? ' -vv ' : '';
    }

    /**
     * Apply patches
     */
    private function applyMccPatches()
    {
        $this->env->log("Applying patches.");
        $this->env->execute('/usr/bin/php ' . Environment::MAGENTO_ROOT . 'vendor/platformsh/magento2-configuration/patch.php');
    }

    /**
     * Apply patches
     */
    private function applyCommittedPatches()
    {
        $patchesDir = Environment::MAGENTO_ROOT . 'm2-hotfixes/';
        $this->env->log("Checking if patches exist under " . $patchesDir);
        if (is_dir($patchesDir)) {
            $files = glob($patchesDir . "*");
            sort($files);
            foreach ($files as $file) {
                $cmd = 'git apply '  . $file;
                $this->env->execute($cmd);
            }
        }
    }

    private function compileDI()
    {
        $this->env->execute('rm -rf generated/*');

        $this->env->log("Enabling all modules");

        if (!$this->getBuildOption(self::BUILD_OPT_SKIP_DI_COMPILATION)) {
            $this->env->log("Running DI compilation");
            $this->env->execute("cd bin/; /usr/bin/php ./magento setup:di:compile");
        } else {
            $this->env->log("Skip running DI compilation");
        }
    }

    /**
     * Clear content of temp directory
     */
    private function clearInitDir()
    {
        $this->env->log("Clearing temporary directory.");
        $this->env->execute('rm -rf ../init/*');
    }

    /**
     * Parse optional build_options.ini file in Magento root directory
     */
    private function parseBuildOptions()
    {
        $fileName = Environment::MAGENTO_ROOT . '/build_options.ini';
        return file_exists($fileName)
            ? parse_ini_file(Environment::MAGENTO_ROOT . '/build_options.ini')
            : [];
    }

    private function getBuildOption($key) {
        return isset($this->buildOptions[$key]) ? $this->buildOptions[$key] : false;
    }

    public function deployStaticContent()
    {
        $configFile = Environment::MAGENTO_ROOT . 'app/etc/config.php';
        if (file_exists($configFile)) {
            $config = include $configFile;

            $flattenedConfig = $this->flatten($config);
            $websites = $this->filter($flattenedConfig, 'scopes/websites', false);
            $stores = $this->filter($flattenedConfig, 'scopes/stores', false);

            $locales = [];
            $locales = array_merge($locales, $this->filter($flattenedConfig, 'general/locale/code'));
            $locales = array_merge(
                $locales,
                $this->filter($flattenedConfig, 'admin_user/locale/code', false)
            );
            $locales[] = 'en_US';
            $locales = array_unique($locales);

            if (count($stores) === 0 && count($websites) === 0) {
                $this->env->log("No stores/website/locales found in config.php");
                return;
            }

            $SCDLocales = implode(' ', $locales);

            $excludeThemesOptions = '';
            if ($this->getBuildOption(self::BUILD_OPT_SCD_EXCLUDE_THEMES)) {
                $themes = preg_split("/[,]+/", $this->getBuildOption(self::BUILD_OPT_SCD_EXCLUDE_THEMES));
                if (count($themes) > 1) {
                    $excludeThemesOptions = "--exclude-theme=" . implode(' --exclude-theme=', $themes);
                } elseif (count($themes) === 1) {
                    $excludeThemesOptions = "--exclude-theme=" . $themes[0];
                }
            }

            $threads = $this->getBuildOption(self::BUILD_OPT_SCD_THREADS)
                ? "{$this->getBuildOption(self::BUILD_OPT_SCD_THREADS)}"
                : '0';

            try {
                $logMessage = $SCDLocales
                    ? "Generating static content for locales: $SCDLocales"
                    : "Generating static content.";
                $logMessage .= $excludeThemesOptions ? "\nExcluding Themes: $excludeThemesOptions" : "";
                $logMessage .= $threads ? "\nUsing $threads Threads" : "";

                $this->env->log($logMessage);

                $parallelCommands = "";
                foreach ($locales as $locale) {
                    // @codingStandardsIgnoreStart
                    $parallelCommands .= "php ./bin/magento setup:static-content:deploy -f $excludeThemesOptions $locale {$this->verbosityLevel}" . '\n';
                    // @codingStandardsIgnoreEnd
                }
                $this->env->execute("printf '$parallelCommands' | xargs -I CMD -P " . (int)$threads . " bash -c CMD");
            } catch (\Exception $e) {
                $this->env->log($e->getMessage());
                exit(5);
            }
        } else {
            $this->env->log("Skipping static content deploy");
        }
    }

    private function generateFreshStaticContent()
    {
        $excludeThemesOptions = $this->staticDeployExcludeThemes
            ? "--exclude-theme=" . implode(' --exclude-theme=', $this->staticDeployExcludeThemes)
            : '';
        $jobsOption = $this->staticDeployThreads
            ? "--jobs={$this->staticDeployThreads}"
            : '';

        $this->env->execute(
            "/usr/bin/php ./bin/magento setup:static-content:deploy -f $jobsOption $excludeThemesOptions {$this->verbosityLevel}"
        );
    }

    private function composerDumpAutoload()
    {
        $this->env->execute('composer dump-autoload -o');
    }

    private function flatten($array, $prefix = '')
    {
        $result = [];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $result = $result + $this->flatten($value, $prefix . $key . '/');
            } else {
                $result[$prefix . $key] = $value;
            }
        }
        return $result;
    }

    private function filter($array, $pattern, $ending = true)
    {
        $filteredResult = [];
        $length = strlen($pattern);
        foreach ($array as $key => $value) {
            if ($ending) {
                if (substr($key, -$length) === $pattern) {
                    $filteredResult[$key] = $value;
                }
            } else {
                if (substr($key, 0, strlen($pattern)) === $pattern) {
                    $filteredResult[$key] = $value;
                }
            }
        }
        return array_unique(array_values($filteredResult));
    }
}
