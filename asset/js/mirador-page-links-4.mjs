/*
 * Mirador 4 companion of js/mirador-page-links.js. Mirador 4 is an ES module and sets no window.Mirador, so this
 * module imports it through the page's import map (the Mirador module writes one) and hands it to the page-links
 * script, whichever of the two runs first.
 */
import * as M from 'mirador';

// Mirador 4 exports its actions and selectors at the top level; the page-links script uses Mirador 3's grouping.
const Mirador = {
    actions: M.actions || { setCanvas: M.setCanvas },
    selectors: M.selectors || { getCanvases: M.getCanvases, getCanvasIndex: M.getCanvasIndex },
};

window.advancedMediaMirador = Mirador;
if (typeof window.advancedMediaPageLinksInit === 'function') {
    window.advancedMediaPageLinksInit(Mirador);
}
