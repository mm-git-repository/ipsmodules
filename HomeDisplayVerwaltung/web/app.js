const PAGES = [
  ["start", "Start"],
  ["energie", "Energie"],
  ["klima", "Klima"],
  ["licht", "Licht"],
  ["rollladen", "Rollladen"],
  ["pool", "Pool und Whirlpool"],
  ["garten", "Garten"],
  ["system", "System"]
];
const CAMERA_LIVE_URL_DEFAULT = "https://display:Starten01!@172.18.1.120/ISAPI/Streaming/channels/102/picture";
const ICONS = [
  ["energy", "Energie", 0xF140B], ["temperature", "Temperatur", 0xF050F], ["light", "Licht", 0xF0335], ["shutter", "Rollladen", 0xF111C],
  ["pump", "Pumpe", 0xF1402], ["valve", "Ventil", 0xF1066], ["alarm", "Alarm", 0xF078F], ["home", "Haus", 0xF02DC], ["clock", "Uhr", 0xF0150], ["info", "Info", 0xF02FD],
  ["lightOn", "Lampe an", 0xF0335], ["lightOff", "Lampe aus", 0xF0336], ["humidity", "Feuchte", 0xF058E], ["sun", "Sonne", 0xF0599], ["moon", "Nacht", 0xF0594],
  ["water", "Wasser", 0xF058C], ["heat", "Heizung", 0xF0438], ["cool", "Kühlen", 0xF0717], ["fan", "Lüftung", 0xF0210], ["door", "Tür", 0xF081A], ["lock", "Schloss", 0xF033E],
  ["plug", "Steckdose", 0xF06A5], ["batteryIcon", "Akku", 0xF0079], ["wifi", "WLAN", 0xF05A9], ["car", "Auto", 0xF010B],
  ["motion", "Bewegung", 0xF0D91], ["window", "Fenster", 0xF05AE], ["garage", "Garage", 0xF06D9], ["smoke", "Rauch", 0xF0392], ["thermostat", "Thermostat", 0xF0393],
  ["solar", "Solar", 0xF0A72], ["camera", "Kamera", 0xF07AE], ["tv", "Fernseher", 0xF0502], ["speaker", "Lautsprecher", 0xF04C3], ["washing", "Waschmaschine", 0xF072A],
  ["bed", "Bett", 0xF02E3], ["bell", "Klingel", 0xF009A], ["leaf", "Blatt", 0xF032A], ["fire", "Feuer", 0xF0238],
  ["kitchen", "Küche", 0xF04DE], ["pool", "Pool", 0xF0606], ["hall", "Gang", 0xF181E], ["kids", "Kinderzimmer", 0xF18FB],
  ["bubbles", "Luftblasen", 0xF0CD1], ["bubbleChart", "Blasen (Sprechblase)", 0xF05E3], ["waves", "Wellen", 0xF078D],
  ["shutterOpen", "Rollladen oben", 0xF111E], ["valveOpen", "Ventil offen", 0xF1068], ["valveClosed", "Ventil zu", 0xF1067],
  ["pumpOff", "Pumpe aus", 0xF1B22], ["doorOpen", "Tür offen", 0xF081C], ["doorClosed", "Tür zu", 0xF081B],
  ["garageOpen", "Garage offen", 0xF06DA], ["lockOpen", "Schloss offen", 0xF033F], ["fanOff", "Lüftung aus", 0xF081D],
  ["plugOff", "Steckdose aus", 0xF06A6], ["bellOff", "Klingel aus", 0xF009B], ["wifiOff", "WLAN aus", 0xF05AA],
  ["speakerOff", "Lautsprecher aus", 0xF04C4], ["tvOff", "Fernseher aus", 0xF083B], ["waterOff", "Wasser aus", 0xF058D],
  ["coolOff", "Kühlen aus", 0xF14E3], ["fireOff", "Feuer aus", 0xF1722], ["motionOff", "Bewegung aus", 0xF1435],
  ["smokeOff", "Rauchmelder aus", 0xF1809], ["windowOpen", "Fenster offen", 0xF05B1],
  ["blindsOpen", "Jalousie offen", 0xF1011], ["blindsClosed", "Jalousie zu", 0xF00AC],
  ["bathtub", "Badewanne", 0xF1818], ["armchair", "Sessel", 0xF156E]
];
/* Auswahllisten zeigen die Symbole nach dem deutschen Namen sortiert. */
const ICONS_SORTED = [...ICONS].sort((a, b) => a[1].localeCompare(b[1], "de"));
const DISPLAY_PROFILES = {
  "waveshare-5b-1024x600": { label: "Waveshare 5B (1024×600)", width: 1024, height: 600, defaultColumns: 3, defaultRows: 4, maxColumns: 4, maxRows: 6 },
  "firebeetle-epd-400x300": { label: "FireBeetle E-Ink 4,2\" (400×300)", width: 400, height: 300, defaultColumns: 3, defaultRows: 4, maxColumns: 3, maxRows: 4 },
  "firebeetle-epd-800x480": { label: "FireBeetle E-Ink 7,5\" (800×480)", width: 800, height: 480, defaultColumns: 4, defaultRows: 6, maxColumns: 4, maxRows: 6 }
};

function migrateLayoutKinds(layout) {
  if (!layout || !Array.isArray(layout.pages)) return;
  for (const page of layout.pages) {
    if (!Array.isArray(page.tiles)) continue;
    page.tiles = page.tiles.filter((t) => (t.kind || "value") !== "system").map((t) => {
      if (t.kind === "display") {
        t.kind = "value";
        if (!t.displaySource) {
          t.displaySource = (t.text && !t.variableId) ? "text" : "variable";
        }
        delete t.actionId;
        delete t.control;
      }
      return t;
    });
  }
}

const KIND_NAMES = { value: "Wert", display: "Wert", chart: "Diagramm", energyflow: "Energiefluss", nav: "Seitenwechsler", label: "Text", image: "Bild", camera: "Kamera", separator: "Trennlinie", scene: "Szene", weather: "Wetter", presence: "Anwesenheit", multi: "Mehrwert", contacts: "Kontakte", gauge: "Bogen", countdown: "Countdown", appointment: "Termin" };
const FONT_PX = [16, 20, 24, 28, 32, 36, 40, 48];
const NAMED_PX = { small: 20, normal: 28, large: 36, xlarge: 48 };
const TITLE_FONT_KINDS = ["value", "display", "chart", "scene", "weather", "presence", "camera", "nav", "multi", "contacts", "gauge", "countdown", "appointment"];
const BODY_FONT_KINDS = ["value", "display", "chart", "scene", "weather", "presence", "label", "multi", "contacts", "gauge", "countdown", "appointment"];
const AGE_KINDS = ["value", "display", "weather", "gauge"];

function titlePxOf(tile) {
  const px = Number(tile && tile.titlePx);
  if (FONT_PX.includes(px)) return px;
  if (tile && tile.kind === "value" && tileInteractionMode(tile) === "") return 28;
  if (tile && tile.kind === "nav") return 28;
  return 20;
}

function textPxOf(tile) {
  const px = Number(tile && tile.textPx);
  if (FONT_PX.includes(px)) return px;
  if (tile && tile.textSize && NAMED_PX[tile.textSize]) return NAMED_PX[tile.textSize];
  if (tile && tile.kind === "label" && (!tile.textSize || tile.textSize === "auto")) return 0;
  if (tile && tile.kind === "chart") return 20;
  return 28;
}

function cssFont(px, bold, italic, underline) {
  const bits = [];
  if (px) bits.push(`font-size:${px}px`);
  if (bold) bits.push("font-weight:700");
  if (italic) bits.push("font-style:italic");
  if (underline) bits.push("text-decoration:underline");
  return bits.length ? ` style="${bits.join(";")}"` : "";
}
const CHART_PERIOD_LABELS = { "1h": "1 Stunde", "6h": "6 Stunden", "24h": "24 Stunden", "7d": "7 Tage" };
const FLOW_SLOT_LABELS = {
  signed: {
    gridImport: "Netz Bezug",
    gridExport: "Netz Einspeisung",
    pv: "PV-Erzeugung",
    load: "Hausverbrauch",
    battery: "Batterie (±)",
    soc: "SOC (optional)"
  },
  split: {
    gridImport: "Netz Bezug",
    gridExport: "Netz Einspeisung",
    pv: "PV-Erzeugung",
    batteryCharge: "Batterie Laden",
    batteryDischarge: "Batterie Entladen",
    load: "Hausverbrauch",
    soc: "SOC (optional)"
  }
};

function editorKicker() {
  return "";
}

function flowAnimSeconds(w) {
  const a = Math.abs(Number(w) || 0);
  if (a < 30) return 2.4;
  return Math.max(0.35, Math.min(2.4, 3500 / a));
}

function flowSlotMap(tile) {
  const mode = (tile && tile.flowMode) || "signed";
  if (mode === "split") {
    return {
      gridImport: "gridImportVariableId",
      gridExport: "gridExportVariableId",
      pv: "pvVariableId",
      batteryCharge: "batteryChargeVariableId",
      batteryDischarge: "batteryDischargeVariableId",
      load: "loadVariableId",
      soc: "batterySocVariableId"
    };
  }
  return {
    gridImport: "gridImportVariableId",
    gridExport: "gridExportVariableId",
    pv: "pvVariableId",
    load: "loadVariableId",
    battery: "batteryVariableId",
    soc: "batterySocVariableId"
  };
}

function flowConfigured(tile) {
  if (!tile || tile.kind !== "energyflow") return false;
  const mode = tile.flowMode || "signed";
  const need = mode === "split"
    ? ["gridImportVariableId", "gridExportVariableId", "pvVariableId", "batteryChargeVariableId", "batteryDischargeVariableId"]
    : ["gridImportVariableId", "gridExportVariableId", "pvVariableId", "batteryVariableId"];
  if (tile.homeOverride !== false) need.push("loadVariableId");
  return need.every((key) => tile[key]);
}

function flowLine(flows, id) {
  const lines = flows && flows.lines;
  if (!lines || !lines[id]) return { active: false, w: 0 };
  return { active: Boolean(lines[id].active), w: Number(lines[id].w) || 0 };
}

function flowNodeText(flows, key) {
  if (flows && flows[key] && flows[key].text) return flows[key].text;
  return "—";
}

function buildEnergyFlowHtml(tile, live, mode) {
  if (!flowConfigured(tile) && !(live && live.flows && live.flows.lines)) {
    return `${editorKicker(mode, "Energiefluss")}<div class="energy-flow hint" style="padding:8px;font-size:12px">Alle Pflicht-Variablen zuweisen (Netz, PV, Batterie, ggf. Haus).</div>`;
  }
  const flows = (live && live.flows) || {};
  const gridImport = flowNodeText(flows, "gridImport");
  const gridExport = flowNodeText(flows, "gridExport");
  const gridFallback = flowNodeText(flows, "grid");
  const labels = {
    pv: flowNodeText(flows, "pv"),
    gridImport: gridImport !== "—" ? gridImport : gridFallback,
    gridExport: gridExport !== "—" ? gridExport : "—",
    load: flowNodeText(flows, "load"),
    battery: flowNodeText(flows, "battery")
  };
  const soc = flows.soc && flows.soc.text ? flows.soc.text : "";
  const path = (d, lineId, extra) => {
    const line = flowLine(flows, lineId);
    if (!line.active) return `<path class="flow-path" d="${d}"/>`;
    return `<path class="flow-path active${extra ? ` ${extra}` : ""}" d="${d}" style="animation-duration:${flowAnimSeconds(line.w)}s"/>`;
  };
  const paths = [
    path("M50 23 L50 41", "solarToHome"),
    path("M50 23 L23 38", "solarToGrid", "export"),
    path("M50 23 L77 38", "solarToBattery", "battery"),
    path("M23 50 L41 50", "gridToHome"),
    path("M41 50 L23 50", "homeToGrid", "export"),
    path("M59 50 L77 50", "homeToBattery", "battery"),
    path("M77 50 L59 50", "batteryToHome", "battery")
  ];
  const homeHint = flows.homeCalculated ? " (berechnet)" : "";
  return `${editorKicker(mode, "Energiefluss")}<div class="energy-flow"><svg viewBox="0 0 100 78" class="flow-svg" preserveAspectRatio="xMidYMid meet" aria-hidden="true">${paths.join("")}<circle cx="50" cy="52" r="9" fill="#1d3350" stroke="#5a7391"/><text class="flow-node" x="50" y="10" text-anchor="middle">PV</text><text class="flow-value" x="50" y="18" text-anchor="middle">${escapeHtml(labels.pv)}</text><text class="flow-node" x="11" y="52" text-anchor="start">Netz</text><text class="flow-value" x="11" y="60" text-anchor="start">↓ ${escapeHtml(labels.gridImport)}</text><text class="flow-value" x="11" y="68" text-anchor="start">↑ ${escapeHtml(labels.gridExport)}</text><text class="flow-node" x="50" y="55" text-anchor="middle">Haus${homeHint}</text><text class="flow-value" x="50" y="63" text-anchor="middle">${escapeHtml(labels.load)}</text><text class="flow-node" x="89" y="55" text-anchor="end">Akku</text><text class="flow-value" x="89" y="63" text-anchor="end">${escapeHtml(labels.battery)}</text>${soc ? `<text class="flow-value" x="89" y="48" text-anchor="end">${escapeHtml(soc)}</text>` : ""}</svg></div>`;
}

function fillFlowSlotSelect(tile) {
  const select = $("p-flow-slot");
  if (!select) return;
  const mode = (tile && tile.flowMode) || "signed";
  const labels = FLOW_SLOT_LABELS[mode] || FLOW_SLOT_LABELS.signed;
  select.innerHTML = "";
  for (const [slot, label] of Object.entries(labels)) {
    const option = document.createElement("option");
    option.value = slot;
    option.textContent = label;
    select.appendChild(option);
  }
}

function syncFlowPropertyPanels(tile) {
  const mode = (tile && tile.flowMode) || ($("p-flow-mode") && $("p-flow-mode").value) || "signed";
  if ($("p-flow-grid-vars")) $("p-flow-grid-vars").classList.remove("hidden");
  if ($("p-flow-signed")) $("p-flow-signed").classList.toggle("hidden", mode === "split");
  if ($("p-flow-split")) $("p-flow-split").classList.toggle("hidden", mode !== "split");
  const homeOverride = tile ? tile.homeOverride !== false : ($("p-flow-home-override") && $("p-flow-home-override").checked);
  if ($("p-flow-load-wrap")) $("p-flow-load-wrap").classList.toggle("hidden", !homeOverride);
  fillFlowSlotSelect(tile || { flowMode: mode });
}

function flowVarLabel(variableId) {
  const v = variableById(variableId);
  return v ? `${v.path ? v.path + " / " : ""}${v.name} (${v.id})` : "";
}

function assignFlowVariable(tile, slot, variable, silent) {
  const map = flowSlotMap(tile);
  if (!tile || tile.kind !== "energyflow" || !variable || !map[slot]) return;
  tile[map[slot]] = variable.id;
  if (!tile.title && slot === "load") tile.title = "Energiefluss";
  if (!silent) {
    markDirty();
    renderGrid();
    renderProperties();
  }
}

function scaledTileHtml(mainText, iconId, subText) {
  const icon = iconId ? `<div class="tile-fit-icon">${iconVisual(iconId)}</div>` : "";
  const sub = subText ? `<div class="tile-fit-sub">${escapeHtml(subText)}</div>` : "";
  return `<div class="tile-scale tile-fit">${icon}<div class="tile-fit-text">${escapeHtml(mainText || "—")}</div>${sub}</div>`;
}

function fitTileContentBox(box) {
  if (!box) return;
  if (box.closest && (box.closest(".tile.chart") || box.querySelector(".dim-ctl"))) {
    box.style.setProperty("--tile-scale", "1");
    return;
  }
  const maxW = box.clientWidth - 2;
  const maxH = box.clientHeight - 2;
  if (maxW <= 0 || maxH <= 0) return;
  box.style.setProperty("--tile-scale", "1");
  let lo = 0.35;
  let hi = Math.min(14, maxH / 8, maxW / 10);
  for (let i = 0; i < 14; i++) {
    const mid = (lo + hi) / 2;
    box.style.setProperty("--tile-scale", String(mid));
    if (box.scrollWidth <= maxW && box.scrollHeight <= maxH) lo = mid;
    else hi = mid;
  }
  box.style.setProperty("--tile-scale", String(lo));
}

function scheduleFitTileText(scope) {
  requestAnimationFrame(() => {
    const root = scope && scope.querySelectorAll ? scope : document.getElementById("grid");
    if (!root) return;
    const nodes = root.querySelectorAll ? root.querySelectorAll(".tile-scale, .tile-fit") : [];
    for (const box of nodes) fitTileContentBox(box);
  });
}

const tileFitObservers = new WeakMap();

function observeTileFitRoot(root) {
  if (!root || tileFitObservers.has(root)) return;
  const observer = new ResizeObserver(() => scheduleFitTileText(root));
  observer.observe(root);
  tileFitObservers.set(root, observer);
}

function displayMainText(tile, live) {
  const source = tile.displaySource || "variable";
  if (source === "text") return (tile.text || "").trim() || "Text";
  if (live && live.text) return live.text;
  const variable = variableById(tile.variableId);
  const sample = variable && (variable.type === 1 || variable.type === 2) ? "12,3" : "Ein";
  return tile.unit ? `${sample} ${tile.unit}` : sample;
}

function panelSwitchHtml(tile, title, on, iconId, showIcon) {
  const align = tile && tile.titleAlign === "center" ? "align-center" : (tile && tile.titleAlign === "right" ? "align-right" : "");
  const titleHtml = tile && tile.showTitle === false ? "" : `<div class="panel-title ${align}"${cssFont(titlePxOf(tile), tile && tile.titleBold, tile && tile.titleItalic)}>${escapeHtml(title || "")}</div>`;
  const iconHtml = showIcon
    ? `<button type="button" class="panel-icon-btn" data-icon-toggle aria-pressed="${on ? "true" : "false"}">${iconVisual(iconId, on, tile && tile.onColor)}</button>`
    : "";
  const stateHtml = tile && tile.showStateText ? `<div class="panel-state"${cssFont(textPxOf(tile), tile.textBold, tile.textItalic, tile.textUnderline)}>${on ? "Ein" : "Aus"}</div>` : "";
  return `<div class="panel-switch">${titleHtml}<div class="panel-icon">${iconHtml}</div>${stateHtml}</div>`;
}

function titleIconEnabled(tile) {
  if (!tile) return false;
  const plain = tile.kind === "value" && tileInteractionMode(tile) === "";
  if (tile.showTitleIcon === true) return true;
  if (tile.showTitleIcon === false) return false;
  return !plain;
}

function titleIconShown(tile) {
  return titleIconEnabled(tile) && tile.showTitle !== false;
}

function titleIconId(tile) {
  if (!tile) return "";
  const plain = tile.kind === "value" && tileInteractionMode(tile) === "";
  if (plain) return tile.titleIcon || "";
  return tile.titleIcon || tile.icon || "";
}

function ohCardMarkup(label, stateHtml, bodyHtml, footHtml, tile) {
  const foot = footHtml ? `<div class="oh-card-foot">${footHtml}</div>` : "";
  const align = tile && tile.titleAlign === "center" ? "align-center" : (tile && tile.titleAlign === "right" ? "align-right" : "");
  const title = tile && tile.showTitle === false ? "" : escapeHtml(label || "");
  const headingId = titleIconShown(tile) ? titleIconId(tile) : "";
  const headingIcon = headingId ? `<span class="oh-card-title-icon">${iconVisual(headingId)}</span>` : "";
  const bits = [];
  if (tile && tile.textColor) bits.push(`color:${tile.textColor}`);
  const bodyPx = tile ? textPxOf(tile) : 28;
  if (bodyPx) bits.push(`font-size:${bodyPx}px`);
  if (tile && tile.textBold) bits.push("font-weight:700");
  if (tile && tile.textItalic) bits.push("font-style:italic");
  if (tile && tile.textUnderline) bits.push("text-decoration:underline");
  const style = bits.length ? ` style="${bits.join(";")}"` : "";
  const titleStyle = tile ? cssFont(titlePxOf(tile), tile.titleBold, tile.titleItalic) : "";
  const state = stateHtml ? `<span class="oh-card-state"${style}>${stateHtml}</span>` : "";
  const age = tile && tile.showAge ? `<div class="age-line">vor 4 min</div>` : "";
  return `<div class="oh-card"><div class="oh-card-head ${align}"${titleStyle}><span class="oh-card-label">${headingIcon}${title}</span>${state}</div><div class="oh-card-body">${bodyHtml || ""}</div>${age}${foot}</div>`;
}

function normalizeTile(tile) {
  if (!tile) return;
  if (tile.kind === "value") {
    if (tile.control === "slider") {
      tile.control = "combo";
      if (tile.dimShowSwitch === undefined) tile.dimShowSwitch = false;
    } else if (tile.control === "dimSwitch") {
      tile.control = "combo";
      if (tile.dimShowSwitch === undefined) tile.dimShowSwitch = true;
    }
    if ((tile.iconMode || "") === "toggle") {
      if (!tile.control || tile.control === "switch") tile.control = "switch";
      if (tile.showIcon === undefined) tile.showIcon = true;
    }
  }
  if (tile.kind === "scene" && !Array.isArray(tile.sceneSteps) && tile.variableId) {
    tile.sceneSteps = [{ variableId: tile.variableId, value: true }];
  }
}

function applyTileChrome(element, tile) {
  element.classList.remove("has-own-bg", "has-own-on");
  if (tile.bgColor && /^#[0-9a-fA-F]{6}$/.test(tile.bgColor)) {
    element.classList.add("has-own-bg");
    element.style.setProperty("--tile-own-bg", tile.bgColor);
  }
  if (tile.onColor && /^#[0-9a-fA-F]{6}$/.test(tile.onColor)) {
    element.classList.add("has-own-on");
    element.style.setProperty("--tile-own-on", tile.onColor);
  }
}

function demoChartSeries(tile) {
  const seed = (tile.tileId || "x").split("").reduce((a, c) => a + c.charCodeAt(0), 0);
  return Array.from({ length: 28 }, (_, i) => 10 + seed % 7 + Math.sin(i / 4) * 3 + i * 0.15);
}

function chartSeriesMinMax(series) {
  if (!series || series.length < 1) return { min: 0, max: 1 };
  let min = series[0];
  let max = series[0];
  for (const v of series) {
    if (v < min) min = v;
    if (v > max) max = v;
  }
  if (min === max) {
    min -= 1;
    max += 1;
  }
  return { min, max };
}

function chartEmptyText(note) {
  if (note === "notLogged") return "Variable wird nicht archiviert";
  if (note === "noArchive") return "Kein Archiv vorhanden";
  if (note === "noData") return "Keine geloggten Werte";
  return "Keine Verlaufsdaten";
}

function chartSvgMarkup(series, style, min, max, emptyText) {
  const empty = emptyText || "Keine Verlaufsdaten";
  if (!series || series.length < 2) return `<div class="chart-empty">${empty}</div>`;
  const w = 100;
  const h = 36;
  const range = max - min || 1;
  const points = series.map((v, i) => {
    const x = (i / (series.length - 1)) * w;
    const y = h - 2 - ((v - min) / range) * (h - 4);
    return `${x.toFixed(2)},${y.toFixed(2)}`;
  });
  const poly = points.join(" ");
  const area = style === "area" ? `<polygon class="oh-chart-area" points="0,${h} ${poly} ${w},${h}"></polygon>` : "";
  return `<svg class="oh-chart-svg" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none">${area}<polyline class="oh-chart-line" points="${poly}"></polyline></svg>`;
}
const CONTROL_NAMES = { switch: "Schalter", combo: "Dimmschalter", battery: "Batterie" };
const INTERACTION_NAMES = { switch: "Schalter (Ein/Aus)", combo: "Dimmschalter", battery: "Batterie", blinds: "Rolladen", setpoint: "Setpoint" };
const INTERACTION_HINTS = {
  switch: "Ein/Aus. Farbe, Icon und Text sind einzeln wählbar.",
  combo: "Dimmer: Schalter, Balken, Prozent, Text und Antippen sind einzeln wählbar.",
  battery: "Ladestand grafisch, ohne Schaltaktion an der Anzeige.",
  blinds: "Position 0–100 % oder Fahrt über schaltbare Variable (Aktionsart Position).",
  setpoint: "Ist-Wert anzeigen, Sollwert mit +/- an der Anzeige ändern."
};
const DIM_CONTROLS = ["dimSwitch", "slider", "combo"];
const PROBE_INLINE_CONTROLS = ["switch", "iconSwitch", "dimSwitch", "slider", "combo", "blinds", "setpoint"];

function valueOnOf(tile) {
  if (tile && tile.valueOn != null && tile.valueOn !== "" && Number.isFinite(Number(tile.valueOn))) return Number(tile.valueOn);
  return DIM_CONTROLS.includes(tile && tile.control) ? (tile.valueMax ?? 100) : 1;
}

function valueOffOf(tile) {
  if (tile && tile.valueOff != null && tile.valueOff !== "" && Number.isFinite(Number(tile.valueOff))) return Number(tile.valueOff);
  return DIM_CONTROLS.includes(tile && tile.control) ? (tile.valueMin ?? 0) : 0;
}

function levelBounds(tile) {
  if (tile && DIM_CONTROLS.includes(tile.control)) return { min: valueOffOf(tile), max: valueOnOf(tile) };
  return { min: tile && tile.valueMin != null ? tile.valueMin : 0, max: tile && tile.valueMax != null ? tile.valueMax : 100 };
}

function valueMeansOn(tile, value) {
  if (typeof value === "boolean") return value;
  const number = Number(value);
  if (!Number.isFinite(number)) return false;
  if (DIM_CONTROLS.includes(tile && tile.control)) return number !== valueOffOf(tile);
  return number === valueOnOf(tile);
}

function liveIsOn(tile, live, demoOn) {
  if (live && typeof live.value === "boolean") return live.value;
  if (live && typeof live.value === "number" && Number.isFinite(live.value)) return valueMeansOn(tile, live.value);
  if (live && live.text) {
    const text = String(live.text).trim();
    if (/^(true|ein|an|on)$/i.test(text)) return true;
    if (/^(false|aus|off)$/i.test(text)) return false;
    const match = text.replace(",", ".").match(/-?\d+(?:\.\d+)?/);
    if (match) return valueMeansOn(tile, parseFloat(match[0]));
  }
  return Boolean(demoOn);
}

