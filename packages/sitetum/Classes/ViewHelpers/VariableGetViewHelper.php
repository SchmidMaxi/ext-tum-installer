<?php

namespace ElementareTeilchen\Sitetum\ViewHelpers;

use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithContentArgumentAndRenderStatic;

/**
 * ### Variable: Get
 *
 * ViewHelper used to read the value of a TSFE-register
 * Can be used to read names of variables which contain dynamic parts:
 *
 * ```
 * <!-- if {variableName} is "Name", outputs value of {dynamicName} -->
 * {tum:variableGet(name: 'dynamic{variableName}')}
 * ```
 */
class VariableGetViewHelper extends AbstractViewHelper
{
    use CompileWithContentArgumentAndRenderStatic;

    /**
     * @var bool
     */
    protected $escapeChildren = false;

    /**
     * @var bool
     */
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        $this->registerArgument('name', 'string', 'Name of register');
    }

    /**
     * @return mixed
     */
    public static function renderStatic(
        array $arguments,
        \Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext
    ) {
        $name = $renderChildrenClosure();
        if (!($GLOBALS['TSFE'] ?? null) instanceof TypoScriptFrontendController) {
            return null;
        }
        return $GLOBALS['TSFE']->register[$name] ?? null;
    }
}
