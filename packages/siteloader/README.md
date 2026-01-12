# Easier Integrations for TYPO3 sites

## Introduction

siteloader is heavily inspired by b13/bolt! Kudos and many thanks!
https://github.com/b13/bolt

Automatically loads TypoScript and PageTsConfig, but (in contrast to b13/bolt) still allows to set / override stuff in the backend.

Provides a Site configuration setting called "sitePackage" that connects a
Site with this site package / extension. This is simply an entry in the Site's .yaml
file.

## Configuration
### TypoScript
* Add extension file `Configuration/TypoScript/constants.typoscript`. This is the main
  TypoScript "constants / settings" entry point for this Site in the page tree. It should
  typically contain `@import` lines to load further "static includes" from other extensions
  as well as own TypoScript provided by the site extension itself. This file is automatically
  loaded by convention using a hook or event of the bolt extension. Since TYPO3 v12, the Backend
  "Template Analyzer" reflects such includes.

* Add extension file `Configuration/TypoScript/setup.typoscript`. This is the main TypoScript "setup"
  entry point for this Site in the page tree. It should typically contain `@import` lines to load further
  "static includes" from other extensions as well as own TypoScript provided by the site extension itself.
  This file is automatically loaded by convention using a hook or event of the bolt extension. Since
  TYPO3 v12, the Backend "Template Analyzer" reflects such includes.

### PageTsConfig
If you have only one site extension and use already TYPO3 12 LTS,
it might be sufficient to use the TYPO3 default loading for PageTsConfig:
https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ExtensionArchitecture/FileStructure/Configuration/PageTsconfig.html

* Add extension file `Configuration/PageTs/main.tsconfig` (if needed). This is the main PageTsConfig entry
  point for this Site in the page tree. It should typically contain further `@import` lines. This file is
  automatically loaded by convention using a hook or event of the bolt extension.
