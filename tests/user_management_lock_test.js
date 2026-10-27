// Exercises simultaneous requests against an isolated local MySQL database.
const { spawn, spawnSync } = require('child_process');
const { randomBytes } = require('crypto');
const fs = require('fs');
const net = require('net');
const path = require('path');
const root = path.resolve(__dirname, '..');
const suffix = randomBytes(6).toString('hex');
const database = 'ecotrack_user_lock_test_' + suffix;
const tmpRoot = path.join(root, '.tmp');
const testDir = path.join(tmpRoot, 'user-lock-' + suffix);
const sessionDir = path.join(testDir, 'sessions');
const php = 'C:\\xampp\\php\\php.exe';
const fixturePath = path.join(root, 'tests/user_management_lock_fixture.php');
const sessions = [1, 2, 3].map(() => randomBytes(16).toString('hex'));
const env = { ...process.env, DB_HOST: '127.0.0.1', DB_NAME: database, DB_USER: 'root' };
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
let servers = [], lockProcess, setupComplete = false, checks = 0;
function check(value, message) { if (!value) throw new Error(message); checks++; }
function fixture(action) {
  const result = spawnSync(php, [fixturePath, action, database, sessionDir, ...sessions], { cwd: root, env, encoding: 'utf8' });
  if (result.status !== 0) throw new Error('Fixture ' + action + ' failed: ' + result.stderr + result.stdout);
  return result.stdout.trim();
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
async function startServer() {
  const port = await freePort();
  const server = spawn(php, ['-d', 'session.save_path="' + sessionDir + '"', '-S', '127.0.0.1:' + port, '-t', root], { cwd: root, env, stdio: 'ignore' });
  servers.push(server);
  const base = 'http://127.0.0.1:' + port;
  await waitFor(async () => { try { const r = await fetch(base + '/login.php'); await r.text(); return r.ok; } catch (_) { return false; } }, 'PHP server');
  return base;
}
async function lockAdmins() {
  lockProcess = spawn(php, [fixturePath, 'lock', database], { cwd: root, env, stdio: ['pipe', 'pipe', 'pipe'] });
  let output = '', errors = '';
  lockProcess.stdout.on('data', data => { output += data; });
  lockProcess.stderr.on('data', data => { errors += data; });
  await waitFor(() => { if (lockProcess.exitCode !== null) throw new Error('Lock fixture failed: ' + errors); return output.includes('LOCKED'); }, 'admin lock');
}
function releaseAdmins() {
  const current = lockProcess;
  return new Promise((resolve, reject) => {
    current.once('exit', code => { lockProcess = null; code === 0 ? resolve() : reject(new Error('Lock fixture exited with ' + code)); });
    current.stdin.end('\n');
  });
}
function post(base, session, values) {
  return fetch(base + '/admin_user_management.php', { method: 'POST', redirect: 'manual', headers: { Cookie: 'PHPSESSID=' + session, 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(values) }).then(async response => ({ status: response.status, body: await response.text() }));
}
function changeStatus(id) { return { deactivate_user: '1', user_id: String(id) }; }
function changeRole(id) {
  return id === 1
    ? { edit_user: '1', user_id: '1', username: 'admin-one', email: 'one@example.test', first_name: 'Admin', last_name: 'One', user_type: 'staff' }
    : { edit_user: '1', user_id: '2', username: 'admin-two', email: 'two@example.test', first_name: 'Admin', last_name: 'Two', user_type: 'staff' };
}
async function concurrentScenario(bases, label, first, second) {
  fixture('reset');
  await lockAdmins();
  const completed = [];
  const requests = [post(bases[0], sessions[0], first), post(bases[1], sessions[1], second)].map((request, index) => request.catch(error => ({ error: String(error) })).then(result => { completed[index] = { status: result.status, error: result.error, body: result.body && result.body.slice(0, 300) }; return result; }));
  try {
    await waitFor(() => JSON.parse(fixture('waiters')).filter(row => /SELECT id FROM users WHERE user_type = 'admin' AND is_active = 1 ORDER BY id FOR UPDATE/i.test(row.INFO || '')).length >= 2, label + ' requests waiting for the lock');
  } catch (error) {
    throw new Error(error.message + ': ' + fixture('waiters') + '; completed=' + JSON.stringify(completed));
  }
  await releaseAdmins();
  const responses = await Promise.all(requests);
  const users = JSON.parse(fixture('status'));
  check(users.filter(user => user.user_type === 'admin' && Number(user.is_active) === 1).length === 1, label + ' leaves exactly one active administrator.');
  check(responses.every(response => response.status === 200) && responses.filter(response => response.body.includes('administrator access has changed')).length === 1, label + ' blocks the administrator whose access changed.');
}
async function main() {
  fs.mkdirSync(sessionDir, { recursive: true });
  fixture('setup');
  setupComplete = true;
  const bases = [await startServer(), await startServer()];
  const staffResponse = await fetch(bases[0] + '/admin_user_management.php', { redirect: 'manual', headers: { Cookie: 'PHPSESSID=' + sessions[2] } });
  check(staffResponse.status === 302 && staffResponse.headers.get('location') === 'access_denied.php', 'Staff cannot access User Management.');
  await staffResponse.text();
  fixture('last');
  const self = await post(bases[0], sessions[0], changeStatus(1));
  check(self.body.includes('cannot deactivate your own account') && Number(JSON.parse(fixture('status'))[0].is_active) === 1, 'The last administrator cannot deactivate their own account.');
  const selfRole = await post(bases[0], sessions[0], changeRole(1));
  check(selfRole.body.includes('Use another active administrator') && JSON.parse(fixture('status'))[0].user_type === 'admin', 'The last administrator cannot change their own role.');
  fixture('reset');
  fixture('seed_token');
  const other = await post(bases[0], sessions[0], changeStatus(2));
  check(other.body.includes('Account status updated successfully') && Number(JSON.parse(fixture('status'))[1].is_active) === 0, 'An administrator can deactivate another when one remains.');
  check(Number(fixture('token_count')) === 0, 'Deactivation revokes the other account’s remembered login.');
  const reactivate = await post(bases[0], sessions[0], changeStatus(2));
  check(reactivate.body.includes('Account status updated successfully') && Number(JSON.parse(fixture('status'))[1].is_active) === 1, 'An administrator can reactivate another account.');
  const demote = await post(bases[0], sessions[0], changeRole(2));
  check(demote.body.includes('Account updated successfully') && JSON.parse(fixture('status'))[1].user_type === 'staff', 'An administrator can change another role when one admin remains.');
  await concurrentScenario(bases, 'Concurrent deactivations', changeStatus(2), changeStatus(1));
  await concurrentScenario(bases, 'Concurrent role changes', changeRole(2), changeRole(1));
  await concurrentScenario(bases, 'Concurrent deactivation and role change', changeStatus(2), changeRole(1));
  fixture('reset');
  const mismatch = await post(bases[0], sessions[0], { add_user: '1', user_type: 'waste_collector', username: 'collector-login', email: 'collector@example.test', first_name: 'Collector', last_name: 'Login', password: 'secure-pass', confirm_password: 'different-pass' });
  check(mismatch.body.includes('Passwords do not match'), 'Waste Collector creation rejects mismatched passwords.');
  const created = await post(bases[0], sessions[0], { add_user: '1', user_type: 'waste_collector', username: 'collector-login', email: 'collector@example.test', first_name: 'Collector', last_name: 'Login', password: 'secure-pass', confirm_password: 'secure-pass' });
  const collector = JSON.parse(fixture('collector_account'));
  check(created.body.includes('Waste Collector account added successfully') && collector.user_type === 'waste_collector' && collector.collector_name === 'Collector Login' && /^\$2[ayb]\$/.test(collector.password_hash), 'Waste Collector creation hashes credentials and links one new collector identity.');
  const duplicate = await post(bases[0], sessions[0], { add_user: '1', user_type: 'waste_collector', username: 'collector-login', email: 'duplicate@example.test', first_name: 'Collector', last_name: 'Duplicate', password: 'secure-pass', confirm_password: 'secure-pass' });
  check(duplicate.body.includes('Username or email already exists'), 'Waste Collector creation prevents duplicate account credentials.');
  const existingCollectorId = fixture('seed_unlinked_collector');
  const linked = await post(bases[0], sessions[0], { add_user: '1', user_type: 'waste_collector', username: 'collector-linked', email: 'linked@example.test', first_name: 'Existing', last_name: 'Collector', password: 'secure-pass', confirm_password: 'secure-pass' });
  const linkedCollector = JSON.parse(fixture('linked_collector_account'));
  check(linked.body.includes('Waste Collector account added successfully') && String(linkedCollector.collector_id) === existingCollectorId && linkedCollector.collector_name === 'Existing Collector', 'Waste Collector creation automatically links a same-name identity without duplicating it.');
  const reset = await post(bases[0], sessions[0], { reset_password: '1', user_id: String(collector.id), new_password: 'updated-pass', confirm_password: 'updated-pass' });
  check(reset.body.includes('Password reset successfully') && JSON.parse(fixture('collector_account')).password_hash !== collector.password_hash, 'Waste Collector password reset updates the stored hash.');
  console.log('PASS: ' + checks + ' isolated User Management access and concurrency checks.');
}
main().catch(error => { console.error('FAIL:', error.stack || error); process.exitCode = 1; }).finally(() => {
  if (lockProcess) lockProcess.kill();
  for (const server of servers) server.kill();
  if (setupComplete) { try { fixture('cleanup'); } catch (error) { console.error(error.message); process.exitCode = 1; } }
  const resolved = path.resolve(testDir);
  if (path.dirname(resolved) === path.resolve(tmpRoot) && path.basename(resolved) === 'user-lock-' + suffix) {
    try { fs.rmSync(resolved, { recursive: true, force: true, maxRetries: 3, retryDelay: 200 }); } catch (_) {}
  }
});
