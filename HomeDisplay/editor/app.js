const PAGES = [
  ["start", "Start"],
  ["energie", "Energie"],
  ["klima", "Klima"],
  ["licht", "Licht und Rollladen"],
  ["pool", "Pool und Whirlpool"],
  ["garten", "Garten"],
  ["system", "System"]
];
const ICONS = ["energy", "temperature", "light", "shutter", "pump", "valve", "alarm"];
const state = { instanceId: 0, catalog: null, variables: [], layout: null, config: null, page: "start", selected: null };

const $ = (id) => document.getElementById(id);

function connection() {
  return { base: $("base").value.trim(), user: $("user").value, password: $("password").value };
}

async function call(method, params) {
  const response = await fetch("/api/call", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ ...connection(), method, params })
  });
  const body = await response.json();
  if (!body.ok) throw new Error(body.error || "Aufruf fehlgeschlagen");
  return typeof body.result === "string" ? JSON.parse(body.result) : body.result;
}

function setStatus(text) {
  $("status").textContent = text;
}

function pageById(id) {
  let page = state.layout.pages.find((item) => item.id === id);
  if (!page) {
    page = { id, title: PAGES.find((item) => item[0] === id)[1], tiles: [] };
    state.layout.pages.push(page);
  }
  return page;
}

function currentPage() {
  return pageById(state.page);
}

function renderLists() {
  const layouts = $("layouts");
  layouts.innerHTML = "";
  for (const layout of state.catalog.layouts) {
    const item = document.createElement("li");
    const button = document.createElement("button");
    button.type = "button";
    button.textContent = `${layout.name} v${layout.version || 0}${layout.published ? "" : " Entwurf"}`;
    button.addEventListener("click", () => selectLayout(layout));
    item.appendChild(button);
    layouts.appendChild(item);
  }
  const configs = $("configs");
  configs.innerHTML = "";
  for (const config of state.catalog.configs || []) {
    const item = document.createElement("li");
    const button = document.createElement("button");
    button.type = "button";
    button.textContent = `${config.name} v${config.version || 0}${config.published ? "" : " Entwurf"}`;
    button.addEventListener("click", () => selectConfig(config));
    item.appendChild(button);
    configs.appendChild(item);
  }
}

function renderPageButtons() {
  const bar = $("pages");
  bar.innerHTML = "";
  for (const [id, title] of PAGES) {
    const button = document.createElement("button");
    button.type = "button";
    button.textContent = title;
    if (id === state.page) button.className = "selected";
    button.addEventListener("click", () => {
      state.page = id;
      state.selected = null;
      renderPageButtons();
      renderGrid();
    });
    bar.appendChild(button);
  }
}

function selectLayout(layout) {
  state.layout = JSON.parse(JSON.stringify(layout));
  state.layout.baseVersion = layout.version || 0;
  state.layout.pages = state.layout.pages || [];
  $("layout-name").value = state.layout.name || "";
  $("columns").value = state.layout.columns || 2;
  state.page = "start";
  renderPageButtons();
  renderGrid();
}

function selectConfig(config) {
  state.config = JSON.parse(JSON.stringify(config));
  state.config.baseVersion = config.version || 0;
  $("config-name").value = config.name || "";
  $("idle-mode").value = String(config.idleMode ?? 0);
  $("day-brightness").value = config.dayBrightness ?? 70;
  $("night-brightness").value = config.nightBrightness ?? 15;
  $("night-from").value = config.nightFrom ?? 22;
  $("night-to").value = config.nightTo ?? 6;
  $("state-interval").value = config.stateIntervalSec ?? 3;
  $("idle-interval").value = config.idleStateIntervalSec ?? 15;
  $("maintenance-from").value = config.maintenanceFrom ?? 3;
  $("maintenance-to").value = config.maintenanceTo ?? 5;
}

function columns() {
  return Math.max(1, Math.min(4, Number($("columns").value) || 2));
}

function renderGrid() {
  if (!state.layout) return;
  const grid = $("grid");
  const count = columns();
  grid.style.gridTemplateColumns = `repeat(${count}, 1fr)`;
  grid.innerHTML = "";
  const tiles = currentPage().tiles;
  for (let row = 0; row < 4; row += 1) {
    for (let col = 0; col < count; col += 1) {
      const tile = tiles.find((item) => item.col === col && item.row === row);
      const cell = document.createElement("button");
      cell.type = "button";
      cell.className = "tile" + (tile && state.selected === tile.tileId ? " selected" : "");
      cell.textContent = tile ? `${tile.title || tile.icon}\n${col + 1}/${row + 1}` : `${col + 1}/${row + 1}`;
      cell.addEventListener("click", () => onCell(col, row, tile));
      grid.appendChild(cell);
    }
  }
}

