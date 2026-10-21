/* Zoom guard.
 *
 * Stops the everyday zoom gestures so the layout can't be nudged by accident:
 *   - Ctrl/Cmd + '+' / '-' / '0'   (keyboard)
 *   - Ctrl/Cmd + scroll wheel      (mouse wheel, trackpad pinch)
 *   - Safari pinch                 (gesturestart / gesturechange)
 *
 * What it CANNOT stop: the browser menu (Zoom), browser settings, and mobile
 * browsers that ignore page-level restrictions. This is a soft lock, not kiosk
 * mode. Escape hatch: Shift+Alt+Z unlocks for the current page load.
 */
(function () {
    'use strict';

    var ZOOM_KEYS = ['+', '=', '-', '_', '0'];

    function unlocked() {
        return window.__zoomLock === false;
    }

    document.addEventListener('keydown', function (event) {
        if (event.shiftKey && event.altKey && (event.key === 'z' || event.key === 'Z')) {
            window.__zoomLock = false;
            return;
        }
        if (unlocked() || !(event.ctrlKey || event.metaKey)) {
            return;
        }
        if (ZOOM_KEYS.indexOf(event.key) === -1) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
    }, { passive: false, capture: true });

    document.addEventListener('wheel', function (event) {
        if (unlocked() || !(event.ctrlKey || event.metaKey)) {
            return;
        }
        event.preventDefault();
    }, { passive: false, capture: true });

    ['gesturestart', 'gesturechange', 'gestureend'].forEach(function (name) {
        document.addEventListener(name, function (event) {
            if (unlocked()) {
                return;
            }
            event.preventDefault();
        }, { passive: false });
    });
})();
