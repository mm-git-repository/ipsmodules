const PAGES = [
  ["start", "Start"],
  ["energie", "Energie"],
  ["klima", "Klima"],
  ["licht", "Licht und Rollladen"],
  ["pool", "Pool und Whirlpool"],
  ["garten", "Garten"],
  ["system", "System"]
];
const ICONS = [
  ["energy", "Energie"], ["temperature", "Temperatur"], ["light", "Licht"], ["shutter", "Rollladen"],
  ["pump", "Pumpe"], ["valve", "Ventil"], ["alarm", "Alarm"], ["home", "Haus"], ["clock", "Uhr"], ["info", "Info"]
];
const KIND_NAMES = { value: "Wert", nav: "Tab-Wechsler", label: "Text", image: "Bild", separator: "Trennlinie" };
const TYPE_NAMES = ["Bool", "Integer", "Float", "String"];

const state = {
  catalog: { layouts: [], configs: [] },
  variables: [],
  images: [],
  layout: null,
  page: "start",
  selected: null,
  dirty: false,
  pickedVariable: null
};

const $ = (id) => document.getElementById(id);

async function call(method, extra) {
  const response = await fetch("api", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ method, ...(extra || {}) })
  });
  const body = await response.json();
  if (!body.ok) throw new Error(body.error || "Aufruf fehlgeschlagen");
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
  state.layout.columns = state.layout.columns || 2;
  state.layout.rows = state.layout.rows || 4;
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
  renderLayoutSelect();
  renderPageTabs();
  renderGrid();
  renderProperties();
  updateTitle();
}

function newLayout(name) {
  selectLayout({ id: "", name: name || "Neue Ansicht", version: 0, published: false, columns: 2, rows: 4, showTabs: true, pages: [{ id: "start", title: "Start", tiles: [] }] });
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
          addTile("value", col, row, state.pickedVariable);
          return;
        }
        state.selected = null;
        renderGrid();
        renderProperties();
      });
      grid.appendChild(cell);
    }
  }
}