function numericLiveValue(tile, live) {
  if (!live) return null;
  if (typeof live.value === "number" && !Number.isNaN(live.value)) return live.value;
  if (typeof live.value === "boolean") {
    if (DIM_CONTROLS.includes(tile && tile.control) || (tile && tile.control === "switch")) return live.value ? valueOnOf(tile) : valueOffOf(tile);
    return live.value ? (tile.valueMax ?? 100) : (tile.valueMin ?? 0);
  }
  if (live.text) {
    const match = String(live.text).replace(",", ".").match(/-?\d+(?:\.\d+)?/);
    if (match) return parseFloat(match[0]);
  }
  return null;
}

function dimPercentFromTile(tile, live, fallbackPct) {
  const bounds = levelBounds(tile);
  const min = bounds.min;
  const max = bounds.max;
  const span = max - min || 1;
  const v = numericLiveValue(tile, live);
  if (v == null) return fallbackPct != null ? fallbackPct : 50;
  return Math.round(Math.min(100, Math.max(0, ((v - min) / span) * 100)));
}

function tileInteractionMode(tile) {
  if (!tile || tile.kind !== "value") return "";
  if (tile.control === "battery") return "battery";
  if (tile.control === "blinds") return "blinds";
  if (tile.control === "setpoint") return "setpoint";
  if (tile.control === "slider" || tile.control === "combo" || tile.control === "dimSwitch") return "combo";
  if ((tile.iconMode || "") === "toggle" || tile.control === "switch" || tile.actionId) return "switch";
  return "";
}

function applyTileStatus(element, live, tile) {
  const color = (live && live.color) || tile?.color || "neutral";
  element.classList.remove("status-ok", "status-warn", "status-alarm", "status-neutral");
  if (color === "ok") element.classList.add("status-ok");
  else if (color === "warn") element.classList.add("status-warn");
  else if (color === "alarm") element.classList.add("status-alarm");
  else element.classList.add("status-neutral");
  if (color !== "neutral" && color !== undefined) {
    element.style.background = colorForTile(color);
  }
}

function normalizeChartSeries(series) {
  if (!Array.isArray(series)) return [];
  const out = [];
  for (const raw of series) {
    if (typeof raw === "number" && !Number.isNaN(raw)) out.push(raw);
    else {
      const n = parseFloat(String(raw).replace(",", "."));
      if (!Number.isNaN(n)) out.push(n);
    }
  }
  return out;
}

function chartSeriesUsable(series) {
  return normalizeChartSeries(series).length >= 2;
}

function chartSeriesFromTile(tile, live, allowDemo = true) {
  if (live && Array.isArray(live.seriesAll)) {
    const usable = live.seriesAll.map(normalizeChartSeries).filter((s) => s.length >= 2);
    if (usable.length) return usable;
  }
  if (live && chartSeriesUsable(live.series)) return [normalizeChartSeries(live.series)];
  if (!allowDemo) return [];
  return [demoChartSeries(tile)];
}

function chartBoundsForTile(tile, live, seriesList, likeDisplay = false) {
  const flat = seriesList.flat();
  let bounds;
  if ((tile.chartYMode || "auto") === "fixed" && tile.chartYMin != null && tile.chartYMax != null) {
    bounds = { min: Number(tile.chartYMin), max: Number(tile.chartYMax) };
  } else if (live && live.seriesMin != null && live.seriesMax != null && flat.length >= 2) {
    bounds = { min: Number(live.seriesMin), max: Number(live.seriesMax) };
  } else {
    bounds = chartSeriesMinMax(flat);
  }
  if (likeDisplay && flat.length >= 2) {
    const samples = chartSeriesMinMax(flat);
    const span = bounds.max - bounds.min;
    const sampleSpan = samples.max - samples.min;
    if (sampleSpan > 0.000001 && span > 0 && sampleSpan < span * 0.2) return samples;
  }
  return bounds;
}

function chartColorOf(tile) {
  return tile && /^#[0-9a-fA-F]{6}$/.test(tile.chartColor || "") ? tile.chartColor : "#ffb547";
}

function chartShowValue(tile) {
  return !tile || tile.chartShowValue !== false;
}

function chartAxisTimes(period) {
  const span = period === "1h" ? 3600000 : (period === "6h" ? 21600000 : (period === "7d" ? 7 * 86400000 : 86400000));
  const end = Date.now();
  const fmt = (ms) => {
    const date = new Date(ms);
    if (period === "7d") {
      const days = ["So", "Mo", "Di", "Mi", "Do", "Fr", "Sa"];
      return `${days[date.getDay()]} ${date.getDate()}.${date.getMonth() + 1}.`;
    }
    return `${String(date.getHours()).padStart(2, "0")}:${String(date.getMinutes()).padStart(2, "0")}`;
  };
  return [fmt(end - span), fmt(end - span / 2), fmt(end)];
}

function chartNumber(value, decimals, unit) {
  const places = Math.max(0, Math.min(3, Number(decimals) || 0));
  const text = Number(value).toFixed(places).replace(".", ",");
  return unit ? `${text} ${unit}` : text;
}

function chartHtmlForTile(tile, live, options = {}) {
  const likeDisplay = Boolean(options.likeDisplay);
  const seriesList = chartSeriesFromTile(tile, live, !likeDisplay);
  const yesterday = live && Array.isArray(live.seriesYesterday) && chartSeriesUsable(live.seriesYesterday)
    ? normalizeChartSeries(live.seriesYesterday)
    : [];
  const boundsSeries = yesterday.length ? seriesList.concat([yesterday]) : seriesList;
  const bounds = chartBoundsForTile(tile, live, boundsSeries, likeDisplay);
  if ((tile.chartYMode || "auto") !== "fixed" && yesterday.length) {
    const extra = chartSeriesMinMax(yesterday);
    bounds.min = Math.min(bounds.min, extra.min);
    bounds.max = Math.max(bounds.max, extra.max);
  }
  const style = tile.chartStyle || "area";
  return chartSvgMulti(seriesList, style, bounds.min, bounds.max, yesterday, chartEmptyText(live && live.chartNote), tile);
}

function chartSvgMulti(seriesList, style, min, max, yesterday, emptyText, tile) {
  const empty = emptyText || "Keine Verlaufsdaten";
  if (!seriesList || !seriesList.length) return `<div class="chart-empty">${empty}</div>`;
  const w = 100;
  const h = 36;
  const range = max - min || 1;
  const color = chartColorOf(tile);
  const line = (series, className, stroke) => {
    if (!series || series.length < 2) return "";
    const points = series.map((v, i) => {
      const x = (i / (series.length - 1)) * w;
      const y = h - 2 - ((v - min) / range) * (h - 4);
      return `${x.toFixed(2)},${y.toFixed(2)}`;
    }).join(" ");
    const ink = stroke ? ` style="stroke:${stroke}"` : "";
    return `<polyline class="${className}" points="${points}"${ink}></polyline>`;
  };
  const primary = seriesList[0] || [];
  let area = "";
  if (style === "area" && primary.length >= 2) {
    const pts = primary.map((v, i) => {
      const x = (i / (primary.length - 1)) * w;
      const y = h - 2 - ((v - min) / range) * (h - 4);
      return `${x.toFixed(2)},${y.toFixed(2)}`;
    }).join(" ");
    area = `<polygon class="oh-chart-area" points="0,${h} ${pts} ${w},${h}" style="fill:${color}"></polygon>`;
  }
  let grid = "";
  if (tile && tile.chartShowGrid) {
    for (let step = 0; step < 3; step++) {
      const y = (step / 2) * h;
      const x = (step / 2) * w;
      grid += `<line class="oh-chart-grid" x1="0" y1="${y}" x2="${w}" y2="${y}"/>`;
      grid += `<line class="oh-chart-grid" x1="${x}" y1="0" x2="${x}" y2="${h}"/>`;
    }
  }
  const extras = seriesList.slice(1).map((s) => line(s, "oh-chart-line oh-chart-line-alt")).join("");
  const yesterdayLine = yesterday && yesterday.length >= 2 ? line(yesterday, "oh-chart-line oh-chart-yesterday") : "";
  const primaryLine = line(primary, "oh-chart-line", color);
  if (!primaryLine && !extras && !yesterdayLine) {
    return `<div class="chart-empty">${empty}</div>`;
  }
  const svg = `<svg class="oh-chart-svg" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none">${grid}${area}${yesterdayLine}${primaryLine}${extras}</svg>`;
  if (!tile || !tile.chartShowAxes) return `<div class="oh-chart-frame"><div class="oh-chart-plot">${svg}</div></div>`;
  const decimals = tile.decimals != null ? tile.decimals : 1;
  const unit = tile.unit || "";
  const yLabels = [chartNumber(max, decimals, unit), chartNumber((min + max) / 2, decimals, ""), chartNumber(min, decimals, "")];
  const xLabels = chartAxisTimes(tile.chartPeriod || "24h");
  return `<div class="oh-chart-frame with-axes"><div class="oh-chart-y">${yLabels.map((label) => `<span>${escapeHtml(label)}</span>`).join("")}</div><div class="oh-chart-plot">${svg}</div><div class="oh-chart-x">${xLabels.map((label) => `<span>${escapeHtml(label)}</span>`).join("")}</div></div>`;
}

function paintChartTileContent(element, tile, live, mode) {
  if (!element || !tile || tile.kind !== "chart") return;
  const variable = variableById(tile.variableId);
  const label = tile.title || (variable ? variable.name : "Diagramm");
  const stateText = live && live.text ? escapeHtml(live.text) : escapeHtml(tile.unit ? `12,3 ${tile.unit}` : "—");
  const chartHtml = chartHtmlForTile(tile, live, { likeDisplay: true });
  const valueText = chartShowValue(tile) ? stateText : "";
  element.innerHTML = `<div class="display-chart">${ohCardMarkup(label, valueText, chartHtml, "", tile)}</div>`;
}

function variableArchived(id) {
  const v = variableById(id);
  return v ? v.archived !== false : true;
}

function syncInteractionPropertyPanels() {
  const interaction = $("p-interaction");
  if (!interaction) return;
  const tile = selectedTile();
  if (!tile || tile.kind !== "value") return;
  const mode = interaction.value;
  const plain = mode === "" || mode === "battery";
  $("p-icon-static")?.classList.toggle("hidden", mode === "switch" || mode === "battery" || mode === "combo");
  $("p-switch-wrap")?.classList.toggle("hidden", mode !== "switch");
  $("p-icon-toggle")?.classList.toggle("hidden", mode !== "switch" || !$("p-show-icon")?.checked);
  $("p-value-style")?.classList.toggle("hidden", mode !== "");
  $("p-battery-wrap")?.classList.toggle("hidden", mode !== "battery");
  const hintEl = $("p-control-hint");
  if (hintEl) {
    const hint = INTERACTION_HINTS[mode] || "";
    hintEl.textContent = hint;
    hintEl.classList.toggle("hidden", !hint);
  }
  $("p-interaction")?.closest("label")?.classList.toggle("hidden", mode === "");
  const actionLabel = $("p-action")?.closest("label");
  if (actionLabel) actionLabel.classList.toggle("hidden", mode === "" || mode === "battery");
  $("p-confirm")?.closest("label")?.classList.toggle("hidden", mode === "");
  if ($("p-value-icon")) $("p-value-icon").disabled = ($("p-icon-place")?.value || "") === "none";
  if ($("p-title-icon")) $("p-title-icon").disabled = !$("p-show-title-icon")?.checked;
  if (DIM_CONTROLS.includes(mode)) {
    const variable = variableById(tile.variableId);
    if (variable && variable.action && $("p-action")) $("p-action").checked = true;
  }
  if (mode === "blinds") {
    const variable = variableById(tile.variableId);
    if (variable && variable.action && $("p-action")) $("p-action").checked = true;
  }
  $("p-setpoint-wrap")?.classList.toggle("hidden", mode !== "setpoint");
  $("p-dim-wrap")?.classList.toggle("hidden", !(mode === "combo" || mode === "blinds"));
  const both = mode === "combo" && $("p-dim-show-switch")?.checked && $("p-dim-show-bar")?.checked;
  $("p-dim-layout-wrap")?.classList.toggle("hidden", !both);
  $("p-dim-tap-wrap")?.classList.toggle("hidden", mode !== "combo" && mode !== "blinds");
  $("p-value-format")?.classList.toggle("hidden", mode === "switch");
  $("p-own-on-wrap")?.classList.toggle("hidden", mode !== "switch" && mode !== "combo");
  $("p-level-wrap")?.classList.toggle("hidden", mode !== "switch" && mode !== "combo");
  const bgLabel = $("p-own-bg-label");
  if (bgLabel) bgLabel.textContent = mode === "switch" || mode === "combo" ? "Farbe Aus" : "Eigene Farbe";
  if (plain) { /* Anzeige ohne Schaltergrafik */ }
}
const TYPE_NAMES = ["Ja/Nein", "Ganzzahl", "Dezimalzahl", "Text"];
const MEDIA_ROLES = {
  mediaId: "Medien-ID",
  imageUrl: "Bild-URL",
  mediaObject: "Medienobjekt"
};

function isMediaObject(item) {
  return Boolean(item && item.objectKind === "media");
}

function isMediaPick(item) {
  return Boolean(item && (item.mediaRole === "mediaId" || item.mediaRole === "mediaObject" || isMediaObject(item)));
}

function isImageUrlRef(item) {
  return Boolean(item && item.mediaRole === "imageUrl");
}

function variableTypeLabel(variable) {
  if (isMediaObject(variable)) return MEDIA_ROLES.mediaObject;
  if (variable.mediaRole && MEDIA_ROLES[variable.mediaRole]) return MEDIA_ROLES[variable.mediaRole];
  return TYPE_NAMES[Number(variable.type)] || "";
}

function bindMediaToTile(tile, item, kind) {
  if (kind === "image") {
    tile.imageSource = "media";
    delete tile.imageId;
  }
  if (kind === "camera") {
    tile.cameraSource = isMediaPick(item) || isMediaObject(item) ? "media" : "url";
  }
  if (isMediaObject(item)) {
    tile.mediaObjectId = item.mediaId || item.id;
    delete tile.variableId;
  } else {
    tile.variableId = item.id;
    delete tile.mediaObjectId;
  }
}

function treeItemById(id) {
  const n = Number(id);
  if (!n) return null;
  return state.variables.find((item) => item.id === n || item.mediaId === n) || null;
}

function mediaBindingName(tile) {
  if (tile.mediaObjectId) {
    const obj = treeItemById(tile.mediaObjectId);
    return obj ? obj.name : `Medienobjekt ${tile.mediaObjectId}`;
  }
  const variable = variableById(tile.variableId);
  return variable ? variable.name : "";
}

function normalizeMediaTileBindings(tile) {
  if (!tile) return;
  if (tile.kind === "camera") {
    if (tile.mediaObjectId) {
      tile.cameraSource = "media";
    } else if (tile.variableId && isMediaPick(variableById(tile.variableId))) {
      tile.cameraSource = "media";
    }
  }
  if (tile.kind === "image" && tile.mediaObjectId) {
    tile.imageSource = "media";
  }
}

function tileNeedsLivePreview(tile) {
  if (!tile) return false;
  if (tile.kind === "camera" && (tile.variableId || tile.mediaObjectId)) return true;
  if (tile.kind === "image" && (tile.imageSource || "catalog") === "media" && (tile.variableId || tile.mediaObjectId)) return true;
  return false;
}

/** Prüfen-Dialog: Layout kommt von Inspect (publicLayout ohne Variablen-IDs). */
const state = {
  catalog: { layouts: [], configs: [] },
  variables: [],
  images: [],
  layout: null,
  page: "start",
  selected: null,
  tileClipboard: null,
  styleSource: null,
  dirty: false,
  pickedVariable: null,
  variableTreeOpen: {},
  variableTreeScroll: 0,
  editorIconDemo: {},
  panelProfiles: {},
  previewPanelId: 0,
  tilePreviewUrls: {},
  tilePreviewErrors: {},
  tilePreviewErrorDetails: {},
  previewTimer: null,
  previewFetchToken: 0
};

const $ = (id) => document.getElementById(id);

async function call(method, extra) {
  const response = await fetch("api", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ method, ...(extra || {}) })
  });
  const text = await response.text();
  let body;
  try {
    body = JSON.parse(text);
  } catch (error) {
    throw new Error("Die Verwaltung hat keine gültige Antwort geliefert. Instanz „Hausanzeige Verwaltung“ im Objektbaum prüfen.");
  }
  if (!body || !body.ok) throw new Error((body && body.error) || "Aufruf fehlgeschlagen");
  return body.result;
}

function setStatus(text) {
  $("status").textContent = text;
}

function setHint(text) {
  $("hint").textContent = text;
}

function pageTitle(id) {
  const found = PAGES.find((item) => item[0] === id);
  return found ? found[1] : id;
}

/* ---------- Raster je Seite ---------- */

const DEFAULT_GRID_COLUMNS = 4;
const DEFAULT_GRID_ROWS = 5;
const MAX_PAGES = 8;

/* Spalten, Zeilen und Anteile (1 bis 3) einer Seite; fehlende Werte werden ergänzt. Alte Ansichten tragen das Raster an der Ansicht. */
function normalizePageGrid(page, layout) {
  const prof = layout ? layoutProfile(layout) : DISPLAY_PROFILES[defaultProfileId()];
  const legacyCols = layout && layout.columns ? Number(layout.columns) : DEFAULT_GRID_COLUMNS;
  const legacyRows = layout && layout.rows ? Number(layout.rows) : DEFAULT_GRID_ROWS;
  page.columns = Math.min(prof.maxColumns, Math.max(1, Number(page.columns) || legacyCols));
  page.rows = Math.min(prof.maxRows, Math.max(1, Number(page.rows) || legacyRows));
  page.columnWeights = normalizeWeights(page.columnWeights, page.columns);
  page.rowWeights = normalizeWeights(page.rowWeights, page.rows);
}

function normalizeWeights(list, count) {
  const out = [];
  for (let index = 0; index < count; index += 1) {
    const weight = Array.isArray(list) ? Math.round(Number(list[index])) : NaN;
    out.push(weight >= 1 && weight <= 3 ? weight : 2);
  }
  return out;
}

function pageGridOf(page) {
  if (!page) return { columns: DEFAULT_GRID_COLUMNS, rows: DEFAULT_GRID_ROWS, columnWeights: normalizeWeights(null, DEFAULT_GRID_COLUMNS), rowWeights: normalizeWeights(null, DEFAULT_GRID_ROWS) };
  return {
    columns: page.columns,
    rows: page.rows,
    columnWeights: normalizeWeights(page.columnWeights, page.columns),
    rowWeights: normalizeWeights(page.rowWeights, page.rows)
  };
}

function currentGrid() {
  return pageGridOf(currentPage());
}

function gridTemplateOf(weights) {
  return weights.map((weight) => `minmax(0, ${weight}fr)`).join(" ");
}

/* Spalte oder Zeile unter einer Position, gemessen an den Anteilen. */
function trackIndexAt(position, length, weights) {
  const total = weights.reduce((sum, weight) => sum + weight, 0) || 1;
  let edge = 0;
  for (let index = 0; index < weights.length; index += 1) {
    edge += weights[index];
    if (position < (length * edge) / total) return index;
  }
  return weights.length - 1;
}

function newPageId(pages) {
  const alphabet = "abcdefghijklmnopqrstuvwxyz0123456789";
  for (let attempt = 0; attempt < 50; attempt += 1) {
    let id = "s";
    for (let index = 0; index < 6; index += 1) id += alphabet[Math.floor(Math.random() * alphabet.length)];
    if (!pages.some((page) => page.id === id)) return id;
  }
  return `s${Date.now().toString(36)}`;
}

function newPage(layout, pages) {
  const page = { id: newPageId(pages), title: `Seite ${pages.length + 1}`, tiles: [] };
  normalizePageGrid(page, Object.assign({}, layout, { columns: DEFAULT_GRID_COLUMNS, rows: DEFAULT_GRID_ROWS }));
  return page;
}

function defaultProfileId() {
  return "waveshare-5b-1024x600";
}

function layoutProfile(layout) {
  const id = (layout && layout.displayProfile) || defaultProfileId();
  return DISPLAY_PROFILES[id] || DISPLAY_PROFILES[defaultProfileId()];
}

function profileLabel(profileId) {
  const meta = DISPLAY_PROFILES[profileId];
  return meta ? meta.label : (profileId || "–");
}

function scaleDisplayScreen(viewportEl, stageEl, screenEl, profile, retry = 0) {
  if (!viewportEl || !screenEl || !profile) return;
  const stage = stageEl || screenEl;
  const pad = 8;
  const availW = viewportEl.clientWidth - pad;
  const availH = viewportEl.clientHeight - pad;
  if ((availW < 48 || availH < 48) && retry < 8) {
    requestAnimationFrame(() => scaleDisplayScreen(viewportEl, stageEl, screenEl, profile, retry + 1));
    return;
  }
  screenEl.style.width = `${profile.width}px`;
  screenEl.style.height = `${profile.height}px`;
  const scale = Math.min(Math.max(availW, 1) / profile.width, Math.max(availH, 1) / profile.height, 1);
  if (stage !== screenEl) {
    stage.style.width = `${profile.width * scale}px`;
    stage.style.height = `${profile.height * scale}px`;
  }
  screenEl.style.transform = `scale(${scale})`;
  screenEl.style.marginBottom = "";
}

function scaleEditorViewport() {
  scaleDisplayScreen(
    $("editor-viewport"),
    $("editor-stage"),
    $("editor-screen"),
    state.layout ? layoutProfile(state.layout) : null
  );
}

const THEME_DEFAULTS = { background: "#102033", button: "#2a4f7c", accent: "#ffb547" };