function onCell(col, row, tile) {
  if (tile) {
    state.selected = tile.tileId;
    fillTile(tile);
    renderGrid();
    return;
  }
  const selected = selectedTile();
  if (selected) {
    selected.col = col;
    selected.row = row;
    renderGrid();
  }
}

function fillTile(tile) {
  $("tile-title").value = tile.title || "";
  $("tile-icon").value = tile.icon;
  $("tile-variable").value = String(tile.variableId || "");
  $("tile-unit").value = tile.unit || "";
  $("tile-decimals").value = tile.decimals || 0;
  $("tile-action").checked = Boolean(tile.actionId);
  $("tile-confirm").checked = Boolean(tile.confirm);
  syncAction();
}

function selectedTile() {
  return currentPage().tiles.find((tile) => tile.tileId === state.selected);
}

function selectedVariable() {
  return state.variables.find((variable) => String(variable.id) === $("tile-variable").value);
}

function syncAction() {
  const variable = selectedVariable();
  $("tile-action").disabled = !(variable && variable.action);
  if (!variable || !variable.action) $("tile-action").checked = false;
}

function applyTileForm() {
  const tile = selectedTile();
  if (!tile) return;
  const variable = selectedVariable();
  tile.title = $("tile-title").value;
  tile.icon = $("tile-icon").value;
  tile.variableId = variable ? variable.id : 0;
  tile.unit = $("tile-unit").value;
  tile.decimals = Number($("tile-decimals").value) || 0;
  if ($("tile-action").checked && variable && variable.action) {
    tile.actionId = tile.actionId || `a-${tile.tileId}`;
    tile.actionKind = variable.actionKind || "switch";
    tile.confirm = $("tile-confirm").checked;
  } else {
    delete tile.actionId;
    delete tile.actionKind;
    tile.confirm = false;
  }
}

function validateLayout(layout) {
  if (!layout.name) throw new Error("Ansicht ohne Namen.");
  if (layout.columns < 1 || layout.columns > 4) throw new Error("Eine bis vier Spalten.");
  const seen = new Set();
  for (const page of layout.pages) {
    if (!PAGES.some((item) => item[0] === page.id)) throw new Error("Unbekannte Seite.");
    const occupied = new Set();
    for (const tile of page.tiles) {
      if (!tile.tileId || seen.has(tile.tileId)) throw new Error("Jede Kachel braucht eine eigene Kennung.");
      seen.add(tile.tileId);
      if (!ICONS.includes(tile.icon)) throw new Error("Unbekanntes Symbol.");
      if (tile.x !== undefined || tile.y !== undefined || tile.scriptId) throw new Error("Pixel und Skripte sind nicht erlaubt.");
      if (tile.col < 0 || tile.row < 0 || tile.col >= layout.columns) throw new Error("Kachel außerhalb des Rasters.");
      const key = `${tile.col}:${tile.row}`;
      if (occupied.has(key)) throw new Error("Kacheln überlappen sich.");
      occupied.add(key);
      if (tile.actionId && !variableAllowsAction(tile.variableId)) throw new Error("Schaltbare Kachel ohne freigegebene Variable.");
    }
  }
}

function variableAllowsAction(id) {
  const variable = state.variables.find((item) => item.id === id);
  return Boolean(variable && variable.action && variable.actionKind);
}

function layoutPayload() {
  applyTileForm();
  state.layout.name = $("layout-name").value.trim();
  state.layout.columns = columns();
  state.layout.pages = state.layout.pages.filter((page) => page.tiles.length);
  if (!state.layout.pages.length) {
    state.layout.pages = [{ id: "start", title: "Start", tiles: [] }];
  }
  validateLayout(state.layout);
  return state.layout;
}

function configPayload() {
  if (!state.config) throw new Error("Keine Konfiguration gewählt.");
  state.config.name = $("config-name").value.trim();
  if (!state.config.name) throw new Error("Konfiguration ohne Namen.");
  state.config.idleMode = Number($("idle-mode").value);
  state.config.dayBrightness = Number($("day-brightness").value);
  state.config.nightBrightness = Number($("night-brightness").value);
  state.config.nightFrom = Number($("night-from").value);
  state.config.nightTo = Number($("night-to").value);
  state.config.stateIntervalSec = Number($("state-interval").value);
  state.config.idleStateIntervalSec = Number($("idle-interval").value);
  state.config.maintenanceFrom = Number($("maintenance-from").value);
  state.config.maintenanceTo = Number($("maintenance-to").value);
  state.config.nightDim = true;
  return state.config;
}

async function storeLayout(publish) {
  const method = publish ? "HDISP_PublishLayout" : "HDISP_SaveLayout";
  const result = await call(method, [state.instanceId, JSON.stringify(layoutPayload())]);
  if (!result.ok) throw new Error(result.error || "Speichern fehlgeschlagen");
  state.catalog = await call("HDISP_ListCatalog", [state.instanceId]);
  renderLists();
  setStatus(publish ? "Ansicht veröffentlicht" : "Ansichtsentwurf gespeichert");
}

