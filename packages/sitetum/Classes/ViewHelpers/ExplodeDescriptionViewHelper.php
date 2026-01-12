<?php

namespace ElementareTeilchen\Sitetum\ViewHelpers;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Exception;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithRenderStatic;

/**
 * Use this view helper to explode the text between its opening and closing tags with a "Pipe" |.
 * Usefull for lightbox elements with different/longer description in lightbox-view.
 *
 * = Examples =
 *
 * <code title="Defaults">
 * <f:format.raw>{file.properties.description -> tum:explodeDescription()}</f:format.raw>
 * </code>
 */
class ExplodeDescriptionViewHelper extends AbstractViewHelper
{
    use CompileWithRenderStatic;

    /**
     * The output may contain HTML and can not be escaped.
     *
     * @var bool
     */
    protected $escapeOutput = false;

    /**
     * Initialize arguments.
     *
     * @api
     * @throws Exception
     */
    public function initializeArguments()
    {
        $this->registerArgument('long', 'bool', 'If true return long description', false, false);
    }

    /**
     * @param array $arguments
     * @param \Closure $renderChildrenClosure
     * @param RenderingContextInterface $renderingContext
     *
     * @return string
     * @throws \InvalidArgumentException
     */
    public static function renderStatic(array $arguments, \Closure $renderChildrenClosure, RenderingContextInterface $renderingContext)
    {
        $stringToExplode = $renderChildrenClosure();
        $explodedContent = explode('|', (string)$stringToExplode);
        if ($arguments['long'] && array_key_exists('1', $explodedContent)) {
            return $explodedContent['1'];
        }
        if (array_key_exists('0', $explodedContent)) {
            return $explodedContent['0'];
        }
        return '';
    }
}