function normalizeTheme(theme) {
  const colors = { ...THEME_DEFAULTS };
  if (!theme) return colors;
  for (const key of Object.keys(colors)) {
    const value = String(theme[key] || "").trim();
    if (/^#[0-9a-fA-F]{6}$/.test(value)) colors[key] = value.toLowerCase();
  }
  return colors;
}

function readThemeInputs() {
  return normalizeTheme({
    background: $("theme-bg")?.value,
    button: $("theme-button")?.value,
    accent: $("theme-accent")?.value,
  });
}

function applyTheme(theme) {
  const colors = normalizeTheme(theme);
  const root = document.documentElement;
  root.style.setProperty("--theme-bg", colors.background);
  root.style.setProperty("--theme-button", colors.button);
  root.style.setProperty("--theme-accent", colors.accent);
  if ($("theme-bg")) $("theme-bg").value = colors.background;
  if ($("theme-button")) $("theme-button").value = colors.button;
  if ($("theme-accent")) $("theme-accent").value = colors.accent;
  return colors;
}

function fillProfileSelect(select, value) {
  select.innerHTML = "";
  for (const [id, meta] of Object.entries(DISPLAY_PROFILES)) {
    const option = document.createElement("option");
    option.value = id;
    option.textContent = meta.label;
    select.appendChild(option);
  }
  if (value) select.value = value;
}

function applyGridAspect() {
  const profile = state.layout ? layoutProfile(state.layout) : DISPLAY_PROFILES[defaultProfileId()];
  document.documentElement.style.setProperty("--display-aspect", `${profile.width} / ${profile.height}`);
  const meta = $("display-profile-meta");
  if (meta) meta.textContent = `${profile.width}×${profile.height} px · Raster je Seite höchstens ${profile.maxColumns} Spalten × ${profile.maxRows} Zeilen`;
  scaleEditorViewport();
}

function tilePreviewStyle(tile) {
  const sw = tile.colSpan || 1;
  const sh = tile.rowSpan || 1;
  return { "--span-w": sw, "--span-h": sh };
}

function effectiveIcon(tile, liveValue, demoOn) {
  if (tile.kind !== "value" || tile.control === "battery") return tile.icon || "energy";
  if ((tile.iconMode || "static") === "toggle") {
    let on = demoOn;
    if (on === undefined && liveValue !== undefined && liveValue !== null) {
      if (typeof liveValue === "boolean") on = liveValue;
      else if (typeof liveValue === "number") on = valueMeansOn(tile, liveValue);
      else on = Boolean(liveValue);
    }
    return on ? (tile.iconOn || "lightOn") : (tile.iconOff || "lightOff");
  }
  return tile.icon || "energy";
}

function colorForTile(color) {
  if (color === "ok") return "#1e6b3a";
  if (color === "warn") return "#8a6a12";
  if (color === "alarm") return "#8a2424";
  return "#1d3350";
}

function currentPage() {
  if (!state.layout) return null;
  let page = state.layout.pages.find((item) => item.id === state.page);
  if (!page) {
    page = state.layout.pages[0];
    if (page) state.page = page.id;
  }
  return page || null;
}

function markDirty() {
  state.dirty = true;
  updateTitle();
}

function updateTitle() {
  const version = state.libraryVersion || "";
  const build = state.libraryBuild ? ` · Build ${state.libraryBuild}` : "";
  const flag = state.dirty ? " *" : "";
  $("title").textContent = version ? `${version}${build}${flag}` : "";
}

function fillTabButton(button, page, fallback) {
  button.textContent = "";
  if (page && page.icon) {
    const icon = document.createElement("span");
    icon.className = "tab-icon";
    icon.innerHTML = iconVisual(page.icon);
    button.appendChild(icon);
  }
  button.appendChild(document.createTextNode((page && (page.title || fallback)) || fallback || ""));
}

/* ---------- Menüs ---------- */

for (const button of document.querySelectorAll("#menubar .menu > button[data-menu]")) {
  button.addEventListener("click", (event) => {
    event.stopPropagation();
    const menu = button.parentElement;
    const open = menu.classList.contains("open");
    closeMenus();
    if (!open) menu.classList.add("open");
  });
}
document.addEventListener("click", closeMenus);
function closeMenus() {
  for (const menu of document.querySelectorAll("#menubar .menu.open")) menu.classList.remove("open");
}

/* ---------- Ansichten ---------- */

function renderLayoutSelect() {
  const select = $("layout-select");
  select.innerHTML = "";
  for (const layout of state.catalog.layouts) {
    const option = document.createElement("option");
    option.value = layout.id;
    option.textContent = `${layout.name} v${layout.version || 0}${layout.published ? "" : " (Entwurf)"}`;
    select.appendChild(option);
  }
  if (state.layout && state.layout.id) select.value = state.layout.id;
}

function selectLayout(layout, keepPage) {
  const previousPage = keepPage ? state.page : null;
  state.layout = JSON.parse(JSON.stringify(layout));
  state.layout.baseVersion = layout.version || 0;
  state.layout.displayProfile = state.layout.displayProfile || defaultProfileId();
  if (state.layout.showTabs === undefined) state.layout.showTabs = true;
  if (state.layout.colorMode === undefined) state.layout.colorMode = "color";
  state.layout.theme = applyTheme(state.layout.theme);
  migrateLayoutKinds(state.layout);
  state.layout.pages = (state.layout.pages || []).filter((page) => page && /^[a-z0-9_-]{1,24}$/.test(String(page.id || ""))).slice(0, MAX_PAGES);
  if (!state.layout.pages.length) state.layout.pages = [{ id: "start", title: "Start", tiles: [] }];
  for (const page of state.layout.pages) {
    /* Alte Ansichten: das Raster der Ansicht gilt für jede Seite ohne eigenes Raster. */
    normalizePageGrid(page, state.layout);
    page.tiles = page.tiles || [];
    for (const tile of page.tiles) {
      tile.kind = tile.kind || "value";
      normalizeTile(tile);
    }
  }
  state.page = previousPage && state.layout.pages.some((page) => page.id === previousPage)
    ? previousPage
    : state.layout.pages[0].id;
  state.selected = null;
  state.dirty = false;
  delete state.layout.columns;
  delete state.layout.rows;
  $("layout-name").value = state.layout.name || "";
  $("show-tabs").checked = state.layout.showTabs;
  if ($("color-mono")) {
    $("color-mono").checked = state.layout.colorMode === "mono";
    document.getElementById("editor-screen")?.classList.toggle("mono", state.layout.colorMode === "mono");
  }
  fillProfileSelect($("display-profile"), state.layout.displayProfile);
  applyGridAspect();
  renderLayoutSelect();
  renderPageTabs();
  renderGrid();
  renderProperties();
  updateTitle();
}

function newLayout(name) {
  selectLayout({
    id: "", name: name || "Neue Ansicht", version: 0, published: false,
    displayProfile: defaultProfileId(),
    showTabs: true, pages: [{ id: "start", title: "Start", columns: DEFAULT_GRID_COLUMNS, rows: DEFAULT_GRID_ROWS, tiles: [] }]
  });
  markDirty();
}

/* ---------- Seiten ---------- */

function renderPageTabs() {
  const bar = $("page-tabs");
  bar.innerHTML = "";
  if (!state.layout) return;
  for (const page of state.layout.pages) {
    const button = document.createElement("button");
    button.type = "button";
    fillTabButton(button, page, pageTitle(page.id));
    if (page.id === state.page) button.classList.add("active");
    button.addEventListener("click", () => {
      state.page = page.id;
      state.selected = null;
      renderPageTabs();
      renderGrid();
      renderProperties();
    });
    button.addEventListener("dblclick", () => openPagesDialog());
    bar.appendChild(button);
  }
  if (state.layout.pages.length < MAX_PAGES) {
    const add = document.createElement("button");
    add.type = "button";
    add.className = "add";
    add.textContent = "+ Seite";
    add.addEventListener("click", () => openPagesDialog(true));
    bar.appendChild(add);
  }
  const menu = $("menu-pages");
  menu.innerHTML = "";
  for (const page of state.layout.pages) {
    const item = document.createElement("button");
    item.type = "button";
    item.textContent = page.title || pageTitle(page.id);
    item.addEventListener("click", () => {
      state.page = page.id;
      renderPageTabs();
      renderGrid();
    });
    menu.appendChild(item);
  }
  const hr = document.createElement("hr");
  menu.appendChild(hr);
  const edit = document.createElement("button");
  edit.type = "button";
  edit.textContent = "Seiten verwalten …";
  edit.addEventListener("click", () => openPagesDialog());
  menu.appendChild(edit);
}

/* ---------- Raster ---------- */

function pageBackgroundImageUrl(page) {
  if (!page || !page.backgroundId) return "";
  const fromCatalog = state.images.find((item) => item.id === page.backgroundId);
  if (fromCatalog) return `images/${fromCatalog.id}.${fromCatalog.ext}`;
  if (page.backgroundFile) return `images/${page.backgroundFile}`;
  return "";
}

function applyPageBackground(element, page) {
  if (!element) return;
  const url = pageBackgroundImageUrl(page);
  if (url) {
    element.style.backgroundImage = `url("${url}")`;
    element.style.backgroundSize = "cover";
    element.style.backgroundPosition = "center";
  } else {
    element.style.backgroundImage = "";
    element.style.backgroundSize = "";
    element.style.backgroundPosition = "";
  }
}

function clearEditorGridBackground() {
  const grid = $("grid");
  if (!grid) return;
  grid.style.backgroundImage = "";
  grid.style.backgroundSize = "";
  grid.style.backgroundPosition = "";
}

function renderPageBackground() {
  const select = $("page-background");
  if (!select) return;
  const page = currentPage();
  fillImageSelect(select);
  select.value = page && page.backgroundId ? page.backgroundId : "";
  select.disabled = !page;
  const iconSel = $("page-icon");
  if (iconSel) {
    const cur = page && page.icon ? page.icon : "";
    iconSel.innerHTML = '<option value="">(kein Symbol)</option>';
    for (const icon of ICONS) {
      const o = document.createElement("option");
      o.value = icon;
      o.textContent = icon;
      iconSel.appendChild(o);
    }
    iconSel.value = cur;
    iconSel.disabled = !page;
  }
}

function isBackgroundImage(tile) {
  return Boolean(tile && tile.kind === "image" && tile.imageBackground === true);
}

function rectsOverlap(a, b) {
  const aw = a.colSpan || 1;
  const ah = a.rowSpan || 1;
  const bw = b.colSpan || 1;
  const bh = b.rowSpan || 1;
  return a.col < b.col + bw && b.col < a.col + aw && a.row < b.row + bh && b.row < a.row + ah;
}

function overlapAllowed(a, b) {
  if (!a || !b) return false;
  const background = isBackgroundImage(a) ? a : (isBackgroundImage(b) ? b : null);
  const other = background === a ? b : a;
  return Boolean(background) && other.kind === "value";
}

function tileAt(col, row) {
  const page = currentPage();
  if (!page) return null;
  const hits = page.tiles.filter((tile) => col >= tile.col && col < tile.col + (tile.colSpan || 1) && row >= tile.row && row < tile.row + (tile.rowSpan || 1));
  return hits.find((tile) => !isBackgroundImage(tile)) || hits[0] || null;
}

function fits(tile, col, row, colSpan, rowSpan, ignoreId) {
  const page = currentPage();
  const grid = pageGridOf(page);
  if (col < 0 || row < 0 || col + colSpan > grid.columns || row + rowSpan > grid.rows) return false;
  const moving = tile || { col, row, colSpan, rowSpan, kind: "" };
  for (const other of page.tiles) {
    if (other.tileId === ignoreId) continue;
    const overlapX = col < other.col + (other.colSpan || 1) && other.col < col + colSpan;
    const overlapY = row < other.row + (other.rowSpan || 1) && other.row < row + rowSpan;
    if (!overlapX || !overlapY) continue;
    const candidate = tile || moving;
    if (tile && overlapAllowed(candidate, other)) continue;
    return false;
  }
  return true;
}

function freeCell(colSpan, rowSpan) {
  const grid = currentGrid();
  for (let row = 0; row < grid.rows; row += 1) {
    for (let col = 0; col < grid.columns; col += 1) {
      if (fits(null, col, row, colSpan || 1, rowSpan || 1, null)) return { col, row };
    }
  }
  return null;
}

function rangesOverlap(a0, a1, b0, b1) {
  return a0 < b1 && b0 < a1;
}

function tilesShareEdge(a, b) {
  const aRight = a.col + (a.colSpan || 1);
  const aBottom = a.row + (a.rowSpan || 1);
  const bRight = b.col + (b.colSpan || 1);
  const bBottom = b.row + (b.rowSpan || 1);
  const side = (aRight === b.col || bRight === a.col) && rangesOverlap(a.row, aBottom, b.row, bBottom);
  const end = (aBottom === b.row || bBottom === a.row) && rangesOverlap(a.col, aRight, b.col, bRight);
  return side || end;
}

function groupBlocks(tiles) {
  const members = (tiles || []).filter((tile) => tile.group && String(tile.group).trim() !== "");
  const parent = members.map((_, index) => index);
  const find = (index) => {
    let cursor = index;
    while (parent[cursor] !== cursor) cursor = parent[cursor];
    return cursor;
  };
  for (let i = 0; i < members.length; i += 1) {
    for (let j = i + 1; j < members.length; j += 1) {
      if (String(members[i].group) !== String(members[j].group)) continue;
      if (!tilesShareEdge(members[i], members[j])) continue;
      parent[find(j)] = find(i);
    }
  }
  const blocks = new Map();
  members.forEach((tile, index) => {
    const root = find(index);
    if (!blocks.has(root)) blocks.set(root, []);
    blocks.get(root).push(tile);
  });
  return [...blocks.values()];
}

function groupOuterSides(tile, tiles) {
  if (!tile || !tile.group) return null;
  const block = groupBlocks(tiles).find((item) => item.some((member) => member.tileId === tile.tileId));
  if (!block) return null;
  const others = block.filter((member) => member.tileId !== tile.tileId);
  const right = tile.col + (tile.colSpan || 1);
  const bottom = tile.row + (tile.rowSpan || 1);
  const touches = (side) => others.some((other) => {
    const oRight = other.col + (other.colSpan || 1);
    const oBottom = other.row + (other.rowSpan || 1);
    if (side === "left") return oRight === tile.col && rangesOverlap(tile.row, bottom, other.row, oBottom);
    if (side === "right") return other.col === right && rangesOverlap(tile.row, bottom, other.row, oBottom);
    if (side === "top") return oBottom === tile.row && rangesOverlap(tile.col, right, other.col, oRight);
    return other.row === bottom && rangesOverlap(tile.col, right, other.col, oRight);
  });
  return { left: !touches("left"), right: !touches("right"), top: !touches("top"), bottom: !touches("bottom") };
}

function paintGroupFrames(grid, tiles) {
  for (const block of groupBlocks(tiles)) {
    const cells = [];
    for (const tile of block) {
      for (let row = tile.row; row < tile.row + (tile.rowSpan || 1); row += 1) {
        for (let col = tile.col; col < tile.col + (tile.colSpan || 1); col += 1) cells.push({ col, row });
      }
    }
    if (!cells.length) continue;
    const key = new Set(cells.map((cell) => `${cell.col}:${cell.row}`));
    const minC = Math.min(...cells.map((cell) => cell.col));
    const maxC = Math.max(...cells.map((cell) => cell.col));
    const minR = Math.min(...cells.map((cell) => cell.row));
    const maxR = Math.max(...cells.map((cell) => cell.row));
    const rectangular = cells.length === (maxC - minC + 1) * (maxR - minR + 1);
    if (rectangular) {
      const frame = document.createElement("div");
      frame.className = "group-frame rect";
      frame.style.gridColumn = `${minC + 1} / span ${maxC - minC + 1}`;
      frame.style.gridRow = `${minR + 1} / span ${maxR - minR + 1}`;
      grid.appendChild(frame);
      continue;
    }
    for (const cell of cells) {
      const frame = document.createElement("div");
      frame.className = "group-frame";
      frame.style.gridColumn = `${cell.col + 1}`;
      frame.style.gridRow = `${cell.row + 1}`;
      const open = {
        left: !key.has(`${cell.col - 1}:${cell.row}`),
        right: !key.has(`${cell.col + 1}:${cell.row}`),
        top: !key.has(`${cell.col}:${cell.row - 1}`),
        bottom: !key.has(`${cell.col}:${cell.row + 1}`)
      };
      frame.style.borderLeftWidth = open.left ? "2px" : "0";
      frame.style.borderRightWidth = open.right ? "2px" : "0";
      frame.style.borderTopWidth = open.top ? "2px" : "0";
      frame.style.borderBottomWidth = open.bottom ? "2px" : "0";
      if (open.left && open.top) frame.style.borderTopLeftRadius = "8px";
      if (open.right && open.top) frame.style.borderTopRightRadius = "8px";
      if (open.left && open.bottom) frame.style.borderBottomLeftRadius = "8px";
      if (open.right && open.bottom) frame.style.borderBottomRightRadius = "8px";
      grid.appendChild(frame);
    }
  }
}

function valueOverBackground(tile, tiles) {
  if (!tile || tile.kind !== "value") return false;
  return (tiles || []).some((other) => isBackgroundImage(other) && rectsOverlap(tile, other));
}

function applyTileLayer(element, tile, tiles) {
  const sides = groupOuterSides(tile, tiles);
  if (sides) {
    if (sides.left) element.style.marginLeft = "8px";
    if (sides.right) element.style.marginRight = "8px";
    if (sides.top) element.style.marginTop = "8px";
    if (sides.bottom) element.style.marginBottom = "8px";
  }
  if (isBackgroundImage(tile)) element.classList.add("as-background");
  if (valueOverBackground(tile, tiles)) element.classList.add("tile-ghost");
}

function cellUnderPointer(event, grid) {
  if (!event || !grid || !state.layout) return null;
  const rect = grid.getBoundingClientRect();
  if (rect.width < 1 || rect.height < 1) return null;
  const layoutGrid = currentGrid();
  const col = trackIndexAt(Math.max(0, event.clientX - rect.left), rect.width, layoutGrid.columnWeights);
  const row = trackIndexAt(Math.max(0, event.clientY - rect.top), rect.height, layoutGrid.rowWeights);
  return { col, row };
}

function renderGrid() {
  const grid = $("grid");
  grid.innerHTML = "";
  if (!state.layout) return;
  const page = currentPage();
  const pageGrid = pageGridOf(page);
  grid.style.gridTemplateColumns = gridTemplateOf(pageGrid.columnWeights);
  grid.style.gridTemplateRows = gridTemplateOf(pageGrid.rowWeights);
  clearEditorGridBackground();
  const covered = new Set();
  for (const tile of page.tiles) {
    for (let x = tile.col; x < tile.col + (tile.colSpan || 1); x += 1) {
      for (let y = tile.row; y < tile.row + (tile.rowSpan || 1); y += 1) covered.add(`${x}:${y}`);
    }
  }
  for (let row = 0; row < pageGrid.rows; row += 1) {
    for (let col = 0; col < pageGrid.columns; col += 1) {
      if (covered.has(`${col}:${row}`)) continue;
      const cell = document.createElement("div");
      cell.className = "cell";
      cell.textContent = `${col + 1} / ${row + 1}`;
      cell.style.gridColumn = `${col + 1}`;
      cell.style.gridRow = `${row + 1}`;
      bindDrop(cell, col, row);
      cell.addEventListener("click", () => {
        state.selected = null;
        renderGrid();
        renderProperties();
      });
      grid.appendChild(cell);
    }
  }
  paintGroupFrames(grid, page.tiles);
  const ordered = [...page.tiles].sort((a, b) => (isBackgroundImage(a) ? 0 : 1) - (isBackgroundImage(b) ? 0 : 1));
  for (const tile of ordered) grid.appendChild(renderTile(tile));
  scheduleEditorTilePreviews();
  observeTileFitRoot(grid);
  scheduleFitTileText(grid);
  scaleEditorViewport();
}

function scheduleEditorTilePreviews() {
  if (state.previewTimer) clearTimeout(state.previewTimer);
  state.previewTimer = setTimeout(() => {
    refreshEditorTilePreviews().catch(() => {});
  }, 350);
}

async function resolvePreviewPanelId() {
  if (state.previewPanelId) return state.previewPanelId;
  try {
    const settings = await call("settings");
    const id = settings.panels && settings.panels[0] ? Number(settings.panels[0].id) : 0;
    if (id) state.previewPanelId = id;
    return id;
  } catch (error) {
    return 0;
  }
}

async function refreshEditorTilePreviews() {
  if (!state.layout) return;
  const page = currentPage();
  if (!page) return;
  const targets = page.tiles.filter(tileNeedsLivePreview);
  const panelId = await resolvePreviewPanelId();
  const token = ++state.previewFetchToken;
  for (const tile of targets) {
    try {
      normalizeMediaTileBindings(tile);
      if (!panelId) {
        state.tilePreviewErrors[tile.tileId] = "camera";
        state.tilePreviewErrorDetails[tile.tileId] = "Keine Hausanzeige-Instanz im System – bitte zuerst eine Hausanzeige anlegen.";
        continue;
      }
      const result = await call("probeImage", { id: panelId, tileId: tile.tileId, tile });
      if (token !== state.previewFetchToken) return;
      if (result.ok && result.data) {
        state.tilePreviewUrls[tile.tileId] = `data:${result.mime || "image/jpeg"};base64,${result.data}`;
        delete state.tilePreviewErrors[tile.tileId];
        delete state.tilePreviewErrorDetails[tile.tileId];
      } else {
        delete state.tilePreviewUrls[tile.tileId];
        state.tilePreviewErrors[tile.tileId] = result.error || "unknown";
        if (result.detail) state.tilePreviewErrorDetails[tile.tileId] = result.detail;
        else delete state.tilePreviewErrorDetails[tile.tileId];
      }
    } catch (error) {
      delete state.tilePreviewUrls[tile.tileId];
      state.tilePreviewErrors[tile.tileId] = "fetch";
      state.tilePreviewErrorDetails[tile.tileId] = error && error.message ? error.message : "";
    }
  }
  if (token !== state.previewFetchToken) return;
  const grid = $("grid");
  if (!grid) return;
  for (const tile of targets) {
    paintEditorLiveTile(grid.querySelector(`.tile[data-tile-id="${tile.tileId}"]`), tile);
  }
}

function gridCellFromPointer(grid, clientX, clientY) {
  const rect = grid.getBoundingClientRect();
  const layoutGrid = currentGrid();
  const col = trackIndexAt(Math.max(0, clientX - rect.left), rect.width, layoutGrid.columnWeights);
  const row = trackIndexAt(Math.max(0, clientY - rect.top), rect.height, layoutGrid.rowWeights);
  return { col, row };
}

function applyTileSpan(tile, col, row, colSpan, rowSpan) {
  if (!fits(tile, col, row, colSpan, rowSpan, tile.tileId)) return false;
  tile.col = col;
  tile.row = row;
  tile.colSpan = colSpan;
  tile.rowSpan = rowSpan;
  return true;
}

function bindResizeHandles(element, tile) {
  const modes = [
    ["se", (cell) => ({ col: tile.col, row: tile.row, colSpan: cell.col - tile.col + 1, rowSpan: cell.row - tile.row + 1 })],
    ["e", (cell) => ({ col: tile.col, row: tile.row, colSpan: cell.col - tile.col + 1, rowSpan: tile.rowSpan || 1 })],
    ["s", (cell) => ({ col: tile.col, row: tile.row, colSpan: tile.colSpan || 1, rowSpan: cell.row - tile.row + 1 })],
    ["w", (cell) => ({ col: cell.col, row: tile.row, colSpan: tile.col + (tile.colSpan || 1) - cell.col, rowSpan: tile.rowSpan || 1 })],
    ["n", (cell) => ({ col: tile.col, row: cell.row, colSpan: tile.colSpan || 1, rowSpan: tile.row + (tile.rowSpan || 1) - cell.row })]
  ];
  const grid = $("grid");
  for (const [mode, calc] of modes) {
    const handle = document.createElement("div");
    handle.className = `resize-handle resize-${mode}`;
    handle.addEventListener("pointerdown", (event) => {
      event.stopPropagation();
      event.preventDefault();
      element.draggable = false;
      handle.setPointerCapture(event.pointerId);
      let preview = grid.querySelector(".grid-resize-preview");
      if (!preview) {
        preview = document.createElement("div");
        preview.className = "grid-resize-preview";
        grid.appendChild(preview);
      }
      const move = (moveEvent) => {
        const cell = gridCellFromPointer(grid, moveEvent.clientX, moveEvent.clientY);
        let { col, row, colSpan, rowSpan } = calc(cell);
        if (event.shiftKey && mode === "se") {
          const side = Math.max(colSpan, rowSpan);
          colSpan = side;
          rowSpan = side;
        }
        colSpan = Math.max(1, colSpan);
        rowSpan = Math.max(1, rowSpan);
        if (!fits(tile, col, row, colSpan, rowSpan, tile.tileId)) return;
        preview.style.gridColumn = `${col + 1} / span ${colSpan}`;
        preview.style.gridRow = `${row + 1} / span ${rowSpan}`;
      };
      const end = (endEvent) => {
        handle.removeEventListener("pointermove", move);
        handle.removeEventListener("pointerup", end);
        element.draggable = true;
        if (preview.parentElement) preview.remove();
        const cell = gridCellFromPointer(grid, endEvent.clientX, endEvent.clientY);
        let { col, row, colSpan, rowSpan } = calc(cell);
        if (event.shiftKey && mode === "se") {
          const side = Math.max(colSpan, rowSpan);
          colSpan = side;
          rowSpan = side;
        }
        colSpan = Math.max(1, colSpan);
        rowSpan = Math.max(1, rowSpan);
        if (applyTileSpan(tile, col, row, colSpan, rowSpan)) {
          markDirty();
          renderGrid();
          renderProperties();
        } else setStatus("Größe passt nicht – Überlappung oder außerhalb des Rasters.");
      };
      handle.addEventListener("pointermove", move);
      handle.addEventListener("pointerup", end);
    });
    element.appendChild(handle);
  }
}

function buildTileElement(tile, context) {
  const mode = context.mode || "edit";
  const live = context.stateByTileId ? context.stateByTileId[tile.tileId] : null;
  const demoOn = state.editorIconDemo[tile.tileId];
  const element = document.createElement("div");
  const spanW = tile.colSpan || 1;
  const spanH = tile.rowSpan || 1;
  element.className = `tile ${tile.kind}${mode === "edit" && state.selected === tile.tileId ? " selected" : ""}`;
  element.style.gridColumn = `${tile.col + 1} / span ${spanW}`;
  element.style.gridRow = `${tile.row + 1} / span ${spanH}`;
  element.dataset.spanW = String(spanW);
  element.dataset.spanH = String(spanH);
  element.dataset.tileId = tile.tileId;
  Object.entries(tilePreviewStyle(tile)).forEach(([key, val]) => element.style.setProperty(key, val));
  if (tile.group) element.dataset.group = tile.group;
  applyTileStatus(element, live, tile);
  applyTileChrome(element, tile);
  element.draggable = mode === "edit";
  element.title = mode === "edit" ? `${KIND_NAMES[tile.kind]} – Doppelklick für Details` : "";
  if (tile.kind === "value") {
    element.classList.add("oh-style");
    const variable = variableById(tile.variableId);
    const interaction = tileInteractionMode(tile);
    const control = tile.control || "";
    if (DIM_CONTROLS.includes(control) || control === "blinds") element.classList.add("dim");
    if (control === "battery") {
      element.classList.add("battery");
      const labelPos = tile.batteryLabel || "inside";
      if (labelPos !== "inside") element.classList.add(`label-${labelPos}`, "label-outside");
    }
    if (control === "setpoint") element.classList.add("setpoint");
    const switchTile = interaction === "switch" && Boolean(tile.actionId);
    if (switchTile) {
      element.classList.add("as-switch");
      const on = liveIsOn(tile, live, demoOn);
      element.classList.toggle("on", on);
    }
    const showIcon = switchTile && tile.showIcon !== false;
    const dimTile = DIM_CONTROLS.includes(control) || (tile.actionKind === "dim" && control !== "setpoint");
    const iconId = showIcon ? effectiveIcon(Object.assign({}, tile, { iconMode: "toggle" }), live ? live.value : undefined, demoOn) : (dimTile ? "" : (tile.icon || ""));
    const kicker = INTERACTION_NAMES[interaction] || CONTROL_NAMES[control] || (interaction === "" ? "Wert" : `${iconName(iconId)}${tile.actionId ? " · schaltbar" : ""}`);
    const body = showIcon ? "" : controlMarkup(control, tile, live, demoOn);
    const displayText = live && live.text ? live.text : (variable ? "12,3" : "—");
    const label = tile.title || (variable ? variable.name : "Wert");
    let on = element.classList.contains("on");
    if (switchTile) {
      element.innerHTML = panelSwitchHtml(tile, label, on, iconId, showIcon);
    } else {
      const stateLine = switchTile
        ? (tile.showStateText ? (on ? "Ein" : "Aus") : "")
        : (interaction === "" ? "" : (escapeHtml(displayText) + (body ? "" : (tile.unit ? " " + escapeHtml(tile.unit) : ""))));
      let cardBody;
      if (showIcon) {
        cardBody = `<button type="button" class="oh-icon-toggle" data-icon-toggle aria-pressed="${on ? "true" : "false"}">${iconVisual(iconId, on, tile.onColor)}</button>`;
      } else if (interaction === "") {
        const align = tile.titleAlign === "center" ? "align-center" : (tile.titleAlign === "right" ? "align-right" : "align-left");
        const core = live && live.text ? live.text : (!variable ? "—" : ((variable.type === 1 || variable.type === 2) ? "12,3" : "Ein"));
        const readout = live && live.text ? core : `${tile.prefix ? tile.prefix + " " : ""}${core}${tile.unit ? " " + tile.unit : ""}`;
        const bits = [];
        if (tile.textColor) bits.push(`color:${tile.textColor}`);
        if (tile.textItalic) bits.push("font-style:italic");
        if (tile.textUnderline) bits.push("text-decoration:underline");
        if (textPxOf(tile)) bits.push(`font-size:${textPxOf(tile)}px`);
        if (tile.textBold) bits.push("font-weight:700");
        const readoutStyle = bits.length ? ` style="${bits.join(";")}"` : "";
        const iconStyle = tile.textColor ? ` style="color:${tile.textColor}"` : "";
        const place = tile.iconPlace === "after" ? "after" : (tile.iconPlace === "none" || !iconId ? "none" : "before");
        const headingId = titleIconShown(tile) ? titleIconId(tile) : "";
        const headingIcon = headingId ? `<span class="value-title-icon">${iconVisual(headingId)}</span>` : "";
        const titleHtml = tile.showTitle === false ? "" : `<div class="value-badge-title ${align}"${cssFont(titlePxOf(tile), tile.titleBold, tile.titleItalic)}>${headingIcon}<span class="value-title-text">${escapeHtml(label)}</span></div>`;
        const valueIcon = place === "none" ? "" : `<span class="value-inline-icon"${iconStyle}>${iconVisual(iconId)}</span>`;
        const readoutInner = place === "after"
          ? `<span class="value-readout-text">${escapeHtml(readout)}</span>${valueIcon}`
          : `${valueIcon}<span class="value-readout-text">${escapeHtml(readout)}</span>`;
        const ageHtml = tile.showAge ? `<div class="age-line">${escapeHtml((live && live.age) || "vor 4 min")}</div>` : "";
        element.innerHTML = `${editorKicker(mode, kicker)}<div class="value-badge">${titleHtml}<div class="value-badge-body"><div class="value-readout-row"><div class="value-readout"${readoutStyle}>${readoutInner}</div>${ageHtml}</div></div></div>`;
        cardBody = null;
      } else {
        cardBody = body || "";
      }
      if (cardBody !== null) {
        element.innerHTML = `${editorKicker(mode, kicker)}<div class="tile-scale">${ohCardMarkup(label, stateLine, cardBody, "", tile)}</div>`;
      }
    }
  } else if (tile.kind === "nav") {
    const navIcon = tile.showTitle === false ? "" : (tile.icon ? `<div class="tile-fit-icon">${iconVisual(tile.icon)}</div>` : "");
    const align = tile.titleAlign === "center" ? "center" : (tile.titleAlign === "right" ? "right" : "left");
    element.innerHTML = `${editorKicker(mode, `${tile.icon ? iconName(tile.icon) + " · " : ""}Seite`)}<div class="tile-scale" style="text-align:${align}">${navIcon}<div class="name"></div></div>`;
    const navName = element.querySelector(".name");
    navName.textContent = tile.showTitle === false ? "" : (tile.title || pageTitleIn(tile.targetPage));
    navName.style.fontSize = `${titlePxOf(tile)}px`;
    if (tile.titleBold) navName.style.fontWeight = "700";
    if (tile.titleItalic) navName.style.fontStyle = "italic";
  } else if (tile.kind === "label") {
    const textClass = labelTextClass(tile);
    const text = escapeHtml(tile.text || "Text");
    if ((tile.labelStyle || "text") === "section") {
      element.classList.add("label-section");
      element.innerHTML = `<div class="section-head ${textClass}">${text}</div>`;
    } else if (textPxOf(tile)) {
      element.innerHTML = `<div class="label-fixed"${cssFont(textPxOf(tile), tile.textBold, tile.textItalic, tile.textUnderline)}>${text}</div>`;
    } else {
      element.innerHTML = scaledTileHtml(tile.text || "Text", "", "");
      const fit = element.querySelector(".tile-fit-text");
      if (fit) fit.className = `tile-fit-text ${textClass}`;
    }
  } else if (tile.kind === "display") {
    element.classList.add("oh-style");
    const variable = variableById(tile.variableId);
    const label = tile.title || (tile.displaySource === "text" ? "Text" : (variable ? variable.name : "Anzeige"));
    const stateText = escapeHtml(displayMainText(tile, live));
    const body = tile.icon ? `<div class="oh-card-icon">${iconVisual(tile.icon)}</div>` : "";
    element.innerHTML = `${editorKicker(mode, "Anzeige")}<div class="tile-scale">${ohCardMarkup(label, stateText, body, "", tile)}</div>`;
  } else if (tile.kind === "chart") {
    element.classList.add("chart");
    const periodKey = tile.chartPeriod || "24h";
    const periodLabel = CHART_PERIOD_LABELS[periodKey] || periodKey;
    element.innerHTML = `${editorKicker(mode, `Diagramm · ${periodLabel}`)}<div class="tile-scale"></div>`;
    paintChartTileContent(element, tile, live, mode);
  } else if (tile.kind === "energyflow") {
    element.classList.add("energyflow");
    element.innerHTML = buildEnergyFlowHtml(tile, live, mode);
  } else if (tile.kind === "scene") {
    element.classList.add("oh-style", "scene");
    const label = tile.title || "Szene";
    const steps = Array.isArray(tile.sceneSteps) ? tile.sceneSteps.filter((step) => step.variableId) : [];
    const showIcon = tile.showIcon !== false;
    let on = Boolean(demoOn);
    if (live && typeof live.value === "boolean") on = live.value;
    element.classList.toggle("as-switch", Boolean(tile.actionId));
    element.classList.toggle("on", Boolean(tile.actionId) && on);
    const iconId = showIcon ? (on ? (tile.iconOn || "lightOn") : (tile.iconOff || "lightOff")) : "";
    const stateText = tile.showStateText ? (on ? "Ein" : "Aus") : (tile.actionId ? "Tippen" : "—");
    const cardBody = showIcon
      ? `<button type="button" class="oh-icon-toggle" data-icon-toggle aria-pressed="${on ? "true" : "false"}">${iconVisual(iconId, on, tile.onColor)}</button>`
      : "";
    const kicker = steps.length ? `Szene · ${steps.length} Lampen` : "Szene";
    element.innerHTML = panelSwitchHtml(tile, label, on, iconId, showIcon);
  } else if (tile.kind === "weather") {
    element.classList.add("oh-style", "weather");
    const label = tile.title || "Wetter";
    const temp = live && live.text ? escapeHtml(live.text) : escapeHtml(tile.unit ? `12,3 ${tile.unit}` : "12,3 °C");
    const forecast = live && live.forecast ? escapeHtml(live.forecast) : "leicht bewölkt";
    element.innerHTML = `${editorKicker(mode, "Wetter")}<div class="tile-scale">${ohCardMarkup(label, temp, `<div class="oh-card-icon">${iconVisual(tile.icon || "sun")}</div>`, forecast)}</div>`;
  } else if (tile.kind === "presence") {
    element.classList.add("oh-style", "presence");
    const label = tile.title || (tile.presenceMode === "alarm" ? "Alarm" : "Anwesenheit");
    const active = live && live.presenceState === "active";
    const stateLine = active ? (tile.presenceMode === "alarm" ? "ALARM" : "Da") : "Abwesend";
    element.innerHTML = `${editorKicker(mode, tile.presenceMode === "alarm" ? "Alarm" : "Anwesenheit")}<div class="tile-scale">${ohCardMarkup(label, escapeHtml(stateLine), `<div class="presence-badge ${active ? "on" : "off"}"></div>`)}</div>`;
  } else if (tile.kind === "separator") {
    const vertical = tile.orientation === "vertical";
    element.classList.add(vertical ? "vertical" : "horizontal");
    element.style.setProperty("--line-thickness", `${Math.max(1, Math.min(12, Number(tile.thickness) || 2))}px`);
    element.style.setProperty("--line-color", tile.lineColor || "#5a7391");
    element.innerHTML = `<div class="line"></div>`;
  } else if (tile.kind === "image") {
    if ((tile.imageSource || "catalog") === "media") {
      normalizeMediaTileBindings(tile);
      const bound = mediaBindingName(tile);
      element.innerHTML = `<div class="media-placeholder">Medien${bound ? ": " + escapeHtml(bound) : ""}</div>`;
    if (context.probeImageUrls && context.probeImageUrls[tile.tileId]) {
      const src = context.probeImageUrls[tile.tileId].url || context.probeImageUrls[tile.tileId];
      element.innerHTML = `<img src="${src}" alt="">`;
    } else if (context.probeImageErrors && context.probeImageErrors[tile.tileId]) {
      const detail = context.probeImageErrorDetails && context.probeImageErrorDetails[tile.tileId];
      element.innerHTML = `<div class="media-placeholder">${escapeHtml(probeImageErrorText(context.probeImageErrors[tile.tileId], detail))}</div>`;
    }
    } else {
      const image = state.images.find((item) => item.id === tile.imageId);
      element.innerHTML = image ? `<img src="images/${image.id}.${image.ext}" alt="">` : `<div class="missing">Bild wählen</div>`;
    }
  } else if (tile.kind === "camera") {
    element.classList.add("camera");
    element.classList.add(tile.cameraFit === "contain" ? "fit-contain" : "fit-cover");
    element.classList.add(tile.showTitle === false ? "no-title" : "has-title");
    normalizeMediaTileBindings(tile);
    const bound = mediaBindingName(tile);
    const fromMedia = (tile.cameraSource || "url") === "media" || Boolean(tile.mediaObjectId) || isMediaPick(variableById(tile.variableId));
    if (context.probeImageUrls && context.probeImageUrls[tile.tileId]) {
      const src = context.probeImageUrls[tile.tileId].url || context.probeImageUrls[tile.tileId];
      setTileMarkup(element, `${editorKicker(mode, "Kamera")}${cameraLiveImgHtml(src)}`);
      const img = element.querySelector("img.live-preview");
      if (img) {
        img.addEventListener("error", () => {
          const err = (context.probeImageErrors && context.probeImageErrors[tile.tileId]) || "camera_fetch";
          const detail = context.probeImageErrorDetails && context.probeImageErrorDetails[tile.tileId];
          paintCameraPreviewError(element, tile, err, detail, fromMedia, bound, tile.title, true);
        });
      }
    } else {
      const live = context.stateByTileId && context.stateByTileId[tile.tileId];
      let preview;
      if (live && live.quality === "empty") {
        preview = "Keine Bildquelle (Variable leer oder nicht konfiguriert)";
      } else if (context.probeImageErrors && context.probeImageErrors[tile.tileId]) {
        const err = context.probeImageErrors && context.probeImageErrors[tile.tileId];
        const detail = context.probeImageErrorDetails && context.probeImageErrorDetails[tile.tileId];
        preview = probeImageErrorText(err || "unknown", detail);
      } else {
        preview = bound
          ? (fromMedia ? `IPS-Medien: ${bound}` : "Snapshot über Bild-URL")
          : (fromMedia ? "Medien-Variable oder Medienobjekt wählen" : "Variable mit Bild-URL wählen");
      }
      element.innerHTML = `${editorKicker(mode, fromMedia ? "Kamera · Medien" : "Kamera · Livebild")}<div class="tile-scale"><div class="name"></div><div class="camera-preview">${escapeHtml(preview)}</div></div>`;
      element.querySelector(".name").textContent = tile.title || bound || "Kamera";
      const camTitle = element.querySelector(".name");
      if (camTitle) {
        camTitle.style.fontSize = `${titlePxOf(tile)}px`;
        if (tile.titleBold) camTitle.style.fontWeight = "700";
        if (tile.titleItalic) camTitle.style.fontStyle = "italic";
      }
    }
  } else if (tile.kind === "multi" || tile.kind === "contacts") {
    const rows = Array.isArray(tile.rows) ? tile.rows : [];
    const lines = (live && live.lines) || [];
    const body = rows.map((row, index) => {
      const line = lines[index] || {};
      if (tile.kind === "contacts") {
        return `<div class="contact-row"><span class="contact-dot${line.on ? " on" : ""}"></span><span>${escapeHtml(row.title || "Kontakt")}</span></div>`;
      }
      return `<div class="multi-row"><span>${escapeHtml(row.title || "Wert")}</span><strong${cssFont(textPxOf(tile), tile.textBold, tile.textItalic)}>${escapeHtml(line.text || "—")}</strong></div>`;
    }).join("") || `<div class="hint">Zeilen wählen</div>`;
    const head = tile.showTitle === false ? "" : `<div class="value-badge-title"${cssFont(titlePxOf(tile), tile.titleBold, tile.titleItalic)}>${escapeHtml(tile.title || KIND_NAMES[tile.kind])}</div>`;
    element.innerHTML = `${editorKicker(mode, KIND_NAMES[tile.kind])}<div class="value-badge">${head}<div class="row-list">${body}</div></div>`;
  } else if (tile.kind === "gauge") {
    const min = Number(tile.valueMin ?? 0);
    const max = Number(tile.valueMax ?? 100);
    const raw = live && live.value != null ? Number(live.value) : (min + max) / 2;
    const span = max === min ? 1 : max - min;
    const pct = Math.max(0, Math.min(100, ((raw - min) / span) * 100));
    const text = live && live.text ? live.text : "—";
    const head = tile.showTitle === false ? "" : `<div class="value-badge-title"${cssFont(titlePxOf(tile), tile.titleBold, tile.titleItalic)}>${escapeHtml(tile.title || "Bogen")}</div>`;
    element.innerHTML = `${editorKicker(mode, "Bogen")}<div class="gauge-tile">${head}<div class="gauge-arc" style="--gauge:${pct.toFixed(1)}"></div><div class="gauge-readout"${cssFont(textPxOf(tile), tile.textBold, tile.textItalic)}>${escapeHtml(text)}</div>${tile.showAge ? `<div class="age-line">${escapeHtml((live && live.age) || "vor 4 min")}</div>` : ""}</div>`;
  } else if (tile.kind === "countdown" || tile.kind === "appointment") {
    const text = live && live.text ? live.text : (tile.kind === "countdown" ? "0 min" : "Termin");
    const sub = live && live.sub ? live.sub : "";
    const head = tile.showTitle === false ? "" : `<div class="value-badge-title"${cssFont(titlePxOf(tile), tile.titleBold, tile.titleItalic)}>${escapeHtml(tile.title || KIND_NAMES[tile.kind])}</div>`;
    element.innerHTML = `${editorKicker(mode, KIND_NAMES[tile.kind])}<div class="value-badge">${head}<div class="value-readout"${cssFont(textPxOf(tile), tile.textBold, tile.textItalic)}>${escapeHtml(text)}</div>${sub ? `<div class="age-line">${escapeHtml(sub)}</div>` : ""}</div>`;
  }
  if (mode === "edit") {
    element.addEventListener("click", (event) => {
      event.stopPropagation();
      if (state.styleSource && state.styleSource !== tile.tileId) {
        const source = tileById(state.styleSource);
        if (source) applyTileStyle(source, tile);
        state.styleSource = null;
        setStatus("Stil übertragen.");
        markDirty();
      }
      state.selected = tile.tileId;
      renderGrid();
      renderProperties();
      renderVariableTree();
    });
    element.addEventListener("dblclick", (event) => {
      event.stopPropagation();
      state.selected = tile.tileId;
      renderGrid();
      renderProperties();
      renderVariableTree();
      openTileDialog(tile);
    });
    element.addEventListener("dragstart", (event) => {
      event.dataTransfer.setData("text/homedisplay-tile", tile.tileId);
      event.dataTransfer.effectAllowed = "move";
    });
    bindDrop(element, tile.col, tile.row, tile);
    if (state.selected === tile.tileId) bindResizeHandles(element, tile);
  }
  if (spanW > 1 || spanH > 1) {
    const badge = document.createElement("span");
    badge.className = "span-badge";
    badge.textContent = `${spanW}×${spanH}`;
    element.appendChild(badge);
  }
  const layerTiles = (context && context.pageTiles) || (currentPage() ? currentPage().tiles : []);
  applyTileLayer(element, tile, layerTiles);
  return element;
}

function renderTile(tile) {
  return buildTileElement(tile, {
    mode: "edit",
    probeImageUrls: state.tilePreviewUrls,
    probeImageErrors: state.tilePreviewErrors,
    probeImageErrorDetails: state.tilePreviewErrorDetails
  });
}

function dimTrackMarkup(tile, control, live, pct) {
  const showSw = tile.dimShowSwitch !== false && control !== "slider";
  const showBar = tile.dimShowBar !== false;
  const showValue = tile.dimShowPercent !== false;
  const layout = tile.dimLayout || "horizontal";
  const numeric = numericLiveValue(tile, live);
  const swOn = numeric != null ? valueMeansOn(tile, numeric) : pct > 0;
  const swHtml = showSw ? `<span class="sw${swOn ? " on" : ""}"></span>` : "";
  const valueHtml = showValue ? `<span class="ctl-value">${pct} %</span>` : "";
  const stateHtml = tile.dimShowStateText ? `<span class="ctl-value">${swOn ? "Ein" : "Aus"}</span>` : "";
  const trackHtml = showBar ? `<div class="track" data-dim-track><div class="fill" style="width:${pct}%"></div><div class="knob" style="left:${pct}%"></div></div>` : "";
  const offClass = showSw && !swOn ? " dim-off" : "";
  if (layout === "stacked" && showSw && showBar) {
    return `<div class="ctl dim-ctl layout-stacked${offClass}">${swHtml}<div class="dim-slider-row">${trackHtml}${valueHtml}${stateHtml}</div></div>`;
  }
  return `<div class="ctl dim-ctl${offClass}">${swHtml}${trackHtml}${valueHtml}${stateHtml}</div>`;
}

function controlMarkup(control, tile, live, demoOn) {
  if (tileInteractionMode(tile) === "switch" && tile.showIcon !== false && tile.actionId) {
    return "";
  }
  if (control === "dimSwitch" || control === "slider" || control === "combo") {
    const pct = dimPercentFromTile(tile, live);
    return dimTrackMarkup(tile, control, live, pct);
  }
  if (control === "switch") {
    if (!tile.actionId) return "";
    let on = liveIsOn(tile, live, demoOn);
    return `<div class="ctl"><span class="sw${on ? " on" : ""}"></span></div>`;
  }
  if (control === "battery") {
    const pad = tile && tile.batteryPad != null ? tile.batteryPad : 8;
    const label = tile && tile.batteryLabel ? tile.batteryLabel : "inside";
    const pct = dimPercentFromTile(tile, live, 72);
    const pctInside = label === "inside" ? `<span class="battery-pct">${pct} %</span>` : "";
    const pctOutside = label !== "inside" ? `<span class="battery-pct">${pct} %</span>` : "";
    return `<div class="battery" style="padding:${pad}px">${pctOutside}<div class="battery-body"><div class="battery-fill" style="width:${pct}%"></div></div><div class="battery-cap"></div>${pctInside}</div>`;
  }
  if (control === "blinds") {
    const pct = dimPercentFromTile(tile, live, 40);
    const showValue = tile.dimShowPercent !== false;
    const valueHtml = showValue ? `<span class="ctl-value">${pct} %</span>` : "";
    const trackHtml = `<div class="track" data-dim-track><div class="fill" style="width:${pct}%"></div><div class="knob" style="left:${pct}%"></div></div>`;
    return `<div class="ctl blinds"><button type="button" class="bl-btn" data-blind="up">▲</button><div class="dim-slider-row">${trackHtml}${valueHtml}</div><button type="button" class="bl-btn" data-blind="down">▼</button></div>`;
  }
  if (control === "setpoint") {
    const displayText = live && live.text ? live.text : "21,0 °C";
    return `<div class="ctl setpoint"><button type="button" class="sp-btn" data-setpoint="down">−</button><span class="sp-val">${escapeHtml(displayText)}</span><button type="button" class="sp-btn" data-setpoint="up">+</button></div>`;
  }
  return "";
}

function bindDrop(target, col, row, existing) {
  target.addEventListener("dragover", (event) => {
    event.preventDefault();
    target.classList.add("over");
  });
  target.addEventListener("dragleave", () => target.classList.remove("over"));
  target.addEventListener("drop", (event) => {
    event.preventDefault();
    target.classList.remove("over");
    const movedId = event.dataTransfer.getData("text/homedisplay-tile");
    const kind = event.dataTransfer.getData("text/homedisplay-kind");
    const control = event.dataTransfer.getData("text/homedisplay-control");
    const variableId = Number(event.dataTransfer.getData("text/homedisplay-variable"));
    if (movedId) {
      let dropCol = col;
      let dropRow = row;
      let dropTarget = existing;
      if (existing && isBackgroundImage(existing)) {
        const hit = cellUnderPointer(event, target.closest("#grid") || target.parentElement);
        if (hit) {
          dropCol = hit.col;
          dropRow = hit.row;
        }
        dropTarget = null;
      }
      moveTile(movedId, dropCol, dropRow, dropTarget);
      return;
    }
    if (variableId) {
      const variable = variableById(variableId);
      const onBackground = existing && isBackgroundImage(existing);
      const hit = onBackground ? cellUnderPointer(event, target.closest("#grid") || target.parentElement) : null;
      const placeCol = hit ? hit.col : col;
      const placeRow = hit ? hit.row : row;
      if (existing && !onBackground) return;
      if (isMediaPick(variable)) {
        addTile("image", placeCol, placeRow, variable);
        return;
      }
      if (isImageUrlRef(variable)) {
        addTile("camera", placeCol, placeRow, variable);
        return;
      }
      addTile("value", placeCol, placeRow, variable);
      return;
    }
    if (kind && (!existing || isBackgroundImage(existing))) {
      const hit = existing && isBackgroundImage(existing) ? cellUnderPointer(event, target.closest("#grid") || target.parentElement) : null;
      addTile(kind, hit ? hit.col : col, hit ? hit.row : row, null, control);
    }
  });
}

function moveTile(tileId, col, row, target) {
  const page = currentPage();
  const tile = page.tiles.find((item) => item.tileId === tileId);
  if (!tile) return;
  if (target && target.tileId !== tileId) {
    const sameSize = (target.colSpan || 1) === (tile.colSpan || 1) && (target.rowSpan || 1) === (tile.rowSpan || 1);
    if (!sameSize) {
      setStatus("Tauschen geht nur bei gleicher Größe.");
      return;
    }
    const swapCol = tile.col;
    const swapRow = tile.row;
    tile.col = target.col;
    tile.row = target.row;
    target.col = swapCol;
    target.row = swapRow;
  } else {
    if (!fits(tile, col, row, tile.colSpan || 1, tile.rowSpan || 1, tile.tileId)) {
      setStatus("Dort ist kein Platz für diese Kachel.");
      return;
    }
    tile.col = col;
    tile.row = row;
  }
  state.selected = tile.tileId;
  markDirty();
  renderGrid();
  renderProperties();
}

function addTile(kind, col, row, variable, control) {
  if (!state.layout) return;
  const page = currentPage();
  const stamp = Date.now().toString(36);
  const tile = { tileId: `t${stamp}`, kind, title: "", col, row, colSpan: 1, rowSpan: 1 };
  if (kind === "value") {
    tile.displaySource = "variable";
    if (!DIM_CONTROLS.includes(control)) tile.icon = "energy";
    tile.variableId = 0;
    tile.unit = DIM_CONTROLS.includes(control) || control === "battery" ? "%" : "";
    tile.decimals = 0;
    tile.prefix = "";
    if (control === "battery") {
      tile.control = "battery";
      tile.valueMin = 0;
      tile.valueMax = 100;
    } else if (DIM_CONTROLS.includes(control)) {
      tile.control = control;
      tile.actionKind = "dim";
      tile.valueMin = 0;
      tile.valueMax = 100;
      tile.valueOff = 0;
      tile.valueOn = 100;
      tile.actionId = tile.actionId || ("a" + stamp);
    } else if (control) {
      tile.control = control;
      tile.actionKind = "switch";
      tile.valueOff = 0;
      tile.valueOn = 1;
      tile.actionId = tile.actionId || ("a" + stamp);
    } else {
      /* Reine Wertanzeige – kein Schalten */
      tile.displaySource = "variable";
    }
    if (variable) assignVariable(tile, variable, true);
  } else if (kind === "nav") {
    const other = state.layout.pages.find((item) => item.id !== state.page) || state.layout.pages[0];
    tile.targetPage = other.id;
    tile.icon = "";
  } else if (kind === "label") {
    tile.text = "Text";
  } else if (kind === "chart") {
    tile.colSpan = Math.min(2, currentGrid().columns);
    tile.rowSpan = Math.min(2, currentGrid().rows);
    tile.variableId = 0;
    tile.chartPeriod = "24h";
    tile.chartStyle = "area";
    tile.unit = "";
    tile.decimals = 1;
    tile.icon = "temperature";
    tile.title = tile.title || "Diagramm";
    if (variable) assignVariable(tile, variable, true);
  } else if (kind === "scene") {
    tile.icon = "home";
    tile.title = tile.title || "Szene";
    tile.variableId = 0;
    if (variable && variable.action) {
      assignVariable(tile, variable, true);
    }
  } else if (kind === "weather") {
    tile.icon = "sun";
    tile.unit = "°C";
    tile.decimals = 1;
    tile.title = tile.title || "Wetter";
    tile.variableId = 0;
    if (variable) assignVariable(tile, variable, true);
  } else if (kind === "presence") {
    tile.presenceMode = "home";
    tile.title = tile.title || "Anwesenheit";
    tile.variableId = 0;
    tile.colSpan = Math.min(2, currentGrid().columns);
    if (variable) assignVariable(tile, variable, true);
  } else if (kind === "energyflow") {
    tile.colSpan = Math.min(2, currentGrid().columns);
    tile.rowSpan = Math.min(2, currentGrid().rows);
    tile.flowMode = "signed";
    tile.homeOverride = true;
    tile.unit = "W";
    tile.decimals = 0;
    tile.flowThreshold = 50;
    tile.pvVariableId = 0;
    tile.loadVariableId = 0;
    tile.batteryVariableId = 0;
    tile.gridImportVariableId = 0;
    tile.gridExportVariableId = 0;
    tile.batteryChargeVariableId = 0;
    tile.batteryDischargeVariableId = 0;
    tile.batterySocVariableId = 0;
    tile.title = tile.title || "Energiefluss";
    if (variable) assignFlowVariable(tile, "pv", variable, true);
  } else if (kind === "multi" || kind === "contacts") {
    tile.rows = [{ variableId: variable ? variable.id : 0, title: variable ? variable.name : "Zeile", unit: "", decimals: 0 }];
    tile.title = tile.title || (kind === "multi" ? "Mehrwert" : "Kontakte");
    tile.colSpan = Math.min(2, currentGrid().columns);
  } else if (kind === "gauge") {
    tile.variableId = variable ? variable.id : 0;
    tile.valueMin = 0;
    tile.valueMax = 100;
    tile.unit = "";
    tile.decimals = 0;
    tile.title = tile.title || "Bogen";
  } else if (kind === "countdown") {
    tile.variableId = variable ? variable.id : 0;
    tile.countdownMode = "until";
    tile.title = tile.title || "Countdown";
  } else if (kind === "appointment") {
    tile.variableId = variable ? variable.id : 0;
    tile.title = tile.title || "Termin";
  } else if (kind === "separator") {
    tile.orientation = "horizontal";
    tile.thickness = 2;
    tile.lineColor = "#5a7391";
    tile.colSpan = Math.max(1, currentGrid().columns);
    tile.rowSpan = 1;
    if (!fits(null, col, row, tile.colSpan, 1, null)) tile.colSpan = 1;
  } else if (kind === "image") {
    if (isMediaPick(variable)) {
      bindMediaToTile(tile, variable, "image");
      tile.title = tile.title || variable.name;
    } else {
      tile.imageSource = "catalog";
      tile.imageId = state.images[0] ? state.images[0].id : "";
    }
  } else if (kind === "camera") {
    tile.title = "Kamera";
    tile.cameraSource = "url";
    tile.cameraFit = "cover";
    tile.cameraPace = "live";
    tile.cameraLiveUrl = CAMERA_LIVE_URL_DEFAULT;
    tile.cameraJpegQuality = 80;
    tile.cameraLiveFps = 3;
    tile.cameraIntervalSec = 10;
    tile.variableId = 0;
    tile.colSpan = Math.min(2, currentGrid().columns);
    tile.rowSpan = Math.min(2, currentGrid().rows);
    if (!fits(null, col, row, tile.colSpan, tile.rowSpan, null)) {
      tile.colSpan = 1;
      tile.rowSpan = 1;
    }
    if (variable) {
      if (isMediaPick(variable)) assignCameraMedia(tile, variable, true);
      else assignVariable(tile, variable, true);
    }
  }
  if (!fits(null, col, row, tile.colSpan, tile.rowSpan, null)) {
    setStatus("Diese Zelle ist belegt.");
    return;
  }
  page.tiles.push(tile);
  state.selected = tile.tileId;
  state.pickedVariable = null;
  markDirty();
  renderGrid();
  renderProperties();
  renderVariableTree();
  if (kind === "image" && tile.imageSource !== "media" && !tile.imageId) openImages();
}

function assignCameraMedia(tile, item, silent) {
  if (!isMediaPick(item) && !isMediaObject(item)) return;
  bindMediaToTile(tile, item, "camera");
  normalizeMediaTileBindings(tile);
  if (!tile.title) tile.title = item.name;
  if (!silent) {
    markDirty();
    renderGrid();
    renderProperties();
    renderVariableTree();
    setStatus(`Kamera-Kachel mit „${item.name}“ verknüpft (IPS-Medien).`);
  }
}

function assignMediaVariable(tile, item, silent) {
  if (!isMediaPick(item)) return;
  bindMediaToTile(tile, item, "image");
  if (!tile.title) tile.title = item.name;
  if (!silent) {
    markDirty();
    renderGrid();
    renderProperties();
  }
}

function assignVariable(tile, variable, silent) {
  if (!variable) return;
  tile.variableId = variable.id;
  delete tile.mediaObjectId;
  if (tile.kind === "display") {
    tile.displaySource = "variable";
    if (variable.unit) tile.unit = variable.unit;
    if (variable.decimals != null) tile.decimals = variable.decimals;
    delete tile.actionId;
    delete tile.actionKind;
    tile.confirm = false;
    if (!tile.title) tile.title = variable.name;
    tile.icon = tile.icon || guessIcon(variable);
    if (!silent) {
      markDirty();
      renderGrid();
      renderProperties();
    }
    return;
  }
  if (tile.kind === "weather") {
    if (variable.type !== 1 && variable.type !== 2) {
      setStatus("Wetter: Temperatur als Ganzzahl- oder Dezimalvariable wählen (Text = Prognose separat anklicken).");
      return;
    }
    tile.variableId = variable.id;
    if (!tile.title) tile.title = variable.name;
    if (variable.unit) tile.unit = variable.unit;
    if (variable.decimals != null) tile.decimals = variable.decimals;
    delete tile.actionId;
    if (!silent) {
      markDirty();
      renderGrid();
      renderProperties();
      setStatus("Temperatur-Variable zugewiesen.");
    }
    return;
  }
  if (tile.kind === "presence") {
    tile.variableId = variable.id;
    if (!tile.title) tile.title = variable.name;
    delete tile.actionId;
    if (!silent) {
      markDirty();
      renderGrid();
      renderProperties();
    }
    return;
  }
  if (tile.kind === "scene") {
    if (!variable.action) {
      setStatus("Szene: bitte eine Variable mit IPS-Aktion wählen (Filter „Nur schaltbare“).");
      return;
    }
    tile.variableId = variable.id;
    if (!tile.title) tile.title = variable.name;
    tile.actionId = tile.actionId || `a-${tile.tileId}`;
    tile.actionKind = "switch";
    tile.icon = tile.icon || guessIcon(variable) || "home";
    if (!silent) {
      markDirty();
      renderGrid();
      renderProperties();
    }
    return;
  }
  if (tile.kind === "chart") {
    if (variable.type !== 1 && variable.type !== 2) {
      setStatus("Diagramm: bitte eine Ganzzahl- oder Dezimalvariable wählen.");
      return;
    }
    if (variable.unit) tile.unit = variable.unit;
    if (variable.decimals != null) tile.decimals = variable.decimals;
    delete tile.actionId;
    delete tile.actionKind;
    tile.confirm = false;
    if (!tile.title) tile.title = variable.name;
    tile.icon = tile.icon || guessIcon(variable);
    if (!silent) {
      markDirty();
      renderGrid();
      renderProperties();
    }
    return;
  }
  if (tile.kind === "camera") {
    if (isMediaPick(variable)) {
      assignCameraMedia(tile, variable, silent);
      return;
    }
    tile.cameraSource = "url";
    if (!tile.title) tile.title = variable.name;
    delete tile.actionId;
    delete tile.actionKind;
    tile.confirm = false;
    if (!silent) {
      markDirty();
      renderGrid();
      renderProperties();
    }
    return;
  }
  if (!tile.title) tile.title = variable.name;
  if (!variable.action) {
    delete tile.actionId;
    delete tile.actionKind;
    tile.confirm = false;
  } else if (DIM_CONTROLS.includes(tile.control)) {
    tile.actionId = tile.actionId || `a-${tile.tileId}`;
    tile.actionKind = "dim";
    tile.valueMin = tile.valueMin ?? 0;
    tile.valueMax = tile.valueMax ?? 100;
    if (variable.actionKind && variable.actionKind !== "dim") setStatus("Diese Variable ist kein Dimmwert. Der Balken sendet trotzdem eine Zahl.");
  } else if (tile.actionId) {
    tile.actionKind = variable.actionKind || tile.actionKind || "switch";
  }
  tile.icon = tile.icon || guessIcon(variable);
  if (!silent) {
    markDirty();
    renderGrid();
    renderProperties();
  }
}

function guessIcon(variable) {
  const text = `${variable.name} ${variable.profile}`.toLowerCase();
  if (text.includes("temp")) return "temperature";
  if (text.includes("licht") || text.includes("light") || text.includes("lampe")) return "light";
  if (text.includes("rollo") || text.includes("rolllad") || text.includes("shutter")) return "shutter";
  if (text.includes("pump")) return "pump";
  if (text.includes("ventil") || text.includes("valve")) return "valve";
  if (text.includes("alarm") || text.includes("warn")) return "alarm";
  if (text.includes("batt") || text.includes("akku") || text.includes("battery")) return "energy";
  return "energy";
}

function deleteSelected() {
  const page = currentPage();
  if (!page || !state.selected) return;
  page.tiles = page.tiles.filter((tile) => tile.tileId !== state.selected);
  state.selected = null;
  markDirty();
  renderGrid();
  renderProperties();
}

function selectedTile() {
  const page = currentPage();
  return page ? page.tiles.find((tile) => tile.tileId === state.selected) || null : null;
}

let tileCopySerial = 0;

/* Legt eine Kopie auf die aktuelle Seite, in die erste freie Zelle. */
function placeTileCopy(source) {
  const page = currentPage();
  if (!source || !page) return false;
  const copy = JSON.parse(JSON.stringify(source));
  tileCopySerial += 1;
  copy.tileId = `t${Date.now().toString(36)}${tileCopySerial.toString(36)}`;
  if (copy.actionId) copy.actionId = `a-${copy.tileId}`;
  const cell = freeCell(copy.colSpan || 1, copy.rowSpan || 1);
  if (!cell) {
    setStatus("Auf dieser Seite ist keine Zelle frei.");
    return false;
  }
  copy.col = cell.col;
  copy.row = cell.row;
  page.tiles.push(copy);
  state.selected = copy.tileId;
  markDirty();
  renderGrid();
  renderProperties();
  setStatus("Kachel eingefügt.");
  return true;
}

function tileById(id) {
  if (!state.layout) return null;
  for (const page of state.layout.pages) {
    const found = page.tiles.find((tile) => tile.tileId === id);
    if (found) return found;
  }
  return null;
}

function applyTileStyle(source, target) {
  const keys = ["bgColor", "onColor", "textColor", "titlePx", "titleBold", "titleItalic", "textPx", "textBold", "textItalic", "textUnderline", "icon", "iconPlace", "titleAlign", "titleIcon", "showTitleIcon"];
  for (const key of keys) {
    if (source[key] == null || source[key] === "") delete target[key];
    else target[key] = source[key];
  }
}

function fillFontSelect(select, includeAuto) {
  if (!select) return;
  const mode = includeAuto ? "auto" : "px";
  if (select.dataset.ready === mode) return;
  select.innerHTML = "";
  if (includeAuto) {
    const auto = document.createElement("option");
    auto.value = "auto";
    auto.textContent = "Automatisch";
    select.appendChild(auto);
  }
  for (const px of FONT_PX) {
    const option = document.createElement("option");
    option.value = String(px);
    option.textContent = `${px} px`;
    select.appendChild(option);
  }
  select.dataset.ready = includeAuto ? "auto" : "px";
}

function syncFontPreview(select, preview) {
  if (!select || !preview) return;
  const px = select.value === "auto" ? 20 : Number(select.value) || 20;
  preview.style.fontSize = `${px}px`;
  if ($("p-title-bold")?.checked && preview.id === "p-title-preview") preview.style.fontWeight = "700";
  else if (preview.id === "p-title-preview") preview.style.fontWeight = "600";
  preview.style.fontStyle = (($("p-title-italic")?.checked && preview.id === "p-title-preview") || ($("p-text-italic")?.checked && preview.id === "p-text-preview")) ? "italic" : "normal";
}

function syncFontProperties(tile) {
  const wrap = $("p-font-wrap");
  if (!wrap) return;
  const showTitle = TITLE_FONT_KINDS.includes(tile.kind);
  const showBody = BODY_FONT_KINDS.includes(tile.kind);
  wrap.classList.toggle("hidden", !showTitle && !showBody);
  $("p-title-font")?.classList.toggle("hidden", !showTitle);
  $("p-body-font")?.classList.toggle("hidden", !showBody);
  const valueMode = tile.kind === "value" ? tileInteractionMode(tile) : "";
  const ageOk = tile.kind !== "value" || valueMode === "" || valueMode === "battery";
  $("p-show-age-wrap")?.classList.toggle("hidden", !AGE_KINDS.includes(tile.kind) || !ageOk);
  $("p-text-underline-wrap")?.classList.toggle("hidden", !["value", "label", "scene", "display"].includes(tile.kind));
  fillFontSelect($("p-title-px"), false);
  fillFontSelect($("p-text-px"), tile.kind === "label");
  if ($("p-title-px")) $("p-title-px").value = String(titlePxOf(tile));
  if ($("p-text-px")) {
    const px = textPxOf(tile);
    $("p-text-px").value = tile.kind === "label" && !px ? "auto" : String(px || 28);
  }
  if ($("p-title-bold")) $("p-title-bold").checked = Boolean(tile.titleBold);
  if ($("p-title-italic")) $("p-title-italic").checked = Boolean(tile.titleItalic);
  if ($("p-text-bold")) $("p-text-bold").checked = Boolean(tile.textBold);
  if ($("p-text-italic")) $("p-text-italic").checked = Boolean(tile.textItalic);
  if ($("p-text-underline")) $("p-text-underline").checked = Boolean(tile.textUnderline);
  if ($("p-show-age")) $("p-show-age").checked = Boolean(tile.showAge);
  syncFontPreview($("p-title-px"), $("p-title-preview"));
  syncFontPreview($("p-text-px"), $("p-text-preview"));
  if (tile.kind === "multi" || tile.kind === "contacts") renderRowEditor(tile);
  if ($("p-gauge-min")) $("p-gauge-min").value = tile.valueMin ?? 0;
  if ($("p-gauge-max")) $("p-gauge-max").value = tile.valueMax ?? 100;
  if ($("p-gauge-unit")) $("p-gauge-unit").value = tile.unit || "";
  if ($("p-gauge-decimals")) $("p-gauge-decimals").value = tile.decimals ?? 0;
  if ($("p-countdown-mode")) $("p-countdown-mode").value = tile.countdownMode === "seconds" ? "seconds" : "until";
}

function applyFontFields(tile) {
  if (!$("p-font-wrap") || $("p-font-wrap").classList.contains("hidden")) return;
  if ($("p-title-px") && !$("p-title-font")?.classList.contains("hidden")) {
    const px = Number($("p-title-px").value);
    if (FONT_PX.includes(px)) tile.titlePx = px;
  }
  if ($("p-text-px") && !$("p-body-font")?.classList.contains("hidden")) {
    if ($("p-text-px").value === "auto") {
      delete tile.textPx;
      delete tile.textSize;
    } else {
      const px = Number($("p-text-px").value);
      if (FONT_PX.includes(px)) {
        tile.textPx = px;
        delete tile.textSize;
      }
    }
  }
  if ($("p-title-bold")?.checked) tile.titleBold = true;
  else delete tile.titleBold;
  if ($("p-title-italic")?.checked) tile.titleItalic = true;
  else delete tile.titleItalic;
  if ($("p-text-bold")?.checked) tile.textBold = true;
  else delete tile.textBold;
  if ($("p-text-italic")?.checked) tile.textItalic = true;
  else delete tile.textItalic;
  if ($("p-text-underline")?.checked && !$("p-text-underline-wrap")?.classList.contains("hidden")) tile.textUnderline = true;
  else delete tile.textUnderline;
  if ($("p-show-age")?.checked && !$("p-show-age-wrap")?.classList.contains("hidden")) tile.showAge = true;
  else delete tile.showAge;
}

function applyExtraTileFields(tile) {
  if (tile.kind === "gauge") {
    tile.valueMin = Number($("p-gauge-min")?.value);
    tile.valueMax = Number($("p-gauge-max")?.value);
    if (!Number.isFinite(tile.valueMin)) tile.valueMin = 0;
    if (!Number.isFinite(tile.valueMax)) tile.valueMax = 100;
    tile.unit = $("p-gauge-unit") ? $("p-gauge-unit").value : "";
    tile.decimals = Number($("p-gauge-decimals")?.value) || 0;
  }
  if (tile.kind === "countdown") {
    tile.countdownMode = $("p-countdown-mode")?.value === "seconds" ? "seconds" : "until";
  }
}

function renderRowEditor(tile) {
  const host = $("p-rows");
  if (!host) return;
  if (!Array.isArray(tile.rows)) tile.rows = [];
  host.innerHTML = "";
  tile.rows.forEach((row, index) => {
    const box = document.createElement("div");
    box.className = "scene-step";
    const variable = variableById(row.variableId);
    const extra = tile.kind === "multi"
      ? `<div class="row"><label>Einheit <input data-row-unit value="${escapeHtml(row.unit || "")}"></label><label>Nachkomma <input data-row-decimals type="number" min="0" max="3" value="${Number(row.decimals) || 0}"></label></div>`
      : "";
    box.innerHTML = `<label>Beschriftung <input data-row-title value="${escapeHtml(row.title || "")}"></label>
      <label>Variable <span class="pick-row"><input readonly value="${escapeHtml(variable ? variable.name + " (" + variable.id + ")" : "")}" placeholder="Auswählen"><button type="button" class="pick-var" data-pick="row" data-slot="${index}">Auswählen</button></span></label>
      ${extra}
      <button type="button" data-row-remove>Entfernen</button>`;
    box.querySelector("[data-row-title]").addEventListener("change", (event) => {
      row.title = event.target.value;
      markDirty();
      renderGrid();
    });
    const unit = box.querySelector("[data-row-unit]");
    if (unit) unit.addEventListener("change", (event) => { row.unit = event.target.value; markDirty(); renderGrid(); });
    const decimals = box.querySelector("[data-row-decimals]");
    if (decimals) decimals.addEventListener("change", (event) => { row.decimals = Number(event.target.value) || 0; markDirty(); renderGrid(); });
    box.querySelector("[data-row-remove]").addEventListener("click", () => {
      tile.rows.splice(index, 1);
      markDirty();
      renderRowEditor(tile);
      renderGrid();
    });
    box.querySelector(".pick-var").addEventListener("click", () => openVariablePicker("row", String(index)));
    host.appendChild(box);
  });
}

/* ---------- Eigenschaften ---------- */

function fillIconSelect(select, allowEmpty) {
  select.innerHTML = allowEmpty ? '<option value="">Kein Symbol</option>' : "";
  select.classList.add("icon-font");
  for (const [value, name, code] of ICONS_SORTED) {
    const option = document.createElement("option");
    option.value = value;
    option.textContent = `${String.fromCodePoint(code)} ${name}`;
    select.appendChild(option);
  }
}

function fillTargetSelect(select) {
  select.innerHTML = "";
  for (const page of state.layout.pages) {
    const option = document.createElement("option");
    option.value = page.id;
    option.textContent = page.title || pageTitle(page.id);
    select.appendChild(option);
  }
}

function fillImageSelect(select) {
  select.innerHTML = '<option value="">Kein Bild</option>';
  for (const image of state.images) {
    const option = document.createElement("option");
    option.value = image.id;
    option.textContent = `${image.name} (${image.width}×${image.height})`;
    select.appendChild(option);
  }
}

function variableLabel(variable) {
  if (!variable) return "";
  return `${variable.path ? variable.path + " / " : ""}${variable.name} (${variable.id})`;
}

function renderSceneSteps(tile) {
  const host = $("p-scene-steps");
  if (!host) return;
  if (!Array.isArray(tile.sceneSteps)) tile.sceneSteps = [];
  host.innerHTML = "";
  tile.sceneSteps.forEach((step, index) => {
    const box = document.createElement("div");
    box.className = "scene-step";
    const lamp = variableById(step.variableId);
    const colorVar = variableById(step.colorVariableId);
    const boolLamp = lamp && Number(lamp.type) === 0;
    const on = boolLamp ? step.value !== false && step.value !== 0 && step.value !== "0" : Number(step.value) > 0;
    const brightness = Number(step.value) > 0 ? Number(step.value) : (Number(step.brightness) || 100);
    const valueField = boolLamp
      ? ""
      : `<label data-step="level-wrap" class="${on ? "" : "hidden"}">Helligkeit <input data-step="value" type="number" min="1" max="100" value="${brightness}"></label>`;
    box.innerHTML = `<label>Lampe <span class="pick-row"><input readonly value="${escapeHtml(variableLabel(lamp))}" placeholder="Auswählen"><button type="button" class="pick-var" data-pick="sceneStep" data-slot="${index}">Auswählen</button></span></label>
      <label>Zustand <select data-step="power"><option value="off" ${on ? "" : "selected"}>Aus</option><option value="on" ${on ? "selected" : ""}>Ein</option></select></label>
      ${valueField}
      <label><input type="checkbox" data-step="useColor" ${step.colorVariableId ? "checked" : ""}> Farbe setzen</label>
      <div data-step="color" class="${step.colorVariableId ? "" : "hidden"}">
        <label>Farb-Variable <span class="pick-row"><input readonly value="${escapeHtml(variableLabel(colorVar))}" placeholder="Auswählen"><button type="button" class="pick-var" data-pick="sceneStepColor" data-slot="${index}">Auswählen</button></span></label>
        <label>Farbe <input data-step="colorValue" type="color" value="${step.color || "#ffb547"}"></label>
      </div>
      <label>Verzögerung (ms) <input data-step="delay" type="number" min="0" max="10000" value="${Number(step.delayMs) || 0}"></label>
      <button type="button" data-step="remove">Entfernen</button>`;
    box.querySelector("[data-step=power]")?.addEventListener("change", (event) => {
      box.querySelector("[data-step=level-wrap]")?.classList.toggle("hidden", event.target.value !== "on");
      applyProperties();
    });
    box.querySelectorAll("[data-step=value], [data-step=delay], [data-step=colorValue], [data-step=useColor]").forEach((field) => {
      field.addEventListener("change", () => applyProperties());
    });
    box.querySelector("[data-step=useColor]").addEventListener("change", (event) => {
      box.querySelector("[data-step=color]").classList.toggle("hidden", !event.target.checked);
      if (!event.target.checked) delete step.colorVariableId;
    });
    box.querySelector("[data-step=remove]").addEventListener("click", () => {
      tile.sceneSteps.splice(index, 1);
      renderSceneSteps(tile);
      applyProperties();
    });
    host.appendChild(box);
  });
  host.querySelectorAll(".pick-var").forEach((button) => {
    button.addEventListener("click", () => openVariablePicker(button.dataset.pick, button.dataset.slot || ""));
  });
}

function collectSceneSteps() {
  const tile = selectedTile();
  const host = $("p-scene-steps");
  if (!tile || !host) return tile && Array.isArray(tile.sceneSteps) ? tile.sceneSteps : [];
  const rows = [...host.querySelectorAll(".scene-step")];
  const steps = rows.map((row, index) => {
    const prev = (tile.sceneSteps || [])[index] || {};
    const valueEl = row.querySelector("[data-step=value]");
    const useColor = row.querySelector("[data-step=useColor]")?.checked;
    const step = { variableId: Number(prev.variableId) || 0 };
    const lamp = variableById(step.variableId);
    const boolLamp = lamp && Number(lamp.type) === 0;
    const power = row.querySelector("[data-step=power]")?.value || "on";
    if (boolLamp) {
      step.value = power === "on";
    } else if (power !== "on") {
      step.value = 0;
      const remembered = Number(valueEl?.value);
      if (remembered > 0) step.brightness = remembered;
      else if (Number(prev.brightness) > 0) step.brightness = Number(prev.brightness);
    } else {
      let level = Number(valueEl?.value);
      if (!(level > 0)) level = Number(prev.brightness) || 100;
      level = Math.max(1, Math.min(100, level));
      step.value = level;
      step.brightness = level;
    }
    if (useColor && prev.colorVariableId) {
      step.colorVariableId = prev.colorVariableId;
      step.color = row.querySelector("[data-step=colorValue]")?.value || "#ffb547";
    }
    const delay = Number(row.querySelector("[data-step=delay]")?.value) || 0;
    if (delay > 0) step.delayMs = delay;
    return step;
  }).filter((step) => step.variableId > 0);
  tile.sceneSteps = steps;
  return steps;
}

function clampCamera(value, min, max, fallback) {
  const number = Number(value);
  if (!Number.isFinite(number)) return fallback;
  return Math.max(min, Math.min(max, Math.round(number)));
}

function syncCameraPacePanels() {
  const live = ($("p-camera-pace")?.value || "live") !== "interval";
  $("p-camera-live-fields")?.classList.toggle("hidden", !live);
}

function renderProperties() {
  const tile = selectedTile();
  const title = $("t-title");
  if (title && tile) title.textContent = `${KIND_NAMES[tile.kind] || tile.kind} – ${tile.title || tile.tileId}`;
  if (!tile) return;
  for (const block of document.querySelectorAll("#prop-form [data-for]")) {
    block.classList.toggle("hidden", !block.dataset.for.split(" ").includes(tile.kind));
  }
  $("p-kind").value = KIND_NAMES[tile.kind];
  $("p-title").value = tile.title || "";
  if ($("p-show-title")) $("p-show-title").checked = tile.showTitle !== false;
  if ($("p-title-align")) $("p-title-align").value = tile.titleAlign || "left";
  if ($("p-show-title-icon")) $("p-show-title-icon").checked = titleIconEnabled(tile);
  if ($("p-title-icon")) {
    fillIconSelect($("p-title-icon"), true);
    const heading = titleIconId(tile);
    $("p-title-icon").value = heading;
    $("p-title-icon").disabled = !$("p-show-title-icon")?.checked;
  }
  if ($("p-own-bg")) $("p-own-bg").checked = Boolean(tile.bgColor);
  if ($("p-bg-color") && tile.bgColor) $("p-bg-color").value = tile.bgColor;
  if ($("p-own-on")) $("p-own-on").checked = Boolean(tile.onColor);
  if ($("p-on-color") && tile.onColor) $("p-on-color").value = tile.onColor;
  if ($("p-group")) $("p-group").value = tile.group || "";
  $("p-col").value = tile.col + 1;
  $("p-row").value = tile.row + 1;
  if (tile.kind === "nav") {
    fillIconSelect($("p-icon"), true);
    $("p-icon").value = tile.icon || "";
  }
  if (tile.kind === "value") {
    fillIconSelect($("p-value-icon"), false);
    $("p-value-icon").value = tile.icon || "";
    if ($("p-icon-place")) {
      const place = tile.iconPlace === "after" || tile.iconPlace === "none" ? tile.iconPlace : "before";
      $("p-icon-place").value = place;
      if ($("p-value-icon")) $("p-value-icon").disabled = place === "none";
    }
    fillIconSelect($("p-iconon"));
    fillIconSelect($("p-iconoff"));
    if ($("p-iconon")) $("p-iconon").value = tile.iconOn || "lightOn";
    if ($("p-iconoff")) $("p-iconoff").value = tile.iconOff || "lightOff";
    if ($("p-show-icon")) $("p-show-icon").checked = tile.showIcon !== false;
    if ($("p-show-state-text")) $("p-show-state-text").checked = tile.showStateText === true;
    if ($("p-interaction")) $("p-interaction").value = tileInteractionMode(tile);
    if ($("p-dim-show-switch")) $("p-dim-show-switch").checked = tile.dimShowSwitch !== false;
    if ($("p-dim-show-bar")) $("p-dim-show-bar").checked = tile.dimShowBar !== false;
    if ($("p-dim-show-state-text")) $("p-dim-show-state-text").checked = tile.dimShowStateText === true;
    syncInteractionPropertyPanels();
    if ($("p-dim-show-percent")) $("p-dim-show-percent").checked = tile.dimShowPercent !== false;
    if ($("p-dim-layout")) $("p-dim-layout").value = tile.dimLayout || "horizontal";
    if ($("p-own-text")) $("p-own-text").checked = Boolean(tile.textColor);
    if ($("p-text-color") && tile.textColor) $("p-text-color").value = tile.textColor;
    if ($("p-threshold")) $("p-threshold").value = tile.threshold != null ? String(tile.threshold) : "";
    if ($("p-status-color")) $("p-status-color").value = tile.color || "neutral";
    if ($("p-dim-tap")) {
      const tap = tile.control === "combo" ? (tile.dimTap || "popup") : (tile.dimTapTrack || "popup");
      if (tap === true || tap === "on") $("p-dim-tap").value = "on";
      else if (tap === false || tap === "off") $("p-dim-tap").value = "off";
      else if (tap === "popup") $("p-dim-tap").value = "popup";
      else $("p-dim-tap").value = tile.control === "blinds" ? "on" : "popup";
    }
    if ($("p-value-on")) $("p-value-on").value = String(valueOnOf(tile));
    if ($("p-value-off")) $("p-value-off").value = String(valueOffOf(tile));
  }
  const variable = variableById(tile.variableId);
  const mediaObj = tile.mediaObjectId ? variableById(tile.mediaObjectId) : null;
  if (tile.kind === "camera" || tile.kind === "value") {
    $("p-variable").value = mediaObj
      ? `${mediaObj.path ? mediaObj.path + " / " : ""}${mediaObj.name} (Medien ${mediaObj.id})`
      : (variable ? `${variable.path ? variable.path + " / " : ""}${variable.name} (${variable.id})` : "");
  } else {
    $("p-variable").value = variable ? `${variable.path ? variable.path + " / " : ""}${variable.name} (${variable.id})` : "";
  }
  $("p-unit").value = tile.unit || "";
  $("p-decimals").value = tile.decimals || 0;
  if ($("p-batterypad")) $("p-batterypad").value = tile.batteryPad != null ? tile.batteryPad : 8;
  if ($("p-batterylabel")) $("p-batterylabel").value = tile.batteryLabel || "inside";
  $("p-action").checked = Boolean(tile.actionId);
  const valueInteraction = tile.kind === "value" ? tileInteractionMode(tile) : null;
  $("p-action").disabled = valueInteraction === "battery" || !(variable && variable.action);
  $("p-confirm").checked = Boolean(tile.confirm);
  if ($("p-actionkind")) $("p-actionkind").value = tile.actionKind || (DIM_CONTROLS.includes(tile.control) ? "dim" : (variable ? variable.actionKind || "switch" : "switch"));
  fillTargetSelect($("p-target"));
  $("p-target").value = tile.targetPage || "";
  $("p-text").value = tile.text || "";
  const displaySource = tile.displaySource || "variable";
  if ($("p-displaysource")) {
    $("p-displaysource").value = displaySource;
    $("p-display-text")?.classList.toggle("hidden", displaySource !== "text");
  }
  if ($("p-display-varpick")) {
    const dv = variableById(tile.variableId);
    $("p-display-varpick").value = dv ? `${dv.path ? dv.path + " / " : ""}${dv.name} (${dv.id})` : "";
  }
  if ($("p-prefix")) $("p-prefix").value = tile.prefix || "";
  if ($("p-display-unit")) $("p-display-unit").value = tile.unit || "";
  if ($("p-display-decimals")) $("p-display-decimals").value = tile.decimals || 0;
  if ($("p-display-textarea")) $("p-display-textarea").value = tile.text || "";
  if ($("p-display-icon")) {
    fillIconSelect($("p-display-icon"), false);
    $("p-display-icon").value = tile.icon || "";
  }
  if (tile.kind === "chart") {
    if ($("p-chart-period")) $("p-chart-period").value = tile.chartPeriod || "24h";
    if ($("p-chart-style")) $("p-chart-style").value = tile.chartStyle || "area";
    if ($("p-chart-color")) $("p-chart-color").value = chartColorOf(tile);
    if ($("p-chart-axes")) $("p-chart-axes").checked = Boolean(tile.chartShowAxes);
    if ($("p-chart-grid")) $("p-chart-grid").checked = Boolean(tile.chartShowGrid);
    if ($("p-chart-value")) $("p-chart-value").checked = chartShowValue(tile);
    if ($("p-chart-unit")) $("p-chart-unit").value = tile.unit || "";
    if ($("p-chart-decimals")) $("p-chart-decimals").value = tile.decimals ?? 1;
    if ($("p-chart-y-mode")) $("p-chart-y-mode").value = tile.chartYMode || "auto";
    if ($("p-chart-y-min")) $("p-chart-y-min").value = tile.chartYMin ?? "";
    if ($("p-chart-y-max")) $("p-chart-y-max").value = tile.chartYMax ?? "";
    if ($("p-chart-yesterday")) $("p-chart-yesterday").checked = Boolean(tile.chartCompareYesterday);
    if ($("p-chart-refresh")) $("p-chart-refresh").value = tile.chartRefreshSec ?? 0;
    if ($("p-chart-bitmap")) $("p-chart-bitmap").checked = tile.chartBitmap !== false;
    if ($("p-chart-extra-ids")) {
      const extra = (tile.chartSeries || []).slice(1).map((r) => r.variableId).filter(Boolean);
      $("p-chart-extra-ids").value = extra.join(", ");
    }
    $("p-chart-y-fixed")?.classList.toggle("hidden", ($("p-chart-y-mode")?.value || "auto") !== "fixed");
    const hint = $("p-chart-archive-hint");
    if (hint) {
      const ids = [tile.variableId, ...(tile.chartSeries || []).map((r) => r.variableId)].filter(Boolean);
      const bad = ids.some((id) => !variableArchived(id));
      hint.classList.toggle("hidden", !bad || ids.length === 0);
    }
  }
  if (tile.kind === "label" && $("p-label-style")) $("p-label-style").value = tile.labelStyle || "text";
  syncFontProperties(tile);
  if ($("p-sep-orient")) $("p-sep-orient").value = tile.orientation === "vertical" ? "vertical" : "horizontal";
  if ($("p-sep-thickness")) $("p-sep-thickness").value = tile.thickness || 2;
  if ($("p-sep-color") && tile.lineColor) $("p-sep-color").value = tile.lineColor;
  if (tile.kind === "scene") {
    fillIconSelect($("p-scene-iconon"));
    fillIconSelect($("p-scene-iconoff"));
    if ($("p-scene-iconon")) $("p-scene-iconon").value = tile.iconOn || "lightOn";
    if ($("p-scene-iconoff")) $("p-scene-iconoff").value = tile.iconOff || "lightOff";
    if ($("p-scene-show-icon")) $("p-scene-show-icon").checked = tile.showIcon !== false;
    $("p-scene-icon-toggle")?.classList.toggle("hidden", !($("p-scene-show-icon")?.checked));
    if ($("p-scene-show-state-text")) $("p-scene-show-state-text").checked = tile.showStateText === true;
    if ($("p-scene-actionid")) $("p-scene-actionid").value = tile.actionId || "";
    if ($("p-scene-confirm")) $("p-scene-confirm").checked = Boolean(tile.confirm);
    renderSceneSteps(tile);
  }
  const ownOnWrap = $("p-own-on-wrap");
  if (ownOnWrap) {
    const ownOnVisible = tile.kind === "scene" || (tile.kind === "value" && (tileInteractionMode(tile) === "switch" || tileInteractionMode(tile) === "combo"));
    ownOnWrap.classList.toggle("hidden", !ownOnVisible);
  }
  if (tile.kind === "weather") {
    fillIconSelect($("p-weather-icon"), false);
    if ($("p-weather-icon")) $("p-weather-icon").value = tile.icon || "sun";
    if ($("p-weather-variable")) $("p-weather-variable").value = variablePickLabel(variable);
    if ($("p-weather-unit")) $("p-weather-unit").value = tile.unit || "°C";
    if ($("p-weather-decimals")) $("p-weather-decimals").value = tile.decimals ?? 1;
    const fv = variableById(tile.forecastVariableId);
    if ($("p-weather-forecast")) $("p-weather-forecast").value = variablePickLabel(fv);
  }
  if (tile.kind === "presence") {
    if ($("p-presence-variable")) $("p-presence-variable").value = variablePickLabel(variable);
    if ($("p-presence-mode")) $("p-presence-mode").value = tile.presenceMode || "home";
  }
  if (tile.kind === "value" && tile.control === "setpoint") {
    const sp = variableById(tile.setpointVariableId);
    if ($("p-setpoint-var")) $("p-setpoint-var").value = sp ? `${sp.path ? sp.path + " / " : ""}${sp.name} (${sp.id})` : "";
    if ($("p-setpoint-step")) $("p-setpoint-step").value = tile.setpointStep ?? 0.5;
  }
  if (tile.kind === "energyflow") {
    if ($("p-flow-mode")) $("p-flow-mode").value = tile.flowMode || "signed";
    if ($("p-flow-home-override")) $("p-flow-home-override").checked = tile.homeOverride !== false;
    syncFlowPropertyPanels(tile);
    if ($("p-flow-grid-import")) $("p-flow-grid-import").value = flowVarLabel(tile.gridImportVariableId);
    if ($("p-flow-grid-export")) $("p-flow-grid-export").value = flowVarLabel(tile.gridExportVariableId);
    if ($("p-flow-battery")) $("p-flow-battery").value = flowVarLabel(tile.batteryVariableId);
    if ($("p-flow-battery-charge")) $("p-flow-battery-charge").value = flowVarLabel(tile.batteryChargeVariableId);
    if ($("p-flow-battery-discharge")) $("p-flow-battery-discharge").value = flowVarLabel(tile.batteryDischargeVariableId);
    if ($("p-flow-pv")) $("p-flow-pv").value = flowVarLabel(tile.pvVariableId);
    if ($("p-flow-load")) $("p-flow-load").value = flowVarLabel(tile.loadVariableId);
    if ($("p-flow-soc")) $("p-flow-soc").value = flowVarLabel(tile.batterySocVariableId);
    if ($("p-flow-unit")) $("p-flow-unit").value = tile.unit || "W";
    if ($("p-flow-decimals")) $("p-flow-decimals").value = tile.decimals ?? 0;
    if ($("p-flow-threshold")) $("p-flow-threshold").value = tile.flowThreshold ?? 50;
  }
  const imgSource = tile.imageSource || "catalog";
  if ($("p-imagesource")) {
    $("p-imagesource").value = imgSource;
    $("p-image-catalog").classList.toggle("hidden", imgSource === "media");
    $("p-image-media").classList.toggle("hidden", imgSource !== "media");
  }
  if ($("p-image")) {
    fillImageSelect($("p-image"));
    $("p-image").value = tile.imageId || "";
  }
  if ($("p-image-background")) $("p-image-background").checked = tile.imageBackground === true;
  const image = state.images.find((item) => item.id === tile.imageId);
  $("p-image-preview").src = image ? `images/${image.id}.${image.ext}` : "";
  $("p-image-preview").classList.toggle("hidden", !image || imgSource === "media");
  const mediaVar = tile.mediaObjectId ? variableById(tile.mediaObjectId) : variableById(tile.variableId);
  if ($("p-image-variable")) {
    $("p-image-variable").value = imgSource === "media" && mediaVar
      ? `${mediaVar.path ? mediaVar.path + " / " : ""}${mediaVar.name} (${mediaVar.id})`
      : "";
  }
  const camSource = tile.cameraSource || "url";
  if ($("p-camerasource")) {
    $("p-camerasource").value = camSource;
    $("p-camera-url").classList.toggle("hidden", camSource === "media");
    $("p-camera-media").classList.toggle("hidden", camSource !== "media");
  }
  if ($("p-camera-fit")) $("p-camera-fit").value = tile.cameraFit === "contain" ? "contain" : "cover";
  if ($("p-camera-pace")) $("p-camera-pace").value = tile.cameraPace === "interval" ? "interval" : "live";
  if ($("p-camera-live-url")) $("p-camera-live-url").value = tile.cameraLiveUrl || CAMERA_LIVE_URL_DEFAULT;
  if ($("p-camera-live-fps")) $("p-camera-live-fps").value = String(clampCamera(tile.cameraLiveFps, 1, 5, 3));
  if ($("p-camera-jpeg-quality")) $("p-camera-jpeg-quality").value = String(clampCamera(tile.cameraJpegQuality, 40, 95, 80));
  if ($("p-camera-interval")) $("p-camera-interval").value = String(clampCamera(tile.cameraIntervalSec, 5, 300, 10));
  syncCameraPacePanels();
  if (tile.kind === "camera" && $("p-variable")) {
    const camVar = variableById(tile.variableId);
    const hasMedia = Boolean(tile.mediaObjectId) || isMediaPick(camVar);
    const wrongMedia = camSource === "media" && !hasMedia;
    const wrongUrl = camSource === "url" && hasMedia && !isImageUrlRef(camVar);
    $("p-variable").placeholder = camSource === "media"
      ? "Medien-Variable aus dem Baum wählen"
      : "Textvariable mit Bild-URL";
    if (wrongMedia || wrongUrl) {
      $("p-variable").value = `${camVar.path ? camVar.path + " / " : ""}${camVar.name} (${camVar.id}) — passt nicht zur Quelle`;
    }
  }
}

function applyProperties() {
  const tile = selectedTile();
  if (!tile) return;
  tile.title = $("p-title").value;
  if ($("p-show-title") && !$("p-show-title").checked) tile.showTitle = false;
  else delete tile.showTitle;
  if ($("p-show-title-icon")) tile.showTitleIcon = $("p-show-title-icon").checked;
  const headingIcon = $("p-title-icon") ? $("p-title-icon").value : "";
  const plainValue = tile.kind === "value" && (($("p-interaction") && $("p-interaction").value) || "") === "";
  if (plainValue) {
    if (headingIcon) tile.titleIcon = headingIcon;
    else delete tile.titleIcon;
  } else if ($("p-title-icon")) {
    if (headingIcon) tile.titleIcon = headingIcon;
    else delete tile.titleIcon;
    if (tile.kind !== "value") tile.icon = headingIcon;
  }
  const titleAlign = $("p-title-align") ? $("p-title-align").value : "left";
  if (titleAlign === "center" || titleAlign === "right") tile.titleAlign = titleAlign;
  else delete tile.titleAlign;
  if ($("p-own-bg")?.checked && $("p-bg-color")) tile.bgColor = $("p-bg-color").value;
  else delete tile.bgColor;
  if ($("p-own-on")?.checked && $("p-on-color") && !$("p-own-on-wrap")?.classList.contains("hidden")) tile.onColor = $("p-on-color").value;
  else delete tile.onColor;
  const group = ($("p-group") && $("p-group").value.trim()) || "";
  if (group) tile.group = group;
  else delete tile.group;
  const col = Math.max(0, Number($("p-col").value) - 1);
  const row = Math.max(0, Number($("p-row").value) - 1);
  const colSpan = Math.max(1, tile.colSpan || 1);
  const rowSpan = Math.max(1, tile.rowSpan || 1);
  if (fits(tile, col, row, colSpan, rowSpan, tile.tileId)) {
    tile.col = col;
    tile.row = row;
    tile.colSpan = colSpan;
    tile.rowSpan = rowSpan;
  } else {
    setStatus("Position oder Größe passt nicht ins Raster.");
  }
  if (tile.kind === "nav") tile.icon = ($("p-title-icon") && $("p-title-icon").value) || ($("p-icon") ? $("p-icon").value : "");
  if (tile.kind === "value") {
    const prefix = $("p-prefix") ? $("p-prefix").value.trim() : "";
    if (prefix) tile.prefix = prefix;
    else delete tile.prefix;
    tile.unit = $("p-unit") ? $("p-unit").value : (tile.unit || "");
    tile.decimals = Number($("p-decimals")?.value) || 0;
    const variable = variableById(tile.variableId);
    const mode = ($("p-interaction") && $("p-interaction").value) || "";
    delete tile.iconMode;
    delete tile.iconOn;
    delete tile.iconOff;
    delete tile.showIcon;
    delete tile.showStateText;
    delete tile.control;
    delete tile.batteryPad;
    delete tile.batteryLabel;
    if (mode === "switch") {
      tile.control = "switch";
      if ($("p-show-icon")?.checked) {
        tile.iconMode = "toggle";
        tile.iconOn = $("p-iconon").value || "lightOn";
        tile.iconOff = $("p-iconoff").value || "lightOff";
      } else {
        tile.showIcon = false;
      }
      if ($("p-show-state-text")?.checked) tile.showStateText = true;
      delete tile.icon;
      delete tile.iconPlace;
    } else if (mode !== "battery" && mode !== "combo") {
      const iconVal = $("p-value-icon") ? $("p-value-icon").value : "";
      if (iconVal) tile.icon = iconVal;
      else delete tile.icon;
      if (mode === "") {
        const place = $("p-icon-place") ? $("p-icon-place").value : "before";
        tile.iconPlace = place === "after" || place === "none" ? place : "before";
      } else {
        delete tile.iconPlace;
      }
    } else {
      delete tile.iconPlace;
    }
    if ($("p-own-text")?.checked && $("p-text-color") && mode === "") tile.textColor = $("p-text-color").value;
    else delete tile.textColor;
    const thresholdRaw = ($("p-threshold")?.value || "").trim();
    if (mode === "" && thresholdRaw !== "" && !Number.isNaN(Number(thresholdRaw))) {
      tile.threshold = Number(thresholdRaw);
      tile.color = $("p-status-color") ? $("p-status-color").value : "neutral";
    } else if (mode === "") {
      delete tile.threshold;
      delete tile.color;
    }
    if (mode === "combo") {
      tile.control = "combo";
      delete tile.icon;
      tile.dimShowSwitch = Boolean($("p-dim-show-switch")?.checked);
      tile.dimShowBar = Boolean($("p-dim-show-bar")?.checked);
      if ($("p-dim-show-state-text")?.checked) tile.dimShowStateText = true;
      else delete tile.dimShowStateText;
    }
    else if (mode === "blinds") {
      tile.control = "blinds";
      tile.actionKind = "position";
      tile.valueMin = tile.valueMin ?? 0;
      tile.valueMax = tile.valueMax ?? 100;
    } else if (mode === "setpoint") {
      tile.control = "setpoint";
      tile.actionKind = "dim";
      tile.setpointStep = Number($("p-setpoint-step")?.value) || 0.5;
      const spLabel = $("p-setpoint-var")?.value || "";
      const spMatch = spLabel.match(/\((\d+)\)\s*$/);
      tile.setpointVariableId = spMatch ? Number(spMatch[1]) : (tile.setpointVariableId || 0);
    } else if (mode === "battery") {
      tile.control = "battery";
      tile.valueMin = tile.valueMin ?? 0;
      tile.valueMax = tile.valueMax ?? 100;
      if (!tile.unit) tile.unit = "%";
      tile.batteryPad = Number($("p-batterypad").value) || 8;
      tile.batteryLabel = $("p-batterylabel").value || "inside";
      delete tile.actionId;
      delete tile.actionKind;
      tile.confirm = false;
    } else if (DIM_CONTROLS.includes(mode)) {
      tile.actionKind = "dim";
      tile.valueMin = tile.valueMin ?? 0;
      tile.valueMax = tile.valueMax ?? 100;
      if (!tile.unit) tile.unit = "%";
    }
    if (mode === "switch" || mode === "combo") {
      const onRaw = $("p-value-on") ? $("p-value-on").value.trim() : "";
      const offRaw = $("p-value-off") ? $("p-value-off").value.trim() : "";
      const onNum = Number(onRaw);
      const offNum = Number(offRaw);
      tile.valueOn = onRaw !== "" && Number.isFinite(onNum) ? onNum : (mode === "combo" ? (tile.valueMax ?? 100) : 1);
      tile.valueOff = offRaw !== "" && Number.isFinite(offNum) ? offNum : (mode === "combo" ? (tile.valueMin ?? 0) : 0);
    } else {
      delete tile.valueOn;
      delete tile.valueOff;
    }
    if (DIM_CONTROLS.includes(mode) || mode === "blinds") {
      if ($("p-dim-show-percent") && !$("p-dim-show-percent").checked) tile.dimShowPercent = false;
      else delete tile.dimShowPercent;
      const layout = $("p-dim-layout") ? $("p-dim-layout").value : "horizontal";
      if (layout === "stacked") tile.dimLayout = "stacked";
      else delete tile.dimLayout;
      if (mode === "combo") {
        const tapMode = $("p-dim-tap") ? $("p-dim-tap").value : "popup";
        if (tapMode === "on" || tapMode === "off") tile.dimTap = tapMode;
        else delete tile.dimTap;
        delete tile.dimTapTrack;
      } else if (mode === "blinds") {
        const tapMode = $("p-dim-tap") ? $("p-dim-tap").value : "on";
        if (tapMode === "off" || tapMode === "popup") tile.dimTapTrack = tapMode === "popup" ? "off" : "off";
        else if (tapMode === "on") delete tile.dimTapTrack;
        else delete tile.dimTapTrack;
      } else {
        delete tile.dimTapTrack;
        delete tile.dimTap;
      }
    } else {
      delete tile.dimShowPercent;
      delete tile.dimLayout;
      delete tile.dimTapTrack;
    }
    if ((mode === "switch" || mode === "combo" || mode === "blinds" || mode === "setpoint") && $("p-action").checked && variable && variable.action) {
      tile.actionId = tile.actionId || `a-${tile.tileId}`;
      tile.actionKind = mode === "blinds" ? "position" : (DIM_CONTROLS.includes(mode) || mode === "setpoint" ? "dim" : "switch");
      tile.confirm = $("p-confirm").checked;
    } else if (mode !== "battery") {
      delete tile.actionId;
      if (!DIM_CONTROLS.includes(mode)) delete tile.actionKind;
      tile.confirm = false;
    }
  }
  if (tile.kind === "nav") tile.targetPage = $("p-target").value;
  if (tile.kind === "label") {
    tile.text = $("p-text").value;
    delete tile.size;
  }
  if (tile.kind === "chart") {
    tile.chartPeriod = $("p-chart-period") ? $("p-chart-period").value : "24h";
    tile.chartStyle = $("p-chart-style") ? $("p-chart-style").value : "area";
    const chartColor = ($("p-chart-color")?.value || "").toLowerCase();
    if (/^#[0-9a-f]{6}$/.test(chartColor) && chartColor !== "#ffb547") tile.chartColor = chartColor;
    else delete tile.chartColor;
    tile.chartShowAxes = Boolean($("p-chart-axes")?.checked);
    tile.chartShowGrid = Boolean($("p-chart-grid")?.checked);
    tile.chartShowValue = $("p-chart-value") ? $("p-chart-value").checked : true;
    tile.unit = $("p-chart-unit") ? $("p-chart-unit").value : "";
    tile.decimals = $("p-chart-decimals") ? Number($("p-chart-decimals").value) || 0 : 0;
    tile.chartYMode = $("p-chart-y-mode") ? $("p-chart-y-mode").value : "auto";
    if (tile.chartYMode === "fixed") {
      tile.chartYMin = Number($("p-chart-y-min").value);
      tile.chartYMax = Number($("p-chart-y-max").value);
    } else {
      delete tile.chartYMin;
      delete tile.chartYMax;
    }
    tile.chartCompareYesterday = Boolean($("p-chart-yesterday")?.checked);
    tile.chartRefreshSec = Number($("p-chart-refresh")?.value) || 0;
    tile.chartBitmap = $("p-chart-bitmap") ? $("p-chart-bitmap").checked : true;
    const extraRaw = ($("p-chart-extra-ids")?.value || "").split(/[,;\s]+/).map((s) => Number(s.trim())).filter((n) => n > 0);
    const series = [{ variableId: tile.variableId || 0, label: "" }];
    for (const id of extraRaw) series.push({ variableId: id, label: "" });
    tile.chartSeries = series.filter((r) => r.variableId > 0);
    if (tile.chartSeries.length <= 1 && tile.variableId) delete tile.chartSeries;
  }
  if (tile.kind === "label") {
    tile.labelStyle = $("p-label-style") ? $("p-label-style").value : "text";
  }
  if (tile.kind === "separator") {
    const vertical = $("p-sep-orient") && $("p-sep-orient").value === "vertical";
    const wasVertical = tile.orientation === "vertical";
    tile.orientation = vertical ? "vertical" : "horizontal";
    const thickness = Math.max(1, Math.min(12, Number($("p-sep-thickness")?.value) || 2));
    tile.thickness = thickness;
    tile.lineColor = $("p-sep-color")?.value || "#5a7391";
    if (vertical !== wasVertical && state.layout) {
      if (vertical) {
        tile.colSpan = 1;
        tile.rowSpan = currentGrid().rows;
        if (tile.row + tile.rowSpan > currentGrid().rows) tile.row = 0;
      } else {
        tile.rowSpan = 1;
        tile.colSpan = currentGrid().columns;
        if (tile.col + tile.colSpan > currentGrid().columns) tile.col = 0;
      }
    }
  }
  if (tile.kind === "scene") {
    const actionIdRaw = ($("p-scene-actionid")?.value || "").trim();
    const steps = collectSceneSteps();
    tile.sceneSteps = steps;
    delete tile.variableId;
    delete tile.actionKind;
    if (steps.length) {
      tile.actionId = actionIdRaw || tile.actionId || `a-${tile.tileId}`;
      if (!/^[A-Za-z0-9_-]{1,40}$/.test(tile.actionId)) tile.actionId = `a-${tile.tileId}`;
      tile.confirm = Boolean($("p-scene-confirm")?.checked);
    } else {
      delete tile.actionId;
      tile.confirm = false;
    }
    if ($("p-scene-show-icon")?.checked) {
      delete tile.showIcon;
      tile.iconMode = "toggle";
      tile.iconOn = $("p-scene-iconon")?.value || "lightOn";
      tile.iconOff = $("p-scene-iconoff")?.value || "lightOff";
      if (!tile.icon) tile.icon = tile.iconOn;
    } else {
      tile.showIcon = false;
      delete tile.iconMode;
      delete tile.iconOn;
      delete tile.iconOff;
    }
    if ($("p-scene-show-state-text")?.checked) tile.showStateText = true;
    else delete tile.showStateText;
  }
  if (tile.kind === "weather") {
    tile.icon = ($("p-title-icon") && $("p-title-icon").value) || ($("p-weather-icon") ? $("p-weather-icon").value : "") || "sun";
    tile.unit = $("p-weather-unit") ? $("p-weather-unit").value : (tile.unit || "°C");
    tile.decimals = $("p-weather-decimals") ? Number($("p-weather-decimals").value) || 0 : (tile.decimals ?? 1);
  }
  if (tile.kind === "presence") {
    tile.presenceMode = $("p-presence-mode") ? $("p-presence-mode").value : "home";
  }
  if (tile.kind === "display") {
    const source = $("p-displaysource") ? $("p-displaysource").value : "variable";
    tile.displaySource = source;
    tile.icon = $("p-display-icon") ? $("p-display-icon").value : "";
    if (source === "text") {
      tile.text = $("p-display-textarea") ? $("p-display-textarea").value : "";
      delete tile.variableId;
      delete tile.unit;
      delete tile.decimals;
    } else {
      tile.unit = $("p-display-unit") ? $("p-display-unit").value : "";
      tile.decimals = $("p-display-decimals") ? Number($("p-display-decimals").value) || 0 : 0;
      delete tile.text;
    }
  }
  if (tile.kind === "energyflow") {
    tile.flowMode = $("p-flow-mode") ? $("p-flow-mode").value : "signed";
    tile.homeOverride = $("p-flow-home-override") ? $("p-flow-home-override").checked : true;
    tile.unit = $("p-flow-unit") ? $("p-flow-unit").value : "W";
    tile.decimals = $("p-flow-decimals") ? Number($("p-flow-decimals").value) || 0 : 0;
    tile.flowThreshold = $("p-flow-threshold") ? Number($("p-flow-threshold").value) || 0 : 50;
    if (!tile.homeOverride) delete tile.loadVariableId;
  }
  if (tile.kind === "image") {
    const source = $("p-imagesource").value || "catalog";
    if (source === "media") {
      tile.imageSource = "media";
      delete tile.imageId;
    } else {
      tile.imageSource = "catalog";
      tile.imageId = $("p-image").value;
      delete tile.variableId;
    }
    tile.imageBackground = $("p-image-background") ? $("p-image-background").checked : false;
    if (!tile.imageBackground) delete tile.imageBackground;
  }
  if (tile.kind === "camera" && $("p-camerasource")) {
    const source = $("p-camerasource").value || "url";
    tile.cameraSource = source;
    const camVar = variableById(tile.variableId);
    if (source === "media" && !tile.mediaObjectId && (!camVar || !isMediaPick(camVar))) {
      tile.variableId = 0;
    }
    if (source === "url" && (tile.mediaObjectId || isMediaPick(camVar))) {
      tile.variableId = isImageUrlRef(camVar) ? tile.variableId : 0;
      delete tile.mediaObjectId;
    }
    tile.cameraFit = $("p-camera-fit") && $("p-camera-fit").value === "contain" ? "contain" : "cover";
    tile.cameraPace = $("p-camera-pace") && $("p-camera-pace").value === "interval" ? "interval" : "live";
    tile.cameraLiveUrl = ($("p-camera-live-url") ? $("p-camera-live-url").value.trim() : "") || CAMERA_LIVE_URL_DEFAULT;
    tile.cameraJpegQuality = clampCamera($("p-camera-jpeg-quality") && $("p-camera-jpeg-quality").value, 40, 95, 80);
    tile.cameraLiveFps = clampCamera($("p-camera-live-fps") && $("p-camera-live-fps").value, 1, 5, 3);
    tile.cameraIntervalSec = clampCamera($("p-camera-interval") && $("p-camera-interval").value, 5, 300, 10);
  }
  applyFontFields(tile);
  applyExtraTileFields(tile);
  markDirty();
  renderGrid();
  renderProperties();
}

const pInteraction = $("p-interaction");
if (pInteraction) {
  pInteraction.addEventListener("change", () => {
    syncInteractionPropertyPanels();
    applyProperties();
  });
}
if ($("p-chart-y-mode")) {
  $("p-chart-y-mode").addEventListener("change", () => {
    $("p-chart-y-fixed")?.classList.toggle("hidden", $("p-chart-y-mode").value !== "fixed");
    applyProperties();
  });
}
for (const id of ["p-title", "p-show-title", "p-show-title-icon", "p-title-icon", "p-title-align", "p-icon-place", "p-own-bg", "p-bg-color", "p-own-on", "p-on-color", "p-group", "p-col", "p-row", "p-icon", "p-value-icon", "p-prefix", "p-iconon", "p-iconoff", "p-show-icon", "p-show-state-text", "p-unit", "p-decimals", "p-action", "p-value-on", "p-value-off", "p-confirm", "p-actionkind", "p-target", "p-text", "p-label-style", "p-label-size", "p-label-bold", "p-label-italic", "p-label-underline", "p-sep-orient", "p-sep-thickness", "p-sep-color", "p-scene-actionid", "p-scene-show-icon", "p-scene-iconon", "p-scene-iconoff", "p-scene-show-state-text", "p-scene-confirm", "p-scene-text-size", "p-scene-text-bold", "p-scene-text-italic", "p-scene-text-underline", "p-weather-unit", "p-weather-decimals", "p-weather-icon", "p-presence-mode", "p-setpoint-step", "p-image", "p-imagesource", "p-camerasource", "p-camera-fit", "p-camera-pace", "p-camera-live-url", "p-camera-live-fps", "p-camera-jpeg-quality", "p-camera-interval", "p-batterypad", "p-batterylabel", "p-displaysource", "p-display-unit", "p-display-decimals", "p-display-icon", "p-chart-period", "p-chart-style", "p-chart-color", "p-chart-axes", "p-chart-grid", "p-chart-value", "p-chart-unit", "p-chart-decimals", "p-chart-y-mode", "p-chart-y-min", "p-chart-y-max", "p-chart-yesterday", "p-chart-refresh", "p-chart-bitmap", "p-chart-extra-ids", "p-flow-unit", "p-flow-decimals", "p-flow-threshold", "p-flow-slot", "p-flow-mode", "p-dim-show-switch", "p-dim-show-bar", "p-dim-show-percent", "p-dim-show-state-text", "p-dim-layout", "p-dim-tap", "p-own-text", "p-text-color", "p-text-size", "p-threshold", "p-status-color"]) {
  if ($(id)) $(id).addEventListener("change", applyProperties);
}
if ($("p-flow-home-override")) {
  $("p-flow-home-override").addEventListener("change", applyProperties);
}
for (const id of ["p-title-px", "p-text-px", "p-title-bold", "p-title-italic", "p-text-bold", "p-text-italic", "p-text-underline", "p-show-age", "p-gauge-min", "p-gauge-max", "p-gauge-unit", "p-gauge-decimals", "p-countdown-mode"]) {
  if ($(id)) $(id).addEventListener("change", () => {
    syncFontPreview($("p-title-px"), $("p-title-preview"));
    syncFontPreview($("p-text-px"), $("p-text-preview"));
    applyProperties();
  });
}
for (const id of ["p-display-textarea"]) {
  if ($(id)) $(id).addEventListener("input", applyProperties);
}
if ($("p-displaysource")) {
  $("p-displaysource").addEventListener("change", () => {
    const source = $("p-displaysource").value;
    $("p-display-text")?.classList.toggle("hidden", source !== "text");
    applyProperties();
  });
}
if ($("p-camerasource")) {
  $("p-camerasource").addEventListener("change", () => {
    $("p-camera-url").classList.toggle("hidden", $("p-camerasource").value === "media");
    $("p-camera-media").classList.toggle("hidden", $("p-camerasource").value !== "media");
    applyProperties();
  });
}
if ($("p-imagesource")) {
  $("p-imagesource").addEventListener("change", () => {
    $("p-image-catalog").classList.toggle("hidden", $("p-imagesource").value === "media");
    $("p-image-media").classList.toggle("hidden", $("p-imagesource").value !== "media");
    applyProperties();
  });
}
if ($("p-image-background")) $("p-image-background").addEventListener("change", applyProperties);
const displayProfileSelect = $("display-profile");
if (displayProfileSelect) {
  displayProfileSelect.addEventListener("change", () => {
    if (!state.layout) return;
    state.layout.displayProfile = displayProfileSelect.value;
    applyGridAspect();
    markDirty();
    if ($("pages-dialog").open) renderPagesDialog();
  });
}
bindClick("p-save", () => {
  applyProperties();
  $("tile-dialog")?.close();
});
$("tile-dialog")?.addEventListener("close", () => {
  if (selectedTile()?.kind === "scene") applyProperties();
});
bindClick("p-scene-add", () => {
  const tile = selectedTile();
  if (!tile) return;
  if (!Array.isArray(tile.sceneSteps)) tile.sceneSteps = [];
  tile.sceneSteps.push({ variableId: 0, value: 100 });
  renderSceneSteps(tile);
});
function openTileDialog(tile) {
  if (!tile) return;
  state.selected = tile.tileId;
  renderProperties();
  const dialog = $("tile-dialog");
  if (dialog && !dialog.open) dialog.showModal();
}

/* ---------- Detaildialog ---------- */

function detailsVariableSelect(selectedId, filterFn) {
  const picker = document.createElement("select");
  picker.innerHTML = '<option value="0">Keine Variable</option>';
  for (const item of state.variables) {
    if (filterFn && !filterFn(item)) continue;
    const option = document.createElement("option");
    option.value = String(item.id);
    option.textContent = `${item.path ? item.path + " / " : ""}${item.name} (${item.id})`;
    picker.appendChild(option);
  }
  picker.value = String(selectedId || 0);
  return picker;
}

function flowVariableFilter(item) {
  const t = item.type;
  return t === 0 || t === 1 || t === 2;
}

function allFlowVariableKeys() {
  const keys = new Set();
  for (const mode of ["signed", "split"]) {
    for (const key of Object.values(flowSlotMap({ flowMode: mode }))) keys.add(key);
  }
  return keys;
}

function appendEnergyFlowDetails(body, tile, fields, add, check, number, text) {
  const modeSelect = document.createElement("select");
  modeSelect.innerHTML = '<option value="signed">Batterie eine Variable (±)</option><option value="split">Batterie getrennt (Laden / Entladen)</option>';
  modeSelect.value = tile.flowMode || "signed";
  fields.flowMode = add("Berechnungsmodus", modeSelect);
  fields.homeOverride = add("Hausverbrauch aus Variable (sonst berechnet)", check(tile.homeOverride !== false));
  const signedSec = document.createElement("div");
  signedSec.className = "flow-section";
  signedSec.dataset.flowSection = "signed";
  const splitSec = document.createElement("div");
  splitSec.className = "flow-section hidden";
  splitSec.dataset.flowSection = "split";
  const gridSec = document.createElement("div");
  gridSec.className = "flow-section";
  gridSec.dataset.flowSection = "grid";
  body.append(gridSec, signedSec, splitSec);
  const relocate = (slot, parent, label, variableId) => {
    const picker = detailsVariableSelect(variableId, flowVariableFilter);
    fields[`flowSlot_${slot}`] = picker;
    const wrap = document.createElement("label");
    wrap.textContent = label;
    wrap.appendChild(picker);
    parent.appendChild(wrap);
  };
  relocate("gridImport", gridSec, "Netz Bezug", tile.gridImportVariableId);
  relocate("gridExport", gridSec, "Netz Einspeisung", tile.gridExportVariableId);
  const sharedSlots = new Set(["load", "pv", "soc", "gridImport", "gridExport"]);
  for (const [slot, label] of Object.entries(FLOW_SLOT_LABELS.signed)) {
    if (sharedSlots.has(slot)) continue;
    const prop = flowSlotMap({ flowMode: "signed" })[slot];
    relocate(slot, signedSec, label, tile[prop]);
  }
  for (const [slot, label] of Object.entries(FLOW_SLOT_LABELS.split)) {
    if (sharedSlots.has(slot)) continue;
    const prop = flowSlotMap({ flowMode: "split" })[slot];
    relocate(slot, splitSec, label, tile[prop]);
  }
  relocate("pv", body, "PV-Erzeugung", tile.pvVariableId);
  relocate("soc", body, "SOC (optional)", tile.batterySocVariableId);
  const loadWrap = document.createElement("label");
  loadWrap.id = "d-flow-load-wrap";
  loadWrap.textContent = "Hausverbrauch ";
  fields.flowSlot_load = detailsVariableSelect(tile.loadVariableId, flowVariableFilter);
  loadWrap.appendChild(fields.flowSlot_load);
  body.appendChild(loadWrap);
  const socHint = document.createElement("p");
  socHint.className = "hint";
  socHint.textContent = "Pflicht: Netz Bezug, Netz Einspeisung, PV und Batterie (je nach Modus). SOC optional. Kachel 2×2 empfohlen.";
  body.appendChild(socHint);
  const unitRow = document.createElement("div");
  unitRow.className = "row";
  body.appendChild(unitRow);
  const unitWrap = document.createElement("label");
  unitWrap.textContent = "Einheit ";
  fields.flowUnit = text(tile.unit || "W");
  unitWrap.appendChild(fields.flowUnit);
  unitRow.appendChild(unitWrap);
  const decWrap = document.createElement("label");
  decWrap.textContent = "Nachkommastellen ";
  fields.flowDecimals = number(tile.decimals ?? 0, 0, 3);
  decWrap.appendChild(fields.flowDecimals);
  unitRow.appendChild(decWrap);
  fields.flowThreshold = add("Animation ab (W)", number(tile.flowThreshold ?? 50, 0, 999999));
  const sync = () => {
    const mode = modeSelect.value;
    signedSec.classList.toggle("hidden", mode === "split");
    splitSec.classList.toggle("hidden", mode !== "split");
    loadWrap.classList.toggle("hidden", !fields.homeOverride.checked);
  };
  modeSelect.addEventListener("change", sync);
  fields.homeOverride.addEventListener("change", sync);
  sync();
}

/* ---------- Seiten-Dialog ---------- */

const pagesDialog = { draft: [], general: null };

function pageTileLoss(page) {
  return (page.tiles || []).filter((tile) => tile.col + (tile.colSpan || 1) > page.columns || tile.row + (tile.rowSpan || 1) > page.rows).length;
}

function pageField(label, control) {
  const wrap = document.createElement("label");
  wrap.className = "pf";
  const caption = document.createElement("span");
  caption.textContent = label;
  wrap.append(caption, control);
  return wrap;
}

function openPagesDialog(addPage) {
  if (!state.layout) return;
  const dialog = $("pages-dialog");
  pagesDialog.draft = JSON.parse(JSON.stringify(state.layout.pages));
  pagesDialog.general = {
    displayProfile: state.layout.displayProfile,
    colorMode: state.layout.colorMode,
    theme: { ...state.layout.theme },
    dirty: state.dirty
  };
  if (addPage && pagesDialog.draft.length < MAX_PAGES) pagesDialog.draft.push(newPage(state.layout, pagesDialog.draft));
  renderPagesDialog();
  dialog.returnValue = "";
  dialog.showModal();
}

function renderPagesDialog() {
  const list = $("l-pages");
  const pages = pagesDialog.draft;
  const prof = layoutProfile(state.layout);
  list.innerHTML = "";
  for (const page of pages) normalizePageGrid(page, state.layout);
  pages.forEach((page, index) => {
    const card = document.createElement("div");
    card.className = "page-card";
    const fields = document.createElement("div");
    fields.className = "page-grid";

    const title = Object.assign(document.createElement("input"), { type: "text", value: page.title || "", maxLength: 40 });
    title.addEventListener("input", () => { page.title = title.value; });
    const icon = document.createElement("select");
    fillIconSelect(icon, true);
    icon.value = page.icon || "";
    icon.addEventListener("change", () => {
      if (icon.value) page.icon = icon.value;
      else delete page.icon;
    });
    const background = document.createElement("select");
    fillImageSelect(background);
    background.value = page.backgroundId || "";
    background.addEventListener("change", () => {
      if (background.value) page.backgroundId = background.value;
      else delete page.backgroundId;
    });
    const columns = Object.assign(document.createElement("input"), { type: "number", min: 1, max: prof.maxColumns, value: page.columns });
    columns.addEventListener("change", () => {
      page.columns = Math.min(prof.maxColumns, Math.max(1, Number(columns.value) || page.columns));
      page.columnWeights = normalizeWeights(page.columnWeights, page.columns);
      renderPagesDialog();
    });
    const rows = Object.assign(document.createElement("input"), { type: "number", min: 1, max: prof.maxRows, value: page.rows });
    rows.addEventListener("change", () => {
      page.rows = Math.min(prof.maxRows, Math.max(1, Number(rows.value) || page.rows));
      page.rowWeights = normalizeWeights(page.rowWeights, page.rows);
      renderPagesDialog();
    });

    const remove = document.createElement("button");
    remove.type = "button";
    remove.textContent = "Entfernen";
    remove.disabled = pages.length <= 1;
    remove.addEventListener("click", () => {
      pages.splice(index, 1);
      renderPagesDialog();
    });
    const lost = pageTileLoss(page);
    const count = (page.tiles || []).length;
    const countText = document.createElement("span");
    countText.className = "page-count" + (lost ? " warn" : "");
    countText.textContent = lost
      ? `${count} Kacheln, ${lost} fallen weg`
      : `${count} ${count === 1 ? "Kachel" : "Kacheln"}`;
    const removeCell = document.createElement("div");
    removeCell.className = "pf";
    removeCell.append(countText, remove);

    fields.append(
      pageField("Titel", title),
      pageField("Symbol", icon),
      pageField("Hintergrund", background),
      pageField("Spalten", columns),
      pageField("Zeilen", rows),
      removeCell
    );

    const weightRow = (label, weights, key, prefix) => {
      const heading = document.createElement("div");
      heading.className = "page-weights-label";
      heading.textContent = label;
      const grid = document.createElement("div");
      grid.className = "page-grid";
      weights.forEach((weight, position) => {
        const input = Object.assign(document.createElement("input"), { type: "number", min: 1, max: 3, step: 1, value: weight });
        input.addEventListener("change", () => {
          const next = Math.min(3, Math.max(1, Math.round(Number(input.value)) || 2));
          input.value = next;
          page[key][position] = next;
        });
        grid.appendChild(pageField(`${prefix} ${position + 1}`, input));
      });
      return [heading, grid];
    };
    card.append(
      fields,
      ...weightRow("Breite je Spalte (1 schmal, 2 normal, 3 breit)", page.columnWeights, "columnWeights", "Spalte"),
      ...weightRow("Höhe je Zeile (1 flach, 2 normal, 3 hoch)", page.rowWeights, "rowWeights", "Zeile")
    );
    list.appendChild(card);
  });
  $("pages-add").disabled = pages.length >= MAX_PAGES;
}

function restorePagesGeneral() {
  const saved = pagesDialog.general;
  if (!saved || !state.layout) return;
  state.layout.displayProfile = saved.displayProfile;
  state.layout.colorMode = saved.colorMode;
  state.layout.theme = applyTheme(saved.theme);
  fillProfileSelect($("display-profile"), saved.displayProfile);
  $("color-mono").checked = saved.colorMode === "mono";
  document.getElementById("editor-screen")?.classList.toggle("mono", saved.colorMode === "mono");
  applyGridAspect();
  state.dirty = saved.dirty;
  updateTitle();
}

$("pages-add").addEventListener("click", () => {
  if (pagesDialog.draft.length >= MAX_PAGES) return;
  pagesDialog.draft.push(newPage(state.layout, pagesDialog.draft));
  renderPagesDialog();
});

$("pages-dialog").addEventListener("close", () => {
  if ($("pages-dialog").returnValue !== "ok") {
    restorePagesGeneral();
    return;
  }
  if (!state.layout) return;
  const pages = pagesDialog.draft;
  let dropped = 0;
  pages.forEach((page, index) => {
    normalizePageGrid(page, state.layout);
    page.title = (page.title || "").trim() || `Seite ${index + 1}`;
    const before = page.tiles.length;
    page.tiles = page.tiles.filter((tile) => tile.col + (tile.colSpan || 1) <= page.columns && tile.row + (tile.rowSpan || 1) <= page.rows);
    dropped += before - page.tiles.length;
  });
  for (const page of pages) {
    for (const tile of page.tiles) {
      if (tile.kind === "nav" && !pages.some((item) => item.id === tile.targetPage)) tile.targetPage = pages[0].id;
    }
  }
  state.layout.pages = pages;
  if (!pages.some((page) => page.id === state.page)) state.page = pages[0].id;
  if (state.selected && !currentPage().tiles.some((tile) => tile.tileId === state.selected)) state.selected = null;
  markDirty();
  renderPageTabs();
  renderGrid();
  renderProperties();
  if (dropped) setStatus(`${dropped} ${dropped === 1 ? "Kachel passte" : "Kacheln passten"} nicht mehr ins Raster und ${dropped === 1 ? "wurde" : "wurden"} entfernt.`);
});

/* ---------- Variablen ---------- */

function variableById(id) {
  return state.variables.find((item) => item.id === Number(id)) || null;
}

function variablePickLabel(variable) {
  if (!variable) return "";
  return `${variable.path ? variable.path + " / " : ""}${variable.name} (${variable.id})`;
}

function iconName(icon) {
  const found = ICONS.find((item) => item[0] === icon);
  return found ? found[1] : "";
}

function iconCode(icon, on) {
  if (icon === "lightOn" || (icon === "light" && on)) return 0xF0335;
  if (icon === "lightOff" || icon === "light") return 0xF0336;
  const found = ICONS.find((item) => item[0] === icon);
  return found && found[2] ? found[2] : 0;
}

function iconVisual(icon, on) {
  const code = iconCode(icon, on);
  if (!code) return escapeHtml(iconName(icon));
  return `<span class="mdi">&#x${code.toString(16)};</span>`;
}

function labelTextClass(tile) {
  const size = tile.textSize || "auto";
  const names = ["label-text", `size-${size}`];
  if (tile.textBold) names.push("bold");
  if (tile.textItalic) names.push("italic");
  if (tile.textUnderline) names.push("underline");
  return names.join(" ");
}

function pageTitleIn(id) {
  const page = state.layout ? state.layout.pages.find((item) => item.id === id) : null;
  return page ? page.title || pageTitle(id) : pageTitle(id);
}

function escapeHtml(text) {
  return String(text).replace(/[&<>"]/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;" }[char]));
}

function renderVariableTree() {
  const scrollHost = $("variable-tree-scroll");
  if (scrollHost) state.variableTreeScroll = scrollHost.scrollTop;
  const tree = $("variable-tree");
  for (const details of tree.querySelectorAll("details.group")) {
    const key = details.dataset.groupKey;
    if (key) state.variableTreeOpen[key] = details.open;
  }
  tree.innerHTML = "";
  const needle = $("variable-search").value.trim().toLowerCase();
  const onlyActions = $("only-actions").checked;
  const groups = new Map();
  for (const variable of state.variables) {
    if (onlyActions && !variable.action && !isMediaPick(variable) && !isImageUrlRef(variable)) continue;
    if (!isMediaObject(variable) && !variable.mediaRole && Number(variable.type) > 3) continue;
    if (!variableMatches(variable, needle)) continue;
    const key = variable.path || "Ohne Kategorie";
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(variable);
  }
  const keys = [...groups.keys()].sort((left, right) => left.localeCompare(right, "de"));
  for (const key of keys) {
    const details = document.createElement("details");
    details.className = "group";
    details.dataset.groupKey = key;
    details.open = state.variableTreeOpen[key] !== undefined
      ? Boolean(state.variableTreeOpen[key])
      : Boolean(needle) || keys.length <= 3;
    details.addEventListener("toggle", () => {
      state.variableTreeOpen[key] = details.open;
    });
    const summary = document.createElement("summary");
    summary.innerHTML = `${escapeHtml(key)} <small>(${groups.get(key).length})</small>`;
    details.appendChild(summary);
    for (const variable of groups.get(key).sort((left, right) => left.name.localeCompare(right.name, "de"))) {
      const bound = boundVariableIds(selectedTile());
      const row = document.createElement("div");
      row.className = "variable" + (bound.has(variable.id) ? " bound" : "");
      row.dataset.variableId = String(variable.id);
      row.draggable = true;
      row.title = `${variable.path ? variable.path + " / " : ""}${variable.name}\nID ${variable.id}${variable.profile ? "\nProfil " + variable.profile : ""}`;
      const mediaBadge = isMediaPick(variable) ? ' <span class="badge badge-media">Medien</span>' : (isImageUrlRef(variable) ? ' <span class="badge badge-media">Bild-URL</span>' : "");
      row.innerHTML = `<span></span><span class="meta">${escapeHtml(variableTypeLabel(variable))}${mediaBadge}${variable.action ? ' <span class="badge">schaltbar</span>' : ""}</span>`;
      row.firstChild.textContent = variable.name;
      row.addEventListener("dragstart", (event) => {
        event.dataTransfer.setData("text/homedisplay-variable", String(variable.id));
        event.dataTransfer.effectAllowed = "copy";
      });
      row.addEventListener("click", () => {});
      row.addEventListener("dblclick", () => {
        const cell = freeCell(1, 1);
        if (!cell) {
          setStatus("Auf dieser Seite ist keine Zelle frei.");
          return;
        }
        if (isMediaPick(variable)) addTile("image", cell.col, cell.row, variable);
        else if (isImageUrlRef(variable)) addTile("camera", cell.col, cell.row, variable);
        else addTile("value", cell.col, cell.row, variable);
      });
      details.appendChild(row);
    }
    tree.appendChild(details);
  }
  if (!keys.length) tree.innerHTML = '<div class="hint">Keine Variablen gefunden.</div>';
  const first = tree.querySelector(".variable.bound");
  if (first) first.scrollIntoView({ block: "nearest" });
  else if (scrollHost) scrollHost.scrollTop = state.variableTreeScroll;
}

function boundVariableIds(tile) {
  const ids = new Set();
  if (!tile) return ids;
  const add = (value) => { const n = Number(value); if (n > 0) ids.add(n); };
  add(tile.variableId);
  add(tile.mediaObjectId);
  add(tile.forecastVariableId);
  add(tile.setpointVariableId);
  for (const key of Object.keys(tile)) {
    if (key.endsWith("VariableId")) add(tile[key]);
  }
  for (const step of tile.sceneSteps || []) {
    add(step.variableId);
    add(step.colorVariableId);
  }
  return ids;
}

function variableMatches(variable, needle) {
  if (!needle) return true;
  return `${variable.name} ${variable.path} ${variable.id} ${variable.profile || ""}`.toLowerCase().includes(needle);
}

let pickerState = null;

function pickerFilter(kind, variable) {
  if (kind === "image") return isMediaPick(variable);
  if (kind === "forecast") return Number(variable.type) === 3;
  if (kind === "setpoint" || kind === "weather" || kind === "flow") return Number(variable.type) === 1 || Number(variable.type) === 2;
  return true;
}

function fillVariablePickerList() {
  const list = $("var-picker-list");
  if (!list || !pickerState) return;
  const needle = ($("var-picker-search").value || "").trim().toLowerCase();
  const onlyActions = $("var-picker-actions").checked;
  list.innerHTML = "";
  const matches = state.variables.filter((variable) => {
    if (onlyActions && !variable.action) return false;
    if (!pickerFilter(pickerState.kind, variable)) return false;
    return variableMatches(variable, needle);
  });
  for (const variable of matches.slice(0, 200)) {
    const row = document.createElement("button");
    row.type = "button";
    row.dataset.id = String(variable.id);
    row.textContent = `${variable.path ? variable.path + " / " : ""}${variable.name} (${variable.id})${variable.action ? " · schaltbar" : ""}`;
    row.addEventListener("click", () => {
      for (const item of list.querySelectorAll("button.selected")) item.classList.remove("selected");
      row.classList.add("selected");
    });
    list.appendChild(row);
  }
}

function acceptVariablePicker() {
  const selected = $("var-picker-list")?.querySelector("button.selected");
  const id = Number(selected && selected.dataset.id);
  const variable = variableById(id);
  const tile = selectedTile();
  if (!variable || !tile || !pickerState) return;
  const kind = pickerState.kind;
  if (kind === "flow") assignFlowVariable(tile, pickerState.slot, variable);
  else if (kind === "image") assignMediaVariable(tile, variable);
  else if (kind === "forecast") {
    tile.forecastVariableId = variable.id;
    markDirty();
    renderProperties();
  } else if (kind === "setpoint") {
    tile.setpointVariableId = variable.id;
    markDirty();
    renderProperties();
  } else if (kind === "row") {
    const index = Number(pickerState.slot);
    if (!Array.isArray(tile.rows)) tile.rows = [];
    if (tile.rows[index]) {
      tile.rows[index].variableId = variable.id;
      if (!tile.rows[index].title) tile.rows[index].title = variable.name;
      markDirty();
      renderRowEditor(tile);
      renderGrid();
    }
  } else if (kind === "sceneStep" || kind === "sceneStepColor") {
    const tile = selectedTile();
    const index = Number(pickerState.slot);
    if (tile && Array.isArray(tile.sceneSteps) && tile.sceneSteps[index]) {
      if (kind === "sceneStep") tile.sceneSteps[index].variableId = variable.id;
      else tile.sceneSteps[index].colorVariableId = variable.id;
      markDirty();
      renderSceneSteps(tile);
      renderGrid();
    }
  } else if (kind === "scene" || kind === "weather" || kind === "presence" || kind === "variable" || kind === "gauge" || kind === "countdown" || kind === "appointment") {
    if (tile.kind === "camera" && isMediaPick(variable)) assignCameraMedia(tile, variable);
    else assignVariable(tile, variable);
  }
  $("var-picker").close();
}

function openVariablePicker(kind, slot) {
  const dialog = $("var-picker");
  if (!dialog) return;
  pickerState = { kind, slot };
  const titles = { variable: "Variable", scene: "Aktions-Variable", weather: "Temperatur", forecast: "Prognose", presence: "Anwesenheit", setpoint: "Sollwert", flow: "Energiefluss", image: "Medien" };
  $("var-picker-title").textContent = titles[kind] || "Variable auswählen";
  $("var-picker-search").value = "";
  $("var-picker-actions").checked = kind === "scene";
  fillVariablePickerList();
  dialog.showModal();
  $("var-picker-search").focus();
}

document.querySelectorAll(".pick-var").forEach((button) => {
  button.addEventListener("click", () => openVariablePicker(button.dataset.pick, button.dataset.slot || ""));
});
$("var-picker-search")?.addEventListener("input", fillVariablePickerList);
$("var-picker-actions")?.addEventListener("change", fillVariablePickerList);
$("var-picker-list")?.addEventListener("dblclick", acceptVariablePicker);
$("var-picker-ok")?.addEventListener("click", acceptVariablePicker);
document.addEventListener("keydown", (event) => {
  if (event.key === "Escape" && $("var-picker")?.open) $("var-picker").close();
});

$("variable-search").addEventListener("input", renderVariableTree);
$("only-actions").addEventListener("change", renderVariableTree);

for (const item of document.querySelectorAll(".palette-item")) {
  item.addEventListener("dragstart", (event) => {
    event.dataTransfer.setData("text/homedisplay-kind", item.dataset.kind);
    event.dataTransfer.setData("text/homedisplay-control", item.dataset.control || "");
    event.dataTransfer.effectAllowed = "copy";
  });
  item.addEventListener("dblclick", () => {
    if (!state.layout) return;
    const cell = freeCell(1, 1);
    if (cell) addTile(item.dataset.kind, cell.col, cell.row, null, item.dataset.control || "");
    else setStatus("Auf dieser Seite ist keine Zelle frei.");
  });
}

/* ---------- Speichern ---------- */

function validateLayout(layout) {
  if (!layout.name) throw new Error("Die Ansicht braucht einen Namen.");
  const seen = new Set();
  for (const page of layout.pages) {
    for (const tile of page.tiles) {
      if (seen.has(tile.tileId)) throw new Error("Doppelte Kachelkennung.");
      seen.add(tile.tileId);
      if (tile.kind === "value" && tile.control === "battery" && !tile.variableId) throw new Error(`Die Batteriekachel „${tile.title || tile.tileId}“ braucht eine Variable.`);
      if (tile.kind === "camera" && !tile.variableId && !tile.mediaObjectId) {
        throw new Error(`Die Kamerakachel „${tile.title || tile.tileId}“ braucht eine Variable oder ein Medienobjekt.`);
      }
      if (tile.kind === "camera" && (tile.variableId || tile.mediaObjectId)) {
        const camVar = tile.variableId ? variableById(tile.variableId) : null;
        const src = tile.cameraSource || "url";
        const hasMedia = Boolean(tile.mediaObjectId) || isMediaPick(camVar);
        if (src === "media" && !hasMedia) throw new Error(`Die Kamerakachel „${tile.title || tile.tileId}“ erwartet Medien (Integer mit Media-ID oder Medienobjekt).`);
        if (src === "url" && hasMedia && !isImageUrlRef(camVar)) throw new Error(`Die Kamerakachel „${tile.title || tile.tileId}“: Quelle „Medien“ wählen oder Bild-URL-Variable zuweisen.`);
      }
      if (tile.kind === "value" && DIM_CONTROLS.includes(tile.control) && !tile.actionId) throw new Error(`„${tile.title || tile.tileId}“ braucht eine schaltbare Dimmvariable.`);
      if (tile.kind === "value" && tile.actionId) {
        const variable = variableById(tile.variableId);
        if (!variable || !variable.action) throw new Error(`„${tile.title || tile.tileId}“ ist schaltbar, hat aber keine schaltbare Variable.`);
      }
      if (tile.kind === "nav" && !layout.pages.some((item) => item.id === tile.targetPage)) throw new Error(`Der Seitenwechsler „${tile.title || tile.tileId}“ zeigt auf eine fehlende Seite.`);
      if (tile.kind === "label" && !(tile.text || "").trim()) throw new Error("Eine Textkachel ist leer.");
      if (tile.kind === "display") {
        const src = tile.displaySource || "variable";
        if (src === "text" && !(tile.text || "").trim()) throw new Error(`Die Anzeigekachel „${tile.title || tile.tileId}“ braucht Text.`);
        if (src === "variable" && !tile.variableId) throw new Error(`Die Anzeigekachel „${tile.title || tile.tileId}“ braucht eine Variable.`);
      }
      if (tile.kind === "chart" && !tile.variableId) {
        throw new Error(`Die Diagrammkachel „${tile.title || tile.tileId}“ braucht eine Variable (Archiv-Protokollierung).`);
      }
      if (tile.kind === "chart") {
        const ids = [tile.variableId, ...(tile.chartSeries || []).map((r) => r.variableId)].filter(Boolean);
        for (const id of ids) {
          if (!variableArchived(id)) throw new Error(`Diagramm „${tile.title || tile.tileId}“: Variable ${id} ist nicht archiviert.`);
        }
      }
      if (tile.kind === "scene" && !(Array.isArray(tile.sceneSteps) && tile.sceneSteps.some((step) => step.variableId))) {
        throw new Error(`Szene „${tile.title || tile.tileId}“ braucht eine schaltbare Aktions-Variable.`);
      }
      if (tile.kind === "weather" && !tile.variableId) throw new Error(`Wetter „${tile.title || tile.tileId}“ braucht eine Temperaturvariable.`);
      if (tile.kind === "presence" && !tile.variableId) throw new Error(`Anwesenheit „${tile.title || tile.tileId}“ braucht eine Variable.`);
      if (tile.kind === "value" && tile.control === "setpoint" && !tile.setpointVariableId) {
        throw new Error(`Setpoint „${tile.title || tile.tileId}“ braucht eine Sollwert-Variable (zweiter Klick im Baum).`);
      }
      if (tile.kind === "value" && tile.control === "blinds" && !tile.actionId) {
        throw new Error(`Rolladen „${tile.title || tile.tileId}“ braucht eine schaltbare Variable.`);
      }
      if (tile.kind === "energyflow" && !flowConfigured(tile)) {
        throw new Error(`Energiefluss „${tile.title || tile.tileId}“: alle Pflicht-Variablen zuweisen (siehe Eigenschaften).`);
      }
      if (tile.kind === "image") {
        if ((tile.imageSource || "catalog") === "media") {
          if (!tile.variableId && !tile.mediaObjectId) throw new Error("Eine Bildkachel (Medien) braucht eine Medien-Variable oder ein Medienobjekt.");
        } else if (!tile.imageId) throw new Error("Eine Bildkachel hat kein Bild.");
      }
    }
    if (page.backgroundId && !state.images.some((image) => image.id === page.backgroundId)) {
      throw new Error(`Die Seite „${page.title || page.id}“ verweist auf ein gelöschtes Hintergrundbild.`);
    }
  }
}

async function store(publish) {
  if (!state.layout) {
    setStatus("Keine Ansicht geladen.");
    return;
  }
  setStatus(publish ? "Veröffentliche …" : "Speichere …");
  try {
    state.layout.showTabs = $("show-tabs").checked;
    state.layout.colorMode = $("color-mono")?.checked ? "mono" : "color";
    state.layout.theme = readThemeInputs();
    state.layout.displayProfile = $("display-profile").value || defaultProfileId();
    validateLayout(state.layout);
    if (publish) {
      try {
        const settings = await call("settings");
        for (const panel of settings.panels || []) {
          if (panel.displayProfile && panel.displayProfile !== state.layout.displayProfile) {
            setHint(`Hinweis: Ansicht-Profil „${state.layout.displayProfile}“ weicht von Anzeige ${panel.name} ab (${panel.displayProfile}).`);
            break;
          }
        }
      } catch (error) {
        /* ignore */
      }
    }
    const result = await call(publish ? "publish" : "save", { layout: state.layout });
    if (result && result.ok === false) throw new Error(result.error || "Speichern fehlgeschlagen");
    const previousId = state.layout.id;
    const previousName = state.layout.name;
    state.catalog = await call("catalog");
    const saved = state.catalog.layouts.find((item) => (previousId ? item.id === previousId : item.name === previousName));
    state.dirty = false;
    if (saved) selectLayout(saved, true);
    else updateTitle();
    renderLayoutSelect();
    setStatus(publish ? "Ansicht veröffentlicht. Die Anzeigen übernehmen sie beim nächsten Abruf." : "Entwurf gespeichert.");
  } catch (error) {
    setStatus(error.message || "Speichern fehlgeschlagen.");
  }
}

function bindClick(id, handler) {
  const el = $(id);
  if (!el) return;
  el.addEventListener("click", (event) => {
    event.preventDefault();
    handler(event);
  });
}

bindClick("save", () => store(false));
bindClick("publish", () => store(true));
bindClick("tb-save", () => store(false));
bindClick("tb-publish", () => store(true));
$("new-layout").addEventListener("click", () => newLayout(prompt("Name der neuen Ansicht", "Neue Ansicht") || "Neue Ansicht"));
$("template-layout").addEventListener("click", () => {
  fillProfileSelect($("t-display-profile"), state.layout ? state.layout.displayProfile : defaultProfileId());
  $("t-name").value = "Übersicht";
  const dialog = $("template-dialog");
  dialog.returnValue = "";
  dialog.showModal();
  dialog.onclose = async () => {
    if (dialog.returnValue !== "ok") return;
    try {
      const layout = await call("template", {
        name: $("t-name").value.trim() || "Übersicht",
        displayProfile: $("t-display-profile").value || defaultProfileId()
      });
      layout.id = "";
      layout.version = 0;
      layout.published = false;
      selectLayout(layout);
      markDirty();
      setStatus("Übersicht geladen. Variablen zuordnen, dann speichern.");
    } catch (error) {
      setStatus(error.message);
    }
  };
});
$("duplicate-layout").addEventListener("click", () => {
  if (!state.layout) return;
  const copy = JSON.parse(JSON.stringify(state.layout));
  copy.id = "";
  copy.version = 0;
  copy.published = false;
  copy.name = `${copy.name} Kopie`;
  selectLayout(copy);
  markDirty();
});
$("edit-details").addEventListener("click", () => {
  const tile = selectedTile();
  if (tile) openTileDialog(tile);
});
$("edit-duplicate")?.addEventListener("click", () => {
  placeTileCopy(selectedTile());
});
$("edit-style")?.addEventListener("click", () => {
  const tile = selectedTile();
  if (!tile) return;
  state.styleSource = tile.tileId;
  setStatus("Stil gemerkt. Zielkachel anklicken.");
});
$("p-row-add")?.addEventListener("click", () => {
  const tile = selectedTile();
  if (!tile) return;
  if (!Array.isArray(tile.rows)) tile.rows = [];
  if (tile.rows.length >= 6) {
    setStatus("Höchstens sechs Zeilen.");
    return;
  }
  tile.rows.push({ variableId: 0, title: "Zeile", unit: "", decimals: 0 });
  markDirty();
  renderRowEditor(tile);
  renderGrid();
});
$("layout-name").addEventListener("input", () => {
  if (!state.layout) return;
  state.layout.name = $("layout-name").value;
  markDirty();
});
$("color-mono")?.addEventListener("change", () => {
  if (!state.layout) return;
  state.layout.colorMode = $("color-mono").checked ? "mono" : "color";
  document.getElementById("editor-screen")?.classList.toggle("mono", $("color-mono").checked);
  markDirty();
});
for (const id of ["theme-bg", "theme-button", "theme-accent"]) {
  $(id)?.addEventListener("input", () => {
    if (!state.layout) return;
    state.layout.theme = applyTheme(readThemeInputs());
    markDirty();
  });
}
$("layout-select").addEventListener("change", () => {
  const layout = state.catalog.layouts.find((item) => item.id === $("layout-select").value);
  if (!layout) return;
  if (state.dirty && !confirm("Änderungen verwerfen?")) {
    renderLayoutSelect();
    return;
  }
  selectLayout(layout);
});
for (const id of ["show-tabs"]) {
  const field = $(id);
  if (!field) continue;
  field.addEventListener("change", () => {
    if (!state.layout) return;
    state.layout.showTabs = $("show-tabs").checked;
    if ($("color-mono")) state.layout.colorMode = $("color-mono").checked ? "mono" : "color";
    markDirty();
    renderGrid();
    renderProperties();
  });
}
$("export").addEventListener("click", () => {
  if (!state.layout) return;
  const blob = new Blob([JSON.stringify(state.layout, null, 2)], { type: "application/json" });
  const link = document.createElement("a");
  link.href = URL.createObjectURL(blob);
  link.download = `${state.layout.name || "ansicht"}.json`;
  link.click();
  URL.revokeObjectURL(link.href);
});
$("import").addEventListener("click", () => $("import-file").click());
$("import-file").addEventListener("change", async () => {
  const file = $("import-file").files[0];
  if (!file) return;
  try {
    const layout = JSON.parse(await file.text());
    layout.id = "";
    layout.version = 0;
    layout.published = false;
    selectLayout(layout);
    markDirty();
    setStatus("Ansicht importiert. Noch nicht gespeichert.");
  } catch (error) {
    setStatus("Die Datei ist keine gültige Ansicht.");
  }
  $("import-file").value = "";
});

document.addEventListener("keydown", (event) => {
  const el = event.target;
  const typing = el && typeof el.matches === "function" && (el.matches("input, textarea, select") || el.isContentEditable);
  if (typing || document.querySelector("dialog[open]")) return;
  if (event.key === "Delete" && state.selected) deleteSelected();
  if (event.key === "Enter" && state.selected) {
    const tile = selectedTile();
    if (tile) openTileDialog(tile);
  }
  if (event.ctrlKey && event.key.toLowerCase() === "s") {
    event.preventDefault();
    store(false);
  }
  if (event.ctrlKey && !event.altKey && event.key.toLowerCase() === "c" && !event.repeat) {
    const tile = selectedTile();
    if (!tile) return;
    event.preventDefault();
    state.tileClipboard = JSON.parse(JSON.stringify(tile));
    setStatus("Kachel kopiert. Strg+V fügt sie ein.");
  }
  if (event.ctrlKey && !event.altKey && event.key.toLowerCase() === "v" && !event.repeat) {
    if (!state.tileClipboard) {
      setStatus("Keine Kachel kopiert.");
      return;
    }
    event.preventDefault();
    placeTileCopy(state.tileClipboard);
  }
});

/* ---------- Bilder ---------- */

async function loadImages() {
  const result = await call("images");
  state.images = result.images || [];
}

function renderImageList() {
  const list = $("image-list");
  list.innerHTML = "";
  for (const image of state.images) {
    const card = document.createElement("div");
    card.className = "image-card";
    card.innerHTML = `<img src="images/${image.id}.${image.ext}" alt=""><div></div><small>${image.width}×${image.height}</small><br>`;
    card.querySelector("div").textContent = image.name;
    const use = document.createElement("button");
    use.type = "button";
    use.textContent = "Verwenden";
    use.addEventListener("click", () => {
      const tile = selectedTile();
      if (tile && tile.kind === "image") {
        tile.imageId = image.id;
        markDirty();
      } else {
        const cell = freeCell(1, 1);
        if (!cell) {
          setStatus("Auf dieser Seite ist keine Zelle frei.");
          return;
        }
        addTile("image", cell.col, cell.row, null);
        selectedTile().imageId = image.id;
      }
      $("images-dialog").close();
      renderGrid();
      renderProperties();
    });
    const remove = document.createElement("button");
    remove.type = "button";
    remove.textContent = "Löschen";
    remove.addEventListener("click", async () => {
      if (!confirm(`Bild „${image.name}“ löschen?`)) return;
      try {
        const result = await call("deleteImage", { id: image.id });
        if (!result.ok) throw new Error(result.error);
        await loadImages();
        renderImageList();
        renderGrid();
        setStatus("Bild gelöscht.");
      } catch (error) {
        setStatus(error.message);
      }
    });
    card.append(use, remove);
    list.appendChild(card);
  }
  if (!state.images.length) list.innerHTML = '<div class="hint">Noch keine Bilder hochgeladen.</div>';
}

function openImages() {
  renderImageList();
  $("images-dialog").showModal();
}

$("open-images").addEventListener("click", openImages);
$("image-file").addEventListener("change", async () => {
  const file = $("image-file").files[0];
  if (!file) return;
  setStatus("Bild wird hochgeladen …");
  try {
    const data = await new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(reader.result);
      reader.onerror = () => reject(new Error("Datei konnte nicht gelesen werden."));
      reader.readAsDataURL(file);
    });
    const result = await call("uploadImage", { name: file.name.replace(/\.[^.]+$/, ""), data });
    if (!result.ok) throw new Error(result.error);
    await loadImages();
    renderImageList();
    const tile = selectedTile();
    if (tile && tile.kind === "image" && !tile.imageId) {
      tile.imageId = result.image.id;
      markDirty();
      renderGrid();
      renderProperties();
    }
    setStatus(`Bild „${result.image.name}“ gespeichert.`);
  } catch (error) {
    setStatus(error.message);
  }
  $("image-file").value = "";
});

