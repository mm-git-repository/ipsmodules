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
const ICONS = [
  ["energy", "Energie"], ["temperature", "Temperatur"], ["light", "Licht"], ["shutter", "Rollladen"],
  ["pump", "Pumpe"], ["valve", "Ventil"], ["alarm", "Alarm"], ["home", "Haus"], ["clock", "Uhr"], ["info", "Info"],
  ["lightOn", "Lampe an"], ["lightOff", "Lampe aus"], ["humidity", "Feuchte"], ["sun", "Sonne"], ["moon", "Nacht"],
  ["water", "Wasser"], ["heat", "Heizung"], ["cool", "Kühlen"], ["fan", "Lüftung"], ["door", "Tür"], ["lock", "Schloss"],
  ["plug", "Steckdose"], ["batteryIcon", "Akku"], ["wifi", "WLAN"], ["car", "Auto"]
];
const DISPLAY_PROFILES = {
  "waveshare-5b-1024x600": { label: "Waveshare 5B (1024×600)", width: 1024, height: 600, defaultColumns: 3, defaultRows: 4, maxColumns: 4, maxRows: 6 },
  "firebeetle-epd-400x300": { label: "FireBeetle E-Ink 4,2\" (400×300)", width: 400, height: 300, defaultColumns: 3, defaultRows: 4, maxColumns: 3, maxRows: 4 },
  "firebeetle-epd-800x480": { label: "FireBeetle E-Ink 7,5\" (800×480)", width: 800, height: 480, defaultColumns: 4, defaultRows: 6, maxColumns: 4, maxRows: 6 }
};
const KIND_NAMES = { value: "Wert", display: "Nur Anzeige", chart: "Diagramm", energyflow: "Energiefluss", nav: "Seitenwechsler", label: "Text", image: "Bild", camera: "Kamera", separator: "Trennlinie", system: "Anzeigestatus", scene: "Szene", weather: "Wetter", presence: "Anwesenheit" };
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

function editorKicker(mode, text) {
  return mode === "edit" && text ? `<div class="kicker">${text}</div>` : "";
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
  const icon = iconId ? `<div class="tile-fit-icon">${escapeHtml(iconName(iconId))}</div>` : "";
  const sub = subText ? `<div class="tile-fit-sub">${escapeHtml(subText)}</div>` : "";
  return `<div class="tile-scale tile-fit">${icon}<div class="tile-fit-text">${escapeHtml(mainText || "—")}</div>${sub}</div>`;
}

