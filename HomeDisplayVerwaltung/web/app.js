const state = { catalog: null, variables: [], layout: null, selected: null };

const $ = (id) => document.getElementById(id);

async function call(method, extra) {
  const response = await fetch("api", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ method, ...extra })
  });
  const body = await response.json();
  if (!body.ok) throw new Error(body.error || "Aufruf fehlgeschlagen");
  return body.result;
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
  if (!state.layout) return;
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
  const result = await call(publish ? "publish" : "save", { layout: payload() });
  if (!result.ok) throw new Error(result.error || "Speichern fehlgeschlagen");
  state.catalog = await call("catalog");
  renderLayouts();
  setStatus(publish ? "Veröffentlicht" : "Entwurf gespeichert");
}

async function connect() {
  setStatus("Lade …");
  state.catalog = await call("catalog");
  const variables = await call("variables");
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
  setStatus("Bereit");
}

$("new-layout").addEventListener("click", () => {
  selectLayout({ id: "", name: "Neue Ansicht", version: 0, published: false, columns: 2, pages: [{ id: "start", title: "Start", tiles: [] }] });
});

$("add-tile").addEventListener("click", () => {
  if (!state.layout) return;
  const tiles = currentPage().tiles;
  const columns = Number($("columns").value) || 2;
  const tile = {
    tileId: `t${Date.now()}`,
    icon: $("tile-icon").value,
    title: $("tile-title").value || "Kachel",
    col: tiles.length % columns,
    row: Math.floor(tiles.length / columns),
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
  if (!state.layout) return;
  currentPage().tiles = currentPage().tiles.filter((tile) => tile.tileId !== state.selected);
  state.selected = null;
  renderGrid();
});

$("save").addEventListener("click", () => store(false).catch((error) => setStatus(error.message)));
$("publish").addEventListener("click", () => store(true).catch((error) => setStatus(error.message)));
$("columns").addEventListener("change", renderGrid);

connect().catch((error) => setStatus(error.message));