/* ---------- Einstellungen ---------- */

function layoutCaption(id) {
  const found = (state.catalog.layouts || []).find((layout) => layout.id === id);
  return found ? found.name : (id || "–");
}

function renderSettings(settings) {
  $("s-host").value = settings.serviceHost || "";
  $("s-service-url").textContent = settings.serviceUrl || "kein Dienst – zuerst eine Anzeige-Instanz anlegen";
  $("s-service-instance").textContent = settings.serviceInstance
    ? `${settings.serviceInstanceName} (ID ${settings.serviceInstance})`
    : "–";
  $("s-admin-url").textContent = settings.adminUrl || "–";
  const body = $("s-panels").querySelector("tbody");
  body.innerHTML = "";
  for (const panel of settings.panels || []) {
    const row = document.createElement("tr");
    if (panel.serves) row.classList.add("serves");
    if (!panel.active) row.classList.add("inactive");
    const cells = [
      `${panel.name} (${panel.id})${panel.active ? "" : " – inaktiv"}`,
      panel.paired ? (panel.pairing || "gekoppelt") : "nicht gekoppelt",
      panel.status || "–",
      layoutCaption(panel.layoutId)
    ];
    for (const text of cells) {
      const cell = document.createElement("td");
      cell.textContent = text;
      row.appendChild(cell);
    }
    body.appendChild(row);
  }
  if (!(settings.panels || []).length) {
    body.innerHTML = '<tr><td colspan="4" class="hint">Noch keine Anzeige-Instanz angelegt.</td></tr>';
  }
  const pick = $("s-bright-panel");
  if (pick) {
    pick.innerHTML = "";
    for (const panel of settings.panels || []) {
      const option = document.createElement("option");
      option.value = String(panel.id);
      option.textContent = panel.name;
      pick.appendChild(option);
    }
    const current = (settings.panels || []).find((panel) => panel.serves) || (settings.panels || [])[0];
    if (current) {
      pick.value = String(current.id);
      applyBrightnessForm(current);
    }
    pick.onchange = () => {
      const chosen = (settings.panels || []).find((panel) => String(panel.id) === pick.value);
      if (chosen) applyBrightnessForm(chosen);
    };
  }
}

