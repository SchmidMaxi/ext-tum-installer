<?php

namespace ElementareTeilchen\Sitetum\Services;

use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Configuration\Exception\SiteConfigurationWriteException;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\Exception\MissingArrayPathException;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SiteSettingsService
{
    protected const PATH_DELIMITER = '.'; // Path delimiter for the yaml key
    protected const YAML_INLINE_LEVEL = 99; // The level where Yaml::dump switches to inline yaml (99 = basically never)
    protected const YAML_INDENT = 2;
    protected string $configFilePath;

    public function __construct(protected Site $site, string $configFile)
    {
        $this->configFilePath = Environment::getConfigPath() . '/sites/' . $site->getIdentifier() . "/{$configFile}.yaml";
    }

    /**
     * Add or replace a setting in the yaml file.
     * - Creates the file if it does not exist
     * - Accepts a string or a JSON object as value
     *
     * @throws ParseException If the YAML file can't be read or is not valid
     * @throws SiteConfigurationWriteException If the yaml file cannot be written to
     */
    public function modifySetting(string $key, string $value): void
    {
        $parsedValue = $this->parseValue($value);
        $yamlArray = $this->getYamlSettingsAsArray();
        $modifiedArray = ArrayUtility::setValueByPath($yamlArray, $key, $parsedValue, self::PATH_DELIMITER);
        $this->writeSettingsToYaml($modifiedArray);
    }

    /**
     * Removes a setting from the yaml file.
     *
     * @throws ParseException If the YAML file can't be read or is not valid
     * @throws SiteConfigurationWriteException If the yaml file cannot be written to
     * @throws MissingArrayPathException If the key does not exist in the yaml file
     * @throws RuntimeException If removeByPath fails for some other reason
     */
    public function removeSetting(string $key): void
    {
        $yamlArray = $this->getYamlSettingsAsArray();
        $modifiedArray = ArrayUtility::removeByPath($yamlArray, $key, self::PATH_DELIMITER);

        /////////////////////////////////////////////////   PROBLEM   /////////////////////////////////////////////////
        /// If the removed value was inside a non-associative list in the yaml file, like this:
        ///
        /// test:
        ///   key1:
        ///     - value1
        ///     - value2
        ///     - value3
        ///
        /// And e.g. 'value2' is removed, 'value3' still has the numeric index '2'. This means when dumping the php
        /// array again, the Symfony Yaml component does this:
        ///
        /// test:
        ///   key1:
        ///     0: value1
        ///     2: value3
        ///
        /// Therefore, the array needs to be re-indexed again before dumping, to preserve the list inside the yaml.
        ///////////////////////////////////////////////////////////////////////////////////////////////////////////////

        // remove last path segment from key
        // e.g. turns 'tum.key1.key2' into 'tum.key1' (if path delimiter is '.')
        // does nothing if key is on root level (e.g. 'tum')
        $keyParent = preg_replace('/' . preg_quote(self::PATH_DELIMITER, '/') . '[^' . preg_quote(self::PATH_DELIMITER, '/') . ']+$/', '', $key);

        $keyIsTopLevel = ($key == $keyParent);
        if ($keyIsTopLevel) {
            $oldValueParent = $yamlArray;
        } else {
            $oldValueParent = ArrayUtility::getValueByPath($yamlArray, $keyParent, self::PATH_DELIMITER);
        }

        if (array_is_list($oldValueParent)) {
            if ($keyIsTopLevel) {
                // key is top level, so just re-index the top level of the array
                $modifiedArray = array_values($modifiedArray);
            } else {
                // key is somewhere inside the array, so just re-index the subsection
                $modifiedValueParent = array_values(ArrayUtility::getValueByPath($modifiedArray, $keyParent, self::PATH_DELIMITER));
                $modifiedArray = ArrayUtility::setValueByPath($modifiedArray, $keyParent, $modifiedValueParent, self::PATH_DELIMITER);
            }
        }

        $this->writeSettingsToYaml($modifiedArray);
    }

    /**
     * Returns the decoded string as array, if it was valid JSON, or the initial value, if not.
     *
     * @return string|int|float|array
     */
    protected function parseValue(string $value): string|int|float|array
    {
        return json_decode($value, true) ?? $value;
    }

    /**
     * Parse the YAML file.
     * Returns either the YAML file contents as php array, or an empty array if the file does not exist.
     *
     * @return array
     * @throws ParseException If the YAML file can't be read or is not valid
     */
    protected function getYamlSettingsAsArray(): array
    {
        if (!is_file($this->configFilePath)) {
            return [];
        }

        return Yaml::parseFile($this->configFilePath);
    }

    /**
     * Writes the contents of $yamlSettings to the yaml file.
     * Creates the file, if it does not exist.
     *
     * @throws SiteConfigurationWriteException If the yaml file cannot be written to
     */
    protected function writeSettingsToYaml(array $yamlSettings): void
    {
        $yamlString = Yaml::dump($yamlSettings, self::YAML_INLINE_LEVEL, self::YAML_INDENT);
        if (!GeneralUtility::writeFile($this->configFilePath, $yamlString)) {
            throw new SiteConfigurationWriteException('Unable to write settings in config file ' . $this->configFilePath, 1700219208);
        }
    }
}
