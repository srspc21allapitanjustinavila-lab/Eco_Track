// Runs real PHP pages against a disposable local MySQL database and headless Chrome.
const { spawn, spawnSync } = require('child_process');
const { randomBytes } = require('crypto');
const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '..');
const tmpRoot = path.join(root, '.tmp');
const suffix = randomBytes(6).toString('hex');
const database = 'ecotrack_attendance_browser_' + suffix;
const testDir = path.join(tmpRoot, 'attendance-browser-' + suffix);
const sessionDir = path.join(testDir, 'sessions');
const php = 'C:\\xampp\\php\\php.exe';
const chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const adminSession = randomBytes(16).toString('hex');
const staffSession = randomBytes(16).toString('hex');
const port = 18871;
const debugPort = 18872;
const base = 'http://127.0.0.1:' + port;
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
let server, browser, ws, nextId = 0, checks = 0;
const pending = new Map();

function check(value, message) { if (!value) throw new Error(message); checks++; }
function setup(action) {
  const result = spawnSync(php, [path.join(root, 'tests/bio_attendance_browser_setup.php'), action, database, sessionDir, adminSession, staffSession], { cwd: root, encoding: 'utf8' });
  if (result.status !== 0) throw new Error('Fixture ' + action + ' failed: ' + result.stderr + result.stdout);
}
async function waitFor(fn, description) {
  for (let i = 0; i < 100; i++) { try { const result = await fn(); if (result) return result; } catch (_) {} await sleep(100); }
  throw new Error('Timed out: ' + description);
}
function send(method, params = {}) {
  const id = ++nextId;
  ws.send(JSON.stringify({ id, method, params }));
  return new Promise((resolve, reject) => pending.set(id, { resolve, reject }));
}
async function evaluate(expression) {
  const reply = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
  if (reply.exceptionDetails) throw new Error(reply.exceptionDetails.exception?.description || reply.exceptionDetails.text);
  return reply.result.value;
}
async function load(route, session) {
  await send('Network.setCookie', { name: 'PHPSESSID', value: session, url: base });
  await send('Page.navigate', { url: base + '/' + route });
  await waitFor(async () => (await evaluate('document.readyState === "complete" && location.pathname.endsWith(' + JSON.stringify('/' + route) + ') && !!document.getElementById("attendance-filter-form")')), route);
}
async function waitMessage() {
  try { await waitFor(async () => await evaluate('!!document.querySelector(".attendance-message, .attendance-error")'), 'attendance response'); }
  catch (error) { throw new Error(error.message + ': ' + JSON.stringify(await evaluate('({url:location.href,title:document.title,body:document.body.innerText.slice(0,700)})'))); }
}
async function main() {
  fs.mkdirSync(sessionDir, { recursive: true });
  setup('setup');
  server = spawn(php, ['-d', 'session.save_path=' + sessionDir, '-S', '127.0.0.1:' + port, '-t', root], { cwd: root, env: { ...process.env, DB_NAME: database }, stdio: 'ignore' });
  await waitFor(async () => { const response = await fetch(base + '/login.php'); await response.text(); return response.ok; }, 'PHP server');
  browser = spawn(chrome, ['--headless=new', '--no-sandbox', '--disable-gpu', '--disable-extensions', '--remote-allow-origins=*', '--remote-debugging-port=' + debugPort, '--user-data-dir=' + path.join(testDir, 'profile'), 'about:blank'], { stdio: 'ignore' });
  const target = await waitFor(async () => (await (await fetch('http://127.0.0.1:' + debugPort + '/json/list')).json()).find(x => x.type === 'page'), 'Chrome');
  ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
  ws.onmessage = event => { const msg = JSON.parse(event.data); if (!msg.id || !pending.has(msg.id)) return; const p = pending.get(msg.id); pending.delete(msg.id); msg.error ? p.reject(new Error(msg.error.message)) : p.resolve(msg.result); };
  await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
  await load('staff_attendance.php', staffSession);
  check(await evaluate('document.querySelector(".attendance-unrecorded").textContent.includes("35 Not Recorded")'), 'Staff sees all 35 imported collectors.');
  check(await evaluate('JSON.stringify(JSON.parse(document.getElementById("attendance-date").dataset.calendarYears).sort()) === JSON.stringify(Array.from(new Set([2025,Number(document.getElementById("attendance-date").dataset.yearPickerToday.slice(0,4))])).sort())'), 'Current year is available even before it has attendance or waste records.');
  check(await evaluate('!Array.from(document.querySelectorAll(".attendance-table th")).some(th=>/\\bID\\b/i.test(th.textContent)) && !document.querySelector("#attendance-search") && !!document.querySelector("#history-search")'), 'Collector filters are in history and IDs are not visible.');
  check(await evaluate('!document.querySelector("[data-delete-id]")'), 'Staff sees no delete controls.');
  check(await evaluate('!!document.querySelector(".attendance-pagination a")'), 'Pagination is present.');
  await evaluate('document.querySelector(".attendance-pagination a").click()');
  await waitFor(async () => await evaluate('!!document.querySelector(".attendance-pagination") && document.querySelector(".attendance-pagination").textContent.includes("Page 2 of 2")'), 'next page');
  check(await evaluate('document.querySelectorAll(".attendance-name").length === 5'), 'Next page shows remaining collectors.');
  await evaluate('document.querySelector(".attendance-pagination a").click()');
  await waitFor(async () => await evaluate('!!document.querySelector(".attendance-pagination") && document.querySelector(".attendance-pagination").textContent.includes("Page 1 of 2")'), 'previous page');
  check(await evaluate('document.querySelectorAll(".attendance-name").length === 30'), 'Previous page returns to the first page.');
  await evaluate('document.querySelector("select[data-attendance-edit]").value="present"; document.querySelector("select[data-attendance-edit]").dispatchEvent(new Event("change", {bubbles:true})); document.getElementById("attendance-save-form").requestSubmit();');
  await waitMessage();
  check(await evaluate('document.querySelector(".attendance-message").textContent.includes("saved") && document.querySelector(".attendance-present strong").textContent === "1"'), 'Staff saves and summary refreshes.');
  await load('admin_attendance.php', adminSession);
  check(await evaluate('document.querySelector(".attendance-present strong").textContent === "1" && !!document.querySelector("[data-delete-id]")'), 'Admin sees Staff attendance.');
  await evaluate('document.querySelector("select[data-attendance-edit]").value="late"; document.querySelector("select[data-attendance-edit]").dispatchEvent(new Event("change",{bubbles:true})); document.querySelector("input[data-attendance-edit]").value="Correction"; document.getElementById("attendance-save-form").requestSubmit()');
  await waitFor(async () => await evaluate('!!document.querySelector(".attendance-late strong") && document.querySelector(".attendance-late strong").textContent === "1"'), 'admin edit');
  check(await evaluate('document.querySelector("input[data-attendance-edit]").value === "Correction"'), 'Admin edits status and remarks.');
  await evaluate('document.querySelector("[data-delete-id]").click()');
  check(await evaluate('document.getElementById("attendance-delete-dialog").open'), 'Delete opens confirmation.');
  await evaluate('document.querySelector("[data-close-delete]").click()');
  check(await evaluate('!document.getElementById("attendance-delete-dialog").open && document.querySelector(".attendance-late strong").textContent === "1"'), 'Cancel preserves attendance.');
  await evaluate('document.querySelector("[data-delete-id]").click(); document.getElementById("attendance-delete-form").requestSubmit()');
  await waitFor(async () => await evaluate('!!document.querySelector(".attendance-unrecorded") && document.querySelector(".attendance-unrecorded").textContent.includes("35 Not Recorded")'), 'admin delete');
  check(await evaluate('document.querySelector(".attendance-unrecorded").textContent.includes("35 Not Recorded")'), 'Admin confirms deletion and summary updates.');
  const staffPage = await fetch(base + '/staff_attendance.php', { headers: { Cookie: 'PHPSESSID=' + staffSession } });
  const html = await staffPage.text();
  const token = /name="attendance_csrf" value="([^"]+)"/.exec(html)[1];
  const forbidden = await fetch(base + '/staff_attendance.php', { method: 'POST', redirect: 'manual', headers: { Cookie: 'PHPSESSID=' + staffSession, 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ attendance_csrf: token, action: 'delete', record_id: '1', record_version: '1' }) });
  check(forbidden.status === 403, 'Staff deletion is rejected by the actual HTTP endpoint.');
  await forbidden.text();
  await evaluate('document.querySelector("select[data-attendance-edit]").value="absent"; document.querySelector("select[data-attendance-edit]").dispatchEvent(new Event("change",{bubbles:true})); document.getElementById("attendance-save-form").requestSubmit()');
  await waitFor(async () => await evaluate('!!document.querySelector(".attendance-absent strong") && document.querySelector(".attendance-absent strong").textContent === "1"'), 'restore attendance');
  await load('staff_attendance.php', staffSession);
  check(await evaluate('document.querySelector(".attendance-absent strong").textContent === "1"'), 'Staff sees restored Admin attendance.');
  await evaluate('document.querySelector("select[data-attendance-edit]").value="on_leave"; document.querySelector("select[data-attendance-edit]").dispatchEvent(new Event("change",{bubbles:true})); document.getElementById("attendance-save-form").requestSubmit()');
  await waitFor(async () => await evaluate('!!document.querySelector(".attendance-on_leave strong") && document.querySelector(".attendance-on_leave strong").textContent === "1"'), 'staff edit');
  check(await evaluate('document.querySelector(".attendance-on_leave strong").textContent === "1"'), 'Staff can correct Admin attendance.');
  await load('admin_attendance.php', adminSession);
  const selectedDate = await evaluate('document.getElementById("attendance-date").value');
  const adminToken = await evaluate('document.querySelector("#attendance-save-form [name=attendance_csrf]").value');
  const exportForm = code => new URLSearchParams({attendance_csrf:adminToken,action:'export',attendance_date:selectedDate,history_search:'Collector 01',history_status:'on_leave',export_otp:code});
  const exportOptions = {method:'POST',headers:{Cookie:'PHPSESSID='+adminSession,'Content-Type':'application/x-www-form-urlencoded'}};
  const badExport = await fetch(base + '/admin_attendance.php', {...exportOptions,body:exportForm('000000')});
  check((await badExport.text()).includes('verification code is invalid'), 'Export rejects an invalid privacy verification code.');
  const exportResponse = await fetch(base + '/admin_attendance.php', {...exportOptions,body:exportForm('123456')});
  const exportPath = path.join(testDir, 'attendance.xlsx');
  fs.writeFileSync(exportPath, Buffer.from(await exportResponse.arrayBuffer()));
  const inspect = spawnSync(php, ['-r', '$z=new ZipArchive();if($z->open($argv[1])!==true)exit(1);$s=$z->getFromName("xl/worksheets/sheet1.xml");echo json_encode([strpos($s,"Collector ID")===false,strpos($s,"Collector 01")!==false,strpos($s,"Collector 03")===false]);', exportPath], {encoding:'utf8'});
  check(exportResponse.ok && inspect.status === 0 && inspect.stdout === '[true,true,true]', 'Verified export excludes Collector ID and respects the applied filters.');
  for (const [status, expected] of [['present', 0], ['absent', 0], ['late', 0], ['on_leave', 1]]) {
    const params = new URLSearchParams({ attendance_date: selectedDate, history_status: status });
    const response = await fetch(base + '/admin_attendance.php?' + params, { headers: { Cookie: 'PHPSESSID=' + adminSession } });
    const filtered = await response.text();
    check(response.ok && filtered.includes(expected + ' records'), 'History filter returns correct results for ' + status + '.');
  }
  await evaluate('document.getElementById("history-search").value="Collector 01";document.getElementById("history-status").value="on_leave";document.querySelector("[data-attendance-filter]").requestSubmit()');
  await waitFor(async () => await evaluate('new URLSearchParams(location.search).get("history_search") === "Collector 01" && !!document.querySelector("#attendance-history tbody") && document.querySelector("#attendance-history .attendance-pagination").textContent.includes("1 records") && document.querySelector("#attendance-history tbody").textContent.includes("Collector 01")'), 'collector history filters');
  check(await evaluate('document.querySelector("#attendance-save-form .attendance-name").textContent.includes("Collector 01") && document.querySelector(".attendance-unrecorded").textContent.includes("35 matching collectors")'), 'History filters leave the daily roster intact.');
  check(await evaluate('document.querySelector(".attendance-print-form [name=history_search]").value === "Collector 01" && document.querySelector(".attendance-print-form [name=history_status]").value === "on_leave"'), 'Print form carries the applied history filters.');
  await evaluate('document.querySelector("#attendance-history [data-attendance-navigation]").click()');
  await waitFor(async () => await evaluate('!new URLSearchParams(location.search).has("history_search") && !!document.getElementById("history-search") && document.getElementById("history-search").value === "" && document.getElementById("history-status").value === ""'), 'collector history reset');
  for (const width of [1920, 1440, 1366, 1024, 820, 768, 425, 375, 320]) {
    await send('Emulation.setDeviceMetricsOverride', { width, height: 800, deviceScaleFactor: 1, mobile: false });
    await sleep(300);
    await evaluate('document.getElementById("print-period").value="month";document.getElementById("print-period").dispatchEvent(new Event("change",{bubbles:true}))');
    check(await evaluate(`(()=>{let r=document.getElementById('print-month').getBoundingClientRect();return r.width>0&&r.left>=-1&&r.right<=innerWidth+1&&document.getElementById('print-week-field').hidden})()`), 'Month picker fits at ' + width + 'px.');
    check(await evaluate(`Array.from(document.querySelectorAll('#attendance-history .attendance-toolbar input:not([type=hidden]), #attendance-history .attendance-toolbar select, #attendance-history .attendance-toolbar button')).every(e=>{let r=e.getBoundingClientRect();return !r.width||(r.left>=-1&&r.right<=innerWidth+1)})`), 'History controls fit at ' + width + 'px.');
    await evaluate('document.getElementById("print-period").value="week";document.getElementById("print-period").dispatchEvent(new Event("change",{bubbles:true}))');
    await evaluate('document.querySelector("[data-delete-id]").click()');
    const modalFits = await evaluate(`(()=>{let d=document.getElementById('attendance-delete-dialog').getBoundingClientRect();return d.left>=-1&&d.right<=innerWidth+1&&d.top>=-1&&d.bottom<=innerHeight+1&&Array.from(document.querySelectorAll('.attendance-dialog-actions button')).every(b=>{let r=b.getBoundingClientRect();return r.left>=d.left&&r.right<=d.right&&r.bottom<=d.bottom})})()`);
    check(modalFits, 'Delete modal overflows at ' + width + 'px.');
    await evaluate('document.querySelector("[data-close-delete]").click()');
    if (width < 900) await evaluate('document.querySelector("[data-sidebar-toggle]").click()');
    await evaluate('document.querySelector("[data-logout-trigger]").click()');
    check(await evaluate(`(()=>{let d=document.getElementById('logoutModal');let r=d.querySelector('.shared-logout-content').getBoundingClientRect();return d.classList.contains('show')&&r.left>=-1&&r.right<=innerWidth+1&&r.top>=-1&&r.bottom<=innerHeight+1&&Array.from(d.querySelectorAll('button,a')).every(b=>{let x=b.getBoundingClientRect();return x.left>=r.left-1&&x.right<=r.right+1&&x.bottom<=r.bottom+1})})()`), 'Logout dialog or controls overflow at ' + width + 'px.');
    await evaluate('document.querySelector("[data-logout-cancel]").click()');
    check(await evaluate('!document.getElementById("logoutModal").classList.contains("show")'), 'Logout cancellation at ' + width + 'px.');
    await evaluate('document.querySelector(".year-picker-trigger").click()');
    const geometry = await evaluate(`(() => { const p=document.querySelector('.year-picker-popover:not([hidden])'); const r=p&&p.getBoundingClientRect(); return { page:document.documentElement.scrollWidth<=innerWidth+1, scroll:document.documentElement.scrollWidth, viewport:innerWidth, calendar:!!r&&r.left>=-1&&r.right<=innerWidth+1&&r.top>=-1&&r.bottom<=innerHeight+1, rect:r&&r.toJSON() }; })()`);
    if (!geometry.page) geometry.offenders = await evaluate(`(()=>{let w=document.querySelector('.attendance-table-wrap');w.style.display='none';let noTable=document.documentElement.scrollWidth;w.style.display='';let p=document.querySelector('.year-picker-popover:not([hidden])');p.hidden=true;let noCalendar=document.documentElement.scrollWidth;p.hidden=false;return {noTable,noCalendar,body:document.body.getBoundingClientRect().toJSON(),html:document.documentElement.getBoundingClientRect().toJSON(), elements:Array.from(document.querySelectorAll('body *')).filter(e=>{const r=e.getBoundingClientRect();return r.right>document.documentElement.clientWidth+1 && !e.closest('.attendance-table-wrap')}).slice(0,20).map(e=>[e.tagName,e.className,e.getBoundingClientRect().toJSON()])}})()`);
    check(geometry.page && geometry.calendar, 'Page or calendar overflow at ' + width + 'px: ' + JSON.stringify(geometry));
    await evaluate('document.dispatchEvent(new KeyboardEvent("keydown",{key:"Escape",bubbles:true}))');
  }
  await evaluate('document.querySelector(".year-picker-trigger").click()');
  check(await evaluate('!!document.querySelector(".year-picker-popover:not([hidden]) .year-picker-year") && !!document.querySelector(".year-picker-popover:not([hidden]) .year-picker-month")'), 'Calendar provides year and month selection.');
  check(await evaluate('JSON.stringify(Array.from(document.querySelector(".year-picker-popover:not([hidden]) .year-picker-year").options).map(o=>Number(o.value)).sort()) === JSON.stringify(Array.from(new Set([2025,Number(document.querySelector("#attendance-date").dataset.yearPickerToday.slice(0,4))])).sort())'), 'Calendar years contain only data years and the current year.');
  check(await evaluate('!!document.querySelector(".year-picker-popover:not([hidden]) .year-picker-grid.days button[aria-current=date]")'), 'Calendar distinguishes today.');
  const before = await evaluate('document.querySelector(".year-picker-popover:not([hidden]) .year-picker-caption").textContent');
  await evaluate('document.querySelector(".year-picker-popover:not([hidden]) .year-picker-nav").click()');
  check((await evaluate('document.querySelector(".year-picker-popover:not([hidden]) .year-picker-caption").textContent')) !== before, 'Previous month navigation works.');
  await evaluate('document.querySelectorAll(".year-picker-popover:not([hidden]) .year-picker-nav")[1].click()');
  check((await evaluate('document.querySelector(".year-picker-popover:not([hidden]) .year-picker-caption").textContent')) === before, 'Next month navigation works.');
  await evaluate('document.querySelector(".year-picker-popover:not([hidden]) .year-picker-month").value="0"; document.querySelector(".year-picker-popover:not([hidden]) .year-picker-month").dispatchEvent(new Event("change",{bubbles:true}))');
  check((await evaluate('document.querySelector(".year-picker-popover:not([hidden]) .year-picker-caption").textContent')).startsWith('January '), 'Month dropdown changes the calendar.');
  await evaluate('document.querySelector(".year-picker-popover:not([hidden]) .year-picker-year").value="2025"; document.querySelector(".year-picker-popover:not([hidden]) .year-picker-year").dispatchEvent(new Event("change",{bubbles:true})); document.querySelector(".year-picker-popover:not([hidden]) .year-picker-grid.days button:not(:disabled)").click()');
  check(await evaluate('document.getElementById("attendance-date").value.startsWith("2025-") && document.querySelector("#attendance-filters-heading").textContent.includes(' + JSON.stringify(selectedDate) + ')'), 'Selecting a date waits for Apply Filter.');
  await evaluate('document.getElementById("attendance-filter-form").requestSubmit()');
  await waitFor(async () => await evaluate('!!document.querySelector("#attendance-filters-heading") && document.querySelector("#attendance-filters-heading").textContent.includes("2025-")'), 'calendar date change');
  check(await evaluate('document.getElementById("attendance-date").value.startsWith("2025-") && document.querySelector(".attendance-unrecorded").textContent.includes("35 Not Recorded")'), 'Selected date loads the unfiltered collector roster.');
  await evaluate('document.getElementById("attendance-date").value=' + JSON.stringify(selectedDate) + ';document.getElementById("attendance-filter-form").requestSubmit()');
  await waitFor(async () => await evaluate('!!document.querySelector(".attendance-on_leave strong") && document.querySelector(".attendance-on_leave strong").textContent === "1"'), 'return to today');
  check(await evaluate('document.getElementById("attendance-date").value === ' + JSON.stringify(selectedDate)), 'Date selector returns to today.');
  await evaluate('window.confirmCalls=0;window.confirm=()=>{window.confirmCalls++;return false};document.querySelector("select[data-attendance-edit]").value="late";document.querySelector("select[data-attendance-edit]").dispatchEvent(new Event("change",{bubbles:true}));document.getElementById("history-search").value="Collector 02";document.querySelector("[data-attendance-filter]").requestSubmit();');
  check(await evaluate('window.confirmCalls===1 && document.querySelector(".attendance-on_leave strong").textContent==="1" && document.getElementById("history-search").value==="Collector 02"'), 'Cancelling an unsaved-change warning keeps edits and staged filters.');
  await evaluate('document.querySelector("#attendance-history [data-attendance-navigation]").click()');
  check(await evaluate('window.confirmCalls===2'), 'Reset also warns before discarding changes.');
  await evaluate('document.getElementById("attendance-save-form").requestSubmit()');
  await waitMessage();
  await load('staff_attendance.php', staffSession);
  if (await evaluate('innerWidth<900')) await evaluate('document.querySelector("[data-sidebar-toggle]").click()');
  await evaluate('document.querySelector("[data-logout-trigger]").click()');
  check(await evaluate('document.getElementById("logoutModal").classList.contains("show") && document.getElementById("logoutModalTitle").textContent === "Confirm logout" && !!document.querySelector(".shared-logout-header")'), 'Staff opens the original shared logout confirmation.');
  await send('Input.dispatchKeyEvent', {type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
  await send('Input.dispatchKeyEvent', {type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
  check(await evaluate('!document.getElementById("logoutModal").classList.contains("show")'), 'Escape closes the logout confirmation.');
  if (await evaluate('innerWidth<900')) await evaluate('document.querySelector("[data-sidebar-toggle]").click()');
  await evaluate('document.querySelector("[data-logout-trigger]").click(); document.querySelector("#logoutModal a[href*=logout]").click()');
  await waitFor(async () => await evaluate('location.pathname.endsWith("/login.php")'), 'staff logout');
  check(await evaluate('location.pathname.endsWith("/login.php")'), 'Staff confirmation signs out.');
  await load('admin_attendance.php', adminSession);
  if (await evaluate('innerWidth<900')) await evaluate('document.querySelector("[data-sidebar-toggle]").click()');
  await evaluate('document.querySelector("[data-logout-trigger]").click(); document.querySelector("#logoutModal a[href*=logout]").click()');
  await waitFor(async () => await evaluate('location.pathname.endsWith("/login.php")'), 'admin logout');
  check(await evaluate('location.pathname.endsWith("/login.php")'), 'Admin confirmation signs out.');
  console.log('PASS: ' + checks + ' live attendance browser checks (9 viewport widths)');
}
main().catch(error => { console.error('FAIL:', error.stack || error); process.exitCode = 1; }).finally(() => {
  if (ws) ws.close();
  if (browser) browser.kill();
  if (server) server.kill();
  try { setup('cleanup'); } catch (error) { console.error(error.message); process.exitCode = 1; }
  const resolved = path.resolve(testDir);
  if (resolved.startsWith(path.resolve(tmpRoot) + path.sep) && path.basename(resolved) === 'attendance-browser-' + suffix) {
    try { fs.rmSync(resolved, { recursive: true, force: true, maxRetries: 3, retryDelay: 200 }); } catch (_) {}
  }
});
