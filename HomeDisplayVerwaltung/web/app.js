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
  ["pump", "Pumpe"], ["valve", "Ventil"], ["alarm", "Alarm"], ["home", "Haus"], ["clock", "Uhr"], ["info", "Info"]
];
const KIND_NAMES = { value: "Wert", nav: "Seitenwechsler", label: "Text", image: "Bild", camera: "Kamera", separator: "Trennlinie", system: "Anzeigestatus" };
const CONTROL_NAMES = { switch: "Schalter", dimSwitch: "Dimmschalter", slider: "Dimmbalken", combo: "Kombischalter", battery: "Batterie" };
const DIM_CONTROLS = ["dimSwitch", "slider", "combo"];
const TYPE_NAMES = ["Ja/Nein", "Ganzzahl", "Dezimalzahl", "Text"];

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
    const control = tile.control || "switch";
    if (DIM_CONTROLS.includes(control)) element.classList.add("dim");
    if (control === "battery") element.classList.add("battery");
    const kicker = CONTROL_NAMES[control] || `${iconName(tile.icon)}${tile.actionId ? " · schaltbar" : ""}`;
    const body = controlMarkup(control);
    element.innerHTML = `<div class="kicker">${kicker}</div><div class="name"></div>${body || `<div class="value">${variable ? "12,3" : "—"}${tile.unit ? " " + escapeHtml(tile.unit) : ""}</div>`}`;
    element.querySelector(".name").textContent = tile.title || (variable ? variable.name : "Wert");
    bindControlPreview(element);
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
    const image = state.images.find((item) => item.id === tile.imageId);
    element.innerHTML = image ? `<img src="images/${image.id}.${image.ext}" alt="">` : `<div class="missing">Bild wählen</div>`;
  } else if (tile.kind === "camera") {
    element.classList.add("camera");
    const variable = variableById(tile.variableId);
    element.innerHTML = `<div class="kicker">Kamera · Livebild</div><div class="name"></div><div class="camera-preview">${variable ? "Snapshot über Variable" : "Variable mit Bild-URL wählen"}</div>`;
    element.querySelector(".name").textContent = tile.title || (variable ? variable.name : "Kamera");
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

function controlMarkup(control) {
  if (control === "dimSwitch") return `<div class="ctl"><span class="sw"></span><span class="ctl-label">Aus</span></div>`;
  if (control === "slider") return `<div class="ctl"><div class="track"><div class="fill"></div><div class="knob"></div></div></div>`;
  if (control === "combo") return `<div class="ctl"><span class="sw"></span><div class="track"><div class="fill"></div><div class="knob"></div></div></div>`;
  if (control === "battery") return `<div class="battery"><div class="battery-body"><div class="battery-fill" style="width:72%"></div></div><div class="battery-cap"></div><span class="battery-pct">72 %</span></div>`;
  return "";
}

/* Vorschau im Editor: der Griff lässt sich ziehen, die Leiste selbst nicht. Der Wert wird nicht gespeichert. */
function bindControlPreview(element) {
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
      if (existing && (existing.kind === "value" || existing.kind === "camera")) {
        assignVariable(existing, variableById(variableId));
        return;
      }
      if (!existing) addTile("value", col, row, variableById(variableId));
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
    tile.imageId = state.images[0] ? state.images[0].id : "";
  } else if (kind === "camera") {
    tile.title = "Kamera";
    tile.variableId = 0;
    tile.colSpan = Math.min(2, state.layout.columns);
    tile.rowSpan = Math.min(2, state.layout.rows);
    if (!fits(null, col, row, tile.colSpan, tile.rowSpan, null)) {
      tile.colSpan = 1;
      tile.rowSpan = 1;
    }
    if (variable) assignVariable(tile, variable, true);
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
  if (tile.kind === "camera") {
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
  fillIconSelect($("p-icon"), tile.kind === "nav");
  $("p-icon").value = tile.icon || "";
  const variable = variableById(tile.variableId);
  $("p-variable").value = variable ? `${variable.path ? variable.path + " / " : ""}${variable.name} (${variable.id})` : "";
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
  $("p-action").checked = Boolean(tile.actionId);
  $("p-action").disabled = !(variable && variable.action);
  $("p-confirm").checked = Boolean(tile.confirm);
  $("p-actionkind").value = tile.actionKind || (DIM_CONTROLS.includes(tile.control) ? "dim" : (variable ? variable.actionKind || "switch" : "switch"));
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
    const control = $("p-control").value || "switch";
    tile.control = control === "switch" ? undefined : control;
    if (control === "switch") delete tile.control;
    if (control === "battery") {
      tile.valueMin = tile.valueMin ?? 0;
      tile.valueMax = tile.valueMax ?? 100;
      if (!tile.unit) tile.unit = "%";
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
  if (tile.kind === "image") tile.imageId = $("p-image").value;
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
    const picker = document.createElement("select");
    picker.innerHTML = '<option value="0">Keine Variable</option>';
    for (const item of state.variables) {
      const option = document.createElement("option");
      option.value = String(item.id);
      option.textContent = `${item.path ? item.path + " / " : ""}${item.name} (${item.id})`;
      picker.appendChild(option);
    }
    picker.value = String(tile.variableId || 0);
    fields.variable = add("Variable (Bild-URL)", picker);
    const hint = document.createElement("p");
    hint.className = "hint";
    hint.textContent = "Textvariable mit http- oder https-Adresse zu einem Kamerabild (JPEG/PNG).";
    body.appendChild(hint);
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
      const chosen = variableById(Number(fields.variable.value));
      tile.variableId = chosen ? chosen.id : 0;
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
  $("l-columns").value = state.layout.columns;
  $("l-rows").value = state.layout.rows;
  $("l-tabs").checked = state.layout.showTabs;
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
    const columns = Math.min(4, Math.max(1, Number($("l-columns").value) || 2));
    const rows = Math.min(6, Math.max(1, Number($("l-rows").value) || 4));
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
      if (tile.kind === "camera" && !tile.variableId) throw new Error(`Die Kamerakachel „${tile.title || tile.tileId}“ braucht eine Variable mit Bild-URL.`);
      if (tile.kind === "value" && DIM_CONTROLS.includes(tile.control) && !tile.actionId) throw new Error(`„${tile.title || tile.tileId}“ braucht eine schaltbare Dimmvariable.`);
      if (tile.kind === "value" && tile.actionId) {
        const variable = variableById(tile.variableId);
        if (!variable || !variable.action) throw new Error(`„${tile.title || tile.tileId}“ ist schaltbar, hat aber keine schaltbare Variable.`);
      }
      if (tile.kind === "nav" && !layout.pages.some((item) => item.id === tile.targetPage)) throw new Error(`Der Seitenwechsler „${tile.title || tile.tileId}“ zeigt auf eine fehlende Seite.`);
      if (tile.kind === "label" && !(tile.text || "").trim()) throw new Error("Eine Textkachel ist leer.");
      if (tile.kind === "image" && !tile.imageId) throw new Error("Eine Bildkachel hat kein Bild.");
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
    validateLayout(state.layout);
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
$("template-layout").addEventListener("click", async () => {
  try {
    const layout = await call("template", { name: prompt("Name der neuen Ansicht", "Übersicht") || "Übersicht" });
    layout.id = "";
    layout.version = 0;
    layout.published = false;
    selectLayout(layout);
    markDirty();
    setStatus("Übersicht geladen. Variablen zuordnen, dann speichern.");
  } catch (error) {
    setStatus(error.message);
  }
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

let probe = { page: "", snapshot: null };

function renderProbe() {
  const stage = $("probe-stage");
  const pages = $("probe-pages");
  stage.innerHTML = "";
  pages.innerHTML = "";
  const layout = probe.snapshot && probe.snapshot.layout;
  if (!layout || !(layout.pages || []).length) {
    stage.innerHTML = '<div class="hint">Diese Anzeige hat keine Ansicht.</div>';
    return;
  }
  if (!probe.page || !(layout.pages || []).some((page) => page.id === probe.page)) probe.page = layout.pages[0].id;
  const values = {};
  for (const tile of (probe.snapshot.state && probe.snapshot.state.tiles) || []) values[tile.tileId] = tile;
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
  const current = layout.pages.find((page) => page.id === probe.page);
  stage.style.gridTemplateColumns = `repeat(${layout.columns || 2}, minmax(0, 1fr))`;
  for (const tile of (current && current.tiles) || []) {
    const card = document.createElement("div");
    card.className = "probe-tile";
    card.style.gridColumn = `${(tile.col || 0) + 1} / span ${tile.colSpan || 1}`;
    card.style.gridRow = `${(tile.row || 0) + 1} / span ${tile.rowSpan || 1}`;
    const title = document.createElement("strong");
    title.textContent = tile.title || tile.kind || "Kachel";
    const value = document.createElement("div");
    value.className = "probe-value";
    const live = values[tile.tileId];
    value.textContent = live ? live.text : (tile.kind === "value" ? "—" : "");
    card.append(title, value);
    if (tile.kind === "nav" && tile.targetPage) {
      card.style.cursor = "pointer";
      card.addEventListener("click", () => {
        probe.page = tile.targetPage;
        renderProbe();
      });
    }
    if (tile.actionId) {
      const action = document.createElement("button");
      action.type = "button";
      action.textContent = tile.actionKind === "dim" ? "Wert senden" : "Schalten";
      action.addEventListener("click", () => sendProbeAction(tile));
      card.appendChild(action);
    }
    stage.appendChild(card);
  }
}

async function loadProbe() {
  const id = Number($("probe-panel").value);
  if (!id) {
    probe.snapshot = null;
    renderProbe();
    return;
  }
  probe.snapshot = await call("inspect", { id });
  renderProbe();
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
    await loadProbe();
  } catch (error) {
    setStatus(error.message);
    return;
  }
  $("probe-dialog").showModal();
}

$("probe-panel").addEventListener("change", () => {
  probe.page = "";
  loadProbe().catch((error) => setStatus(error.message));
});
$("probe-refresh").addEventListener("click", () => loadProbe().catch((error) => setStatus(error.message)));
$("open-probe").addEventListener("click", openProbe);

/* ---------- Start ---------- */

async function start() {
  try {
    state.catalog = await call("catalog");
    const variables = await call("variables");
    state.variables = (variables.variables || []).filter((variable) => variable.type >= 0 && variable.type <= 3);
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
