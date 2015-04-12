<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link     http://piwik.org
 * @license  http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\LanguagesManager\Commands;

use Piwik\Plugins\LanguagesManager\API;
use Symfony\Component\Console\Helper\DialogHelper;
use Symfony\Component\Console\Helper\ProgressHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 */
class Update extends TranslationBase
{
    protected function configure()
    {
        $this->setName('translations:update')
            ->setDescription('Updates translation files')
            ->addOption('username', 'u', InputOption::VALUE_OPTIONAL, 'Transifex username')
            ->addOption('password', 'p', InputOption::VALUE_OPTIONAL, 'Transifex password')
            ->addOption('plugin', 'P', InputOption::VALUE_OPTIONAL, 'optional name of plugin to update translations for');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $start = microtime(true);

        /** @var DialogHelper $dialog */
        $dialog = $this->getHelperSet()->get('dialog');

        $languages = API::getInstance()->getAvailableLanguageNames();

        $languageCodes = array();
        foreach ($languages as $languageInfo) {
            $languageCodes[] = $languageInfo['code'];
        }

        $plugin = $input->getOption('plugin');

        $output->writeln("Starting to import new language files");

        if (!$input->isInteractive()) {
            $output->writeln("(!) Non interactive mode: New languages will be skipped");
        }

        $pluginList = array($plugin);
        if (empty($plugin)) {
            $pluginList = self::getPluginsInCore();
            array_unshift($pluginList, '');
        }

        foreach ($pluginList as $plugin) {

            // fetch base or specific plugin
            $this->fetchTranslations($input, $output, $plugin);

            $files = _glob(FetchFromTransifex::getDownloadPath() . DIRECTORY_SEPARATOR . '*.json');

            /** @var ProgressHelper $progress */
            $progress = $this->getHelperSet()->get('progress');

            $progress->start($output, count($files));

            foreach ($files as $filename) {

                $progress->advance();

                $code = basename($filename, '.json');

                if (!in_array($code, $languageCodes)) {

                    if (!empty($plugin)) {
                        continue; # never create a new language for plugin only
                    }

                    $createNewFile = false;
                    if ($input->isInteractive()) {
                        $createNewFile = $dialog->askConfirmation($output, "\nLanguage $code does not exist. Should it be added? ", false);
                    }

                    if (!$createNewFile) {
                        continue; # do not create a new file for the language
                    }

                    @touch(PIWIK_DOCUMENT_ROOT . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . $code . '.json');
                    API::unsetInstance(); // unset language manager instance, so valid names are refetched
                }

                $command = $this->getApplication()->find('translations:set');
                $arguments = array(
                    'command' => 'translations:set',
                    '--code' => $code,
                    '--file' => $filename,
                    '--plugin' => $plugin
                );
                $inputObject = new ArrayInput($arguments);
                $inputObject->setInteractive($input->isInteractive());
                $command->run($inputObject, new NullOutput());
            }

            $progress->finish();
        }

        $output->writeln("Finished in " . round(microtime(true)-$start, 3) . "s");
    }

    /**
     * Returns all plugins having their own translations that are bundled in core
     * @return array
     */
    public static function getPluginsInCore()
    {
        static $pluginsInCore;

        if (!empty($pluginsInCore)) {
            return $pluginsInCore;
        }

        $submodules = shell_exec('git submodule status');
        preg_match_all('/plugins\/([a-zA-z]+) /', $submodules, $matches);
        $submodulePlugins = $matches[1];

        // ignore complete new plugins aswell
        $changes = shell_exec('git status');
        preg_match_all('/plugins\/([a-zA-z]+)\/\n/', $changes, $matches);
        $newPlugins = $matches[1];

        $pluginsNotInCore = array_merge($submodulePlugins, $newPlugins);

        $pluginsWithTranslations = glob(sprintf('%s/plugins/*/lang/en.json', PIWIK_INCLUDE_PATH));
        $pluginsWithTranslations = array_map(function ($elem) {
            return str_replace(array(sprintf('%s/plugins/', PIWIK_INCLUDE_PATH), '/lang/en.json'), '', $elem);
        }, $pluginsWithTranslations);

        $pluginsInCore = array_diff($pluginsWithTranslations, $pluginsNotInCore);

        return $pluginsInCore;
    }

    protected function fetchTranslations($input, $output, $plugin)
    {
        $command = $this->getApplication()->find('translations:fetch');
        $arguments = array(
            'command' => 'translations:fetch',
            '--username' => $input->getOption('username'),
            '--password' => $input->getOption('password'),
            '--plugin' => $plugin
        );
        $inputObject = new ArrayInput($arguments);
        $inputObject->setInteractive($input->isInteractive());
        $command->run($inputObject, $output);
    }
}
