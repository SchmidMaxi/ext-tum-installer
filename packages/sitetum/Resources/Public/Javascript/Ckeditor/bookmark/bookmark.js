import { Plugin, Command } from '@ckeditor/ckeditor5-core';
import { ButtonView } from '@ckeditor/ckeditor5-ui';

export class BookmarkPlugin extends Plugin {
  static get pluginName() {
    return 'BookmarkPlugin';
  }

  init() {
    const editor = this.editor;

    // 1. Schema definieren
    editor.model.schema.register('bookmark', {
      allowWhere: '$text',
      allowAttributes: ['name'],
      isInline: true,
      isObject: true
    });

    // 2. Konvertierung: HTML -> Model (beim Laden)
    editor.conversion.for('upcast').elementToElement({
      view: {
        name: 'a',
        attributes: { id: true }
      },
      model: (viewElement, { writer }) => {
        const name = viewElement.getAttribute('id');
        // Ignoriere echte Links mit href
        if (!name || viewElement.hasAttribute('href')) return null;
        return writer.createElement('bookmark', { name });
      }
    });

    // 3. Konvertierung: Model -> HTML (beim Speichern)
    editor.conversion.for('dataDowncast').elementToElement({
      model: 'bookmark',
      view: (modelElement, { writer }) => {
        return writer.createEmptyElement('a', {
          id: modelElement.getAttribute('name'),
          class: 'anchor'
        });
      }
    });

    // 4. Konvertierung: Model -> Editor-Ansicht
    editor.conversion.for('editingDowncast').elementToElement({
      model: 'bookmark',
      view: (modelElement, { writer }) => {
        const name = modelElement.getAttribute('name');
        const viewWrapper = writer.createContainerElement('span', {
          class: 'ck-bookmark-marker',
          style: 'background-color: #fcf8e3; border: 1px dashed #faebcc; padding: 0 4px; color: #8a6d3b; cursor: pointer; font-size: 0.8em;',
          title: 'Anker: ' + name
        });
        writer.insert(writer.createPositionAt(viewWrapper, 0), writer.createText('⚓ ' + name));
        return viewWrapper;
      }
    });

    // 5. Command registrieren
    editor.commands.add('insertBookmark', new InsertBookmarkCommand(editor));

    // 6. Button zur Toolbar hinzufügen
    editor.ui.componentFactory.add('insertBookmark', locale => {
      const view = new ButtonView(locale);

      // Inline SVG
      const icon = '<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M5 2a2 2 0 0 0-2 2v14l7-3 7 3V4a2 2 0 0 0-2-2H5z"/></svg>';

      view.set({
        label: 'Anker bearbeiten / einfügen',
        icon: icon,
        tooltip: true
      });

      const command = editor.commands.get('insertBookmark');

      // Button aktivieren, wenn Command aktiv ODER wenn ein Anker ausgewählt ist
      view.bind('isEnabled').to(command, 'isEnabled', (isEnabled) => {
        // Prüfen ob ein Anker selektiert ist (für Editier-Modus)
        const selection = editor.model.document.selection;
        const selectedElement = selection.getSelectedElement();
        const isBookmark = selectedElement && selectedElement.name === 'bookmark';
        return isEnabled || isBookmark;
      });

      view.on('execute', () => {
        const selection = editor.model.document.selection;
        const selectedElement = selection.getSelectedElement();
        const isBookmark = selectedElement && selectedElement.name === 'bookmark';

        // Wenn bereits ein Anker ausgewählt ist, nehmen wir dessen Namen als Default
        const currentName = isBookmark ? selectedElement.getAttribute('name') : '';

        let promptText = 'Name des Ankers (ID) eingeben:';
        if (isBookmark) {
          promptText = 'Anker bearbeiten (Name leeren zum Löschen):';
        }

        let name = prompt(promptText, currentName);

        // Abbrechen gedrückt
        if (name === null) return;

        // Bereinigen (Slugify)
        name = name.trim().toLowerCase()
            .replace(/\s+/g, '-')
            .replace(/[^a-z0-9\-_]/g, '');

        editor.model.change(writer => {
          if (name === '') {
            // LÖSCHEN: Wenn Name leer und es war ein Anker -> weg damit
            if (isBookmark) {
              writer.remove(selectedElement);
            }
          } else {
            // UPDATE oder NEU
            if (isBookmark) {
              // Update existierenden Anker
              writer.setAttribute('name', name, selectedElement);
            } else {
              // Neuen Anker erstellen
              editor.execute('insertBookmark', { name });
              editor.editing.view.focus();
            }
          }
        });
      });

      return view;
    });
  }
}

class InsertBookmarkCommand extends Command {
  execute(options = {}) {
    const editor = this.editor;
    editor.model.change(writer => {
      const bookmark = writer.createElement('bookmark', { name: options.name });
      editor.model.insertContent(bookmark);
    });
  }
  refresh() {
    const model = this.editor.model;
    const selection = model.document.selection;
    const allowedIn = model.schema.findAllowedParent(selection.getFirstPosition(), 'bookmark');
    this.isEnabled = allowedIn !== null;
  }
}