function applyBrightnessForm(panel) {
  if ($("s-night-dim")) $("s-night-dim").checked = panel.nightDim !== false;
  if ($("s-day-brightness")) $("s-day-brightness").value = panel.dayBrightness ?? 70;
  if ($("s-night-brightness")) $("s-night-brightness").value = panel.nightBrightness ?? 15;
  if ($("s-night-from")) $("s-night-from").value = panel.nightFrom ?? 22;
  if ($("s-night-to")) $("s-night-to").value = panel.nightTo ?? 6;
}

async function openSettings() {
  const dialog = $("settings-dialog");
  try {
    renderSettings(await call("settings"));
  } catch (error) {
    setStatus(error.message);
    return;
  }
  dialog.returnValue = "";
  dialog.showModal();
  dialog.onclose = async () => {
    if (dialog.returnValue !== "ok") return;
    try {
      const result = await call("saveSettings", { serviceHost: $("s-host").value.trim() });
      if ($("s-bright-panel") && $("s-bright-panel").value) {
        await call("saveBrightness", {
          panel: Number($("s-bright-panel").value),
          nightDim: Boolean($("s-night-dim")?.checked),
          dayBrightness: Number($("s-day-brightness")?.value),
          nightBrightness: Number($("s-night-brightness")?.value),
          nightFrom: Number($("s-night-from")?.value),
          nightTo: Number($("s-night-to")?.value)
        });
      }
      setStatus(result.serviceUrl ? `Einstellungen gespeichert. Dienst: ${result.serviceUrl}` : "Einstellungen gespeichert.");
    } catch (error) {
      setStatus(error.message);
    }
  };
}

