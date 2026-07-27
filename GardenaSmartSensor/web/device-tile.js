(function () {
    'use strict';
    var state = window.__GS_INITIAL__ || {};
    var root = document.getElementById('gs-tile-root');
    if (!root) return;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function render() {
        var online = !!state.online;
        var html = '';
        html += '<div class="gs-head"><div class="gs-title">' + esc(state.name || 'Sensor') + '</div>';
        html += '<div class="gs-meta"><span class="gs-dot ' + (online ? 'on' : 'off') + '"></span>' +
            (online ? 'online' : 'offline') +
            (state.frost ? ' · Frostwarnung' : '') +
            '</div></div>';
        html += '<div class="gs-grid">';
        html += '<div class="gs-valve"><h3>Bodenfeuchte</h3><div style="font-size:1.6rem;font-weight:650">' +
            esc(state.moisture) + ' %</div></div>';
        html += '<div class="gs-valve"><h3>Temperatur</h3><div style="font-size:1.6rem;font-weight:650">' +
            esc(state.temperature) + ' °C</div></div>';
        html += '<div class="gs-valve"><h3>Batterie</h3><div style="font-size:1.6rem;font-weight:650">' +
            esc(state.battery) + ' %</div></div>';
        html += '<div class="gs-valve"><h3>Licht</h3><div style="font-size:1.6rem;font-weight:650">' +
            esc(state.light) + '</div></div>';
        html += '</div>';
        root.innerHTML = html;
    }
    render();
})();
