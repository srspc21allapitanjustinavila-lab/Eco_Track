// Real staff/admin attendance pages in an isolated database and headless Chrome.
const { spawn, spawnSync } = require('child_process');
const { randomBytes } = require('crypto');
const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '..');
const tmpRoot = path.join(root, '.tmp');
const suffix = randomBytes(6).toString('hex');
const database = 'ecotrack_attendance_browser_' + suffix;
const testDir = path.join(tmpRoot, 'staff-attendance-browser-' + suffix);
const sessionDir = path.join(testDir, 'sessions');
const php = 'C:\\xampp\\php\\php.exe';
const chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const adminSession = randomBytes(16).toString('hex');
const staffSession = randomBytes(16).toString('hex');
const port = 18881, debugPort = 18882, base = 'http://127.0.0.1:' + port;
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
let server, browser, ws, nextId = 0, checks = 0;
const pending = new Map();
function check(value, message) { if (!value) throw new Error(message); checks++; }
function setup(action) {
  const r = spawnSync(php, [path.join(root, 'tests/bio_attendance_browser_setup.php'), action, database, sessionDir, adminSession, staffSession], {cwd:root, encoding:'utf8'});
  if (r.status !== 0) throw new Error('Fixture ' + action + ': ' + r.stderr + r.stdout);
}
async function waitFor(fn, description) {
  for (let i = 0; i < 100; i++) { try { if (await fn()) return; } catch (_) {} await sleep(100); }
  throw new Error('Timed out: ' + description);
}
function send(method, params = {}) {
  const id = ++nextId; ws.send(JSON.stringify({id, method, params}));
  return new Promise((resolve, reject) => pending.set(id, {resolve, reject}));
}
async function evaluate(expression) {
  const reply = await send('Runtime.evaluate', {expression, returnByValue:true, awaitPromise:true});
  if (reply.exceptionDetails) throw new Error(reply.exceptionDetails.exception?.description || reply.exceptionDetails.text);
  return reply.result.value;
}
async function load(route, session) {
  await send('Network.setCookie', {name:'PHPSESSID', value:session, url:base});
  await send('Page.navigate', {url:base + '/' + route});
  await waitFor(async () => await evaluate('document.readyState === "complete" && location.pathname.endsWith(' + JSON.stringify('/' + route) + ') && !!document.getElementById("attendance-filter-form")'), route);
}
async function main() {
  fs.mkdirSync(sessionDir, {recursive:true});
  setup('setup');
  const seed = spawnSync(php, [path.join(root, 'tests/staff_attendance_browser_seed.php'), database], {cwd:root, encoding:'utf8'});
  if (seed.status !== 0) throw new Error('Staff fixture: ' + seed.stderr);
  server = spawn(php, ['-d', 'session.save_path=' + sessionDir, '-S', '127.0.0.1:' + port, '-t', root], {cwd:root, env:{...process.env, DB_NAME:database}, stdio:'ignore'});
  await waitFor(async () => { const r = await fetch(base + '/login.php'); await r.text(); return r.ok; }, 'PHP server');
  browser = spawn(chrome, ['--headless=new', '--no-sandbox', '--disable-gpu', '--disable-extensions', '--remote-allow-origins=*', '--remote-debugging-port=' + debugPort, '--user-data-dir=' + path.join(testDir, 'profile'), 'about:blank'], {stdio:'ignore'});
  let target;
  await waitFor(async () => { target = (await (await fetch('http://127.0.0.1:' + debugPort + '/json/list')).json()).find(x => x.type === 'page'); return target; }, 'Chrome');
  ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => {ws.onopen = resolve; ws.onerror = reject;});
  ws.onmessage = event => {const msg=JSON.parse(event.data); if(!msg.id || !pending.has(msg.id)) return; const p=pending.get(msg.id); pending.delete(msg.id); msg.error ? p.reject(new Error(msg.error.message)) : p.resolve(msg.result);};
  await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
  await load('staff_legacy_attendance.php', staffSession);
  check(await evaluate('document.querySelector("h1").textContent === "Staff Attendance" && document.querySelector(".attendance-unrecorded").textContent.includes("35 matching staff") && document.querySelectorAll("#attendance-save-form .attendance-name").length === 30'), 'Staff roster is populated before recording and paginated.');
  check(await evaluate('document.querySelector("#attendance-history").textContent.includes("Former Worker") && document.querySelector("#attendance-history").textContent.includes("Inactive")'), 'Inactive staff history remains visible.');
  check(await evaluate('!document.querySelector("[data-delete-id]") && !document.querySelector(".attendance-export")'), 'Staff sees no delete or export action.');
  check(await evaluate('JSON.stringify(JSON.parse(document.getElementById("attendance-date").dataset.calendarYears).sort()) === JSON.stringify(Array.from(new Set([2020,2025,Number(document.getElementById("attendance-date").dataset.yearPickerToday.slice(0,4))])).sort())'), 'Years include active waste, staff history and current year.');
  await evaluate('document.querySelector(".attendance-pagination a").click()');
  await waitFor(async () => await evaluate('document.querySelector(".attendance-pagination").textContent.includes("Page 2 of 2")'), 'page 2');
  check(await evaluate('document.querySelectorAll("#attendance-save-form .attendance-name").length === 5 && document.querySelector(".attendance-unrecorded").textContent.includes("35 matching staff")'), 'Pagination keeps counts across the full roster.');
  await evaluate('document.querySelector(".attendance-pagination a").click()');
  await waitFor(async () => await evaluate('document.querySelector(".attendance-pagination").textContent.includes("Page 1 of 2")'), 'page 1');
  const initialDate = await evaluate('document.getElementById("attendance-date").value');
  await evaluate(`document.querySelector('#attendance-filter-form .year-picker-trigger').click();let p=document.querySelector('.year-picker-popover:not([hidden])');p.querySelector('.year-picker-year').value='2025';p.querySelector('.year-picker-year').dispatchEvent(new Event('change',{bubbles:true}));p.querySelector('.year-picker-month').value='0';p.querySelector('.year-picker-month').dispatchEvent(new Event('change',{bubbles:true}));p.querySelector('.year-picker-grid.days button[aria-label="January 1, 2025"]').click()`);
  const dateStage = await evaluate('({date:document.getElementById("attendance-date").value,heading:document.getElementById("attendance-filters-heading").textContent})');
  check(dateStage.date === '2025-01-01' && dateStage.heading.includes(initialDate), 'Calendar date stays staged until Apply: ' + JSON.stringify(dateStage));
  await evaluate('document.getElementById("attendance-search").value="Staff Tester";document.getElementById("attendance-status").value="present";document.getElementById("attendance-filter-form").requestSubmit()');
  await waitFor(async () => await evaluate('document.getElementById("attendance-filters-heading").textContent.includes("2025-01-01")'), 'historical date and filters');
  check(await evaluate('document.querySelector("#attendance-save-form .attendance-name").textContent === "Staff Tester" && document.querySelector(".attendance-present strong").textContent === "1"'), 'Date, name and status apply together to historical staff attendance.');
  await evaluate('document.querySelector("#attendance-filter-form [data-attendance-navigation]").click()');
  await waitFor(async () => await evaluate('document.getElementById("attendance-date").value === ' + JSON.stringify(initialDate) + ' && document.querySelector(".attendance-unrecorded").textContent.includes("35 matching staff")'), 'reset historical date');
  check(await evaluate('document.getElementById("attendance-search").value === "" && document.getElementById("attendance-status").value === ""'), 'Reset restores today, name and status.');
  await evaluate('document.getElementById("attendance-search").value="Staff 03";document.getElementById("attendance-status").value="not_recorded";document.getElementById("attendance-filter-form").requestSubmit()');
  await waitFor(async () => await evaluate('document.querySelector(".attendance-unrecorded").textContent.includes("1 matching staff")'), 'staff search and status');
  check(await evaluate('document.querySelector("#attendance-save-form .attendance-name").textContent === "Staff 03"'), 'Name and status apply together.');
  await evaluate('document.querySelector("select[data-attendance-edit]").value="present";document.querySelector("select[data-attendance-edit]").dispatchEvent(new Event("change",{bubbles:true}));document.querySelector("input[data-attendance-edit]").value="On time";document.getElementById("attendance-save-form").requestSubmit()');
  await waitFor(async () => await evaluate('!!document.querySelector(".attendance-message") && document.querySelector(".attendance-message").textContent.includes("saved")'), 'staff save');
  check(await evaluate('document.querySelector(".attendance-unrecorded").textContent.includes("0 matching staff")'), 'Saved status removes row from Not Recorded filter.');
  await evaluate('document.querySelector("#attendance-filter-form [data-attendance-navigation]").click()');
  await waitFor(async () => await evaluate('document.querySelector(".attendance-present strong").textContent === "1"'), 'reset filter');
  check(await evaluate('document.getElementById("attendance-search").value === "" && document.getElementById("attendance-status").value === ""'), 'Reset clears filters and reloads current attendance.');
  const date = await evaluate('document.getElementById("attendance-date").value');
  const csrf = await evaluate('document.querySelector("#attendance-save-form [name=attendance_csrf]").value');
  const forbidden = await fetch(base + '/staff_legacy_attendance.php', {method:'POST',headers:{Cookie:'PHPSESSID='+staffSession,'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({attendance_csrf:csrf,action:'delete',attendance_date:date,record_id:'1',record_version:'1'})});
  check(forbidden.status === 403, 'Staff delete is forbidden at HTTP endpoint.'); await forbidden.text();
  const invalidCsrf = await fetch(base + '/staff_legacy_attendance.php', {method:'POST',headers:{Cookie:'PHPSESSID='+staffSession,'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({attendance_csrf:'bad',action:'save',attendance_date:date})});
  check((await invalidCsrf.text()).includes('form expired'), 'Invalid CSRF is rejected.');
  await load('admin_legacy_attendance.php', adminSession);
  check(await evaluate('document.querySelector(".attendance-present strong").textContent === "1" && !!document.querySelector("[data-delete-id]")'), 'Admin sees Staff entry and delete control.');
  const staleToken = await evaluate('document.querySelector("#attendance-save-form [name=attendance_csrf]").value');
  const staleBody = new URLSearchParams({attendance_csrf:staleToken,action:'save',attendance_date:date,'attendance[3][status]':'late','attendance[3][remarks]':'stale','attendance[3][version]':'0'});
  const staleResponse = await fetch(base + '/admin_legacy_attendance.php', {method:'POST',headers:{Cookie:'PHPSESSID='+adminSession,'Content-Type':'application/x-www-form-urlencoded'},body:staleBody});
  check(staleResponse.status === 409 && (await staleResponse.text()).includes('changed in another session'), 'The HTTP endpoint rejects a concurrent stale edit.');
  for (const [status, expected] of [['present',1],['absent',0],['late',0],['on_leave',0],['not_recorded',34]]) {
    const response = await fetch(base + '/admin_legacy_attendance.php?' + new URLSearchParams({attendance_date:date,status}), {headers:{Cookie:'PHPSESSID='+adminSession}});
    check(response.ok && (await response.text()).includes(expected + ' matching staff'), 'Correct filtered total for ' + status + '.');
  }
  await evaluate('document.querySelector("[data-delete-id]").click()');
  check(await evaluate('document.getElementById("attendance-delete-dialog").open'), 'Delete asks for confirmation.');
  await evaluate('document.querySelector("[data-close-delete]").click()');
  check(await evaluate('!document.getElementById("attendance-delete-dialog").open && document.querySelector(".attendance-present strong").textContent === "1"'), 'Cancel preserves the record.');
  await evaluate('document.querySelector("select[data-attendance-edit]").value="late";document.querySelector("select[data-attendance-edit]").dispatchEvent(new Event("change",{bubbles:true}));document.getElementById("attendance-save-form").requestSubmit()');
  await waitFor(async () => await evaluate('!!document.querySelector(".attendance-late strong") && document.querySelector(".attendance-late strong").textContent === "1"'), 'admin edit');
  check(await evaluate('document.querySelector("#attendance-history").textContent.includes("Staff 03")'), 'Admin edit is reflected in history.');
  const adminToken = await evaluate('document.querySelector("#attendance-save-form [name=attendance_csrf]").value');
  const exportForm = code => new URLSearchParams({attendance_csrf:adminToken, action:'export', attendance_date:date, history_search:'Former Worker', history_status:'late', export_otp:code});
  const exportOptions = {method:'POST',headers:{Cookie:'PHPSESSID='+adminSession,'Content-Type':'application/x-www-form-urlencoded'}};
  const badExport = await fetch(base + '/admin_legacy_attendance.php', {...exportOptions,body:exportForm('000000')});
  check((await badExport.text()).includes('verification code is invalid'), 'Invalid export verification is rejected.');
  const exportResponse = await fetch(base + '/admin_legacy_attendance.php', {...exportOptions,body:exportForm('123456')});
  const exportPath = path.join(testDir, 'staff-history.xlsx'); fs.writeFileSync(exportPath, Buffer.from(await exportResponse.arrayBuffer()));
  const inspect = spawnSync(php, ['-r', '$z=new ZipArchive();if($z->open($argv[1])!==true)exit(1);$s=$z->getFromName("xl/worksheets/sheet1.xml");echo json_encode([strpos($s,"Former Worker")!==false,strpos($s,"Staff 03")===false]);', exportPath], {encoding:'utf8'});
  check(exportResponse.ok && inspect.status === 0 && inspect.stdout === '[true,true]', 'Verified history export includes inactive staff and respects filters.');
  await evaluate('document.getElementById("history-search").value="Former Worker";document.getElementById("history-status").value="late";document.querySelector("[data-attendance-filter]").requestSubmit()');
  await waitFor(async () => await evaluate('document.querySelector("#attendance-history .attendance-pagination").textContent.includes("1 records")'), 'history filters');
  check(await evaluate('document.querySelector("#attendance-history tbody").textContent.includes("Former Worker") && !document.querySelector("#attendance-history tbody").textContent.includes("Staff 03")'), 'History filters combine correctly.');
  await evaluate('document.querySelector("#attendance-history [data-attendance-navigation]").click()');
  await waitFor(async () => await evaluate('document.querySelector("#attendance-history tbody").textContent.includes("Staff 03")'), 'history reset');
  check(await evaluate('document.getElementById("history-search").value === "" && document.getElementById("history-status").value === ""'), 'History Reset clears its filters.');
  await evaluate('window.confirmCalls=0;window.confirm=()=>{window.confirmCalls++;return false};document.querySelector("select[data-attendance-edit]").value="absent";document.querySelector("select[data-attendance-edit]").dispatchEvent(new Event("change",{bubbles:true}));document.querySelector("[data-attendance-filter]").requestSubmit()');
  check(await evaluate('window.confirmCalls === 1'), 'History filter warns about unsaved attendance.');
  await evaluate('document.getElementById("attendance-save-form").requestSubmit()');
  await waitFor(async () => await evaluate('!!document.querySelector(".attendance-absent strong") && document.querySelector(".attendance-absent strong").textContent === "1"'), 'save before layout checks');
  for (const width of [1920,1440,1366,1024,820,768,425,375,320]) {
    await send('Emulation.setDeviceMetricsOverride', {width, height:800, deviceScaleFactor:1, mobile:false}); await sleep(150);
    await evaluate('document.querySelector("[data-delete-id]").click()');
    check(await evaluate(`(()=>{let d=document.getElementById('attendance-delete-dialog').getBoundingClientRect();return d.left>=-1&&d.right<=innerWidth+1&&d.top>=-1&&d.bottom<=innerHeight+1})()`), 'Delete modal fits at ' + width + 'px.');
    await evaluate('document.querySelector("[data-close-delete]").click();document.querySelector("#attendance-filter-form .year-picker-trigger").click()');
    check(await evaluate(`(()=>{let p=document.querySelector('.year-picker-popover:not([hidden])');let r=p.getBoundingClientRect();return document.documentElement.scrollWidth<=innerWidth+1&&r.left>=-1&&r.right<=innerWidth+1&&r.top>=-1&&r.bottom<=innerHeight+1})()`), 'Page and calendar fit at ' + width + 'px.');
    await evaluate('document.dispatchEvent(new KeyboardEvent("keydown",{key:"Escape",bubbles:true}))');
  }
  await evaluate('document.querySelector("[data-delete-id]").click();document.getElementById("attendance-delete-form").requestSubmit()');
  await waitFor(async () => await evaluate('!!document.querySelector(".attendance-unrecorded") && document.querySelector(".attendance-unrecorded").textContent.includes("35 Not Recorded")'), 'admin delete');
  check(await evaluate('!document.querySelector("#attendance-history tbody").textContent.includes("Staff 03")'), 'Admin delete updates active history.');
  await load('staff_legacy_attendance.php', staffSession);
  await evaluate('document.querySelector("select[data-attendance-edit]").value="present";document.querySelector("select[data-attendance-edit]").dispatchEvent(new Event("change",{bubbles:true}));document.getElementById("attendance-save-form").requestSubmit()');
  await waitFor(async () => await evaluate('!!document.querySelector(".attendance-present strong") && document.querySelector(".attendance-present strong").textContent === "1"'), 'staff restore');
  check(await evaluate('document.querySelector("#attendance-history tbody").textContent.includes("Staff 03")'), 'Staff re-recording restores the deleted entry.');
  await load('staff_attendance.php', staffSession);
  check(await evaluate('document.querySelector(".attendance-header a").textContent === "Staff Attendance" && document.querySelector(".attendance-unrecorded").textContent.includes("35 matching collectors")'), 'Bio Collector page keeps separate roster and links to Staff Attendance.');
  check(await evaluate('document.querySelector(".attendance-print-form").target === "_blank" && document.getElementById("print-period").value === "week" && document.getElementById("print-date").value === document.getElementById("attendance-date").dataset.yearPickerToday && !document.getElementById("print-week-field").hidden && document.getElementById("print-month-field").hidden && document.getElementById("print-month").disabled'), 'Print form defaults to the current week and opens a new tab.');
  await evaluate('document.getElementById("print-period").focus();document.getElementById("print-period").value="month";document.getElementById("print-period").dispatchEvent(new Event("change",{bubbles:true}))');
  check(await evaluate('document.activeElement.id === "print-period" && document.getElementById("print-period").value === "month" && document.getElementById("print-week-field").hidden && document.getElementById("print-date").disabled && !document.getElementById("print-month-field").hidden && document.getElementById("print-month").type === "month" && document.getElementById("print-month").required && !document.getElementById("print-month").disabled'), 'Monthly selection shows only the required month picker.');
  check(await evaluate('(()=>{const f=new FormData(document.querySelector(".attendance-print-form"));return f.has("print_month") && !f.has("print_date")})()'), 'Monthly form submits the month without a day.');
  await evaluate('document.getElementById("print-period").value="week";document.getElementById("print-period").dispatchEvent(new Event("change",{bubbles:true}))');
  check(await evaluate('!document.getElementById("print-week-field").hidden && document.getElementById("print-date").required && !document.getElementById("print-date").disabled && document.getElementById("print-month-field").hidden && document.getElementById("print-month").disabled'), 'Switching back restores the week date picker.');
  const printSeed = spawnSync(php, [path.join(root, 'tests/attendance_print_browser_seed.php'), database], {cwd:root, encoding:'utf8'});
  if (printSeed.status !== 0) throw new Error('Print fixture: ' + printSeed.stderr + printSeed.stdout);
  for (const [route, session, count, monthly, inactive] of [
    ['staff_attendance.php', staffSession, 244, 175, 'Collector 35 (Inactive)'],
    ['admin_attendance.php', adminSession, 244, 175, 'Collector 35 (Inactive)'],
    ['staff_legacy_attendance.php', staffSession, 245, 176, 'Former Worker (Inactive)'],
    ['admin_legacy_attendance.php', adminSession, 245, 176, 'Former Worker (Inactive)'],
  ]) {
    const params = new URLSearchParams({view:'print',print_period:'week',print_date:'2025-01-01',history_date:'2020-01-01',history_status:'present'});
    const response = await fetch(base + '/' + route + '?' + params, {headers:{Cookie:'PHPSESSID='+session}});
    const output = await response.text();
    check(response.ok && output.includes('2024-12-30 to 2025-01-05') && output.includes(count + ' records') && (output.match(/<tr>/g)||[]).length === count + 1 && output.includes(inactive), 'Week print includes every page and inactive records for ' + route);
    if (!route.includes('legacy')) check(!output.includes('sada'), 'Excluded collector does not appear in print for ' + route);
    params.set('print_period', 'month');
    params.delete('print_date');
    params.set('print_month', '2025-01');
    const monthOutput = await (await fetch(base + '/' + route + '?' + params, {headers:{Cookie:'PHPSESSID='+session}})).text();
    check(monthOutput.includes('2025-01-01 to 2025-01-31') && monthOutput.includes(monthly + ' records'), 'Month print includes the whole month for ' + route);
    params.set('history_search', route.includes('legacy') ? 'Former Worker' : 'Collector 35');
    const filteredOutput = await (await fetch(base + '/' + route + '?' + params, {headers:{Cookie:'PHPSESSID='+session}})).text();
    check(filteredOutput.includes(inactive) && filteredOutput.includes(route.includes('legacy') ? '1 record' : '5 records') && !filteredOutput.includes(route.includes('legacy') ? 'Staff 03</td>' : 'Collector 01</td>'), 'Print applies name and status filters for ' + route);
    params.set('history_status', 'absent');
    const emptyOutput = await (await fetch(base + '/' + route + '?' + params, {headers:{Cookie:'PHPSESSID='+session}})).text();
    check(emptyOutput.includes('No attendance records match this period and filters.'), 'Empty filtered print is shown for ' + route);
  }
  const excludedHistory = await fetch(base + '/staff_attendance.php?' + new URLSearchParams({history_search:'sada'}), {headers:{Cookie:'PHPSESSID='+staffSession}});
  check((await excludedHistory.text()).includes('No attendance records match these filters.'), 'Excluded collector does not appear in bio collector history.');
  const invalidPrint = await fetch(base + '/staff_attendance.php?' + new URLSearchParams({view:'print',print_period:'year',print_date:'2025-01-01'}), {headers:{Cookie:'PHPSESSID='+staffSession}});
  check(invalidPrint.status === 400, 'Invalid print period is rejected.');
  const invalidMonth = await fetch(base + '/staff_attendance.php?' + new URLSearchParams({view:'print',print_period:'month',print_month:'2025-13'}), {headers:{Cookie:'PHPSESSID='+staffSession}});
  check(invalidMonth.status === 400, 'Invalid print month is rejected.');
  await send('Page.addScriptToEvaluateOnNewDocument', {source:'window.__printCalls = 0; window.print = function () { window.__printCalls++; };'});
  await send('Page.navigate', {url:base + '/staff_attendance.php?' + new URLSearchParams({view:'print',print_period:'week',print_date:'2025-01-01'})});
  await waitFor(async () => await evaluate('document.readyState === "complete" && window.__printCalls === 1 && document.title.includes("attendance history")'), 'print preview');
  check(await evaluate('document.querySelector(".print-actions button").click();window.__printCalls === 2'), 'Print preview opens automatically and the retry button works.');
  console.log('PASS: ' + checks + ' staff attendance browser checks (9 viewport widths)');
}
main().catch(e => {console.error('FAIL:', e.stack || e); process.exitCode=1;}).finally(() => {
  if(ws) ws.close(); if(browser) browser.kill(); if(server) server.kill();
  try {setup('cleanup');} catch(e) {console.error(e.message);process.exitCode=1;}
  const resolved=path.resolve(testDir);
  if(resolved.startsWith(path.resolve(tmpRoot)+path.sep) && path.basename(resolved)==='staff-attendance-browser-'+suffix) {
    try {fs.rmSync(resolved,{recursive:true,force:true,maxRetries:3,retryDelay:200});} catch(_) {}
  }
});