async function storeConfig(publish) {
  const method = publish ? "HDISP_PublishConfig" : "HDISP_SaveConfig";
  const result = await call(method, [state.instanceId, JSON.stringify(configPayload())]);
  if (!result.ok) throw new Error(result.error || "Speichern fehlgeschlagen");
  state.catalog = await call("HDISP_ListCatalog", [state.instanceId]);
  renderLists();
  setStatus(publish ? "Konfiguration veröffentlicht" : "Konfigurationsentwurf gespeichert");
}

$("connect").addEventListener("click", async () => {
  localStorage.setItem("homedisplay", JSON.stringify({ base: connection().base, user: connection().user }));
  setStatus("Verbinde …");
  try {
    const ids = await call("IPS_GetInstanceListByModuleID", ["{4E8A1C67-9D32-4B5F-A6E1-0C7B2F94D813}"]);
    if (!ids.length) throw new Error("Keine HomeDisplay-Instanz gefunden");
    state.instanceId = ids[0];
    await call("HDISP_GetService", [state.instanceId]);
    state.catalog = await call("HDISP_ListCatalog", [state.instanceId]);
    const variables = await call("HDISP_ListVariables", [state.instanceId]);
    state.variables = (variables.variables || []).filter((variable) => variable.type >= 0 && variable.type <= 3);
    const select = $("tile-variable");
    select.innerHTML = '<option value="">Keine</option>';
    for (const variable of state.variables) {
      const option = document.createElement("option");
      option.value = String(variable.id);
      const place = variable.category ? `${variable.category} / ` : "";
      option.textContent = `${place}${variable.name}`;
      select.appendChild(option);
    }
    renderLists();
    if (state.catalog.layouts[0]) selectLayout(state.catalog.layouts[0]);
    if (state.catalog.configs && state.catalog.configs[0]) selectConfig(state.catalog.configs[0]);
    setStatus("Verbunden");
  } catch (error) {
    setStatus(error.message);
  }
});

$("new-layout").addEventListener("click", () => {
  selectLayout({ id: "", name: "Neue Ansicht", version: 0, published: false, columns: 2, pages: [] });
});

$("new-config").addEventListener("click", () => {
  selectConfig({ id: "", name: "Neue Konfiguration", version: 0, published: false, idleMode: 0, nightDim: true, dayBrightness: 70, nightBrightness: 15, nightFrom: 22, nightTo: 6, stateIntervalSec: 3, idleStateIntervalSec: 15, maintenanceFrom: 3, maintenanceTo: 5 });
});

$("add-tile").addEventListener("click", () => {
  if (!state.layout) return;
  const count = columns();
  const tiles = currentPage().tiles;
  let placed = null;
  for (let row = 0; row < 4 && !placed; row += 1) {
    for (let col = 0; col < count; col += 1) {
      if (!tiles.some((tile) => tile.col === col && tile.row === row)) {
        placed = { col, row };
        break;
      }
    }
  }
  if (!placed) {
    setStatus("Auf dieser Seite ist keine Zelle frei.");
    return;
  }
  const variable = selectedVariable();
  const tile = {
    tileId: `t${Date.now()}`,
    icon: $("tile-icon").value,
    title: $("tile-title").value || "Kachel",
    col: placed.col,
    row: placed.row,
    colSpan: 1,
    rowSpan: 1,
    variableId: variable ? variable.id : 0,
    unit: $("tile-unit").value,
    decimals: Number($("tile-decimals").value) || 0,
    confirm: $("tile-confirm").checked
  };
  if ($("tile-action").checked && variable && variable.action) {
    tile.actionId = `a${Date.now()}`;
    tile.actionKind = variable.actionKind || "switch";
  }
  tiles.push(tile);
  state.selected = tile.tileId;
  renderGrid();
});

$("delete-tile").addEventListener("click", () => {
  if (!state.layout) return;
  currentPage().tiles = currentPage().tiles.filter((tile) => tile.tileId !== state.selected);
  state.selected = null;
  renderGrid();
});

$("tile-variable").addEventListener("change", syncAction);
$("save").addEventListener("click", () => storeLayout(false).catch((error) => setStatus(error.message)));
$("publish").addEventListener("click", () => storeLayout(true).catch((error) => setStatus(error.message)));
$("save-config").addEventListener("click", () => storeConfig(false).catch((error) => setStatus(error.message)));
$("publish-config").addEventListener("click", () => storeConfig(true).catch((error) => setStatus(error.message)));
$("columns").addEventListener("change", renderGrid);

const saved = JSON.parse(localStorage.getItem("homedisplay") || "{}");
if (saved.base) $("base").value = saved.base;
if (saved.user) $("user").value = saved.user;
