/**
 * Original sources: https://github.com/leknoppix/ckeditor5-fullscreen
 *
 * Adapted to work in TYPO3
 */

import { Plugin } from '@ckeditor/ckeditor5-core';
import { ButtonView } from '@ckeditor/ckeditor5-ui';

const iconFullscreen = '<?xml version="1.0" ?><svg enable-background="new 0 0 32 32" height="32px" id="svg2" version="1.1" viewBox="0 0 32 32" width="32px" xml:space="preserve" xmlns="http://www.w3.org/2000/svg" xmlns:cc="http://creativecommons.org/ns#" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd" xmlns:svg="http://www.w3.org/2000/svg"><g id="background"><rect fill="none" height="32" width="32"/></g><g id="fullscreen"><path d="M20,8l8,8V8H20z M4,24h8l-8-8V24z"/><path d="M32,28V4H0v24h14v2H8v2h16v-2h-6v-2H32z M2,26V6h28v20H2z"/></g></svg>';
const iconFullscreenCancel = '<?xml version="1.0" ?><svg enable-background="new 0 0 32 32" height="32px" id="svg2" version="1.1" viewBox="0 0 32 32" width="32px" xml:space="preserve" xmlns="http://www.w3.org/2000/svg" xmlns:cc="http://creativecommons.org/ns#" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd" xmlns:svg="http://www.w3.org/2000/svg"><g id="background"><rect fill="none" height="32" width="32"/></g><g id="fullscreen"><path d="M20,8l8,8V8H20z M4,24h8l-8-8V24z"/><path d="M32,28V4H0v24h14v2H8v2h16v-2h-6v-2H32z M2,26V6h28v20H2z"/></g></svg>';

const buttonStateNormal = {
  label: 'Full Screen',
  icon: iconFullscreen,
  tooltip: true
};

const buttonStateFullScreen = {
  label: 'Mode Normal',
  icon: iconFullscreenCancel,
  tooltip: true
}

export class FullScreen extends Plugin {
    init() {
        const editor = this.editor;

        editor.ui.componentFactory.add( 'fullScreen', (locale) => {

            const button = new ButtonView(locale);

            let state = 'normal';

            button.set(buttonStateNormal);

            button.on('execute', () => {
                if (state === 'fullscreen') {
                    editor.sourceElement.nextSibling.classList.remove('ck-fullscreen');
                    button.set(buttonStateNormal);
                    state = 'normal';
                } else {
                    editor.sourceElement.nextSibling.classList.add('ck-fullscreen');
                    button.set(buttonStateFullScreen);
                    state = 'fullscreen';
                }
            });

            return button;
        });
    }
}
