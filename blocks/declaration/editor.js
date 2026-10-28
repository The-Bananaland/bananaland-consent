(function (wp) {
  var el = wp.element.createElement;
  wp.blocks.registerBlockType('bananaland/cookie-declaration', {
    edit: function () {
      return el('div', wp.blockEditor.useBlockProps(), el(wp.serverSideRender, { block: 'bananaland/cookie-declaration' }));
    },
    save: function () {
      return null;
    },
  });
})(window.wp);
