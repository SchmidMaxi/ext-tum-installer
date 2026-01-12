import { Plugin } from '@ckeditor/ckeditor5-core';


export default class LinkableBrs extends Plugin {
  init() {
    const editor = this.editor;
    editor.model.schema.extend('softBreak', {
      allowAttributesOf: '$text'
    });
  }

  /**
   * @inheritdoc
   */
  static get pluginName() {
    return 'LinkableBrs';
  }
}