$("open-settings").addEventListener("click", openSettings);

/* ---------- Prüfen ---------- */

const PROBE_IMAGE_ERROR_TEXT = {
  tile: "Kachel nicht in der veröffentlichten Ansicht",
  camera: "Variable ohne Bildadresse (http(s), Pfad oder Medien-ID)",
  camera_fetch: "Snapshot von Symcon aus nicht ladbar (URL auf dem Symcon-Rechner testen)",
  media: "Medienobjekt nicht lesbar",
  media_fetch: "Mediendatei fehlt oder ist leer",
  fetch: "Verwaltung/Symcon-Antwort ungültig (Bibliothek Build 126?)",
  unknown: "Snapshot fehlgeschlagen"
};

function probeImageErrorText(code, detail) {
  const base = PROBE_IMAGE_ERROR_TEXT[code] || PROBE_IMAGE_ERROR_TEXT.unknown;
  if (detail) return `${base}: ${detail}`;
  return base;
}

function setTileMarkup(element, html) {
  const chrome = [...element.children].filter((node) => node.classList.contains("resize-handle") || node.classList.contains("span-badge"));
  element.innerHTML = html;
  for (const node of chrome) element.appendChild(node);
}

function cameraLiveImgHtml(src) {
  return `<img src="${src}" alt="" draggable="false" class="live-preview">`;
}

