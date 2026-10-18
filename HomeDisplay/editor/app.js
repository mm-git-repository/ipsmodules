const MODULE_ID = "{4E8A1C67-9D32-4B5F-A6E1-0C7B2F94D813}";
const state = { instanceId: 0, catalog: null, variables: [], layout: null, selected: null };

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

function currentPage() {
  return state.layout.pages[0];
}

function renderLayouts() {
  const list = $("layouts");
  list.innerHTML = "";
  for (const layout of state.catalog.layouts) {
    const item = document.createElement("li");
    const button = document.createElement("button");
    button.type = "button";
    button.textContent = `${layout.name} v${layout.version || 0}${layout.published ? "" : " Entwurf"}`;
    button.addEventListener("click", () => selectLayout(layout));
    item.appendChild(button);
    list.appendChild(item);
  }
}

function selectLayout(layout) {
  state.layout = JSON.parse(JSON.stringify(layout));
  state.layout.baseVersion = layout.version || 0;
  if (!state.layout.pages || !state.layout.pages.length) {
    state.layout.pages = [{ id: "start", title: "Start", tiles: [] }];
  }
  $("layout-name").value = state.layout.name || "";
  $("columns").value = state.layout.columns || 2;
  renderGrid();
}

function renderGrid() {
  const grid = $("grid");
  const columns = Math.max(1, Number($("columns").value) || 2);
  grid.style.gridTemplateColumns = `repeat(${columns}, 1fr)`;
  grid.innerHTML = "";
  for (const tile of currentPage().tiles) {
    const card = document.createElement("button");
    card.type = "button";
    card.className = "tile" + (state.selected === tile.tileId ? " selected" : "");
    card.textContent = `${tile.title || tile.icon}\n${tile.col + 1}/${tile.row + 1}`;
    card.addEventListener("click", () => {
      state.selected = tile.tileId;
      fillTile(tile);
      renderGrid();
    });
    grid.appendChild(card);
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
}

function selectedTile() {
  return currentPage().tiles.find((tile) => tile.tileId === state.selected);
}

function applyTileForm() {
  const tile = selectedTile();
  if (!tile) return;
  tile.title = $("tile-title").value;
  tile.icon = $("tile-icon").value;
  tile.variableId = Number($("tile-variable").value) || 0;
  tile.unit = $("tile-unit").value;
  tile.decimals = Number($("tile-decimals").value) || 0;
  tile.actionId = $("tile-action").checked ? (tile.actionId || `a-${tile.tileId}`) : "";
  tile.confirm = $("tile-confirm").checked;
}

function payload() {
  applyTileForm();
  state.layout.name = $("layout-name").value.trim();
  state.layout.columns = Math.max(1, Number($("columns").value) || 2);
  return state.layout;
}

async function store(publish) {
  const method = publish ? "HDISP_PublishLayout" : "HDISP_SaveLayout";
  const result = await call(method, [state.instanceId, JSON.stringify(payload())]);
  if (!result.ok) throw new Error(result.error || "Speichern fehlgeschlagen");
  state.catalog = await call("HDISP_ListCatalog", [state.instanceId]);
  renderLayouts();
  setStatus(publish ? "Veröffentlicht" : "Entwurf gespeichert");
}

$("connect").addEventListener("click", async () => {
  localStorage.setItem("homedisplay", JSON.stringify(connection()));
  setStatus("Verbinde …");
  const ids = await call("IPS_GetInstanceListByModuleID", [MODULE_ID]);
  if (!ids.length) throw new Error("Keine HomeDisplay-Instanz gefunden");
  state.instanceId = ids[0];
  await call("HDISP_GetService", [state.instanceId]);
  state.catalog = await call("HDISP_ListCatalog", [state.instanceId]);
  const variables = await call("HDISP_ListVariables", [state.instanceId]);
  state.variables = variables.variables || [];
  const select = $("tile-variable");
  select.innerHTML = '<option value="">Keine</option>';
  for (const variable of state.variables) {
    const option = document.createElement("option");
    option.value = String(variable.id);
    option.textContent = `${variable.name} (${variable.id})`;
    select.appendChild(option);
  }
  renderLayouts();
  if (state.catalog.layouts[0]) selectLayout(state.catalog.layouts[0]);
  setStatus("Verbunden");
});

$("new-layout").addEventListener("click", () => {
  selectLayout({ id: "", name: "Neues Layout", version: 0, published: false, columns: 2, pages: [{ id: "start", title: "Start", tiles: [] }] });
});

$("add-tile").addEventListener("click", () => {
  const tiles = currentPage().tiles;
  const tile = {
    tileId: `t${Date.now()}`,
    icon: $("tile-icon").value,
    title: $("tile-title").value || "Kachel",
    col: tiles.length % (Number($("columns").value) || 2),
    row: Math.floor(tiles.length / (Number($("columns").value) || 2)),
    colSpan: 1,
    rowSpan: 1,
    variableId: Number($("tile-variable").value) || 0,
    unit: $("tile-unit").value,
    decimals: Number($("tile-decimals").value) || 0,
    actionId: $("tile-action").checked ? `a${Date.now()}` : "",
    confirm: $("tile-confirm").checked
  };
  tiles.push(tile);
  state.selected = tile.tileId;
  renderGrid();
});

$("delete-tile").addEventListener("click", () => {
  currentPage().tiles = currentPage().tiles.filter((tile) => tile.tileId !== state.selected);
  state.selected = null;
  renderGrid();
});

$("save").addEventListener("click", () => store(false).catch((error) => setStatus(error.message)));
$("publish").addEventListener("click", () => store(true).catch((error) => setStatus(error.message)));
$("columns").addEventListener("change", renderGrid);

const saved = JSON.parse(localStorage.getItem("homedisplay") || "{}");
if (saved.base) $("base").value = saved.base;
if (saved.user) $("user").value = saved.user;