function fitTileContentBox(box) {
  if (!box) return;
  if (box.closest && box.closest(".tile.chart")) {
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

function ohCardMarkup(label, stateHtml, bodyHtml, footHtml) {
  const foot = footHtml ? `<div class="oh-card-foot">${footHtml}</div>` : "";
  return `<div class="oh-card"><div class="oh-card-head"><span class="oh-card-label">${escapeHtml(label || "")}</span><span class="oh-card-state">${stateHtml}</span></div><div class="oh-card-body">${bodyHtml || ""}</div>${foot}</div>`;
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

function chartSvgMarkup(series, style, min, max) {
  if (!series || series.length < 2) return `<div class="chart-empty">Keine Verlaufsdaten</div>`;
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
const CONTROL_NAMES = { switch: "Schalter", dimSwitch: "Dimmschalter", slider: "Dimmbalken", combo: "Kombischalter", battery: "Batterie" };
const INTERACTION_NAMES = { switch: "Schalter (Ein/Aus)", iconSwitch: "Icon-Schalter", dimSwitch: "Dimmschalter", slider: "Nur Dimmer", combo: "Kombi", battery: "Batterie", blinds: "Rolladen", setpoint: "Setpoint" };
const INTERACTION_HINTS = {
  switch: "Ein/Aus per Schalter an der Anzeige.",
  iconSwitch: "Tipp schaltet um; das Symbol wechselt zwischen Ein und Aus.",
  dimSwitch: "Schalter schaltet aus; Balken stellt Helligkeit ein (ziehen oder optional antippen).",
  slider: "Dimmen am Balken – Griff ziehen; optional Leiste antippen (Eigenschaften).",
  combo: "Schalter Ein/Aus plus Dimmer-Balken (ziehen oder antippen).",
  battery: "Ladestand grafisch, ohne Schaltaktion an der Anzeige.",
  blinds: "Position 0–100 % oder Fahrt über schaltbare Variable (Aktionsart Position).",
  setpoint: "Ist-Wert anzeigen, Sollwert mit +/- an der Anzeige ändern."
};
const DIM_CONTROLS = ["dimSwitch", "slider", "combo"];
const PROBE_INLINE_CONTROLS = ["switch", "iconSwitch", "dimSwitch", "slider", "combo", "blinds", "setpoint"];

function numericLiveValue(tile, live) {
  if (!live) return null;
  if (typeof live.value === "number" && !Number.isNaN(live.value)) return live.value;
  if (typeof live.value === "boolean") return live.value ? (tile.valueMax ?? 100) : (tile.valueMin ?? 0);
  if (live.text) {
    const match = String(live.text).replace(",", ".").match(/-?\d+(?:\.\d+)?/);
    if (match) return parseFloat(match[0]);
  }
  return null;
}

function dimPercentFromTile(tile, live, fallbackPct) {
  const min = tile.valueMin ?? 0;
  const max = tile.valueMax ?? 100;
  const span = max - min || 1;
  const v = numericLiveValue(tile, live);
  if (v == null) return fallbackPct != null ? fallbackPct : 50;
  return Math.round(Math.min(100, Math.max(0, ((v - min) / span) * 100)));
}

function valueFromDimPercent(tile, pct) {
  const min = tile.valueMin ?? 0;
  const max = tile.valueMax ?? 100;
  const raw = min + (pct / 100) * (max - min);
  const decimals = tile.decimals != null ? tile.decimals : 0;
  if (decimals <= 0) return Math.round(raw);
  return Math.round(raw * 10 ** decimals) / 10 ** decimals;
}

function dimTapTrackEnabled(tile, control) {
  const mode = tile.dimTapTrack;
  if (mode === "on" || mode === true) return true;
  if (mode === "off" || mode === false) return false;
  return control === "combo" || control === "blinds";
}

function tileHasInlineProbeControl(tile) {
  return Boolean(tile.actionId && tile.kind === "value" && PROBE_INLINE_CONTROLS.includes(tileInteractionMode(tile)));
}

function tileInteractionMode(tile) {
  if (!tile || tile.kind !== "value") return "switch";
  if (tile.control === "battery") return "battery";
  if (tile.control === "blinds") return "blinds";
  if (tile.control === "setpoint") return "setpoint";
  if (tile.control === "slider") return "slider";
  if (tile.control === "combo") return "combo";
  if (tile.control === "dimSwitch") return "dimSwitch";
  if ((tile.iconMode || "static") === "toggle") return "iconSwitch";
  return "switch";
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

function chartSeriesFromTile(tile, live) {
  if (live && Array.isArray(live.seriesAll)) {
    const usable = live.seriesAll.map(normalizeChartSeries).filter((s) => s.length >= 2);
    if (usable.length) return usable;
  }
  if (live && chartSeriesUsable(live.series)) return [normalizeChartSeries(live.series)];
  return [demoChartSeries(tile)];
}

function chartBoundsForTile(tile, live, seriesList) {
  const flat = seriesList.flat();
  if ((tile.chartYMode || "auto") === "fixed" && tile.chartYMin != null && tile.chartYMax != null) {
    return { min: tile.chartYMin, max: tile.chartYMax };
  }
  if (live && live.seriesMin != null && live.seriesMax != null && flat.length >= 2) {
    return { min: live.seriesMin, max: live.seriesMax };
  }
  return chartSeriesMinMax(flat);
}

function chartHtmlForTile(tile, live) {
  const seriesList = chartSeriesFromTile(tile, live);
  const bounds = chartBoundsForTile(tile, live, seriesList);
  const style = tile.chartStyle || "area";
  const yesterday = live && Array.isArray(live.seriesYesterday) && chartSeriesUsable(live.seriesYesterday)
    ? normalizeChartSeries(live.seriesYesterday)
    : [];
  return chartSvgMulti(seriesList, style, bounds.min, bounds.max, yesterday);
}

function chartSvgMulti(seriesList, style, min, max, yesterday) {
  if (!seriesList || !seriesList.length) return `<div class="chart-empty">Keine Verlaufsdaten</div>`;
  const w = 100;
  const h = 36;
  const range = max - min || 1;
  const line = (series, className) => {
    if (!series || series.length < 2) return "";
    const points = series.map((v, i) => {
      const x = (i / (series.length - 1)) * w;
      const y = h - 2 - ((v - min) / range) * (h - 4);
      return `${x.toFixed(2)},${y.toFixed(2)}`;
    }).join(" ");
    return `<polyline class="${className}" points="${points}"></polyline>`;
  };
  const primary = seriesList[0] || [];
  let area = "";
  if (style === "area" && primary.length >= 2) {
    const pts = primary.map((v, i) => {
      const x = (i / (primary.length - 1)) * w;
      const y = h - 2 - ((v - min) / range) * (h - 4);
      return `${x.toFixed(2)},${y.toFixed(2)}`;
    }).join(" ");
    area = `<polygon class="oh-chart-area" points="0,${h} ${pts} ${w},${h}"></polygon>`;
  }
  const extras = seriesList.slice(1).map((s) => line(s, "oh-chart-line oh-chart-line-alt")).join("");
  const yesterdayLine = yesterday && yesterday.length >= 2 ? line(yesterday, "oh-chart-line oh-chart-yesterday") : "";
  const primaryLine = line(primary, "oh-chart-line");
  if (!primaryLine && !extras && !yesterdayLine) {
    return `<div class="chart-empty">Keine Verlaufsdaten</div>`;
  }
  return `<svg class="oh-chart-svg" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none">${area}${yesterdayLine}${primaryLine}${extras}</svg>`;
}

function paintChartTileContent(element, tile, live) {
  if (!element || !tile || tile.kind !== "chart") return;
  const variable = variableById(tile.variableId);
  const label = tile.title || (variable ? variable.name : "Diagramm");
  const stateText = live && live.text ? escapeHtml(live.text) : escapeHtml(tile.unit ? `12,3 ${tile.unit}` : "—");
  const chartHtml = chartHtmlForTile(tile, live);
  const periodKey = tile.chartPeriod || "24h";
  const periodLabel = CHART_PERIOD_LABELS[periodKey] || periodKey;
  const kicker = element.querySelector(".kicker");
  const kickerHtml = kicker ? kicker.outerHTML : "";
  element.innerHTML = `${kickerHtml}<div class="tile-scale">${ohCardMarkup(label, stateText, chartHtml, escapeHtml(periodLabel))}</div>`;
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
  $("p-icon-static")?.classList.toggle("hidden", mode === "iconSwitch" || mode === "battery");
  $("p-icon-toggle")?.classList.toggle("hidden", mode !== "iconSwitch");
  $("p-battery-wrap")?.classList.toggle("hidden", mode !== "battery");
  const hintEl = $("p-control-hint");
  if (hintEl) {
    const hint = INTERACTION_HINTS[mode] || "";
    hintEl.textContent = hint;
    hintEl.classList.toggle("hidden", !hint);
  }
  const actionLabel = $("p-action")?.closest("label");
  if (actionLabel) actionLabel.classList.toggle("hidden", mode === "battery");
  if (DIM_CONTROLS.includes(mode)) {
    if ($("p-actionkind")) $("p-actionkind").value = "dim";
    const variable = variableById(tile.variableId);
    if (variable && variable.action && $("p-action")) $("p-action").checked = true;
  }
  if (mode === "blinds" && $("p-actionkind")) {
    $("p-actionkind").value = "position";
    const variable = variableById(tile.variableId);
    if (variable && variable.action && $("p-action")) $("p-action").checked = true;
  }
  $("p-setpoint-wrap")?.classList.toggle("hidden", mode !== "setpoint");
  $("p-dim-wrap")?.classList.toggle("hidden", !(DIM_CONTROLS.includes(mode) || mode === "blinds"));
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
function tileNeedsProbeImage(tile) {
  if (!tile) return false;
  if (tile.kind === "camera") return true;
  if (tile.kind === "image" && (tile.imageSource || "catalog") === "media") return true;
  return false;
}

const state = {
  catalog: { layouts: [], configs: [] },
  variables: [],
  images: [],
  layout: null,
  page: "start",
  selected: null,
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

function probeSourceMode() {
  const picked = document.querySelector('input[name="probe-source"]:checked');
  return picked ? picked.value : "draft";
}

function editorLayoutSnapshot() {
  if (!state.layout) return null;
  const layout = JSON.parse(JSON.stringify(state.layout));
  layout.columns = Number($("columns").value) || layout.columns;
  layout.rows = Number($("rows").value) || layout.rows;
  layout.showTabs = $("show-tabs").checked;
  layout.displayProfile = $("display-profile").value || layout.displayProfile;
  return layout;
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
  const maxCol = profile.maxColumns;
  const maxRow = profile.maxRows;
  $("columns").max = maxCol;
  $("rows").max = maxRow;
  const pColSpan = $("p-colspan");
  const pRowSpan = $("p-rowspan");
  if (pColSpan) pColSpan.max = state.layout ? state.layout.columns : maxCol;
  if (pRowSpan) pRowSpan.max = state.layout ? state.layout.rows : maxRow;
  const meta = $("display-profile-meta");
  if (meta) meta.textContent = `${profile.label} · ${profile.width}×${profile.height} px`;
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
      else if (typeof liveValue === "number") on = liveValue > (tile.valueMin ?? 0);
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
  if (!state.layout) {
    $("title").textContent = "";
    return;
  }
  const flag = state.dirty ? " *" : "";
  const version = state.layout.version ? ` v${state.layout.version}` : " (neu)";
  $("title").textContent = `${state.layout.name}${version}${state.layout.published ? "" : " – Entwurf"}${flag}`;
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

function selectLayout(layout) {
  state.layout = JSON.parse(JSON.stringify(layout));
  state.layout.baseVersion = layout.version || 0;
  state.layout.displayProfile = state.layout.displayProfile || defaultProfileId();
  const prof = layoutProfile(state.layout);
  state.layout.columns = state.layout.columns || prof.defaultColumns;
  state.layout.rows = state.layout.rows || prof.defaultRows;
  if (state.layout.showTabs === undefined) state.layout.showTabs = true;
  state.layout.pages = (state.layout.pages || []).filter((page) => PAGES.some((item) => item[0] === page.id));
  if (!state.layout.pages.length) state.layout.pages = [{ id: "start", title: "Start", tiles: [] }];
  for (const page of state.layout.pages) {
    page.tiles = page.tiles || [];
    for (const tile of page.tiles) tile.kind = tile.kind || "value";
  }
  state.page = state.layout.pages[0].id;
  state.selected = null;
  state.dirty = false;
  $("columns").value = state.layout.columns;
  $("rows").value = state.layout.rows;
  $("show-tabs").checked = state.layout.showTabs;
  fillProfileSelect($("display-profile"), state.layout.displayProfile);
  applyGridAspect();
  renderLayoutSelect();
  renderPageTabs();
  renderGrid();
  renderProperties();
  updateTitle();
}

function newLayout(name) {
  const prof = DISPLAY_PROFILES[defaultProfileId()];
  selectLayout({
    id: "", name: name || "Neue Ansicht", version: 0, published: false,
    displayProfile: defaultProfileId(), columns: prof.defaultColumns, rows: prof.defaultRows,
    showTabs: true, pages: [{ id: "start", title: "Start", tiles: [] }]
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
    button.textContent = page.title || pageTitle(page.id);
    if (page.id === state.page) button.classList.add("active");
    button.addEventListener("click", () => {
      state.page = page.id;
      state.selected = null;
      renderPageTabs();
      renderGrid();
      renderProperties();
    });
    button.addEventListener("dblclick", () => openLayoutDialog());
    bar.appendChild(button);
  }
  const missing = PAGES.filter((item) => !state.layout.pages.some((page) => page.id === item[0]));
  if (missing.length) {
    const add = document.createElement("button");
    add.type = "button";
    add.className = "add";
    add.textContent = "+ Seite";
    add.addEventListener("click", () => openLayoutDialog());
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
  edit.addEventListener("click", () => openLayoutDialog());
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
}

function tileAt(col, row) {
  const page = currentPage();
  if (!page) return null;
  return page.tiles.find((tile) => col >= tile.col && col < tile.col + (tile.colSpan || 1) && row >= tile.row && row < tile.row + (tile.rowSpan || 1)) || null;
}

function fits(tile, col, row, colSpan, rowSpan, ignoreId) {
  const page = currentPage();
  if (col < 0 || row < 0 || col + colSpan > state.layout.columns || row + rowSpan > state.layout.rows) return false;
  for (const other of page.tiles) {
    if (other.tileId === ignoreId) continue;
    const overlapX = col < other.col + (other.colSpan || 1) && other.col < col + colSpan;
    const overlapY = row < other.row + (other.rowSpan || 1) && other.row < row + rowSpan;
    if (overlapX && overlapY) return false;
  }
  return true;
}

function freeCell(colSpan, rowSpan) {
  for (let row = 0; row < state.layout.rows; row += 1) {
    for (let col = 0; col < state.layout.columns; col += 1) {
      if (fits(null, col, row, colSpan || 1, rowSpan || 1, null)) return { col, row };
    }
  }
  return null;
}

function renderGrid() {
  const grid = $("grid");
  grid.innerHTML = "";
  if (!state.layout) return;
  const page = currentPage();
  grid.style.gridTemplateColumns = `repeat(${state.layout.columns}, 1fr)`;
  grid.style.gridTemplateRows = `repeat(${state.layout.rows}, 1fr)`;
  clearEditorGridBackground();
  const covered = new Set();
  for (const tile of page.tiles) {
    for (let x = tile.col; x < tile.col + (tile.colSpan || 1); x += 1) {
      for (let y = tile.row; y < tile.row + (tile.rowSpan || 1); y += 1) covered.add(`${x}:${y}`);
    }
  }
  for (let row = 0; row < state.layout.rows; row += 1) {
    for (let col = 0; col < state.layout.columns; col += 1) {
      const tile = page.tiles.find((item) => item.col === col && item.row === row);
      if (tile) {
        grid.appendChild(renderTile(tile));
        continue;
      }
      if (covered.has(`${col}:${row}`)) continue;
      const cell = document.createElement("div");
      cell.className = "cell";
      cell.textContent = `${col + 1} / ${row + 1}`;
      cell.style.gridColumn = `${col + 1}`;
      cell.style.gridRow = `${row + 1}`;
      bindDrop(cell, col, row);
      cell.addEventListener("click", () => {
        if (state.pickedVariable) {
          if (isMediaPick(state.pickedVariable)) {
            const tile = selectedTile();
            if (tile && tile.kind === "camera") assignCameraMedia(tile, state.pickedVariable);
            else if (tile && tile.kind === "image") assignMediaVariable(tile, state.pickedVariable);
            else addTile("image", col, row, state.pickedVariable);
          } else if (isImageUrlRef(state.pickedVariable)) {
            const tile = selectedTile();
            if (tile && tile.kind === "camera") assignVariable(tile, state.pickedVariable);
            else addTile("camera", col, row, state.pickedVariable);
          } else addTile("value", col, row, state.pickedVariable);
          return;
        }
        state.selected = null;
        renderGrid();
        renderProperties();
      });
      grid.appendChild(cell);
    }
  }
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

async function refreshEditorTilePreviews() {
  if (!state.previewPanelId || !state.layout) return;
  const page = currentPage();
  if (!page) return;
  const targets = page.tiles.filter(tileNeedsLivePreview);
  const token = ++state.previewFetchToken;
  for (const tile of targets) {
    try {
      normalizeMediaTileBindings(tile);
      const result = await call("probeImage", { id: state.previewPanelId, tileId: tile.tileId, tile });
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
  const col = Math.min(state.layout.columns - 1, Math.max(0, Math.floor(((clientX - rect.left) / rect.width) * state.layout.columns)));
  const row = Math.min(state.layout.rows - 1, Math.max(0, Math.floor(((clientY - rect.top) / rect.height) * state.layout.rows)));
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

function resizeTilePreset(colSpan, rowSpan) {
  const tile = selectedTile();
  if (!tile) return;
  if (applyTileSpan(tile, tile.col, tile.row, colSpan, rowSpan)) {
    markDirty();
    renderGrid();
    renderProperties();
  } else setStatus("Diese Größe passt nicht ins Raster.");
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
  element.draggable = mode === "edit";
  element.title = mode === "edit" ? `${KIND_NAMES[tile.kind]} – Doppelklick für Details` : "";
  if (tile.kind === "value") {
    element.classList.add("oh-style");
    const variable = variableById(tile.variableId);
    const interaction = tileInteractionMode(tile);
    const control = tile.control || "switch";
    if (DIM_CONTROLS.includes(control) || control === "blinds") element.classList.add("dim");
    if (control === "battery") {
      element.classList.add("battery");
      const labelPos = tile.batteryLabel || "inside";
      if (labelPos !== "inside") element.classList.add(`label-${labelPos}`, "label-outside");
    }
    if (control === "setpoint") element.classList.add("setpoint");
    const iconSwitch = interaction === "iconSwitch";
    const iconId = effectiveIcon(tile, live ? live.value : undefined, demoOn);
    const kicker = INTERACTION_NAMES[interaction] || CONTROL_NAMES[control] || `${iconName(iconId)}${tile.actionId ? " · schaltbar" : ""}`;
    const body = iconSwitch ? "" : controlMarkup(control, tile, live, demoOn);
    const displayText = live && live.text ? live.text : (variable ? "12,3" : "—");
    const label = tile.title || (variable ? variable.name : "Wert");
    const stateLine = escapeHtml(displayText) + (body ? "" : (tile.unit ? " " + escapeHtml(tile.unit) : ""));
    let cardBody;
    if (iconSwitch) {
      let on = Boolean(demoOn);
      if (live && typeof live.value === "boolean") on = live.value;
      else if (live && live.text && /^(1|true|ein|an|on)$/i.test(String(live.text).trim())) on = true;
      cardBody = `<button type="button" class="oh-icon-toggle" data-icon-toggle aria-pressed="${on ? "true" : "false"}">${escapeHtml(iconName(iconId))}</button>`;
    } else {
      cardBody = body || (iconId ? `<div class="oh-card-icon">${escapeHtml(iconName(iconId))}</div>` : "");
    }
    element.innerHTML = `${editorKicker(mode, kicker)}<div class="tile-scale">${ohCardMarkup(label, stateLine, cardBody)}</div>`;
    if (DIM_CONTROLS.includes(control) || control === "blinds" || control === "setpoint" || control === "switch" || interaction === "iconSwitch") {
      bindTileControls(element, tile, mode, live);
    }
  } else if (tile.kind === "nav") {
    const navIcon = tile.icon ? `<div class="tile-fit-icon">${escapeHtml(iconName(tile.icon))}</div>` : "";
    element.innerHTML = `${editorKicker(mode, `${tile.icon ? iconName(tile.icon) + " · " : ""}Seite`)}<div class="tile-scale">${navIcon}<div class="name"></div></div>`;
    element.querySelector(".name").textContent = tile.title || pageTitleIn(tile.targetPage);
  } else if (tile.kind === "label") {
    if ((tile.labelStyle || "text") === "section") {
      element.classList.add("label-section");
      element.innerHTML = `<div class="section-head">${escapeHtml(tile.text || "Abschnitt")}</div>`;
    } else {
      element.innerHTML = scaledTileHtml(tile.text || "Text", "", "");
    }
  } else if (tile.kind === "display") {
    element.classList.add("oh-style");
    const variable = variableById(tile.variableId);
    const label = tile.title || (tile.displaySource === "text" ? "Text" : (variable ? variable.name : "Anzeige"));
    const stateText = escapeHtml(displayMainText(tile, live));
    const body = tile.icon ? `<div class="oh-card-icon">${escapeHtml(iconName(tile.icon))}</div>` : "";
    element.innerHTML = `${editorKicker(mode, "Anzeige")}<div class="tile-scale">${ohCardMarkup(label, stateText, body)}</div>`;
  } else if (tile.kind === "chart") {
    element.classList.add("chart");
    const periodKey = tile.chartPeriod || "24h";
    const periodLabel = CHART_PERIOD_LABELS[periodKey] || periodKey;
    element.innerHTML = `${editorKicker(mode, `Diagramm · ${periodLabel}`)}<div class="tile-scale"></div>`;
    paintChartTileContent(element, tile, live);
  } else if (tile.kind === "energyflow") {
    element.classList.add("energyflow");
    element.innerHTML = buildEnergyFlowHtml(tile, live, mode);
  } else if (tile.kind === "scene") {
    element.classList.add("oh-style", "scene");
    const label = tile.title || "Szene";
    element.innerHTML = `${editorKicker(mode, "Szene")}<div class="tile-scale">${ohCardMarkup(label, tile.actionId ? "Tippen" : "—", `<div class="oh-card-icon">${escapeHtml(iconName(tile.icon || "home"))}</div>`)}</div>`;
  } else if (tile.kind === "weather") {
    element.classList.add("oh-style", "weather");
    const label = tile.title || "Wetter";
    const temp = live && live.text ? escapeHtml(live.text) : escapeHtml(tile.unit ? `12,3 ${tile.unit}` : "12,3 °C");
    const forecast = live && live.forecast ? escapeHtml(live.forecast) : "leicht bewölkt";
    element.innerHTML = `${editorKicker(mode, "Wetter")}<div class="tile-scale">${ohCardMarkup(label, temp, `<div class="oh-card-icon">${escapeHtml(iconName(tile.icon || "sun"))}</div>`, forecast)}</div>`;
  } else if (tile.kind === "presence") {
    element.classList.add("oh-style", "presence");
    const label = tile.title || (tile.presenceMode === "alarm" ? "Alarm" : "Anwesenheit");
    const active = live && live.presenceState === "active";
    const stateLine = active ? (tile.presenceMode === "alarm" ? "ALARM" : "Da") : "Abwesend";
    element.innerHTML = `${editorKicker(mode, tile.presenceMode === "alarm" ? "Alarm" : "Anwesenheit")}<div class="tile-scale">${ohCardMarkup(label, escapeHtml(stateLine), `<div class="presence-badge ${active ? "on" : "off"}"></div>`)}</div>`;
  } else if (tile.kind === "separator") {
    element.innerHTML = `<div class="line"></div>`;
  } else if (tile.kind === "system") {
    element.innerHTML = `${editorKicker(mode, "Anzeigestatus")}<div class="tile-scale"><div class="name"></div><div class="text">Programm · WLAN · Laufzeit · Datenalter</div></div>`;
    element.querySelector(".name").textContent = tile.title || "Anzeige";
  } else if (tile.kind === "image") {
    if ((tile.imageSource || "catalog") === "media") {
      normalizeMediaTileBindings(tile);
      const bound = mediaBindingName(tile);
      element.innerHTML = `<div class="media-placeholder">Medien${bound ? ": " + escapeHtml(bound) : ""}</div>`;
    if (context.probeImageUrls && context.probeImageUrls[tile.tileId]) {
      const src = context.probeImageUrls[tile.tileId].url || context.probeImageUrls[tile.tileId];
      element.innerHTML = `<img src="${src}" alt="">`;
    } else if (mode === "probe" && context.probeImageErrors && context.probeImageErrors[tile.tileId]) {
      const detail = context.probeImageErrorDetails && context.probeImageErrorDetails[tile.tileId];
      element.innerHTML = `<div class="media-placeholder">${escapeHtml(probeImageErrorText(context.probeImageErrors[tile.tileId], detail))}</div>`;
    }
    } else {
      const image = state.images.find((item) => item.id === tile.imageId);
      element.innerHTML = image ? `<img src="images/${image.id}.${image.ext}" alt="">` : `<div class="missing">Bild wählen</div>`;
    }
  } else if (tile.kind === "camera") {
    element.classList.add("camera");
    normalizeMediaTileBindings(tile);
    const bound = mediaBindingName(tile);
    const fromMedia = (tile.cameraSource || "url") === "media" || Boolean(tile.mediaObjectId) || isMediaPick(variableById(tile.variableId));
    if (context.probeImageUrls && context.probeImageUrls[tile.tileId]) {
      const src = context.probeImageUrls[tile.tileId].url || context.probeImageUrls[tile.tileId];
      element.innerHTML = `${editorKicker(mode, "Kamera")}<img src="${src}" alt="" style="max-width:100%;max-height:100%;object-fit:contain;flex:1" class="live-preview">`;
      const img = element.querySelector("img.live-preview");
      if (img && mode === "probe") {
        img.addEventListener("error", () => {
          const err = (context.probeImageErrors && context.probeImageErrors[tile.tileId]) || "camera_fetch";
          const detail = context.probeImageErrorDetails && context.probeImageErrorDetails[tile.tileId];
          paintCameraPreviewError(element, tile, err, detail, fromMedia, bound, tile.title, mode === "edit");
        });
      }
    } else {
      const live = context.stateByTileId && context.stateByTileId[tile.tileId];
      let preview;
      if (live && live.quality === "empty") {
        preview = "Keine Bildquelle (Variable leer oder nicht konfiguriert)";
      } else if ((mode === "probe" && tile.cameraFile) || (mode === "edit" && context.probeImageErrors && context.probeImageErrors[tile.tileId])) {
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
    }
  }
  if (mode === "edit") {
    element.addEventListener("click", (event) => {
      event.stopPropagation();
      state.selected = tile.tileId;
      renderGrid();
      renderProperties();
    });
    element.addEventListener("dblclick", (event) => {
      event.stopPropagation();
      state.selected = tile.tileId;
      renderGrid();
      renderProperties();
      openDetails(tile);
    });
    element.addEventListener("dragstart", (event) => {
      event.dataTransfer.setData("text/homedisplay-tile", tile.tileId);
      event.dataTransfer.effectAllowed = "move";
    });
    bindDrop(element, tile.col, tile.row, tile);
    if (state.selected === tile.tileId) bindResizeHandles(element, tile);
  }
  if (mode === "probe" && tile.actionId && !tileHasInlineProbeControl(tile)) {
    const action = document.createElement("button");
    action.type = "button";
    action.textContent = tile.actionKind === "dim" ? "Wert senden" : "Schalten";
    action.addEventListener("click", (event) => {
      event.stopPropagation();
      sendProbeAction(tile);
    });
    element.appendChild(action);
  }
  if (mode === "probe" && tile.kind === "nav" && tile.targetPage) {
    element.style.cursor = "pointer";
    element.addEventListener("click", () => {
      probe.page = tile.targetPage;
      renderProbe();
    });
  }
  if (spanW > 1 || spanH > 1) {
    const badge = document.createElement("span");
    badge.className = "span-badge";
    badge.textContent = `${spanW}×${spanH}`;
    element.appendChild(badge);
  }
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
  const showSw = control === "dimSwitch" || control === "combo";
  const showValue = tile.dimShowPercent !== false;
  const layout = tile.dimLayout || "horizontal";
  const min = tile.valueMin ?? 0;
  const numeric = numericLiveValue(tile, live);
  const swOn = control === "dimSwitch" ? (numeric != null ? numeric > min + 0.001 : pct > 2) : pct > 2;
  const swHtml = showSw ? `<span class="sw${swOn ? " on" : ""}"></span>` : "";
  const valueHtml = showValue ? `<span class="ctl-value">${pct} %</span>` : "";
  const trackHtml = `<div class="track" data-dim-track><div class="fill" style="width:${pct}%"></div><div class="knob" style="left:${pct}%"></div></div>`;
  const offClass = showSw && control === "dimSwitch" && !swOn ? " dim-off" : "";
  if (layout === "stacked" && showSw) {
    return `<div class="ctl dim-ctl layout-stacked${offClass}">${swHtml}<div class="dim-slider-row">${trackHtml}${valueHtml}</div></div>`;
  }
  return `<div class="ctl dim-ctl${offClass}">${swHtml}${trackHtml}${valueHtml}</div>`;
}

function controlMarkup(control, tile, live, demoOn) {
  if (tileInteractionMode(tile) === "iconSwitch") {
    return "";
  }
  if (control === "dimSwitch" || control === "slider" || control === "combo") {
    const pct = dimPercentFromTile(tile, live);
    return dimTrackMarkup(tile, control, live, pct);
  }
  if (control === "switch") {
    let on = Boolean(demoOn);
    if (live && typeof live.value === "boolean") on = live.value;
    else if (live && live.text && /^(1|true|ein|an|on)$/i.test(String(live.text).trim())) on = true;
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

function applyDimVisual(element, pct, tile) {
  const track = element.querySelector("[data-dim-track]") || element.querySelector(".track");
  const fill = track ? track.querySelector(".fill") : element.querySelector(".fill");
  const knob = track ? track.querySelector(".knob") : element.querySelector(".knob");
  const valueEl = element.querySelector(".ctl-value");
  const clamped = Math.round(Math.min(100, Math.max(0, pct)));
  if (fill) fill.style.width = `${clamped}%`;
  if (knob) knob.style.left = `${clamped}%`;
  if (valueEl) valueEl.textContent = `${clamped} %`;
  const control = tile.control || "switch";
  const sw = element.querySelector(".sw");
  if (sw && control === "combo") sw.classList.toggle("on", clamped > 2);
  element.dataset.lastDimPct = String(clamped);
  return clamped;
}

function bindTileControls(element, tile, mode, live) {
  const control = tile.control || "switch";
  const iconSwitch = tileInteractionMode(tile) === "iconSwitch";
  const stop = (event) => {
    event.stopPropagation();
    event.preventDefault();
  };
  const ctlRoot = element.querySelector(".ctl");
  if (ctlRoot) {
    ctlRoot.addEventListener("click", (event) => event.stopPropagation());
    ctlRoot.addEventListener("dblclick", (event) => event.stopPropagation());
  }
  const dimCtl = element.querySelector(".dim-ctl");
  const sw = element.querySelector(".sw");
  let lastPct = dimPercentFromTile(tile, live);
  element.dataset.lastDimPct = String(lastPct);

  const syncSwitchOffState = () => {
    if (!dimCtl || control !== "dimSwitch") return;
    const on = sw && sw.classList.contains("on");
    dimCtl.classList.toggle("dim-off", !on);
  };

  const iconToggle = element.querySelector("[data-icon-toggle]");
  if (iconSwitch && iconToggle) {
    iconToggle.addEventListener("pointerdown", stop);
    iconToggle.addEventListener("click", (event) => {
      stop(event);
      const on = iconToggle.getAttribute("aria-pressed") === "true";
      const next = !on;
      iconToggle.setAttribute("aria-pressed", next ? "true" : "false");
      iconToggle.textContent = iconName(next ? (tile.iconOn || "lightOn") : (tile.iconOff || "lightOff"));
      if (mode === "edit") {
        state.editorIconDemo[tile.tileId] = next;
        return;
      }
      if (mode === "probe" && tile.actionId) sendProbeAction(tile, { switchToggle: true });
    });
  }

  if (sw && (control === "dimSwitch" || control === "combo" || control === "switch") && !iconSwitch) {
    sw.addEventListener("pointerdown", stop);
    sw.addEventListener("click", (event) => {
      stop(event);
      const wasOn = sw.classList.contains("on");
      if (control === "switch") {
        sw.classList.toggle("on");
        if (mode === "probe" && tile.actionId) sendProbeAction(tile, { switchToggle: true });
        return;
      }
      if (control === "dimSwitch") {
        if (wasOn) {
          lastPct = applyDimVisual(element, Number(element.dataset.lastDimPct || lastPct) || lastPct, tile);
          element.dataset.storedDimPct = String(lastPct);
          sw.classList.remove("on");
          applyDimVisual(element, 0, tile);
          syncSwitchOffState();
          if (mode === "probe" && tile.actionId) sendProbeAction(tile, { value: String(tile.valueMin ?? 0) });
        } else {
          sw.classList.add("on");
          const restore = Number(element.dataset.storedDimPct || element.dataset.lastDimPct || 50) || 50;
          applyDimVisual(element, restore, tile);
          syncSwitchOffState();
          if (mode === "probe" && tile.actionId) {
            sendProbeAction(tile, { value: String(valueFromDimPercent(tile, Number(element.dataset.lastDimPct || restore))) });
          }
        }
        return;
      }
      sw.classList.toggle("on");
      if (control === "combo") {
        if (!sw.classList.contains("on")) {
          applyDimVisual(element, 0, tile);
          if (mode === "probe" && tile.actionId) sendProbeAction(tile, { value: String(tile.valueMin ?? 0) });
        } else if (mode === "probe" && tile.actionId) sendProbeAction(tile, { switchToggle: true });
      }
    });
  }
  syncSwitchOffState();

  const track = element.querySelector("[data-dim-track]") || element.querySelector(".track");
  const knob = track ? track.querySelector(".knob") : null;
  const tapTrack = dimTapTrackEnabled(tile, control);

  const canAdjustDim = () => {
    if (control === "dimSwitch" && sw && !sw.classList.contains("on")) return false;
    return Boolean(track && knob);
  };

  const pctFromPointer = (clientX) => {
    const rect = track.getBoundingClientRect();
    const ratio = rect.width > 0 ? (clientX - rect.left) / rect.width : 0;
    return applyDimVisual(element, ratio * 100, tile);
  };

  if (track && knob) {
    if (tapTrack) track.classList.add("tap-enabled");
    const endDrag = (endEvent, sendValue) => {
      try {
        track.releasePointerCapture(endEvent.pointerId);
      } catch (_e) { /* ignore */ }
      track.removeEventListener("pointermove", onMove);
      track.removeEventListener("pointerup", onUp);
      track.removeEventListener("lostpointercapture", onUp);
      if (mode === "edit") element.draggable = true;
      if (sendValue && mode === "probe" && tile.actionId) {
        const pct = Number(element.dataset.lastDimPct || lastPct);
        sendProbeAction(tile, { value: String(valueFromDimPercent(tile, pct)) });
      }
    };
    let onMove;
    let onUp;
    const startDrag = (event) => {
      if (!canAdjustDim()) return;
      stop(event);
      if (mode === "edit") element.draggable = false;
      track.setPointerCapture(event.pointerId);
      lastPct = pctFromPointer(event.clientX);
      onMove = (moveEvent) => {
        lastPct = pctFromPointer(moveEvent.clientX);
      };
      onUp = (endEvent) => endDrag(endEvent, true);
      track.addEventListener("pointermove", onMove);
      track.addEventListener("pointerup", onUp);
      track.addEventListener("lostpointercapture", onUp);
    };
    knob.addEventListener("pointerdown", startDrag);
    track.addEventListener("pointerdown", (event) => {
      if (event.target === knob || knob.contains(event.target)) return;
      if (!tapTrack) {
        stop(event);
        return;
      }
      startDrag(event);
    });
  }

  element.querySelectorAll(".bl-btn").forEach((btn) => {
    btn.addEventListener("pointerdown", stop);
    btn.addEventListener("click", (event) => {
      stop(event);
      const dir = btn.dataset.blind === "up" ? 1 : -1;
      const step = 10;
      const pct = Number(element.dataset.lastDimPct || dimPercentFromTile(tile, live));
      const next = applyDimVisual(element, pct + dir * step, tile);
      if (mode === "probe" && tile.actionId) sendProbeAction(tile, { value: String(valueFromDimPercent(tile, next)) });
    });
  });

  element.querySelectorAll(".sp-btn").forEach((btn) => {
    btn.addEventListener("pointerdown", stop);
    btn.addEventListener("click", (event) => {
      stop(event);
      const step = tile.setpointStep != null ? tile.setpointStep : 0.5;
      const dir = btn.dataset.setpoint === "up" ? 1 : -1;
      const spVal = element.querySelector(".sp-val");
      const current = numericLiveValue(tile, live) ?? 21;
      const next = Math.round((current + dir * step) * 10) / 10;
      if (spVal) spVal.textContent = `${String(next).replace(".", ",")}${tile.unit ? " " + tile.unit : " °C"}`;
      if (mode === "probe" && tile.actionId) sendProbeAction(tile, { value: String(next) });
    });
  });
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
      moveTile(movedId, col, row, existing);
      return;
    }
    if (variableId) {
      const variable = variableById(variableId);
      if (existing && existing.kind === "image" && isMediaPick(variable)) {
        assignMediaVariable(existing, variable);
        return;
      }
      if (existing && existing.kind === "camera" && isMediaPick(variable)) {
        assignCameraMedia(existing, variable);
        return;
      }
      if (existing && existing.kind === "camera" && (isImageUrlRef(variable) || !isMediaPick(variable))) {
        assignVariable(existing, variable);
        return;
      }
      if (existing && (existing.kind === "value" || existing.kind === "display" || existing.kind === "chart" || existing.kind === "scene" || existing.kind === "weather" || existing.kind === "presence")) {
        assignVariable(existing, variable);
        return;
      }
      if (existing && existing.kind === "energyflow") {
        const slot = $("p-flow-slot") ? $("p-flow-slot").value : "load";
        assignFlowVariable(existing, slot, variable);
        return;
      }
      if (!existing && isMediaPick(variable)) {
        addTile("image", col, row, variable);
        return;
      }
      if (!existing && isImageUrlRef(variable)) {
        addTile("camera", col, row, variable);
        return;
      }
      if (!existing) addTile("value", col, row, variable);
      return;
    }
    if (kind && !existing) addTile(kind, col, row, null, control);
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
    tile.icon = DIM_CONTROLS.includes(control) || control === "battery" ? (control === "battery" ? "energy" : "light") : "energy";
    tile.variableId = 0;
    tile.unit = DIM_CONTROLS.includes(control) || control === "battery" ? "%" : "";
    tile.decimals = 0;
    if (control === "battery") {
      tile.control = "battery";
      tile.valueMin = 0;
      tile.valueMax = 100;
    } else if (DIM_CONTROLS.includes(control)) {
      tile.control = control;
      tile.actionKind = "dim";
      tile.valueMin = 0;
      tile.valueMax = 100;
    }
    if (variable) assignVariable(tile, variable, true);
  } else if (kind === "nav") {
    const other = state.layout.pages.find((item) => item.id !== state.page) || state.layout.pages[0];
    tile.targetPage = other.id;
    tile.icon = "";
  } else if (kind === "label") {
    tile.text = "Text";
  } else if (kind === "display") {
    tile.displaySource = "variable";
    tile.icon = "info";
    tile.variableId = 0;
    tile.unit = "";
    tile.decimals = 0;
    if (variable) assignVariable(tile, variable, true);
  } else if (kind === "chart") {
    tile.colSpan = Math.min(2, state.layout.columns);
    tile.rowSpan = Math.min(2, state.layout.rows);
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
    tile.colSpan = Math.min(2, state.layout.columns);
    if (variable) assignVariable(tile, variable, true);
  } else if (kind === "energyflow") {
    tile.colSpan = Math.min(2, state.layout.columns);
    tile.rowSpan = Math.min(2, state.layout.rows);
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
  } else if (kind === "separator") {
    tile.colSpan = Math.max(1, state.layout.columns);
    if (!fits(null, col, row, tile.colSpan, 1, null)) tile.colSpan = 1;
  } else if (kind === "system") {
    tile.title = "Anzeige";
    tile.colSpan = Math.min(2, state.layout.columns);
    tile.rowSpan = Math.min(2, state.layout.rows);
    if (!fits(null, col, row, tile.colSpan, tile.rowSpan, null)) {
      tile.colSpan = 1;
      tile.rowSpan = 1;
    }
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
    tile.variableId = 0;
    tile.colSpan = Math.min(2, state.layout.columns);
    tile.rowSpan = Math.min(2, state.layout.rows);
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

/* ---------- Eigenschaften ---------- */

function fillIconSelect(select, allowEmpty) {
  select.innerHTML = allowEmpty ? '<option value="">Kein Symbol</option>' : "";
  for (const [value, name] of ICONS) {
    const option = document.createElement("option");
    option.value = value;
    option.textContent = name;
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

function renderProperties() {
  const tile = selectedTile();
  const hasLayout = Boolean(state.layout);
  $("prop-empty").classList.toggle("hidden", Boolean(tile) || hasLayout);
  $("prop-form").classList.toggle("hidden", !tile);
  if ($("prop-page")) {
    $("prop-page").classList.toggle("hidden", !hasLayout || Boolean(tile));
    if (hasLayout && !tile) renderPageBackground();
  }
  if (!tile) return;
  for (const block of document.querySelectorAll("#prop-form [data-for]")) {
    block.classList.toggle("hidden", !block.dataset.for.split(" ").includes(tile.kind));
  }
  $("p-kind").value = KIND_NAMES[tile.kind];
  $("p-title").value = tile.title || "";
  if ($("p-group")) $("p-group").value = tile.group || "";
  $("p-col").value = tile.col + 1;
  $("p-row").value = tile.row + 1;
  $("p-colspan").value = tile.colSpan || 1;
  $("p-rowspan").value = tile.rowSpan || 1;
  $("p-colspan").max = state.layout.columns;
  $("p-rowspan").max = state.layout.rows;
  if (tile.kind === "nav") {
    fillIconSelect($("p-icon"), true);
    $("p-icon").value = tile.icon || "";
  }
  if (tile.kind === "value") {
    fillIconSelect($("p-value-icon"), false);
    $("p-value-icon").value = tile.icon || "";
    fillIconSelect($("p-iconon"));
    fillIconSelect($("p-iconoff"));
    if ($("p-iconon")) $("p-iconon").value = tile.iconOn || "lightOn";
    if ($("p-iconoff")) $("p-iconoff").value = tile.iconOff || "lightOff";
    if ($("p-interaction")) $("p-interaction").value = tileInteractionMode(tile);
    syncInteractionPropertyPanels();
    if ($("p-dim-show-percent")) $("p-dim-show-percent").checked = tile.dimShowPercent !== false;
    if ($("p-dim-layout")) $("p-dim-layout").value = tile.dimLayout || "horizontal";
    if ($("p-dim-tap")) {
      const tap = tile.dimTapTrack;
      if (tap === true || tap === "on") $("p-dim-tap").value = "on";
      else if (tap === false || tap === "off") $("p-dim-tap").value = "off";
      else $("p-dim-tap").value = "auto";
    }
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
  $("p-actionkind").value = tile.actionKind || (DIM_CONTROLS.includes(tile.control) ? "dim" : (variable ? variable.actionKind || "switch" : "switch"));
  fillTargetSelect($("p-target"));
  $("p-target").value = tile.targetPage || "";
  $("p-text").value = tile.text || "";
  const displaySource = tile.displaySource || "variable";
  if ($("p-displaysource")) {
    $("p-displaysource").value = displaySource;
    $("p-display-variable").classList.toggle("hidden", displaySource !== "variable");
    $("p-display-text").classList.toggle("hidden", displaySource !== "text");
  }
  if ($("p-display-varpick")) {
    const dv = variableById(tile.variableId);
    $("p-display-varpick").value = dv ? `${dv.path ? dv.path + " / " : ""}${dv.name} (${dv.id})` : "";
  }
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
  if (tile.kind === "scene") {
    fillIconSelect($("p-scene-icon"), false);
    if ($("p-scene-icon")) $("p-scene-icon").value = tile.icon || "home";
    const sceneVar = variableById(tile.variableId);
    if ($("p-scene-variable")) {
      $("p-scene-variable").value = sceneVar
        ? `${sceneVar.path ? sceneVar.path + " / " : ""}${sceneVar.name} (${sceneVar.id})`
        : "";
    }
    if ($("p-scene-actionid")) $("p-scene-actionid").value = tile.actionId || "";
    if ($("p-scene-confirm")) $("p-scene-confirm").checked = Boolean(tile.confirm);
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
  fillImageSelect($("p-image"));
  $("p-image").value = tile.imageId || "";
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
  const group = ($("p-group") && $("p-group").value.trim()) || "";
  if (group) tile.group = group;
  else delete tile.group;
  const col = Math.max(0, Number($("p-col").value) - 1);
  const row = Math.max(0, Number($("p-row").value) - 1);
  const colSpan = Math.max(1, Number($("p-colspan").value) || 1);
  const rowSpan = Math.max(1, Number($("p-rowspan").value) || 1);
  if (fits(tile, col, row, colSpan, rowSpan, tile.tileId)) {
    tile.col = col;
    tile.row = row;
    tile.colSpan = colSpan;
    tile.rowSpan = rowSpan;
  } else {
    setStatus("Position oder Größe passt nicht ins Raster.");
  }
  if (tile.kind === "nav") tile.icon = $("p-icon").value;
  if (tile.kind === "value") {
    tile.unit = $("p-unit").value;
    tile.decimals = Number($("p-decimals").value) || 0;
    const variable = variableById(tile.variableId);
    const mode = ($("p-interaction") && $("p-interaction").value) || tileInteractionMode(tile);
    delete tile.iconMode;
    delete tile.iconOn;
    delete tile.iconOff;
    delete tile.control;
    delete tile.batteryPad;
    delete tile.batteryLabel;
    if (mode === "iconSwitch") {
      tile.iconMode = "toggle";
      tile.iconOn = $("p-iconon").value;
      tile.iconOff = $("p-iconoff").value;
      delete tile.icon;
    } else if (mode !== "battery") {
      const iconVal = $("p-value-icon") ? $("p-value-icon").value : "";
      if (iconVal) tile.icon = iconVal;
      else delete tile.icon;
    }
    if (mode === "dimSwitch") tile.control = "dimSwitch";
    else if (mode === "slider") tile.control = "slider";
    else if (mode === "combo") tile.control = "combo";
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
    if (DIM_CONTROLS.includes(mode) || mode === "blinds") {
      if ($("p-dim-show-percent") && !$("p-dim-show-percent").checked) tile.dimShowPercent = false;
      else delete tile.dimShowPercent;
      const layout = $("p-dim-layout") ? $("p-dim-layout").value : "horizontal";
      if (layout === "stacked") tile.dimLayout = "stacked";
      else delete tile.dimLayout;
      const tapMode = $("p-dim-tap") ? $("p-dim-tap").value : "auto";
      if (tapMode === "on") tile.dimTapTrack = "on";
      else if (tapMode === "off") tile.dimTapTrack = "off";
      else delete tile.dimTapTrack;
    } else {
      delete tile.dimShowPercent;
      delete tile.dimLayout;
      delete tile.dimTapTrack;
    }
    if (mode !== "battery" && $("p-action").checked && variable && variable.action) {
      tile.actionId = tile.actionId || `a-${tile.tileId}`;
      tile.actionKind = mode === "blinds" ? "position" : (DIM_CONTROLS.includes(mode) || mode === "setpoint" ? "dim" : $("p-actionkind").value);
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
  if (tile.kind === "scene") {
    tile.icon = $("p-scene-icon") ? $("p-scene-icon").value || "home" : (tile.icon || "home");
    const sceneVar = variableById(tile.variableId);
    const actionIdRaw = ($("p-scene-actionid")?.value || "").trim();
    if (sceneVar && sceneVar.action) {
      tile.actionId = actionIdRaw || tile.actionId || `a-${tile.tileId}`;
      if (!/^[A-Za-z0-9_-]{1,40}$/.test(tile.actionId)) {
        tile.actionId = `a-${tile.tileId}`;
      }
      tile.actionKind = "switch";
      tile.confirm = Boolean($("p-scene-confirm")?.checked);
    } else {
      delete tile.actionId;
      delete tile.actionKind;
      tile.confirm = false;
    }
  }
  if (tile.kind === "weather") {
    tile.icon = $("p-weather-icon") ? $("p-weather-icon").value || "sun" : "sun";
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
  }
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
for (const id of ["p-title", "p-group", "p-col", "p-row", "p-colspan", "p-rowspan", "p-icon", "p-value-icon", "p-iconon", "p-iconoff", "p-unit", "p-decimals", "p-action", "p-confirm", "p-actionkind", "p-target", "p-text", "p-label-style", "p-scene-actionid", "p-scene-icon", "p-scene-confirm", "p-weather-unit", "p-weather-decimals", "p-weather-icon", "p-presence-mode", "p-setpoint-step", "p-image", "p-imagesource", "p-camerasource", "p-batterypad", "p-batterylabel", "p-displaysource", "p-display-unit", "p-display-decimals", "p-display-icon", "p-chart-period", "p-chart-style", "p-chart-unit", "p-chart-decimals", "p-chart-y-mode", "p-chart-y-min", "p-chart-y-max", "p-chart-yesterday", "p-chart-refresh", "p-chart-bitmap", "p-chart-extra-ids", "p-flow-unit", "p-flow-decimals", "p-flow-threshold", "p-flow-slot", "p-flow-mode"]) {
  if ($(id)) $(id).addEventListener("change", applyProperties);
}
if ($("p-flow-home-override")) {
  $("p-flow-home-override").addEventListener("change", applyProperties);
}
for (const id of ["p-display-textarea"]) {
  if ($(id)) $(id).addEventListener("input", applyProperties);
}
if ($("p-displaysource")) {
  $("p-displaysource").addEventListener("change", () => {
    const source = $("p-displaysource").value;
    $("p-display-variable").classList.toggle("hidden", source !== "variable");
    $("p-display-text").classList.toggle("hidden", source !== "text");
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
for (const id of ["resize-1x1", "resize-2x1", "resize-1x2", "resize-2x2", "resize-full-width"]) {
  const button = $(id);
  if (!button) continue;
  button.addEventListener("click", () => {
    const tile = selectedTile();
    if (!tile) return;
    if (id === "resize-1x1") resizeTilePreset(1, 1);
    if (id === "resize-2x1") resizeTilePreset(2, 1);
    if (id === "resize-1x2") resizeTilePreset(1, 2);
    if (id === "resize-2x2") resizeTilePreset(2, 2);
    if (id === "resize-full-width") resizeTilePreset(state.layout.columns, tile.rowSpan || 1);
  });
}
const displayProfileSelect = $("display-profile");
if (displayProfileSelect) {
  displayProfileSelect.addEventListener("change", () => {
    if (!state.layout) return;
    state.layout.displayProfile = displayProfileSelect.value;
    applyGridAspect();
    markDirty();
    if ($("probe-dialog").open && probeSourceMode() === "draft") {
      loadProbe(true).catch((error) => setStatus(error.message));
    }
  });
}
bindClick("p-details", () => {
  const tile = selectedTile();
  if (tile) openDetails(tile);
});
bindClick("p-delete", deleteSelected);

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

function openDetails(tile) {
  const dialog = $("details");
  const body = $("d-body");
  $("d-title").textContent = `${KIND_NAMES[tile.kind]} – ${tile.title || tile.text || tile.tileId}`;
  body.innerHTML = "";
  const fields = [];
  const add = (label, input) => {
    const wrap = document.createElement("label");
    wrap.textContent = label;
    wrap.appendChild(input);
    body.appendChild(wrap);
    return input;
  };
  const text = (value) => Object.assign(document.createElement("input"), { value: value ?? "" });
  const number = (value, min, max) => Object.assign(document.createElement("input"), { type: "number", value, min, max });
  const check = (value) => Object.assign(document.createElement("input"), { type: "checkbox", checked: Boolean(value) });
  const info = document.createElement("p");
  info.className = "hint";
  info.textContent = `Kennung ${tile.tileId}${tile.actionId ? ", Aktion " + tile.actionId : ""}. Seite ${pageTitleIn(state.page)}.`;
  body.appendChild(info);
  fields.title = add("Titel", text(tile.title));
  const grid = document.createElement("div");
  grid.className = "row";
  body.appendChild(grid);
  const spanHint = document.createElement("p");
  spanHint.className = "hint";
  spanHint.textContent = "Breite und Höhe = Anzahl Rasterfelder; am Raster ziehen oder hier eintragen.";
  body.appendChild(spanHint);
  for (const [label, key, value, min, max] of [["Spalte", "col", tile.col + 1, 1, state.layout.columns], ["Zeile", "row", tile.row + 1, 1, state.layout.rows], ["Breite", "colSpan", tile.colSpan || 1, 1, state.layout.columns], ["Höhe", "rowSpan", tile.rowSpan || 1, 1, state.layout.rows]]) {
    const wrap = document.createElement("label");
    wrap.textContent = label;
    fields[key] = number(value, min, max);
    wrap.appendChild(fields[key]);
    grid.appendChild(wrap);
  }
  if (tile.kind === "nav") {
    fields.icon = add("Symbol", document.createElement("select"));
    fillIconSelect(fields.icon, true);
    fields.icon.value = tile.icon || "";
  }
  if (tile.kind === "value") {
    const variable = variableById(tile.variableId);
    const picker = document.createElement("select");
    picker.innerHTML = '<option value="0">Keine Variable</option>';
    for (const item of state.variables) {
      const option = document.createElement("option");
      option.value = String(item.id);
      option.textContent = `${item.path ? item.path + " / " : ""}${item.name} (${item.id})`;
      picker.appendChild(option);
    }
    picker.value = String(tile.variableId || 0);
    fields.variable = add("Variable", picker);
    fields.unit = add("Einheit", text(tile.unit));
    fields.decimals = add("Nachkommastellen", number(tile.decimals || 0, 0, 3));
    const interactionSel = document.createElement("select");
    interactionSel.innerHTML = '<option value="switch">Schalter (Ein/Aus)</option><option value="iconSwitch">Icon-Schalter</option><option value="dimSwitch">Dimmschalter</option><option value="slider">Nur Dimmer</option><option value="combo">Kombi</option><option value="battery">Batterie (Ladestand)</option>';
    interactionSel.value = tileInteractionMode(tile);
    fields.interaction = add("Schalter / Bedienung", interactionSel);
    fields.action = add("Schaltbar", check(tile.actionId));
    fields.action.disabled = !(variable && variable.action);
    fields.confirm = add("Bestätigung an der Anzeige", check(tile.confirm));
    const kind = document.createElement("select");
    kind.innerHTML = '<option value="switch">Schalten</option><option value="dim">Dimmen</option><option value="position">Position</option>';
    kind.value = tile.actionKind || "switch";
    fields.actionKind = add("Aktionsart", kind);
    fields.valueMin = add("Minimum (Dimmen/Position)", number(tile.valueMin ?? 0));
    fields.valueMax = add("Maximum (Dimmen/Position)", number(tile.valueMax ?? 100));
    fields.threshold = add("Schwellwert für Farbe (leer = aus)", text(tile.threshold ?? ""));
    const color = document.createElement("select");
    color.innerHTML = '<option value="neutral">Neutral</option><option value="ok">Grün</option><option value="warn">Gelb</option><option value="alarm">Rot</option>';
    color.value = tile.color || "neutral";
    fields.color = add("Farbe über dem Schwellwert", color);
    const staticIconWrap = document.createElement("label");
    staticIconWrap.textContent = "Symbol (optional) ";
    fields.icon = document.createElement("select");
    fillIconSelect(fields.icon, false);
    fields.icon.value = tile.icon || "";
    staticIconWrap.appendChild(fields.icon);
    body.appendChild(staticIconWrap);
    const toggleIconWrap = document.createElement("div");
    toggleIconWrap.className = "row hidden";
    toggleIconWrap.dataset.iconSection = "toggle";
    const onWrap = document.createElement("label");
    onWrap.textContent = "Symbol Ein ";
    fields.iconOn = document.createElement("select");
    fillIconSelect(fields.iconOn, false);
    fields.iconOn.value = tile.iconOn || "lightOn";
    onWrap.appendChild(fields.iconOn);
    const offWrap = document.createElement("label");
    offWrap.textContent = "Symbol Aus ";
    fields.iconOff = document.createElement("select");
    fillIconSelect(fields.iconOff, false);
    fields.iconOff.value = tile.iconOff || "lightOff";
    offWrap.appendChild(fields.iconOff);
    toggleIconWrap.append(onWrap, offWrap);
    body.appendChild(toggleIconWrap);
    const batteryWrap = document.createElement("div");
    batteryWrap.className = "row hidden";
    batteryWrap.dataset.controlSection = "battery";
    const padWrap = document.createElement("label");
    padWrap.textContent = "Innenabstand (px) ";
    fields.batteryPad = number(tile.batteryPad != null ? tile.batteryPad : 8, 0, 40);
    padWrap.appendChild(fields.batteryPad);
    const labelWrap = document.createElement("label");
    labelWrap.textContent = "Prozent-Anzeige ";
    const batteryLabel = document.createElement("select");
    batteryLabel.innerHTML = '<option value="inside">In der Batterie</option><option value="right">Rechts daneben</option><option value="below">Darunter</option>';
    batteryLabel.value = tile.batteryLabel || "inside";
    fields.batteryLabel = batteryLabel;
    labelWrap.appendChild(batteryLabel);
    batteryWrap.append(padWrap, labelWrap);
    body.appendChild(batteryWrap);
    const syncValueDetails = () => {
      const mode = interactionSel.value;
      const toggle = mode === "iconSwitch";
      staticIconWrap.classList.toggle("hidden", toggle || mode === "battery");
      toggleIconWrap.classList.toggle("hidden", !toggle);
      batteryWrap.classList.toggle("hidden", mode !== "battery");
      const chosenVar = variableById(Number(picker.value));
      fields.action.disabled = mode === "battery" || !(chosenVar && chosenVar.action);
      if (mode === "battery") fields.action.checked = false;
      if (DIM_CONTROLS.includes(mode)) fields.actionKind.value = "dim";
    };
    interactionSel.addEventListener("change", syncValueDetails);
    syncValueDetails();
    picker.addEventListener("change", () => {
      const chosen = variableById(Number(picker.value));
      fields.action.disabled = !(chosen && chosen.action);
      if (!fields.action.disabled) fields.actionKind.value = chosen.actionKind || "switch";
      if (!fields.title.value && chosen) fields.title.value = chosen.name;
      syncValueDetails();
    });
  }
  if (tile.kind === "nav") {
    fields.target = add("Zielseite", document.createElement("select"));
    fillTargetSelect(fields.target);
    fields.target.value = tile.targetPage;
  }
  if (tile.kind === "label") {
    const style = document.createElement("select");
    style.innerHTML = '<option value="text">Text</option><option value="section">Abschnitt (Frame)</option>';
    style.value = tile.labelStyle || "text";
    fields.labelStyle = add("Stil", style);
    const area = document.createElement("textarea");
    area.rows = 4;
    area.value = tile.text || "";
    fields.text = add("Text", area);
  }
  if (tile.kind === "display") {
    const source = document.createElement("select");
    source.innerHTML = '<option value="variable">Variable (live)</option><option value="text">Fester Text</option>';
    source.value = tile.displaySource || "variable";
    fields.displaySource = add("Quelle", source);
    const picker = document.createElement("select");
    picker.innerHTML = '<option value="0">Keine Variable</option>';
    for (const item of state.variables) {
      const option = document.createElement("option");
      option.value = String(item.id);
      option.textContent = `${item.path ? item.path + " / " : ""}${item.name} (${item.id})`;
      picker.appendChild(option);
    }
    picker.value = String(tile.variableId || 0);
    fields.variable = add("Variable", picker);
    fields.unit = add("Einheit", text(tile.unit));
    fields.decimals = add("Nachkommastellen", number(tile.decimals || 0, 0, 3));
    const area = document.createElement("textarea");
    area.rows = 3;
    area.value = tile.text || "";
    fields.displayText = add("Text (fest)", area);
    fields.icon = add("Symbol", document.createElement("select"));
    fillIconSelect(fields.icon, false);
    fields.icon.value = tile.icon || "";
  }
  if (tile.kind === "chart") {
    const picker = document.createElement("select");
    picker.innerHTML = '<option value="0">Keine Variable</option>';
    for (const item of state.variables) {
      if (item.type !== 1 && item.type !== 2) continue;
      const option = document.createElement("option");
      option.value = String(item.id);
      option.textContent = `${item.path ? item.path + " / " : ""}${item.name} (${item.id})`;
      picker.appendChild(option);
    }
    picker.value = String(tile.variableId || 0);
    fields.variable = add("Variable", picker);
    const period = document.createElement("select");
    period.innerHTML = '<option value="1h">1 Stunde</option><option value="6h">6 Stunden</option><option value="24h">24 Stunden</option><option value="7d">7 Tage</option>';
    period.value = tile.chartPeriod || "24h";
    fields.chartPeriod = add("Zeitraum", period);
    const style = document.createElement("select");
    style.innerHTML = '<option value="area">Fläche</option><option value="line">Linie</option>';
    style.value = tile.chartStyle || "area";
    fields.chartStyle = add("Darstellung", style);
    fields.unit = add("Einheit", text(tile.unit));
    fields.decimals = add("Nachkommastellen", number(tile.decimals ?? 1, 0, 3));
    const hint = document.createElement("p");
    hint.className = "hint";
    hint.textContent = "Karte im openHAB-Stil: aktueller Wert oben rechts, Verlauf darunter. Variable muss im Archiv protokolliert sein.";
    body.appendChild(hint);
    picker.addEventListener("change", () => {
      const chosen = variableById(Number(picker.value));
      if (!fields.title.value && chosen) fields.title.value = chosen.name;
    });
  }
  if (tile.kind === "image") {
    const imgSource = document.createElement("select");
    imgSource.innerHTML = '<option value="catalog">Katalog (hochgeladen)</option><option value="media">IPS-Medien-Variable</option>';
    imgSource.value = tile.imageSource || "catalog";
    fields.imageSource = add("Bildquelle", imgSource);
    const catalogWrap = document.createElement("div");
    catalogWrap.dataset.imageSection = "catalog";
    fields.image = document.createElement("select");
    fillImageSelect(fields.image);
    fields.image.value = tile.imageId || "";
    const catLabel = document.createElement("label");
    catLabel.textContent = "Bild ";
    catLabel.appendChild(fields.image);
    catalogWrap.appendChild(catLabel);
    body.appendChild(catalogWrap);
    const mediaWrap = document.createElement("div");
    mediaWrap.className = "hidden";
    mediaWrap.dataset.imageSection = "media";
    const mediaId = tile.mediaObjectId || (tile.imageSource === "media" ? tile.variableId : 0);
    const mediaLabel = document.createElement("label");
    mediaLabel.textContent = "Medien-Variable ";
    fields.imageMedia = detailsVariableSelect(mediaId, isMediaPick);
    mediaLabel.appendChild(fields.imageMedia);
    mediaWrap.appendChild(mediaLabel);
    body.appendChild(mediaWrap);
    const syncImageDetails = () => {
      const media = imgSource.value === "media";
      catalogWrap.classList.toggle("hidden", media);
      mediaWrap.classList.toggle("hidden", !media);
    };
    imgSource.addEventListener("change", syncImageDetails);
    syncImageDetails();
    const manage = document.createElement("button");
    manage.type = "button";
    manage.textContent = "Bilder verwalten …";
    manage.addEventListener("click", () => openImages());
    body.appendChild(manage);
  }
  if (tile.kind === "camera") {
    const source = document.createElement("select");
    source.innerHTML = '<option value="url">Variable mit Bild-URL</option><option value="media">IPS-Medien-Variable</option>';
    source.value = tile.cameraSource || "url";
    fields.cameraSource = add("Quelle", source);
    const picker = document.createElement("select");
    picker.innerHTML = '<option value="0">Keine Variable</option>';
    fields.variable = add("Variable", picker);
    const refreshPicker = () => {
      const wantMedia = source.value === "media";
      picker.innerHTML = '<option value="0">Keine Variable</option>';
      for (const item of state.variables) {
        if (wantMedia) {
          if (!isMediaPick(item)) continue;
        } else if (isMediaObject(item) || (isMediaPick(item) && !isImageUrlRef(item))) {
          continue;
        }
        const option = document.createElement("option");
        option.value = String(item.id);
        option.textContent = `${item.path ? item.path + " / " : ""}${item.name} (${item.id})`;
        picker.appendChild(option);
      }
      picker.value = String(tile.mediaObjectId || tile.variableId || 0);
    };
    refreshPicker();
    source.addEventListener("change", refreshPicker);
    picker.addEventListener("change", () => {
      const chosen = variableById(Number(picker.value));
      if (!fields.title.value && chosen) fields.title.value = chosen.name;
    });
  }
  if (tile.kind === "energyflow") {
    appendEnergyFlowDetails(body, tile, fields, add, check, number, text);
  }
  if (tile.kind === "scene") {
    const picker = document.createElement("select");
    picker.innerHTML = '<option value="0">Keine Variable</option>';
    for (const item of state.variables) {
      if (!item.action) continue;
      const option = document.createElement("option");
      option.value = String(item.id);
      option.textContent = variablePickLabel(item);
      picker.appendChild(option);
    }
    picker.value = String(tile.variableId || 0);
    fields.variable = add("Aktions-Variable", picker);
    fields.actionId = add("Aktions-ID", text(tile.actionId || ""));
    fields.icon = add("Symbol", document.createElement("select"));
    fillIconSelect(fields.icon, false);
    fields.icon.value = tile.icon || "home";
    fields.confirm = add("Bestätigung an der Anzeige", check(tile.confirm));
    const hint = document.createElement("p");
    hint.className = "hint";
    hint.textContent = "Nur Variablen mit IPS-Aktion. Die Anzeige sendet beim Tippen eine Schaltaktion (RequestAction).";
    body.appendChild(hint);
    picker.addEventListener("change", () => {
      const chosen = variableById(Number(picker.value));
      if (chosen && !fields.title.value) fields.title.value = chosen.name;
    });
  }
  if (tile.kind === "weather") {
    const tempPicker = document.createElement("select");
    tempPicker.innerHTML = '<option value="0">Keine Variable</option>';
    for (const item of state.variables) {
      if (item.type !== 1 && item.type !== 2) continue;
      const option = document.createElement("option");
      option.value = String(item.id);
      option.textContent = variablePickLabel(item);
      tempPicker.appendChild(option);
    }
    tempPicker.value = String(tile.variableId || 0);
    fields.variable = add("Temperatur-Variable", tempPicker);
    fields.unit = add("Einheit", text(tile.unit || "°C"));
    fields.decimals = add("Nachkommastellen", number(tile.decimals ?? 1, 0, 3));
    const forecastPicker = document.createElement("select");
    forecastPicker.innerHTML = '<option value="0">Keine Prognose</option>';
    for (const item of state.variables) {
      if (item.type !== 3) continue;
      const option = document.createElement("option");
      option.value = String(item.id);
      option.textContent = variablePickLabel(item);
      forecastPicker.appendChild(option);
    }
    forecastPicker.value = String(tile.forecastVariableId || 0);
    fields.forecast = add("Prognose-Variable (Text)", forecastPicker);
    fields.icon = add("Symbol", document.createElement("select"));
    fillIconSelect(fields.icon, false);
    fields.icon.value = tile.icon || "sun";
    tempPicker.addEventListener("change", () => {
      const chosen = variableById(Number(tempPicker.value));
      if (chosen && !fields.title.value) fields.title.value = chosen.name;
      if (chosen && chosen.unit && !fields.unit.value) fields.unit.value = chosen.unit;
    });
  }
  if (tile.kind === "presence") {
    const picker = document.createElement("select");
    picker.innerHTML = '<option value="0">Keine Variable</option>';
    for (const item of state.variables) {
      const option = document.createElement("option");
      option.value = String(item.id);
      option.textContent = variablePickLabel(item);
      picker.appendChild(option);
    }
    picker.value = String(tile.variableId || 0);
    fields.variable = add("Variable", picker);
    const mode = document.createElement("select");
    mode.innerHTML = '<option value="home">Anwesenheit</option><option value="alarm">Alarm</option>';
    mode.value = tile.presenceMode || "home";
    fields.presenceMode = add("Modus", mode);
    picker.addEventListener("change", () => {
      const chosen = variableById(Number(picker.value));
      if (chosen && !fields.title.value) fields.title.value = chosen.name;
    });
  }
  if (tile.kind === "system") {
    const hint = document.createElement("p");
    hint.className = "hint";
    hint.textContent = "Die Anzeige füllt diese Kachel selbst (Programm, WLAN, IP, Laufzeit, Speicher, Datenalter). Mindestens 2×2 Zellen empfohlen.";
    body.appendChild(hint);
  }
  if (tile.kind === "separator") {
    const hint = document.createElement("p");
    hint.className = "hint";
    hint.textContent = "Horizontale Trennlinie über die volle Rasterbreite der Zeile.";
    body.appendChild(hint);
  }
  dialog.returnValue = "";
  dialog.showModal();
  dialog.onclose = () => {
    if (dialog.returnValue !== "ok") return;
    tile.title = fields.title.value;
    const col = Number(fields.col.value) - 1;
    const row = Number(fields.row.value) - 1;
    const colSpan = Number(fields.colSpan.value) || 1;
    const rowSpan = Number(fields.rowSpan.value) || 1;
    if (fits(tile, col, row, colSpan, rowSpan, tile.tileId)) {
      Object.assign(tile, { col, row, colSpan, rowSpan });
    } else {
      setStatus("Position oder Größe passt nicht ins Raster. Übrige Angaben wurden übernommen.");
    }
    if (fields.icon && tile.kind === "nav") tile.icon = fields.icon.value;
    if (tile.kind === "value") {
      const chosen = variableById(Number(fields.variable.value));
      tile.variableId = chosen ? chosen.id : 0;
      tile.unit = fields.unit.value;
      tile.decimals = Number(fields.decimals.value) || 0;
      const mode = fields.interaction.value || "switch";
      delete tile.control;
      delete tile.iconMode;
      delete tile.iconOn;
      delete tile.iconOff;
      delete tile.icon;
      if (mode === "iconSwitch") {
        tile.iconMode = "toggle";
        tile.iconOn = fields.iconOn.value;
        tile.iconOff = fields.iconOff.value;
      } else if (mode !== "battery" && fields.icon) {
        tile.icon = fields.icon.value;
      }
      if (mode === "dimSwitch") tile.control = "dimSwitch";
      else if (mode === "slider") tile.control = "slider";
      else if (mode === "combo") tile.control = "combo";
      else if (mode === "battery") {
        tile.control = "battery";
        tile.valueMin = Number(fields.valueMin.value);
        tile.valueMax = Number(fields.valueMax.value);
        tile.batteryPad = Number(fields.batteryPad.value) || 8;
        tile.batteryLabel = fields.batteryLabel.value || "inside";
        delete tile.actionId;
        delete tile.actionKind;
        tile.confirm = false;
      } else if (fields.action.checked && chosen && chosen.action) {
        tile.actionId = tile.actionId || `a-${tile.tileId}`;
        tile.actionKind = DIM_CONTROLS.includes(mode) ? "dim" : fields.actionKind.value;
        tile.confirm = fields.confirm.checked;
        tile.valueMin = Number(fields.valueMin.value);
        tile.valueMax = Number(fields.valueMax.value);
        delete tile.batteryPad;
        delete tile.batteryLabel;
      } else {
        delete tile.actionId;
        if (!DIM_CONTROLS.includes(mode)) delete tile.actionKind;
        delete tile.valueMin;
        delete tile.valueMax;
        delete tile.batteryPad;
        delete tile.batteryLabel;
        tile.confirm = false;
      }
      if (fields.threshold.value.trim() !== "" && !Number.isNaN(Number(fields.threshold.value))) {
        tile.threshold = Number(fields.threshold.value);
        tile.color = fields.color.value;
      } else {
        delete tile.threshold;
        delete tile.color;
      }
    }
    if (tile.kind === "nav") tile.targetPage = fields.target.value;
    if (tile.kind === "scene") {
      const chosen = variableById(Number(fields.variable.value));
      tile.variableId = chosen ? chosen.id : 0;
      tile.icon = fields.icon.value || "home";
      if (chosen && chosen.action) {
        const idRaw = (fields.actionId.value || "").trim();
        tile.actionId = idRaw && /^[A-Za-z0-9_-]{1,40}$/.test(idRaw) ? idRaw : (tile.actionId || `a-${tile.tileId}`);
        tile.actionKind = "switch";
        tile.confirm = fields.confirm.checked;
      } else {
        delete tile.actionId;
        delete tile.actionKind;
        tile.confirm = false;
      }
    }
    if (tile.kind === "weather") {
      tile.variableId = Number(fields.variable.value) || 0;
      const forecastId = Number(fields.forecast.value) || 0;
      if (forecastId > 0) tile.forecastVariableId = forecastId;
      else delete tile.forecastVariableId;
      tile.unit = fields.unit.value || "°C";
      tile.decimals = Number(fields.decimals.value) || 0;
      tile.icon = fields.icon.value || "sun";
    }
    if (tile.kind === "presence") {
      tile.variableId = Number(fields.variable.value) || 0;
      tile.presenceMode = fields.presenceMode.value || "home";
    }
    if (tile.kind === "label") {
      tile.text = fields.text.value;
      tile.labelStyle = fields.labelStyle ? fields.labelStyle.value : "text";
      delete tile.size;
    }
    if (tile.kind === "display") {
      tile.displaySource = fields.displaySource.value;
      tile.icon = fields.icon.value;
      if (fields.displaySource.value === "text") {
        tile.text = fields.displayText.value;
        delete tile.variableId;
        delete tile.unit;
        delete tile.decimals;
      } else {
        tile.variableId = Number(fields.variable.value) || 0;
        tile.unit = fields.unit.value;
        tile.decimals = Number(fields.decimals.value) || 0;
        delete tile.text;
      }
    }
    if (tile.kind === "chart") {
      tile.variableId = Number(fields.variable.value) || 0;
      tile.chartPeriod = fields.chartPeriod.value || "24h";
      tile.chartStyle = fields.chartStyle.value || "area";
      tile.unit = fields.unit.value;
      tile.decimals = Number(fields.decimals.value) || 0;
    }
    if (tile.kind === "image") {
      const source = fields.imageSource.value || "catalog";
      if (source === "media") {
        tile.imageSource = "media";
        delete tile.imageId;
        const mediaVar = variableById(Number(fields.imageMedia.value));
        if (mediaVar && isMediaPick(mediaVar)) assignMediaVariable(tile, mediaVar, true);
        else {
          tile.variableId = 0;
          delete tile.mediaObjectId;
        }
      } else {
        tile.imageSource = "catalog";
        tile.imageId = fields.image.value;
        delete tile.variableId;
        delete tile.mediaObjectId;
      }
    }
    if (tile.kind === "energyflow") {
      tile.flowMode = fields.flowMode.value;
      tile.homeOverride = fields.homeOverride.checked;
      tile.unit = fields.flowUnit.value || "W";
      tile.decimals = Number(fields.flowDecimals.value) || 0;
      tile.flowThreshold = Number(fields.flowThreshold.value) || 0;
      for (const key of allFlowVariableKeys()) delete tile[key];
      const map = flowSlotMap(tile);
      for (const [slot, propKey] of Object.entries(map)) {
        const picker = fields[`flowSlot_${slot}`];
        if (!picker) continue;
        const id = Number(picker.value) || 0;
        if (id > 0) tile[propKey] = id;
      }
      if (!tile.homeOverride) delete tile.loadVariableId;
      if (!tile.title) tile.title = "Energiefluss";
    }
    if (tile.kind === "camera") {
      tile.cameraSource = fields.cameraSource ? fields.cameraSource.value : "url";
      const chosen = treeItemById(Number(fields.variable.value));
      if (!chosen || Number(fields.variable.value) === 0) {
        tile.variableId = 0;
        delete tile.mediaObjectId;
      } else if (tile.cameraSource === "media") assignCameraMedia(tile, chosen, true);
      else assignVariable(tile, chosen, true);
    }
    markDirty();
    renderGrid();
    renderProperties();
  };
}

/* ---------- Ansicht-Dialog ---------- */

function openLayoutDialog() {
  const dialog = $("layout-dialog");
  $("l-name").value = state.layout.name;
  fillProfileSelect($("l-display-profile"), state.layout.displayProfile || defaultProfileId());
  $("l-columns").value = state.layout.columns;
  $("l-rows").value = state.layout.rows;
  $("l-tabs").checked = state.layout.showTabs;
  const prof = layoutProfile(state.layout);
  $("l-columns").max = prof.maxColumns;
  $("l-rows").max = prof.maxRows;
  const list = $("l-pages");
  list.innerHTML = "";
  for (const [id, name] of PAGES) {
    const existing = state.layout.pages.find((page) => page.id === id);
    const row = document.createElement("div");
    row.className = "page-row";
    const wrap = document.createElement("label");
    const check = Object.assign(document.createElement("input"), { type: "checkbox", checked: Boolean(existing), value: id });
    const title = Object.assign(document.createElement("input"), { type: "text", value: existing ? existing.title || name : name });
    const count = document.createElement("small");
    count.textContent = existing ? `${existing.tiles.length}` : "";
    wrap.append(check, title, count);
    const background = document.createElement("select");
    background.className = "page-bg";
    fillImageSelect(background);
    background.value = existing && existing.backgroundId ? existing.backgroundId : "";
    row.append(wrap, background);
    list.appendChild(row);
  }
  dialog.returnValue = "";
  dialog.showModal();
  dialog.onclose = () => {
    if (dialog.returnValue !== "ok") return;
    state.layout.name = $("l-name").value.trim() || state.layout.name;
    state.layout.displayProfile = $("l-display-profile").value || defaultProfileId();
    const prof = layoutProfile(state.layout);
    const columns = Math.min(prof.maxColumns, Math.max(1, Number($("l-columns").value) || prof.defaultColumns));
    const rows = Math.min(prof.maxRows, Math.max(1, Number($("l-rows").value) || prof.defaultRows));
    const pages = [];
    for (const wrap of list.querySelectorAll("label")) {
      const [check, title] = wrap.querySelectorAll("input");
      if (!check.checked) continue;
      const existing = state.layout.pages.find((page) => page.id === check.value);
      const page = existing ? Object.assign(existing, { title: title.value.trim() || pageTitle(check.value) }) : { id: check.value, title: title.value.trim() || pageTitle(check.value), tiles: [] };
      const background = wrap.parentElement.querySelector("select.page-bg");
      if (background && background.value) page.backgroundId = background.value;
      else delete page.backgroundId;
      pages.push(page);
    }
    if (!pages.length) pages.push(state.layout.pages[0] || { id: "start", title: "Start", tiles: [] });
    state.layout.pages = pages;
    for (const page of pages) {
      page.tiles = page.tiles.filter((tile) => tile.col + (tile.colSpan || 1) <= columns && tile.row + (tile.rowSpan || 1) <= rows);
      for (const tile of page.tiles) {
        if (tile.kind === "nav" && !pages.some((item) => item.id === tile.targetPage)) tile.targetPage = pages[0].id;
      }
    }
    state.layout.columns = columns;
    state.layout.rows = rows;
    state.layout.showTabs = $("l-tabs").checked;
    $("columns").value = columns;
    $("rows").value = rows;
    $("show-tabs").checked = state.layout.showTabs;
    if (!pages.some((page) => page.id === state.page)) state.page = pages[0].id;
    markDirty();
    renderPageTabs();
    renderGrid();
    renderProperties();
  };
}

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
    if (needle && !`${variable.name} ${variable.path} ${variable.id}`.toLowerCase().includes(needle)) continue;
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
      const row = document.createElement("div");
      row.className = "variable" + (state.pickedVariable && state.pickedVariable.id === variable.id ? " selected" : "");
      row.draggable = true;
      row.title = `${variable.path ? variable.path + " / " : ""}${variable.name}\nID ${variable.id}${variable.profile ? "\nProfil " + variable.profile : ""}`;
      const mediaBadge = isMediaPick(variable) ? ' <span class="badge badge-media">Medien</span>' : (isImageUrlRef(variable) ? ' <span class="badge badge-media">Bild-URL</span>' : "");
      row.innerHTML = `<span></span><span class="meta">${escapeHtml(variableTypeLabel(variable))}${mediaBadge}${variable.action ? ' <span class="badge">schaltbar</span>' : ""}</span>`;
      row.firstChild.textContent = variable.name;
      row.addEventListener("dragstart", (event) => {
        event.dataTransfer.setData("text/homedisplay-variable", String(variable.id));
        event.dataTransfer.effectAllowed = "copy";
      });
      row.addEventListener("click", () => {
        const tile = selectedTile();
        if (tile && tile.kind === "image" && isMediaPick(variable)) {
          assignMediaVariable(tile, variable);
          return;
        }
        if (tile && tile.kind === "camera" && isMediaPick(variable)) {
          assignCameraMedia(tile, variable);
          return;
        }
        if (tile && tile.kind === "weather" && variable.type === 3) {
          tile.forecastVariableId = variable.id;
          markDirty();
          renderGrid();
          renderProperties();
          setStatus("Prognose-Variable zugewiesen.");
          return;
        }
        if (tile && tile.kind === "value" && tileInteractionMode(tile) === "setpoint" && tile.variableId && tile.variableId !== variable.id && (variable.type === 1 || variable.type === 2)) {
          tile.setpointVariableId = variable.id;
          markDirty();
          renderProperties();
          setStatus("Sollwert-Variable zugewiesen.");
          return;
        }
        if (tile && tile.kind === "scene") {
          assignVariable(tile, variable);
          return;
        }
        if (tile && (tile.kind === "value" || tile.kind === "display" || tile.kind === "chart" || tile.kind === "weather" || tile.kind === "presence")) {
          assignVariable(tile, variable);
          return;
        }
        if (tile && tile.kind === "energyflow") {
          const slot = $("p-flow-slot") ? $("p-flow-slot").value : "load";
          assignFlowVariable(tile, slot, variable);
          return;
        }
        if (tile && tile.kind === "camera" && (isImageUrlRef(variable) || !isMediaPick(variable))) {
          assignVariable(tile, variable);
          return;
        }
        state.pickedVariable = state.pickedVariable && state.pickedVariable.id === variable.id ? null : variable;
        let hint;
        if (isMediaPick(variable)) {
          hint = `„${variable.name}“ gewählt – Bild-/Kamera-Kachel (Medien) oder freie Zelle (legt Bild-Kachel an).`;
        } else if (isImageUrlRef(variable)) {
          hint = `„${variable.name}“ gewählt – Kamera-Kachel oder freie Zelle (legt Kamera an).`;
        } else {
          hint = `„${variable.name}“ gewählt – freie Zelle anklicken, um eine Wertkachel anzulegen.`;
        }
        setHint(state.pickedVariable ? hint : "");
        renderVariableTree();
      });
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
  if (scrollHost) scrollHost.scrollTop = state.variableTreeScroll;
}

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
      if (tile.kind === "scene" && (!tile.actionId || !tile.variableId)) {
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
    state.layout.columns = Number($("columns").value) || 2;
    state.layout.rows = Number($("rows").value) || 4;
    state.layout.showTabs = $("show-tabs").checked;
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
    if (saved) selectLayout(saved);
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
  if (tile) openDetails(tile);
});
$("edit-delete").addEventListener("click", deleteSelected);
$("edit-layout").addEventListener("click", openLayoutDialog);
$("page-background").addEventListener("change", () => {
  const page = currentPage();
  if (!page) return;
  if ($("page-background").value) page.backgroundId = $("page-background").value;
  else delete page.backgroundId;
  markDirty();
});
$("layout-select").addEventListener("change", () => {
  const layout = state.catalog.layouts.find((item) => item.id === $("layout-select").value);
  if (!layout) return;
  if (state.dirty && !confirm("Änderungen verwerfen?")) {
    renderLayoutSelect();
    return;
  }
  selectLayout(layout);
});
for (const id of ["columns", "rows", "show-tabs"]) {
  const field = $(id);
  if (!field) continue;
  field.addEventListener("change", () => {
    if (!state.layout) return;
    const columns = Math.min(4, Math.max(1, Number($("columns").value) || 2));
    const rows = Math.min(6, Math.max(1, Number($("rows").value) || 4));
    for (const page of state.layout.pages) {
      page.tiles = page.tiles.filter((tile) => tile.col + (tile.colSpan || 1) <= columns && tile.row + (tile.rowSpan || 1) <= rows);
    }
    state.layout.columns = columns;
    state.layout.rows = rows;
    state.layout.showTabs = $("show-tabs").checked;
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
  if (event.target.matches("input, textarea, select") || document.querySelector("dialog[open]")) return;
  if (event.key === "Delete" && state.selected) deleteSelected();
  if (event.key === "Enter" && state.selected) {
    const tile = selectedTile();
    if (tile) openDetails(tile);
  }
  if (event.ctrlKey && event.key.toLowerCase() === "s") {
    event.preventDefault();
    store(false);
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

function probeImageStreamUrl(panelId, tileId, bust) {
  const q = new URLSearchParams({
    panel: String(panelId),
    tile: String(tileId),
    v: String(bust ?? Date.now())
  });
  return `probe-image?${q.toString()}`;
}

function revokeProbeObjectUrls() {
  for (const entry of Object.values(probe.probeImageUrls || {})) {
    if (entry && entry.revoke) URL.revokeObjectURL(entry.url);
  }
}

function paintCameraPreviewError(element, tile, err, detail, fromMedia, bound, title, showKicker) {
  const preview = probeImageErrorText(err || "unknown", detail);
  const kicker = showKicker ? editorKicker("edit", fromMedia ? "Kamera · Medien" : "Kamera · Livebild") : "";
  element.innerHTML = `${kicker}<div class="name"></div><div class="camera-preview">${escapeHtml(preview)}</div>`;
  element.querySelector(".name").textContent = title || bound || "Kamera";
}

function paintEditorLiveTile(element, tile) {
  if (!element) return;
  const url = state.tilePreviewUrls[tile.tileId];
  const err = state.tilePreviewErrors[tile.tileId];
  const detail = state.tilePreviewErrorDetails[tile.tileId];
  if (tile.kind === "image" && (tile.imageSource || "catalog") === "media") {
    if (url) {
      element.innerHTML = `<img src="${url}" alt="">`;
      return;
    }
    if (err) {
      element.innerHTML = `<div class="media-placeholder">${escapeHtml(probeImageErrorText(err, detail))}</div>`;
    }
    return;
  }
  if (tile.kind !== "camera") return;
  const bound = mediaBindingName(tile);
  const fromMedia = (tile.cameraSource || "url") === "media" || Boolean(tile.mediaObjectId) || isMediaPick(variableById(tile.variableId));
  if (url) {
    element.innerHTML = `${editorKicker("edit", "Kamera")}<img src="${url}" alt="" style="max-width:100%;max-height:100%;object-fit:contain;flex:1" class="live-preview">`;
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

let probe = { page: "", snapshot: null, panels: [], probeImageUrls: {}, probeImageErrors: {}, probeImageErrorDetails: {}, timer: null, lastLive: "" };

function scaleProbeViewport() {
  const profile = probe.snapshot && probe.snapshot.layout ? layoutProfile(probe.snapshot.layout) : DISPLAY_PROFILES[defaultProfileId()];
  scaleDisplayScreen($("probe-viewport"), $("probe-stage"), $("probe-screen"), profile);
}

function applyLiveStateToProbeTile(element, tile, live) {
  if (!element || !tile || !live) return;
  applyTileStatus(element, live, tile);
  const ohState = element.querySelector(".oh-card-state");
  if (tile.kind === "presence") {
    const active = live.presenceState === "active";
    const stateLine = active ? (tile.presenceMode === "alarm" ? "ALARM" : "Da") : "Abwesend";
    if (ohState) ohState.innerHTML = escapeHtml(stateLine);
    const badge = element.querySelector(".presence-badge");
    if (badge) {
      badge.classList.toggle("on", active);
      badge.classList.toggle("off", !active);
    }
  } else if (tile.kind === "weather") {
    if (ohState && live.text != null && live.text !== "") ohState.innerHTML = escapeHtml(live.text);
    const ohFoot = element.querySelector(".oh-card-foot");
    if (ohFoot && live.forecast != null) ohFoot.textContent = live.forecast;
  } else if (tile.kind === "scene") {
    if (ohState) ohState.textContent = tile.actionId ? "Tippen" : "—";
  } else if (tile.kind === "value") {
    if (ohState && live.text != null && live.text !== "") {
      ohState.innerHTML = escapeHtml(live.text);
    }
    const iconBtn = element.querySelector("[data-icon-toggle]");
    if (iconBtn && tileInteractionMode(tile) === "iconSwitch") {
      const iconId = effectiveIcon(tile, live.value, undefined);
      iconBtn.textContent = iconName(iconId);
      let on = false;
      if (typeof live.value === "boolean") on = live.value;
      else if (typeof live.value === "number") on = live.value > (tile.valueMin ?? 0);
      else if (live.text && /^(1|true|ein|an|on)$/i.test(String(live.text).trim())) on = true;
      iconBtn.setAttribute("aria-pressed", on ? "true" : "false");
    }
    const sw = element.querySelector(".sw");
    if (sw && (tile.control || "switch") === "switch" && tileInteractionMode(tile) !== "iconSwitch") {
      let on = false;
      if (typeof live.value === "boolean") on = live.value;
      else if (live.text && /^(1|true|ein|an|on)$/i.test(String(live.text).trim())) on = true;
      sw.classList.toggle("on", on);
    }
    if (live.value != null && live.value !== "" && element.querySelector(".dim-ctl")) {
      const pct = dimPercentFromTile(tile, live);
      applyDimVisual(element, pct, tile);
    }
  } else if (live.text != null && live.text !== "") {
    if (ohState) ohState.innerHTML = escapeHtml(live.text);
    const valueEl = element.querySelector(".value");
    if (valueEl) valueEl.textContent = live.text;
    const fitEl = element.querySelector(".tile-fit-text");
    if (fitEl) fitEl.textContent = live.text;
  }
  if (tile.kind === "energyflow" && live.flows) {
    const kicker = element.querySelector(".kicker");
    const kickerHtml = kicker ? kicker.outerHTML : "";
    element.innerHTML = kickerHtml + buildEnergyFlowHtml(tile, live, "probe");
    element.dataset.tileId = tile.tileId;
  }
  if (tile.kind === "chart") paintChartTileContent(element, tile, live);
}

function updateProbeValuesOnly() {
  if (!probe.snapshot || !probe.snapshot.state) return;
  const values = {};
  for (const tile of probe.snapshot.state.tiles || []) values[tile.tileId] = tile;
  const layout = probe.snapshot.layout;
  const page = layout && layout.pages ? layout.pages.find((p) => p.id === probe.page) : null;
  const tilesById = {};
  for (const t of (page && page.tiles) || []) tilesById[t.tileId] = t;
  for (const element of $("probe-grid").querySelectorAll(".tile")) {
    const tileId = element.dataset.tileId;
    if (!tileId || !values[tileId]) continue;
    applyLiveStateToProbeTile(element, tilesById[tileId], values[tileId]);
  }
  probe.lastLive = new Date().toLocaleTimeString("de-DE");
  scheduleFitTileText($("probe-grid"));
}

function probeImageBlobFromResult(result) {
  if (!result || !result.ok || !result.data) return null;
  const mime = result.mime || "image/jpeg";
  const binary = atob(result.data);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
  return new Blob([bytes], { type: mime });
}

async function loadProbeImages(layout, panelId, stateTiles) {
  revokeProbeObjectUrls();
  probe.probeImageUrls = {};
  probe.probeImageErrors = {};
  probe.probeImageErrorDetails = {};
  if (!panelId || !layout) return;
  for (const page of layout.pages || []) {
    for (const tile of page.tiles || []) {
      if (!tileNeedsProbeImage(tile)) continue;
      try {
        normalizeMediaTileBindings(tile);
        const result = await call("probeImage", { id: panelId, tileId: tile.tileId, tile });
        const blob = probeImageBlobFromResult(result);
        if (blob && blob.size) {
          probe.probeImageUrls[tile.tileId] = { url: URL.createObjectURL(blob), revoke: true };
          continue;
        }
        probe.probeImageErrors[tile.tileId] = (result && result.error) || "camera_fetch";
        probe.probeImageErrorDetails[tile.tileId] = (result && result.detail) || "Kein Bild von Symcon";
      } catch (error) {
        probe.probeImageErrors[tile.tileId] = "fetch";
        probe.probeImageErrorDetails[tile.tileId] = error && error.message ? error.message : "";
      }
    }
  }
}

function renderProbe() {
  const grid = $("probe-grid");
  const pages = $("probe-pages");
  const tabs = $("probe-tabs");
  grid.innerHTML = "";
  pages.innerHTML = "";
  tabs.innerHTML = "";
  const layout = probe.snapshot && probe.snapshot.layout;
  if (!layout || !(layout.pages || []).length) {
    grid.innerHTML = '<div class="hint" style="color:#9fb2c8;padding:12px">Diese Anzeige hat keine Ansicht.</div>';
    return;
  }
  if (!probe.page || !(layout.pages || []).some((page) => page.id === probe.page)) probe.page = layout.pages[0].id;
  const values = {};
  for (const tile of (probe.snapshot.state && probe.snapshot.state.tiles) || []) values[tile.tileId] = tile;
  const profile = layoutProfile({ displayProfile: layout.displayProfile });
  const probeInfo = probe.snapshot && probe.snapshot.probe;
  let metaText;
  if (probeInfo && probeInfo.draft) {
    metaText = `Editor-Vorschau · ${profile.label} · ${profile.width}×${profile.height}`;
    if (state.dirty) metaText += " · ungespeicherte Änderungen";
  } else {
    metaText = `Anzeige-Vorschau · ${profile.label} · ${profile.width}×${profile.height}`;
    if (probeInfo && probeInfo.previewActive) {
      metaText += " · Geräte-Vorschau (zeitlich begrenzt) aktiv";
    } else {
      metaText += " · veröffentlichte Ansicht der Instanz";
    }
    if (state.dirty && state.layout && probeInfo && state.layout.id === probeInfo.layoutId) {
      metaText += " · Editor-Entwurf ist noch nicht veröffentlicht";
    }
  }
  const panelId = Number($("probe-panel").value);
  const panel = (probe.panels || []).find((item) => item.id === panelId);
  if (panel && panel.displayProfile && layout.displayProfile && panel.displayProfile !== layout.displayProfile) {
    metaText += ` · Anzeige-Hardware: ${profileLabel(panel.displayProfile)}`;
  }
  $("probe-meta").textContent = metaText;
  if (layout.showTabs) {
    tabs.classList.remove("hidden");
    for (const page of layout.pages) {
      const button = document.createElement("button");
      button.type = "button";
      button.textContent = page.title || page.id;
      if (page.id === probe.page) button.classList.add("active");
      button.addEventListener("click", () => {
        probe.page = page.id;
        renderProbe();
      });
      tabs.appendChild(button);
    }
  } else {
    tabs.classList.add("hidden");
    for (const page of layout.pages) {
      const button = document.createElement("button");
      button.type = "button";
      button.textContent = page.title || page.id;
      if (page.id === probe.page) button.classList.add("active");
      button.addEventListener("click", () => {
        probe.page = page.id;
        renderProbe();
      });
      pages.appendChild(button);
    }
  }
  const current = layout.pages.find((page) => page.id === probe.page);
  applyPageBackground(grid, current || null);
  grid.style.gridTemplateColumns = `repeat(${layout.columns || 2}, 1fr)`;
  grid.style.gridTemplateRows = `repeat(${layout.rows || 4}, 1fr)`;
  for (const tile of (current && current.tiles) || []) {
    const element = buildTileElement(tile, {
      mode: "probe",
      stateByTileId: values,
      probeImageUrls: probe.probeImageUrls,
      probeImageErrors: probe.probeImageErrors,
      probeImageErrorDetails: probe.probeImageErrorDetails
    });
    element.dataset.tileId = tile.tileId;
    grid.appendChild(element);
  }
  observeTileFitRoot(grid);
  scheduleFitTileText(grid);
  scaleProbeViewport();
  probe.lastLive = new Date().toLocaleTimeString("de-DE");
}

async function loadProbeDraft(id) {
  const layout = editorLayoutSnapshot();
  if (!layout) {
    probe.snapshot = null;
    renderProbe();
    return;
  }
  let config = {};
  let stateTiles = [];
  if (id) {
    const preview = await call("previewLayout", { id, layout });
    if (preview.ok === false) throw new Error(preview.error || "Live-Vorschau fehlgeschlagen");
    stateTiles = preview.tiles || [];
    try {
      const inspect = await call("inspect", { id });
      config = inspect.config || {};
    } catch (error) {
      /* optional */
    }
  }
  probe.snapshot = {
    layout,
    state: { tiles: stateTiles },
    config,
    probe: { draft: true, layoutId: layout.id || "", previewActive: false }
  };
  await loadProbeImages(layout, id, stateTiles);
  renderProbe();
}

async function loadProbe(fullRender) {
  const id = Number($("probe-panel").value);
  if (probeSourceMode() === "draft") {
    await loadProbeDraft(id);
    return;
  }
  if (!id) {
    probe.snapshot = null;
    renderProbe();
    return;
  }
  const previous = probe.snapshot;
  probe.snapshot = await call("inspect", { id });
  const state = probe.snapshot.state || {};
  if (state.unchanged && previous && previous.state && (previous.state.tiles || []).length) {
    state.tiles = previous.state.tiles;
    probe.snapshot.state = state;
  }
  const grid = $("probe-grid");
  const hasTiles = grid.querySelectorAll(".tile").length > 0;
  const layoutSame =
    previous &&
    previous.layout &&
    probe.snapshot.layout &&
    previous.layout.id === probe.snapshot.layout.id &&
    (previous.layout.version || 0) === (probe.snapshot.layout.version || 0);
  if (fullRender === false && (state.unchanged || (hasTiles && layoutSame))) {
    updateProbeValuesOnly();
    return;
  }
  if (probe.snapshot.layout) {
    await loadProbeImages(probe.snapshot.layout, id, (probe.snapshot.state && probe.snapshot.state.tiles) || []);
  }
  renderProbe();
}

function stopProbeTimer() {
  if (probe.timer) {
    clearInterval(probe.timer);
    probe.timer = null;
  }
}

function startProbeTimer() {
  stopProbeTimer();
  if (!$("probe-live").checked) return;
  const sec = Math.max(2, Number(probe.snapshot && probe.snapshot.config && probe.snapshot.config.stateIntervalSec) || 3);
  probe.timer = setInterval(() => {
    if (!$("probe-dialog").open) {
      stopProbeTimer();
      return;
    }
    loadProbe(false).catch(() => {});
  }, sec * 1000);
}

function probeTileFromLayout(tileId) {
  const layout = probe.snapshot && probe.snapshot.layout;
  if (!layout) return null;
  for (const page of layout.pages || []) {
    for (const t of page.tiles || []) {
      if (t.tileId === tileId) return t;
    }
  }
  return null;
}

async function sendProbeAction(tile, options = {}) {
  const layoutTile = probeTileFromLayout(tile.tileId) || tile;
  if (!layoutTile.actionId) {
    setStatus("Kachel ist nicht schaltbar: In Details „Schaltbar“ aktivieren, Variable mit Aktion wählen, Entwurf speichern bzw. veröffentlichen.");
    return;
  }
  let value = "";
  if (options.value != null && options.value !== "") {
    value = String(options.value);
  } else if (options.switchToggle) {
    value = "";
  } else if (layoutTile.actionKind === "dim" || layoutTile.actionKind === "position") {
    const entered = prompt("Wert", "50");
    if (entered === null) return;
    value = entered.trim();
  }
  try {
    const result = await call("simulateAction", {
      id: Number($("probe-panel").value),
      tileId: layoutTile.tileId,
      actionId: layoutTile.actionId,
      value,
      tile: layoutTile
    });
    if (result && result.ok === false) throw new Error(result.error || "Aktion abgelehnt");
    setStatus("Aktion ausgeführt.");
    await loadProbe(false);
  } catch (error) {
    setStatus(error.message);
  }
}

async function openProbe() {
  const select = $("probe-panel");
  try {
    const settings = await call("settings");
    probe.panels = settings.panels || [];
    select.innerHTML = "";
    for (const panel of probe.panels) {
      const option = document.createElement("option");
      option.value = String(panel.id);
      const hw = profileLabel(panel.displayProfile);
      option.textContent = `${panel.name} (${panel.id}) · ${hw}`;
      select.appendChild(option);
    }
    if (probe.panels[0]) state.previewPanelId = probe.panels[0].id;
    if (!select.options.length) {
      setStatus("Noch keine Anzeige-Instanz angelegt.");
      return;
    }
    await loadProbe(true);
  } catch (error) {
    setStatus(error.message);
    return;
  }
  $("probe-dialog").showModal();
  requestAnimationFrame(() => {
    scaleProbeViewport();
    scheduleFitTileText($("probe-grid"));
  });
  startProbeTimer();
}

$("probe-dialog").addEventListener("close", () => {
  stopProbeTimer();
  revokeProbeObjectUrls();
});
$("probe-live").addEventListener("change", () => {
  if ($("probe-dialog").open) startProbeTimer();
  else stopProbeTimer();
});
window.addEventListener("resize", () => {
  scaleEditorViewport();
  scheduleFitTileText($("grid"));
  if ($("probe-dialog").open) {
    scaleProbeViewport();
    scheduleFitTileText($("probe-grid"));
  }
});

for (const input of document.querySelectorAll('input[name="probe-source"]')) {
  input.addEventListener("change", () => {
    if ($("probe-dialog").open) loadProbe(true).catch((error) => setStatus(error.message));
  });
}

$("probe-panel").addEventListener("change", () => {
  probe.page = "";
  loadProbe(true).catch((error) => setStatus(error.message));
});
$("probe-refresh").addEventListener("click", () => loadProbe(true).catch((error) => setStatus(error.message)));
$("open-probe").addEventListener("click", openProbe);

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