function paintCameraPreviewError(element, tile, err, detail, fromMedia, bound, title, showKicker) {
  const preview = probeImageErrorText(err || "unknown", detail);
  const kicker = showKicker ? editorKicker("edit", fromMedia ? "Kamera · Medien" : "Kamera · Livebild") : "";
  setTileMarkup(element, `${kicker}<div class="name"></div><div class="camera-preview">${escapeHtml(preview)}</div>`);
  element.querySelector(".name").textContent = title || bound || "Kamera";
}

function paintEditorLiveTile(element, tile) {
  if (!element || document.querySelector("#grid .grid-resize-preview")) return;
  const url = state.tilePreviewUrls[tile.tileId];
  const err = state.tilePreviewErrors[tile.tileId];
  const detail = state.tilePreviewErrorDetails[tile.tileId];
  if (tile.kind === "image" && (tile.imageSource || "catalog") === "media") {
    if (url) {
      setTileMarkup(element, `<img src="${url}" alt="" draggable="false">`);
      return;
    }
    if (err) {
      setTileMarkup(element, `<div class="media-placeholder">${escapeHtml(probeImageErrorText(err, detail))}</div>`);
    }
    return;
  }
  if (tile.kind !== "camera") return;
  const bound = mediaBindingName(tile);
  const fromMedia = (tile.cameraSource || "url") === "media" || Boolean(tile.mediaObjectId) || isMediaPick(variableById(tile.variableId));
  if (url) {
    const current = element.querySelector("img.live-preview");
    const handlesOk = state.selected !== tile.tileId || element.querySelector(".resize-handle");
    if (current && current.getAttribute("src") === url && handlesOk) return;
    setTileMarkup(element, `${editorKicker("edit", "Kamera")}${cameraLiveImgHtml(url)}`);
    const img = element.querySelector("img.live-preview");
    if (img) {
      img.addEventListener("error", () => {
        paintCameraPreviewError(element, tile, "camera_fetch", "Bild im Browser nicht darstellbar", fromMedia, bound, tile.title, true);
      });
    }
    return;
  }
  if (err) {
    paintCameraPreviewError(element, tile, err, detail, fromMedia, bound, tile.title, true);
  }
}