function renderTile(tile) {
  const element = document.createElement("div");
  element.className = `tile ${tile.kind}${tile.kind === "label" && tile.size === "large" ? " large" : ""}${state.selected === tile.tileId ? " selected" : ""}`;
  element.style.gridColumn = `${tile.col + 1} / span ${tile.colSpan || 1}`;
  element.style.gridRow = `${tile.row + 1} / span ${tile.rowSpan || 1}`;
  element.draggable = true;
  element.title = `${KIND_NAMES[tile.kind]} – Doppelklick für Details`;
  if (tile.kind === "value") {
    const variable = variableById(tile.variableId);
    element.innerHTML = `<div class="kicker">${iconName(tile.icon)}${tile.actionId ? " · schaltbar" : ""}</div><div class="name"></div><div class="value">${variable ? "12,3" : "—"}${tile.unit ? " " + escapeHtml(tile.unit) : ""}</div>`;
    element.querySelector(".name").textContent = tile.title || (variable ? variable.name : "Wert");
  } else if (tile.kind === "nav") {
    element.innerHTML = `<div class="kicker">${tile.icon ? iconName(tile.icon) + " · " : ""}Tab</div><div class="name"></div>`;
    element.querySelector(".name").textContent = tile.title || pageTitleIn(tile.targetPage);
  } else if (tile.kind === "label") {
    element.innerHTML = `<div class="text"></div>`;
    element.querySelector(".text").textContent = tile.text || "Text";
  } else if (tile.kind === "separator") {
    element.innerHTML = `<div class="line"></div>`;
  } else if (tile.kind === "image") {
    const image = state.images.find((item) => item.id === tile.imageId);
    element.innerHTML = image ? `<img src="images/${image.id}.${image.ext}" alt="">` : `<div class="missing">Bild wählen</div>`;
  }
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
  return element;
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
    const variableId = Number(event.dataTransfer.getData("text/homedisplay-variable"));
    if (movedId) {
      moveTile(movedId, col, row, existing);
      return;
    }
    if (variableId) {
      if (existing && existing.kind === "value") {
        assignVariable(existing, variableById(variableId));
        return;
      }
      if (!existing) addTile("value", col, row, variableById(variableId));
      return;
    }
    if (kind && !existing) addTile(kind, col, row, null);
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

function addTile(kind, col, row, variable) {
  if (!state.layout) return;
  const page = currentPage();
  const stamp = Date.now().toString(36);
  const tile = { tileId: `t${stamp}`, kind, title: "", col, row, colSpan: 1, rowSpan: 1 };
  if (kind === "value") {
    tile.icon = "energy";
    tile.variableId = 0;
    tile.unit = "";
    tile.decimals = 0;
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
  } else if (kind === "image") {
    tile.imageId = state.images[0] ? state.images[0].id : "";
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
  if (kind === "image" && !tile.imageId) openImages();
}

function assignVariable(tile, variable, silent) {
  if (!variable) return;
  tile.variableId = variable.id;
  if (!tile.title) tile.title = variable.name;
  if (!variable.action) {
    delete tile.actionId;
    delete tile.actionKind;
    tile.confirm = false;
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
  fillIconSelect($("p-icon"), tile.kind === "nav");
  $("p-icon").value = tile.icon || "";
  const variable = variableById(tile.variableId);
  $("p-variable").value = variable ? `${variable.path ? variable.path + " / " : ""}${variable.name} (${variable.id})` : "";
  $("p-unit").value = tile.unit || "";
  $("p-decimals").value = tile.decimals || 0;
  $("p-action").checked = Boolean(tile.actionId);
  $("p-action").disabled = !(variable && variable.action);
  $("p-confirm").checked = Boolean(tile.confirm);
  $("p-actionkind").value = tile.actionKind || (variable ? variable.actionKind || "switch" : "switch");
  fillTargetSelect($("p-target"));
  $("p-target").value = tile.targetPage || "";
  $("p-text").value = tile.text || "";
  $("p-size").value = tile.size || "small";
  fillImageSelect($("p-image"));
  $("p-image").value = tile.imageId || "";
  const image = state.images.find((item) => item.id === tile.imageId);
  $("p-image-preview").src = image ? `images/${image.id}.${image.ext}` : "";
  $("p-image-preview").classList.toggle("hidden", !image);
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
  if (tile.kind === "value" || tile.kind === "nav") tile.icon = $("p-icon").value;
  if (tile.kind === "value") {
    tile.unit = $("p-unit").value;
    tile.decimals = Number($("p-decimals").value) || 0;
    const variable = variableById(tile.variableId);
    if ($("p-action").checked && variable && variable.action) {
      tile.actionId = tile.actionId || `a-${tile.tileId}`;
      tile.actionKind = $("p-actionkind").value;
      tile.confirm = $("p-confirm").checked;
    } else {
      delete tile.actionId;
      delete tile.actionKind;
      tile.confirm = false;
    }
  }
  if (tile.kind === "nav") tile.targetPage = $("p-target").value;
  if (tile.kind === "label") {
    tile.text = $("p-text").value;
    tile.size = $("p-size").value;
  }
  if (tile.kind === "image") tile.imageId = $("p-image").value;
  markDirty();
  renderGrid();
  renderProperties();
}

for (const id of ["p-title", "p-col", "p-row", "p-colspan", "p-rowspan", "p-icon", "p-unit", "p-decimals", "p-action", "p-confirm", "p-actionkind", "p-target", "p-text", "p-size", "p-image"]) {
  $(id).addEventListener("change", applyProperties);
}
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
  for (const [label, key, value, min, max] of [["Spalte", "col", tile.col + 1, 1, state.layout.columns], ["Zeile", "row", tile.row + 1, 1, state.layout.rows], ["Breite", "colSpan", tile.colSpan || 1, 1, 4], ["Höhe", "rowSpan", tile.rowSpan || 1, 1, 6]]) {
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
    fields.action = add("Schaltbar", check(tile.actionId));
    fields.action.disabled = !(variable && variable.action);
    fields.confirm = add("Bestätigung am Panel", check(tile.confirm));
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
      if (fields.action.checked && chosen && chosen.action) {
        tile.actionId = tile.actionId || `a-${tile.tileId}`;
        tile.actionKind = fields.actionKind.value;
        tile.confirm = fields.confirm.checked;
        tile.valueMin = Number(fields.valueMin.value);
        tile.valueMax = Number(fields.valueMax.value);
      } else {
        delete tile.actionId;
        delete tile.actionKind;
        delete tile.valueMin;
        delete tile.valueMax;
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
    markDirty();
    renderGrid();
    renderProperties();
  };
}

/* ---------- Ansicht-Dialog ---------- */

function openLayoutDialog() {
  const dialog = $("layout-dialog");
  $("l-name").value = state.layout.name;
  $("l-columns").value = state.layout.columns;
  $("l-rows").value = state.layout.rows;
  $("l-tabs").checked = state.layout.showTabs;
  const list = $("l-pages");
  list.innerHTML = "";
  for (const [id, name] of PAGES) {
    const existing = state.layout.pages.find((page) => page.id === id);
    const wrap = document.createElement("label");
    const check = Object.assign(document.createElement("input"), { type: "checkbox", checked: Boolean(existing), value: id });
    const title = Object.assign(document.createElement("input"), { type: "text", value: existing ? existing.title || name : name });
    const count = document.createElement("small");
    count.textContent = existing ? `${existing.tiles.length} Kacheln` : "";
    wrap.append(check, title, count);
    list.appendChild(wrap);
  }
  dialog.returnValue = "";
  dialog.showModal();
  dialog.onclose = () => {
    if (dialog.returnValue !== "ok") return;
    state.layout.name = $("l-name").value.trim() || state.layout.name;
    const columns = Math.min(4, Math.max(1, Number($("l-columns").value) || 2));
    const rows = Math.min(6, Math.max(1, Number($("l-rows").value) || 4));
    const pages = [];
    for (const wrap of list.querySelectorAll("label")) {
      const [check, title] = wrap.querySelectorAll("input");
      if (!check.checked) continue;
      const existing = state.layout.pages.find((page) => page.id === check.value);
      pages.push(existing ? Object.assign(existing, { title: title.value.trim() || pageTitle(check.value) }) : { id: check.value, title: title.value.trim() || pageTitle(check.value), tiles: [] });
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
  const tree = $("variable-tree");
  tree.innerHTML = "";
  const needle = $("variable-search").value.trim().toLowerCase();
  const onlyActions = $("only-actions").checked;
  const groups = new Map();
  for (const variable of state.variables) {
    if (onlyActions && !variable.action) continue;
    if (needle && !`${variable.name} ${variable.path} ${variable.id}`.toLowerCase().includes(needle)) continue;
    const key = variable.path || "Ohne Kategorie";
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(variable);
  }
  const keys = [...groups.keys()].sort((left, right) => left.localeCompare(right, "de"));
  for (const key of keys) {
    const details = document.createElement("details");
    details.className = "group";
    details.open = Boolean(needle) || keys.length <= 3;
    const summary = document.createElement("summary");
    summary.innerHTML = `${escapeHtml(key)} <small>(${groups.get(key).length})</small>`;
    details.appendChild(summary);
    for (const variable of groups.get(key).sort((left, right) => left.name.localeCompare(right.name, "de"))) {
      const row = document.createElement("div");
      row.className = "variable" + (state.pickedVariable && state.pickedVariable.id === variable.id ? " selected" : "");
      row.draggable = true;
      row.title = `${variable.path ? variable.path + " / " : ""}${variable.name}\nID ${variable.id}${variable.profile ? "\nProfil " + variable.profile : ""}`;
      row.innerHTML = `<span></span><span class="meta">${TYPE_NAMES[variable.type] || ""}${variable.action ? ' <span class="badge">schaltbar</span>' : ""}</span>`;
      row.firstChild.textContent = variable.name;
      row.addEventListener("dragstart", (event) => {
        event.dataTransfer.setData("text/homedisplay-variable", String(variable.id));
        event.dataTransfer.effectAllowed = "copy";
      });
      row.addEventListener("click", () => {
        const tile = selectedTile();
        if (tile && tile.kind === "value") {
          assignVariable(tile, variable);
          return;
        }
        state.pickedVariable = state.pickedVariable && state.pickedVariable.id === variable.id ? null : variable;
        setHint(state.pickedVariable ? `„${variable.name}“ gewählt – freie Zelle anklicken, um eine Wertkachel anzulegen.` : "");
        renderVariableTree();
      });
      row.addEventListener("dblclick", () => {
        const cell = freeCell(1, 1);
        if (cell) addTile("value", cell.col, cell.row, variable);
        else setStatus("Auf dieser Seite ist keine Zelle frei.");
      });
      details.appendChild(row);
    }
    tree.appendChild(details);
  }
  if (!keys.length) tree.innerHTML = '<div class="hint">Keine Variablen gefunden.</div>';
}

$("variable-search").addEventListener("input", renderVariableTree);
$("only-actions").addEventListener("change", renderVariableTree);

for (const item of document.querySelectorAll(".palette-item")) {
  item.addEventListener("dragstart", (event) => {
    event.dataTransfer.setData("text/homedisplay-kind", item.dataset.kind);
    event.dataTransfer.effectAllowed = "copy";
  });
  item.addEventListener("dblclick", () => {
    if (!state.layout) return;
    const cell = freeCell(1, 1);
    if (cell) addTile(item.dataset.kind, cell.col, cell.row, null);
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
      if (tile.kind === "value" && tile.actionId) {
        const variable = variableById(tile.variableId);
        if (!variable || !variable.action) throw new Error(`„${tile.title || tile.tileId}“ ist schaltbar, hat aber keine schaltbare Variable.`);
      }
      if (tile.kind === "nav" && !layout.pages.some((item) => item.id === tile.targetPage)) throw new Error(`Der Tab-Wechsler „${tile.title || tile.tileId}“ zeigt auf eine fehlende Seite.`);
      if (tile.kind === "label" && !(tile.text || "").trim()) throw new Error("Eine Textkachel ist leer.");
      if (tile.kind === "image" && !tile.imageId) throw new Error("Eine Bildkachel hat kein Bild.");
    }
  }
}

async function store(publish) {
  if (!state.layout) return;
  try {
    state.layout.columns = Number($("columns").value) || 2;
    state.layout.rows = Number($("rows").value) || 4;
    state.layout.showTabs = $("show-tabs").checked;
    validateLayout(state.layout);
    const result = await call(publish ? "publish" : "save", { layout: state.layout });
    if (!result.ok) throw new Error(result.error || "Speichern fehlgeschlagen");
    const previousId = state.layout.id;
    const previousName = state.layout.name;
    state.catalog = await call("catalog");
    const saved = state.catalog.layouts.find((item) => previousId ? item.id === previousId : item.name === previousName);
    if (saved) selectLayout(saved);
    renderLayoutSelect();
    setStatus(publish ? "Ansicht veröffentlicht. Die Panels übernehmen sie beim nächsten Abruf." : "Entwurf gespeichert.");
  } catch (error) {
    setStatus(error.message);
  }
}

$("save").addEventListener("click", () => store(false));
$("publish").addEventListener("click", () => store(true));
$("tb-save").addEventListener("click", () => store(false));
$("tb-publish").addEventListener("click", () => store(true));
$("new-layout").addEventListener("click", () => newLayout(prompt("Name der neuen Ansicht", "Neue Ansicht") || "Neue Ansicht"));
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

/* ---------- Konfigurationen ---------- */

function renderConfigMenu() {
  const menu = $("menu-config");
  menu.innerHTML = "";
  for (const config of state.catalog.configs || []) {
    const item = document.createElement("button");
    item.type = "button";
    item.textContent = `${config.name} v${config.version || 0}${config.published ? "" : " (Entwurf)"}`;
    item.addEventListener("click", () => openConfig(config));
    menu.appendChild(item);
  }
  menu.appendChild(document.createElement("hr"));
  const add = document.createElement("button");
  add.type = "button";
  add.textContent = "Neue Konfiguration …";
  add.addEventListener("click", () => openConfig({ id: "", name: "Neue Konfiguration", version: 0, idleMode: 0, nightDim: true, dayBrightness: 70, nightBrightness: 15, nightFrom: 22, nightTo: 6, stateIntervalSec: 3, idleStateIntervalSec: 15, maintenanceFrom: 3, maintenanceTo: 5 }));
  menu.appendChild(add);
}

function openConfig(config) {
  const dialog = $("config-dialog");
  const draft = JSON.parse(JSON.stringify(config));
  draft.baseVersion = config.version || 0;
  $("c-name").value = draft.name || "";
  $("c-idle").value = String(draft.idleMode ?? 0);
  $("c-day").value = draft.dayBrightness ?? 70;
  $("c-night").value = draft.nightBrightness ?? 15;
  $("c-night-from").value = draft.nightFrom ?? 22;
  $("c-night-to").value = draft.nightTo ?? 6;
  $("c-state").value = draft.stateIntervalSec ?? 3;
  $("c-idle-state").value = draft.idleStateIntervalSec ?? 15;
  $("c-maint-from").value = draft.maintenanceFrom ?? 3;
  $("c-maint-to").value = draft.maintenanceTo ?? 5;
  dialog.returnValue = "";
  dialog.showModal();
  dialog.onclose = async () => {
    if (dialog.returnValue !== "save" && dialog.returnValue !== "publish") return;
    draft.name = $("c-name").value.trim();
    draft.idleMode = Number($("c-idle").value);
    draft.nightDim = true;
    draft.dayBrightness = Number($("c-day").value);
    draft.nightBrightness = Number($("c-night").value);
    draft.nightFrom = Number($("c-night-from").value);
    draft.nightTo = Number($("c-night-to").value);
    draft.stateIntervalSec = Number($("c-state").value);
    draft.idleStateIntervalSec = Number($("c-idle-state").value);
    draft.maintenanceFrom = Number($("c-maint-from").value);
    draft.maintenanceTo = Number($("c-maint-to").value);
    try {
      const result = await call(dialog.returnValue === "publish" ? "publishConfig" : "saveConfig", { config: draft });
      if (!result.ok) throw new Error(result.error || "Speichern fehlgeschlagen");
      state.catalog = await call("catalog");
      renderConfigMenu();
      setStatus(dialog.returnValue === "publish" ? "Konfiguration veröffentlicht." : "Konfigurationsentwurf gespeichert.");
    } catch (error) {
      setStatus(error.message);
    }
  };
}

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

/* ---------- Start ---------- */

async function start() {
  try {
    state.catalog = await call("catalog");
    const variables = await call("variables");
    state.variables = (variables.variables || []).filter((variable) => variable.type >= 0 && variable.type <= 3);
    await loadImages();
    renderVariableTree();
    renderConfigMenu();
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
