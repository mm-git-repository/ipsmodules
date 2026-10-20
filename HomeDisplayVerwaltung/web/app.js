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
const KIND_NAMES = { value: "Wert", nav: "Seitenwechsler", label: "Text", image: "Bild", camera: "Kamera", separator: "Trennlinie", system: "Anzeigestatus" };
const CONTROL_NAMES = { switch: "Schalter", dimSwitch: "Dimmschalter", slider: "Dimmbalken", combo: "Kombischalter", battery: "Batterie" };
const DIM_CONTROLS = ["dimSwitch", "slider", "combo"];
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
  $("p-colspan").max = state.layout ? state.layout.columns : maxCol;
  $("p-rowspan").max = state.layout ? state.layout.rows : maxRow;
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

function renderPageBackground() {
  const select = $("page-background");
  const page = currentPage();
  fillImageSelect(select);
  select.value = page && page.backgroundId ? page.backgroundId : "";
  select.disabled = !page;
  const image = page ? state.images.find((item) => item.id === page.backgroundId) : null;
  const grid = $("grid");
  if (image) {
    grid.style.backgroundImage = `url("images/${image.id}.${image.ext}")`;
    grid.style.backgroundSize = "cover";
    grid.style.backgroundPosition = "center";
  } else {
    grid.style.backgroundImage = "";
  }
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
  renderPageBackground();
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
  element.className = `tile ${tile.kind}${tile.kind === "label" && tile.size === "large" ? " large" : ""}${mode === "edit" && state.selected === tile.tileId ? " selected" : ""}`;
  element.style.gridColumn = `${tile.col + 1} / span ${spanW}`;
  element.style.gridRow = `${tile.row + 1} / span ${spanH}`;
  element.dataset.spanW = String(spanW);
  element.dataset.spanH = String(spanH);
  element.dataset.tileId = tile.tileId;
  Object.entries(tilePreviewStyle(tile)).forEach(([key, val]) => element.style.setProperty(key, val));
  if (live && live.color) element.style.background = colorForTile(live.color);
  element.draggable = mode === "edit";
  element.title = mode === "edit" ? `${KIND_NAMES[tile.kind]} – Doppelklick für Details` : "";
  if (tile.kind === "value") {
    const variable = variableById(tile.variableId);
    const control = tile.control || "switch";
    if (DIM_CONTROLS.includes(control)) element.classList.add("dim");
    if (control === "battery") {
      element.classList.add("battery");
      const labelPos = tile.batteryLabel || "inside";
      if (labelPos !== "inside") element.classList.add(`label-${labelPos}`, "label-outside");
    }
    const iconId = effectiveIcon(tile, live ? live.value : undefined, demoOn);
    const kicker = CONTROL_NAMES[control] || `${iconName(iconId)}${tile.actionId ? " · schaltbar" : ""}`;
    const body = controlMarkup(control, tile);
    const displayText = live && live.text ? live.text : (variable ? "12,3" : "—");
    element.innerHTML = `<div class="kicker">${kicker}</div><div class="name"></div>${body || `<div class="value">${escapeHtml(displayText)}${tile.unit ? " " + escapeHtml(tile.unit) : ""}</div>`}`;
    element.querySelector(".name").textContent = tile.title || (variable ? variable.name : "Wert");
    if (mode === "edit") bindControlPreview(element, tile);
  } else if (tile.kind === "nav") {
    element.innerHTML = `<div class="kicker">${tile.icon ? iconName(tile.icon) + " · " : ""}Seite</div><div class="name"></div>`;
    element.querySelector(".name").textContent = tile.title || pageTitleIn(tile.targetPage);
  } else if (tile.kind === "label") {
    element.innerHTML = `<div class="text"></div>`;
    element.querySelector(".text").textContent = tile.text || "Text";
  } else if (tile.kind === "separator") {
    element.innerHTML = `<div class="line"></div>`;
  } else if (tile.kind === "system") {
    element.innerHTML = `<div class="kicker">Anzeigestatus</div><div class="name"></div><div class="text">Programm · WLAN · Laufzeit · Datenalter</div>`;
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
      element.innerHTML = `<div class="kicker">Kamera</div><img src="${src}" alt="" style="max-width:100%;max-height:100%;object-fit:contain;flex:1" class="live-preview">`;
      const img = element.querySelector("img.live-preview");
      if (img && mode === "probe") {
        img.addEventListener("error", () => {
          const err = (context.probeImageErrors && context.probeImageErrors[tile.tileId]) || "camera_fetch";
          const detail = context.probeImageErrorDetails && context.probeImageErrorDetails[tile.tileId];
          paintCameraPreviewError(element, tile, err, detail, fromMedia, bound, tile.title);
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
      element.innerHTML = `<div class="kicker">${fromMedia ? "Kamera · Medien" : "Kamera · Livebild"}</div><div class="name"></div><div class="camera-preview">${escapeHtml(preview)}</div>`;
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
  if (mode === "probe" && tile.actionId) {
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

function controlMarkup(control, tile) {
  if (control === "dimSwitch") return `<div class="ctl"><span class="sw"></span><span class="ctl-label">Aus</span></div>`;
  if (control === "slider") return `<div class="ctl"><div class="track"><div class="fill"></div><div class="knob"></div></div></div>`;
  if (control === "combo") return `<div class="ctl"><span class="sw"></span><div class="track"><div class="fill"></div><div class="knob"></div></div></div>`;
  if (control === "battery") {
    const pad = tile && tile.batteryPad != null ? tile.batteryPad : 8;
    const label = tile && tile.batteryLabel ? tile.batteryLabel : "inside";
    const pctInside = label === "inside" ? `<span class="battery-pct">72 %</span>` : "";
    const pctOutside = label !== "inside" ? `<span class="battery-pct">72 %</span>` : "";
    return `<div class="battery" style="padding:${pad}px">${pctOutside}<div class="battery-body"><div class="battery-fill" style="width:72%"></div></div><div class="battery-cap"></div>${pctInside}</div>`;
  }
  return "";
}

/* Vorschau im Editor: der Griff lässt sich ziehen, die Leiste selbst nicht. Der Wert wird nicht gespeichert. */
function bindControlPreview(element, tile) {
  const knob = element.querySelector(".knob");
  const track = element.querySelector(".track");
  const fill = element.querySelector(".fill");
  const sw = element.querySelector(".sw");
  const label = element.querySelector(".ctl-label");
  const stop = (event) => {
    event.stopPropagation();
    event.preventDefault();
  };
  const box = element.querySelector(".ctl");
  if (box) {
    box.addEventListener("click", (event) => event.stopPropagation());
    box.addEventListener("dblclick", (event) => event.stopPropagation());
  }
  if (sw) {
    sw.addEventListener("pointerdown", stop);
    sw.addEventListener("click", (event) => {
      event.stopPropagation();
      sw.classList.toggle("on");
      if (label) label.textContent = sw.classList.contains("on") ? "Ein" : "Aus";
      if (tile && (tile.iconMode || "static") === "toggle") {
        state.editorIconDemo[tile.tileId] = sw.classList.contains("on");
        renderGrid();
      }
    });
  }
  if (!knob || !track) return;
  knob.addEventListener("pointerdown", (event) => {
    stop(event);
    knob.setPointerCapture(event.pointerId);
    const move = (moveEvent) => {
      const rect = track.getBoundingClientRect();
      const travel = Math.max(1, rect.width - knob.offsetWidth);
      const x = Math.min(travel, Math.max(0, moveEvent.clientX - rect.left - knob.offsetWidth / 2));
      knob.style.left = `${x}px`;
      if (fill) fill.style.width = `${x + knob.offsetWidth / 2}px`;
      if (label) label.textContent = `${Math.round(x / travel * 100)} %`;
      if (sw) sw.classList.toggle("on", x > 2);
    };
    const end = () => {
      knob.removeEventListener("pointermove", move);
      knob.removeEventListener("pointerup", end);
    };
    knob.addEventListener("pointermove", move);
    knob.addEventListener("pointerup", end);
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
      if (existing && existing.kind === "value") {
        assignVariable(existing, variable);
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
    tile.size = "small";
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
  $("prop-empty").classList.toggle("hidden", Boolean(tile));
  $("prop-form").classList.toggle("hidden", !tile);
  if (!tile) return;
  for (const block of document.querySelectorAll("#prop-form [data-for]")) {
    block.classList.toggle("hidden", !block.dataset.for.split(" ").includes(tile.kind));
  }
  $("p-kind").value = KIND_NAMES[tile.kind];
  $("p-title").value = tile.title || "";
  $("p-col").value = tile.col + 1;
  $("p-row").value = tile.row + 1;
  $("p-colspan").value = tile.colSpan || 1;
  $("p-rowspan").value = tile.rowSpan || 1;
  $("p-colspan").max = state.layout.columns;
  $("p-rowspan").max = state.layout.rows;
  const iconMode = tile.iconMode || "static";
  if ($("p-iconmode")) {
    $("p-iconmode").value = iconMode;
    $("p-icon-static").classList.toggle("hidden", iconMode === "toggle");
    $("p-icon-toggle").classList.toggle("hidden", iconMode !== "toggle");
  }
  fillIconSelect($("p-icon"), tile.kind === "nav");
  fillIconSelect($("p-iconon"));
  fillIconSelect($("p-iconoff"));
  $("p-icon").value = tile.icon || "";
  if ($("p-iconon")) $("p-iconon").value = tile.iconOn || "lightOn";
  if ($("p-iconoff")) $("p-iconoff").value = tile.iconOff || "lightOff";
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
  $("p-control").value = tile.control || "switch";
  const hints = {
    dimSwitch: "Schaltet den Dimmwert aus und stellt beim Einschalten den letzten Wert wieder her.",
    slider: "Der Wert ändert sich nur, wenn der Griff gezogen wird. Tippen auf die Leiste bewirkt nichts.",
    combo: "Schalter für Ein und Aus, Balken für den Dimmwert. Der Balken reagiert nur auf Ziehen am Griff."
  };
  $("p-control-hint").textContent = hints[tile.control] || "";
  $("p-control-hint").classList.toggle("hidden", !hints[tile.control]);
  $("p-battery-wrap").classList.toggle("hidden", tile.control !== "battery");
  if ($("p-batterypad")) $("p-batterypad").value = tile.batteryPad != null ? tile.batteryPad : 8;
  if ($("p-batterylabel")) $("p-batterylabel").value = tile.batteryLabel || "inside";
  $("p-action").checked = Boolean(tile.actionId);
  $("p-action").disabled = !(variable && variable.action);
  $("p-confirm").checked = Boolean(tile.confirm);
  $("p-actionkind").value = tile.actionKind || (DIM_CONTROLS.includes(tile.control) ? "dim" : (variable ? variable.actionKind || "switch" : "switch"));
  fillTargetSelect($("p-target"));
  $("p-target").value = tile.targetPage || "";
  $("p-text").value = tile.text || "";
  $("p-size").value = tile.size || "small";
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
  if (tile.kind === "value" || tile.kind === "nav") {
    if (tile.kind === "value" && $("p-iconmode") && $("p-iconmode").value === "toggle") {
      tile.iconMode = "toggle";
      tile.iconOn = $("p-iconon").value;
      tile.iconOff = $("p-iconoff").value;
    } else {
      delete tile.iconMode;
      delete tile.iconOn;
      delete tile.iconOff;
      tile.icon = $("p-icon").value;
    }
  }
  if (tile.kind === "nav") tile.icon = $("p-icon").value;
  if (tile.kind === "value") {
    tile.unit = $("p-unit").value;
    tile.decimals = Number($("p-decimals").value) || 0;
    const variable = variableById(tile.variableId);
    const control = $("p-control").value || "switch";
    tile.control = control === "switch" ? undefined : control;
    if (control === "switch") delete tile.control;
    if (control === "battery") {
      tile.valueMin = tile.valueMin ?? 0;
      tile.valueMax = tile.valueMax ?? 100;
      if (!tile.unit) tile.unit = "%";
      tile.batteryPad = Number($("p-batterypad").value) || 8;
      tile.batteryLabel = $("p-batterylabel").value || "inside";
      delete tile.actionId;
      delete tile.actionKind;
      tile.confirm = false;
    } else if (DIM_CONTROLS.includes(control)) {
      tile.actionKind = "dim";
      tile.valueMin = tile.valueMin ?? 0;
      tile.valueMax = tile.valueMax ?? 100;
      if (!tile.unit) tile.unit = "%";
    }
    if ($("p-action").checked && variable && variable.action) {
      tile.actionId = tile.actionId || `a-${tile.tileId}`;
      tile.actionKind = DIM_CONTROLS.includes(control) ? "dim" : $("p-actionkind").value;
      tile.confirm = $("p-confirm").checked;
    } else {
      delete tile.actionId;
      if (!DIM_CONTROLS.includes(control)) delete tile.actionKind;
      tile.confirm = false;
    }
  }
  if (tile.kind === "nav") tile.targetPage = $("p-target").value;
  if (tile.kind === "label") {
    tile.text = $("p-text").value;
    tile.size = $("p-size").value;
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

$("p-control").addEventListener("change", () => {
  if (DIM_CONTROLS.includes($("p-control").value)) {
    $("p-actionkind").value = "dim";
    const tile = selectedTile();
    const variable = tile ? variableById(tile.variableId) : null;
    if (variable && variable.action) $("p-action").checked = true;
  }
  applyProperties();
});
for (const id of ["p-title", "p-col", "p-row", "p-colspan", "p-rowspan", "p-icon", "p-iconmode", "p-iconon", "p-iconoff", "p-unit", "p-decimals", "p-action", "p-confirm", "p-actionkind", "p-target", "p-text", "p-size", "p-image", "p-imagesource", "p-camerasource", "p-batterypad", "p-batterylabel"]) {
  if ($(id)) $(id).addEventListener("change", applyProperties);
}
if ($("p-iconmode")) {
  $("p-iconmode").addEventListener("change", () => {
    $("p-icon-static").classList.toggle("hidden", $("p-iconmode").value === "toggle");
    $("p-icon-toggle").classList.toggle("hidden", $("p-iconmode").value !== "toggle");
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
$("display-profile").addEventListener("change", () => {
  if (!state.layout) return;
  state.layout.displayProfile = $("display-profile").value;
  applyGridAspect();
  markDirty();
});
$("p-details").addEventListener("click", () => {
  const tile = selectedTile();
  if (tile) openDetails(tile);
});
$("p-delete").addEventListener("click", deleteSelected);

/* ---------- Detaildialog ---------- */

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
  if (tile.kind === "value" || tile.kind === "nav") {
    fields.icon = add("Symbol", document.createElement("select"));
    fillIconSelect(fields.icon, tile.kind === "nav");
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
    const control = document.createElement("select");
    control.innerHTML = '<option value="switch">Schalter</option><option value="dimSwitch">Dimmschalter</option><option value="slider">Dimmbalken</option><option value="combo">Kombischalter</option><option value="battery">Batterie (Ladestand)</option>';
    control.value = tile.control || "switch";
    fields.control = add("Bedienung", control);
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
    picker.addEventListener("change", () => {
      const chosen = variableById(Number(picker.value));
      fields.action.disabled = !(chosen && chosen.action);
      if (!fields.action.disabled) fields.actionKind.value = chosen.actionKind || "switch";
      if (!fields.title.value && chosen) fields.title.value = chosen.name;
    });
  }
  if (tile.kind === "nav") {
    fields.target = add("Zielseite", document.createElement("select"));
    fillTargetSelect(fields.target);
    fields.target.value = tile.targetPage;
  }
  if (tile.kind === "label") {
    const area = document.createElement("textarea");
    area.rows = 4;
    area.value = tile.text || "";
    fields.text = add("Text", area);
    const size = document.createElement("select");
    size.innerHTML = '<option value="small">Klein</option><option value="large">Groß</option>';
    size.value = tile.size || "small";
    fields.size = add("Größe", size);
  }
  if (tile.kind === "image") {
    fields.image = add("Bild", document.createElement("select"));
    fillImageSelect(fields.image);
    fields.image.value = tile.imageId || "";
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
    if (fields.icon) tile.icon = fields.icon.value;
    if (tile.kind === "value") {
      const chosen = variableById(Number(fields.variable.value));
      tile.variableId = chosen ? chosen.id : 0;
      tile.unit = fields.unit.value;
      tile.decimals = Number(fields.decimals.value) || 0;
      const control = fields.control.value || "switch";
      if (control === "switch") delete tile.control;
      else tile.control = control;
      if (DIM_CONTROLS.includes(control)) fields.actionKind.value = "dim";
      if (fields.action.checked && chosen && chosen.action) {
        tile.actionId = tile.actionId || `a-${tile.tileId}`;
        tile.actionKind = DIM_CONTROLS.includes(control) ? "dim" : fields.actionKind.value;
        tile.confirm = fields.confirm.checked;
        tile.valueMin = Number(fields.valueMin.value);
        tile.valueMax = Number(fields.valueMax.value);
      } else {
        delete tile.actionId;
        if (!DIM_CONTROLS.includes(control)) delete tile.actionKind;
        if (control === "battery") {
          tile.valueMin = Number(fields.valueMin.value);
          tile.valueMax = Number(fields.valueMax.value);
        } else {
          delete tile.valueMin;
          delete tile.valueMax;
        }
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
    if (tile.kind === "label") {
      tile.text = fields.text.value;
      tile.size = fields.size.value;
    }
    if (tile.kind === "image") tile.imageId = fields.image.value;
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
  const scrollHost = $("variable-scroll");
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
        if (tile && tile.kind === "value") {
          assignVariable(tile, variable);
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
  if (!state.layout) return;
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
    if (!result.ok) throw new Error(result.error || "Speichern fehlgeschlagen");
    const previousId = state.layout.id;
    const previousName = state.layout.name;
    state.catalog = await call("catalog");
    const saved = state.catalog.layouts.find((item) => previousId ? item.id === previousId : item.name === previousName);
    if (saved) selectLayout(saved);
    renderLayoutSelect();
    setStatus(publish ? "Ansicht veröffentlicht. Die Anzeigen übernehmen sie beim nächsten Abruf." : "Entwurf gespeichert.");
  } catch (error) {
    setStatus(error.message);
  }
}

$("save").addEventListener("click", () => store(false));
$("publish").addEventListener("click", () => store(true));
$("tb-save").addEventListener("click", () => store(false));
$("tb-publish").addEventListener("click", () => store(true));
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
  renderGrid();
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
  $(id).addEventListener("change", () => {
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

function paintCameraPreviewError(element, tile, err, detail, fromMedia, bound, title) {
  const preview = probeImageErrorText(err || "unknown", detail);
  element.innerHTML = `<div class="kicker">${fromMedia ? "Kamera · Medien" : "Kamera · Livebild"}</div><div class="name"></div><div class="camera-preview">${escapeHtml(preview)}</div>`;
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
    element.innerHTML = `<div class="kicker">Kamera</div><img src="${url}" alt="" style="max-width:100%;max-height:100%;object-fit:contain;flex:1" class="live-preview">`;
    const img = element.querySelector("img.live-preview");
    if (img) {
      img.addEventListener("error", () => {
        paintCameraPreviewError(element, tile, "camera_fetch", "Bild im Browser nicht darstellbar", fromMedia, bound, tile.title);
      });
    }
    return;
  }
  if (err) {
    paintCameraPreviewError(element, tile, err, detail, fromMedia, bound, tile.title);
  }
}

let probe = { page: "", snapshot: null, probeImageUrls: {}, probeImageErrors: {}, probeImageErrorDetails: {}, timer: null, lastLive: "" };

function scaleProbeViewport() {
  const viewport = $("probe-viewport");
  const screen = $("probe-screen");
  if (!viewport || !screen) return;
  const profile = probe.snapshot && probe.snapshot.layout ? layoutProfile(probe.snapshot.layout) : DISPLAY_PROFILES[defaultProfileId()];
  screen.style.width = `${profile.width}px`;
  screen.style.height = `${profile.height}px`;
  const scale = Math.min(1, (viewport.clientWidth - 8) / profile.width);
  screen.style.transform = scale < 1 ? `scale(${scale})` : "";
  screen.style.marginBottom = scale < 1 ? `${-(profile.height * (1 - scale))}px` : "";
}

function updateProbeValuesOnly() {
  if (!probe.snapshot || !probe.snapshot.state) return;
  const values = {};
  for (const tile of probe.snapshot.state.tiles || []) values[tile.tileId] = tile;
  for (const element of $("probe-grid").querySelectorAll(".tile")) {
    const tileId = element.dataset.tileId;
    if (!tileId || !values[tileId]) continue;
    const live = values[tileId];
    const valueEl = element.querySelector(".value");
    if (valueEl && live.text) valueEl.textContent = live.text;
    if (live.color) element.style.background = colorForTile(live.color);
  }
}

async function loadProbeImages(layout, panelId, stateTiles) {
  revokeProbeObjectUrls();
  probe.probeImageUrls = {};
  probe.probeImageErrors = {};
  probe.probeImageErrorDetails = {};
  const liveById = {};
  for (const st of stateTiles || []) liveById[st.tileId] = st;
  for (const page of layout.pages || []) {
    for (const tile of page.tiles || []) {
      if (!tileNeedsProbeImage(tile)) continue;
      try {
        const live = liveById[tile.tileId];
        const bust = live && (live.cameraSeq != null || live.mediaSeq != null) ? (live.cameraSeq ?? live.mediaSeq) : Date.now();
        const result = await call("probeImage", { id: panelId, tileId: tile.tileId, meta: true });
        if (!result.ok) {
          probe.probeImageErrors[tile.tileId] = result.error || "unknown";
          if (result.detail) probe.probeImageErrorDetails[tile.tileId] = result.detail;
          continue;
        }
        const streamUrl = probeImageStreamUrl(panelId, tile.tileId, bust);
        const response = await fetch(streamUrl, { credentials: "same-origin" });
        if (!response.ok) {
          probe.probeImageErrors[tile.tileId] = response.headers.get("X-HomeDisplay-Error") || "camera_fetch";
          const detail = response.headers.get("X-HomeDisplay-Detail");
          if (detail) probe.probeImageErrorDetails[tile.tileId] = detail;
          continue;
        }
        const blob = await response.blob();
        if (!blob.size) {
          probe.probeImageErrors[tile.tileId] = "camera_fetch";
          probe.probeImageErrorDetails[tile.tileId] = "Leere Antwort von Symcon";
          continue;
        }
        probe.probeImageUrls[tile.tileId] = { url: URL.createObjectURL(blob), revoke: true };
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
  let metaText = `Panel-Vorschau · ${profile.width}×${profile.height}`;
  const probeInfo = probe.snapshot && probe.snapshot.probe;
  if (probeInfo && probeInfo.previewActive) {
    metaText += " · Geräte-Vorschau (zeitlich begrenzt) aktiv";
  } else {
    metaText += " · veröffentlichte Ansicht der Instanz";
  }
  if (state.dirty && state.layout && probeInfo && state.layout.id === probeInfo.layoutId) {
    metaText += " · Editor-Entwurf ist noch nicht veröffentlicht";
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
  scaleProbeViewport();
  probe.lastLive = new Date().toLocaleTimeString("de-DE");
}

async function loadProbe(fullRender) {
  const id = Number($("probe-panel").value);
  if (!id) {
    probe.snapshot = null;
    renderProbe();
    return;
  }
  const previous = probe.snapshot;
  probe.snapshot = await call("inspect", { id });
  if (fullRender === false && previous && probe.snapshot.state && probe.snapshot.state.unchanged) {
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

async function sendProbeAction(tile) {
  let value = "";
  if (tile.actionKind === "dim" || tile.actionKind === "position") {
    const entered = prompt("Wert", "50");
    if (entered === null) return;
    value = entered.trim();
  }
  try {
    const result = await call("simulateAction", {
      id: Number($("probe-panel").value),
      tileId: tile.tileId,
      actionId: tile.actionId,
      value
    });
    if (result.ok === false) throw new Error(result.error || "Aktion abgelehnt");
    setStatus("Aktion ausgeführt.");
    await loadProbe();
  } catch (error) {
    setStatus(error.message);
  }
}

async function openProbe() {
  const select = $("probe-panel");
  try {
    const settings = await call("settings");
    select.innerHTML = "";
    for (const panel of settings.panels || []) {
      const option = document.createElement("option");
      option.value = String(panel.id);
      option.textContent = `${panel.name} (${panel.id})`;
      select.appendChild(option);
    }
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
  if ($("probe-dialog").open) scaleProbeViewport();
});

$("probe-panel").addEventListener("change", () => {
  probe.page = "";
  loadProbe(true).catch((error) => setStatus(error.message));
});
$("probe-refresh").addEventListener("click", () => loadProbe(true).catch((error) => setStatus(error.message)));
$("open-probe").addEventListener("click", openProbe);

/* ---------- Start ---------- */

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
