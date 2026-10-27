// Checks canonical role-specific URLs against an isolated local database.
const { spawn, spawnSync } = require('child_process');
const { randomBytes } = require('crypto');
const fs = require('fs');
const net = require('net');
const path = require('path');
const root = path.resolve(__dirname, '..');
const suffix = randomBytes(6).toString('hex');
const database = 'ecotrack_attendance_browser_' + suffix;
const tmpRoot = path.join(root, '.tmp');
const testDir = path.join(tmpRoot, 'role-routes-' + suffix);
const sessionDir = path.join(testDir, 'sessions');
const adminSession = randomBytes(16).toString('hex');
const staffSession = randomBytes(16).toString('hex');
const collectorSession = randomBytes(16).toString('hex');
const php = 'C:\\xampp\\php\\php.exe';
const mysql = 'C:\\xampp\\mysql\\bin\\mysql.exe';
const env = { ...process.env, DB_HOST: '127.0.0.1', DB_NAME: database, DB_USER: 'root' };
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
let server, serverErrors = '', setupComplete = false, checks = 0;
function check(value, message) { if (!value) throw new Error(message); checks++; }
function fixture(action) {
  const result = spawnSync(php, [path.join(root, 'tests/bio_attendance_browser_setup.php'), action, database, sessionDir, adminSession, staffSession, collectorSession], { cwd: root, env, encoding: 'utf8' });
  if (result.status !== 0) throw new Error('Fixture ' + action + ' failed: ' + result.stderr + result.stdout);
}
async function waitFor(fn, label) {
  for (let i = 0; i < 100; i++) { if (await fn()) return; await sleep(100); }
  throw new Error('Timed out: ' + label);
}
async function freePort() {
  const listener = net.createServer();
  await new Promise((resolve, reject) => { listener.once('error', reject); listener.listen(0, '127.0.0.1', resolve); });
  const port = listener.address().port;
  await new Promise(resolve => listener.close(resolve));
  return port;
}
async function main() {
  fs.mkdirSync(sessionDir, { recursive: true });
  fixture('setup');
  setupComplete = true;
  const schema = spawnSync(mysql, ['-u', 'root', '-h', '127.0.0.1', '-D', database, '-e', 'ALTER TABLE waste_records ADD date VARCHAR(50), ADD phase_number VARCHAR(50), ADD street VARCHAR(100), ADD kilogram_of_waste DECIMAL(10,2) DEFAULT 0, ADD recyclable_kg DECIMAL(10,2) DEFAULT 0, ADD residual_kg DECIMAL(10,2) DEFAULT 0, ADD hazardous_kg DECIMAL(10,2) DEFAULT 0, ADD created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, ADD updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, ADD created_by VARCHAR(100)'], { encoding: 'utf8' });
  if (schema.status !== 0) throw new Error('Fixture waste schema failed: ' + schema.stderr);
  const port = await freePort();
  const base = 'http://127.0.0.1:' + port;
  server = spawn(php, ['-d', 'session.save_path="' + sessionDir + '"', '-S', '127.0.0.1:' + port, '-t', root], { cwd: root, env, stdio: ['ignore', 'ignore', 'pipe'] });
  server.stderr.on('data', data => { serverErrors = (serverErrors + data.toString()).slice(-6000); });
  await waitFor(async () => { try { const r = await fetch(base + '/login.php'); await r.text(); return r.ok; } catch (_) { return false; } }, 'PHP server');
  async function request(route, session, method = 'GET') {
    const options = { method, redirect: 'manual', headers: {}, signal: AbortSignal.timeout(30000) };
    if (session) options.headers.Cookie = 'PHPSESSID=' + session;
    if (method === 'POST') { options.headers['Content-Type'] = 'application/x-www-form-urlencoded'; options.body = 'save_privacy_settings=1&share_anonymized_data=0&data_export_enabled=0'; }
    const response = await fetch(base + '/' + route, options);
    const body = await response.text();
    return { status: response.status, location: response.headers.get('location'), contentType: response.headers.get('content-type') || '', body };
  }
  const files = fs.readdirSync(root);
  const adminRoutes = files.filter(file => /^admin_[a-z_]+\.php$/.test(file));
  const staffRoutes = files.filter(file => /^staff_[a-z_]+\.php$/.test(file));
  for (const [routes, session, role] of [[staffRoutes, adminSession, 'admin'], [adminRoutes, staffSession, 'staff']]) {
    for (const route of routes) {
      for (const method of ['GET', 'POST']) {
        const response = await request(route, session, method);
        check(response.status === 302 && response.location === 'access_denied.php', role + ' must be denied ' + method + ' access to ' + route + ': ' + response.status + ' ' + response.location);
      }
    }
  }
  for (const [route, session] of [['staff_waste_heatmap.php', adminSession], ['admin_waste_heatmap.php', staffSession]]) {
    const response = await request(route + '?format=json', session);
    check(response.status === 403 && response.contentType.includes('application/json') && JSON.parse(response.body).error === 'Access denied.', 'Cross-role heatmap JSON returns 403 on ' + route);
  }
  for (const route of ['staff_settings.php', 'admin_system_settings.php']) {
    const response = await request(route, null);
    check(response.status === 302 && response.location === 'login.php', 'Anonymous user is sent to login from ' + route);
  }
  const anonymousJson = await request('staff_waste_heatmap.php?format=json', null);
  check(anonymousJson.status === 401 && JSON.parse(anonymousJson.body).error === 'Authentication required.', 'Anonymous heatmap JSON returns 401.');
  const collectorAttendance = await request('waste_collector_attendance.php', collectorSession);
  check(collectorAttendance.status === 200 && collectorAttendance.body.includes('Collector Attendance') && collectorAttendance.body.includes('All Bio Collectors') && collectorAttendance.body.includes('Collector 01') && collectorAttendance.body.includes('Save Collector Attendance') && collectorAttendance.body.includes('href="waste_collector_attendance.php"') && !collectorAttendance.body.includes('href="staff_home.php"') && !collectorAttendance.body.includes('id="attendance-history"') && !collectorAttendance.body.includes('attendance-print-form'), 'Waste Collector shared account sees and can save today\'s attendance for the complete Bio Collector roster.');
  for (const route of ['staff_attendance.php', 'staff_legacy_attendance.php']) {
    for (const method of ['GET', 'POST']) {
      const response = await request(route, staffSession, method);
      check(response.status === 302 && response.location === 'access_denied.php', 'Staff cannot access attendance through ' + method + ' ' + route + '.');
    }
  }
  const staffHome = await request('staff_home.php', staffSession);
  check(staffHome.status === 200 && !staffHome.body.includes('href="staff_attendance.php"'), 'Staff navigation no longer exposes Attendance.');
  for (const route of [...adminRoutes, ...staffRoutes]) {
    const response = await request(route, collectorSession);
    check(response.status === 302 && response.location === 'access_denied.php', 'Waste Collector is denied direct access to ' + route);
  }
  const collectorJson = await request('admin_waste_heatmap.php?format=json', collectorSession);
  check(collectorJson.status === 403 && collectorJson.contentType.includes('application/json') && JSON.parse(collectorJson.body).error === 'Access denied.', 'Waste Collector receives JSON denial for a restricted API.');
  const collectorStreetState = await request('street_chart_filter_state.php', collectorSession);
  check(collectorStreetState.status === 403 && collectorStreetState.contentType.includes('application/json') && JSON.parse(collectorStreetState.body).error === 'Access denied.', 'Waste Collector cannot retrieve street filter data.');
  const collectorWasteVersion = await request('waste_data_version.php', collectorSession);
  check(collectorWasteVersion.status === 403 && collectorWasteVersion.contentType.includes('application/json') && JSON.parse(collectorWasteVersion.body).error === 'Access denied.', 'Waste Collector cannot retrieve restricted data-version API output.');
  const collectorLogin = await request('login.php', collectorSession);
  check(collectorLogin.status === 302 && collectorLogin.location === 'waste_collector_attendance.php', 'Waste Collector login resumes at attendance.');
  for (const [session, home] of [[adminSession, 'admin_dashboard.php'], [staffSession, 'staff_home.php']]) {
    const response = await request('access_denied.php', session);
    check(response.status === 200 && response.body.includes('Access restricted') && response.body.includes('href="' + home + '"'), 'Access restricted page returns the user to ' + home);
  }
  for (const [route, session] of [
    ['admin_system_settings.php', adminSession], ['staff_settings.php', staffSession],
    ['admin_waste_records.php', adminSession], ['staff_waste_records.php', staffSession],
    ['admin_waste_heatmap.php', adminSession], ['staff_waste_heatmap.php', staffSession],
  ]) {
    const response = await request(route, session);
    check(response.status === 200 && !response.body.includes('Fatal error') && response.body.includes('sidebar'), 'Correct role can access ' + route + ': ' + response.status);
  }
  for (const [session, route] of [[adminSession, 'admin_waste_heatmap.php'], [staffSession, 'staff_waste_heatmap.php']]) {
    const direct = await request('waste_heatmap.php?mode=year', session);
    check(direct.status === 302 && direct.location === route + '?mode=year', 'Shared heatmap URL resolves to ' + route);
    const data = await request(route + '?format=json', session);
    check(data.status === 200 && data.contentType.includes('application/json'), 'Correct role can access heatmap JSON at ' + route + ': ' + data.status + ' ' + serverErrors);
    JSON.parse(data.body);
  }
  const alter = role => {
    const result = spawnSync(mysql, ['-u', 'root', '-h', '127.0.0.1', '-D', database, '-e', "UPDATE users SET user_type = '" + role + "' WHERE id = 1"], { encoding: 'utf8' });
    if (result.status !== 0) throw new Error('Fixture role change failed: ' + result.stderr);
  };
  alter('staff');
  const changedRole = await request('admin_waste_heatmap.php?format=json', adminSession);
  check(changedRole.status === 403 && JSON.parse(changedRole.body).error === 'Access denied.', 'Heatmap JSON checks the current database role, not a stale session role.');
  alter('admin');
  console.log('PASS: ' + checks + ' isolated role-route access checks.');
}
main().catch(error => { console.error('FAIL:', error.stack || error); process.exitCode = 1; }).finally(() => {
  if (server) server.kill();
  if (setupComplete) { try { fixture('cleanup'); } catch (error) { console.error(error.message); process.exitCode = 1; } }
  const resolved = path.resolve(testDir);
  if (path.dirname(resolved) === path.resolve(tmpRoot) && path.basename(resolved) === 'role-routes-' + suffix) {
    try { fs.rmSync(resolved, { recursive: true, force: true, maxRetries: 3, retryDelay: 200 }); } catch (_) {}
  }
});