window.addEventListener("resize", () => {
  scaleEditorViewport();
  scheduleFitTileText($("grid"));
});

/* ---------- Start ---------- */

function installEditorDragGuards() {
  const arm = () => document.body.classList.add("editor-dragging");
  const disarm = () => document.body.classList.remove("editor-dragging");
  document.addEventListener("dragstart", arm, true);
  document.addEventListener("dragend", disarm, true);
  document.addEventListener("drop", disarm, true);
  document.addEventListener("mouseup", disarm, true);
  document.addEventListener("selectstart", (event) => {
    if (!document.body.classList.contains("editor-dragging")) return;
    const target = event.target;
    if (target && target.closest && target.closest("button, input, textarea, select, label")) return;
    event.preventDefault();
  });
}

installEditorDragGuards();

async function start() {
  try {
    const settings = await call("settings");
    state.previewPanelId = settings.panels && settings.panels[0] ? settings.panels[0].id : 0;
    state.libraryVersion = settings.libraryVersion || "";
    state.libraryBuild = settings.libraryBuild || 0;
    updateTitle();
    state.catalog = await call("catalog");
    const variables = await call("variables");
    state.variables = (variables.variables || []).filter((variable) => {
      if (isMediaObject(variable) || variable.mediaRole) return true;
      const type = Number(variable.type);
      return type >= 0 && type <= 3;
    });
    fillProfileSelect($("display-profile"), defaultProfileId());
    await loadImages();
    renderVariableTree();
    if (state.catalog.layouts[0]) selectLayout(state.catalog.layouts[0]);
    else newLayout("Start");
    setStatus(`Bereit. ${state.variables.length} Variablen, ${state.catalog.layouts.length} Ansichten, ${state.images.length} Bilder.`);
  } catch (error) {
    setStatus(error.message);
  }
}

window.addEventListener("beforeunload", (event) => {
  if (state.dirty) event.preventDefault();
});

start();
