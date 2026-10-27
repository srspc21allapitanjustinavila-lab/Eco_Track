// Exercises the real PHP endpoint and shared modals against a disposable local database.
const { spawn, spawnSync } = require('child_process');
const { randomBytes } = require('crypto');
const fs = require('fs');
const net = require('net');
const os = require('os');
const path = require('path');
const tls = require('tls');
const root = path.resolve(__dirname, '..');
const suffix = randomBytes(6).toString('hex');
const database = 'ecotrack_backup_test_' + suffix;
const testDir = fs.mkdtempSync(path.join(os.tmpdir(), 'ecotrack-backup-test-'));
const sessionDir = path.join(testDir, 'sessions');
const backupDir = path.join(testDir, 'backups');
const php = 'C:\\xampp\\php\\php.exe';
const chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const openssl = 'C:\\xampp\\apache\\bin\\openssl.exe';
const adminSession = randomBytes(16).toString('hex');
const staffSession = randomBytes(16).toString('hex');
const port = 18931, debugPort = 18932, smtpPort = 18933, base = 'http://127.0.0.1:' + port;
const env = { ...process.env, DB_HOST: '127.0.0.1', DB_NAME: database, DB_USER: 'root', GMAIL_API_ENABLED: 'false', EMAIL_HOST: '127.0.0.1', EMAIL_PORT: String(smtpPort), EMAIL_USERNAME: 'test', EMAIL_PASSWORD: 'test', EMAIL_FROM: 'sender@example.test', ECOTRACK_BACKUP_DIR: backupDir };
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
let server, browser, smtpServer, ws, nextId = 0, checks = 0, setupStarted = false;
const emails = [];
const pending = new Map();
function check(value, message) { if (!value) throw new Error(message); checks++; }
function fixture(action) {
  const result = spawnSync(php, [path.join(root, 'tests/backup_otp_browser_fixture.php'), action, database, sessionDir, adminSession, staffSession], { cwd: root, env, encoding: 'utf8' });
  if (result.status !== 0) throw new Error('Fixture ' + action + ' failed: ' + result.stderr + result.stdout);
  return result.stdout.trim();
}
async function waitFor(fn, label) {
  for (let i = 0; i < 100; i++) { try { if (await fn()) return; } catch (_) {} await sleep(100); }
  throw new Error('Timed out: ' + label);
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
function backups() { return fs.existsSync(backupDir) ? fs.readdirSync(backupDir).filter(name => name.endsWith('.sql.gz')) : []; }
async function startSmtp() {
  const cert = path.join(testDir, 'smtp-cert.pem');
  const key = path.join(testDir, 'smtp-key.pem');
  const config = path.join(testDir, 'openssl.cnf');
  fs.writeFileSync(config, '[req]\ndistinguished_name = dn\n[dn]\n');
  const generated = spawnSync(openssl, ['req', '-x509', '-newkey', 'rsa:2048', '-sha256', '-days', '1', '-nodes', '-keyout', key, '-out', cert, '-subj', '/CN=localhost', '-addext', 'subjectAltName=IP:127.0.0.1', '-config', config], { encoding: 'utf8' });
  if (generated.status !== 0) throw new Error('Could not create local SMTP certificate: ' + generated.stderr);
  const secureContext = tls.createSecureContext({ key: fs.readFileSync(key), cert: fs.readFileSync(cert) });
  smtpServer = net.createServer(socket => {
    let buffer = '', data = '', collecting = false, authStep = 0;
    function attach(stream, secure) {
      stream.on('error', () => {});
      stream.on('data', chunk => {
        buffer += chunk.toString();
        let end;
        while ((end = buffer.indexOf('\r\n')) !== -1) {
          const line = buffer.slice(0, end); buffer = buffer.slice(end + 2);
          if (collecting) {
            if (line === '.') { emails.push(data); data = ''; collecting = false; stream.write('250 Message accepted\r\n'); }
            else data += line + '\n';
          } else if (authStep) {
            if (authStep++ === 1) stream.write('334 UGFzc3dvcmQ6\r\n');
            else { authStep = 0; stream.write('235 Authentication successful\r\n'); }
          } else if (/^EHLO /i.test(line)) {
            stream.write(secure ? '250-localhost\r\n250 AUTH LOGIN\r\n' : '250-localhost\r\n250 STARTTLS\r\n');
          } else if (/^STARTTLS$/i.test(line)) {
            stream.write('220 Ready to start TLS\r\n');
            stream.removeAllListeners('data');
            buffer = '';
            attach(new tls.TLSSocket(stream, { isServer: true, secureContext }), true);
            return;
          } else if (/^AUTH LOGIN/i.test(line)) { authStep = 1; stream.write('334 VXNlcm5hbWU6\r\n'); }
          else if (/^DATA$/i.test(line)) { collecting = true; stream.write('354 End with a dot\r\n'); }
          else if (/^QUIT$/i.test(line)) { stream.end('221 Bye\r\n'); }
          else stream.write('250 OK\r\n');
        }
      });
    }
    attach(socket, false);
    socket.write('220 localhost test SMTP\r\n');
  });
  await new Promise((resolve, reject) => { smtpServer.once('error', reject); smtpServer.listen(smtpPort, '127.0.0.1', resolve); });
  return cert;
}
async function main() {
  fs.mkdirSync(sessionDir, { recursive: true });
  fixture('setup');
  setupStarted = true;
  check(fixture('purposes') === 'purpose checks passed', 'Download, print, and import OTP purposes remain isolated and one-time use.');
  const cert = await startSmtp();
  server = spawn(php, ['-d', 'session.save_path="' + sessionDir + '"', '-d', 'openssl.cafile="' + cert + '"', '-S', '127.0.0.1:' + port, '-t', root], { cwd: root, env, stdio: 'ignore' });
  await waitFor(async () => { const response = await fetch(base + '/login.php'); await response.text(); return response.ok; }, 'PHP server');
  browser = spawn(chrome, ['--headless=new', '--no-sandbox', '--disable-gpu', '--disable-extensions', '--remote-allow-origins=*', '--remote-debugging-port=' + debugPort, '--user-data-dir=' + path.join(testDir, 'profile'), 'about:blank'], { stdio: 'ignore' });
  let target;
  await waitFor(async () => { target = (await (await fetch('http://127.0.0.1:' + debugPort + '/json/list')).json()).find(x => x.type === 'page'); return target; }, 'Chrome');
  ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
  ws.onmessage = event => { const msg = JSON.parse(event.data); if (!msg.id || !pending.has(msg.id)) return; const p = pending.get(msg.id); pending.delete(msg.id); msg.error ? p.reject(new Error(msg.error.message)) : p.resolve(msg.result); };
  await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
  await send('Network.setCookie', { name: 'PHPSESSID', value: adminSession, url: base });
  await send('Page.navigate', { url: base + '/admin_system_settings.php?tab=backups' });
  try {
    await waitFor(async () => await evaluate('!!document.querySelector("#backups button[name=create_database_backup]")'), 'backup settings');
  } catch (error) {
    throw new Error(error.message + ': ' + JSON.stringify(await evaluate('({url:location.href, body:document.body.innerText.slice(0,1000)})')));
  }
  const token = await evaluate('document.querySelector("#backups [name=database_backup_csrf]").value');
  const post = async (body, session = adminSession) => fetch(base + '/admin_system_settings.php?tab=backups', { method: 'POST', redirect: 'manual', headers: { Cookie: 'PHPSESSID=' + session, 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(body) });
  const values = code => ({ create_database_backup: '1', database_backup_csrf: token, backup_otp: code });
  for (const width of [1440, 768, 425, 320]) {
    await send('Emulation.setDeviceMetricsOverride', { width, height: 800, deviceScaleFactor: 1, mobile: false });
    await evaluate('document.querySelector("#backups button[name=create_database_backup]").click()');
    check(await evaluate('document.querySelector("#transactionConfirmationModal.is-open") && document.getElementById("transactionConfirmationTitle").textContent === "Confirm database backup"'), 'Confirmation opens at ' + width + 'px.');
    check(await evaluate('(()=>{const r=document.querySelector("#transactionConfirmationModal .transaction-confirmation-dialog").getBoundingClientRect();return r.left>=0&&r.right<=innerWidth&&r.top>=0&&r.bottom<=innerHeight})()'), 'Confirmation fits at ' + width + 'px.');
    await evaluate('document.querySelector("[data-transaction-confirm-cancel]").click()');
    check(await evaluate('!document.querySelector("#transactionConfirmationModal.is-open")'), 'Confirmation cancels at ' + width + 'px.');
  }
  check(backups().length === 0, 'Cancelling does not create a backup.');
  const invalidCsrf = await post({ ...values('123456'), database_backup_csrf: 'invalid' });
  check((await invalidCsrf.text()).includes('backup form expired') && backups().length === 0, 'Invalid CSRF blocks backup.');
  const forbidden = await post(values('123456'), staffSession);
  check(forbidden.status === 302 && forbidden.headers.get('location') === 'access_denied.php' && backups().length === 0, 'Staff cannot perform the backup.');
  const wrongPurpose = await post(values('654321'));
  check((await wrongPurpose.text()).includes('verification code is invalid') && backups().length === 0, 'An export code cannot authorize a backup.');
  await evaluate('document.querySelector("#backups button[name=create_database_backup]").click();document.querySelector("[data-transaction-confirm-continue]").click()');
  await waitFor(async () => await evaluate('!!document.querySelector("#backupOtpModal.is-open")'), 'OTP popup');
  check(backups().length === 0, 'Missing OTP does not create a backup.');
  for (const width of [1440, 768, 425, 320]) {
    await send('Emulation.setDeviceMetricsOverride', { width, height: 800, deviceScaleFactor: 1, mobile: false });
    check(await evaluate('(()=>{const r=document.querySelector("#backupOtpModal .otp-modal-dialog").getBoundingClientRect();return r.left>=0&&r.right<=innerWidth&&r.top>=0&&r.bottom<=innerHeight})()'), 'OTP popup fits at ' + width + 'px.');
  }
  await evaluate('document.querySelector("#backupOtpModal .otp-modal-cancel").click()');
  await waitFor(async () => await evaluate('!document.getElementById("backupOtpModal") && !!document.querySelector("#backups button[name=create_database_backup]")'), 'cancel OTP');
  check(backups().length === 0, 'Cancelling OTP does not create a backup.');
  await evaluate('document.querySelector("#backups button[name=create_database_backup]").click();document.querySelector("[data-transaction-confirm-continue]").click()');
  await waitFor(async () => await evaluate('!!document.querySelector("#backupOtpModal.is-open")'), 'reopen OTP');
  await evaluate('document.querySelector("#backupOtpModal input[name=backup_otp]").value="000000";document.querySelector("#backupOtpModal form").requestSubmit()');
  await waitFor(async () => await evaluate('!!document.querySelector("#backupOtpModal .otp-modal-error")'), 'invalid code');
  check(await evaluate('document.querySelector("#backupOtpModal .otp-modal-error").textContent.includes("invalid") && !document.querySelector("#settingsValidationModal")'), 'Invalid code remains in the OTP popup without a second error dialog.');
  await evaluate('document.querySelector("#backupOtpModal input[name=backup_otp]").value="123456";document.querySelector("#backupOtpModal form").requestSubmit()');
  await waitFor(async () => await evaluate('!!document.querySelector(".message.success") && !document.getElementById("backupOtpModal")'), 'successful backup');
  check(backups().length === 1 && await evaluate('document.querySelector(".message.success").textContent.includes("Database backup created successfully") && document.querySelector("#backups table").textContent.includes(".sql.gz")'), 'Verified backup succeeds with data export disabled and appears in the list.');
  await send('Page.reload');
  await waitFor(async () => await evaluate('document.readyState === "complete" && !!document.querySelector("#backups table")'), 'refresh');
  check(backups().length === 1, 'Refreshing does not create a duplicate backup.');
  const replay = await post(values('123456'));
  check((await replay.text()).includes('expired or was already used') && backups().length === 1 && emails.length === 1, 'A used code is rejected and a replacement is emailed.');
  check(emails[0].includes('database backup'), 'The email identifies the database backup action.');
  const replacement = (emails[0].match(/\b\d{6}\b/) || [])[0];
  check(!!replacement, 'The replacement email contains a six-digit code.');
  const replaced = await post(values(replacement));
  check(replaced.status === 303 && backups().length === 2, 'The emailed replacement authorizes exactly one backup.');
  fixture('expire');
  const expired = await post(values('987654'));
  check((await expired.text()).includes('expired or was already used') && backups().length === 2 && emails.length === 2, 'An expired code is rejected and a replacement is emailed.');
  fixture('expire');
  const initial = await post({ create_database_backup: '1', database_backup_csrf: token });
  check((await initial.text()).includes('backupOtpModal') && backups().length === 2 && emails.length === 3, 'A new backup request emails a code and opens verification.');
  fixture('expire');
  await new Promise(resolve => smtpServer.close(resolve));
  smtpServer = null;
  const deliveryFailure = await post(values('987654'));
  check((await deliveryFailure.text()).includes('verification code could not be sent') && backups().length === 2, 'Delivery failure prevents the backup and shows an error.');
  console.log('PASS: ' + checks + ' isolated backup OTP browser and endpoint checks.');
}
main().catch(error => { console.error('FAIL:', error.stack || error); process.exitCode = 1; }).finally(() => {
  if (ws) ws.close();
  if (browser) browser.kill();
  if (server) server.kill();
  if (smtpServer) smtpServer.close();
  if (setupStarted) { try { fixture('cleanup'); } catch (error) { console.error(error.message); process.exitCode = 1; } }
  const resolved = path.resolve(testDir);
  if (path.dirname(resolved) === path.resolve(os.tmpdir()) && path.basename(resolved).startsWith('ecotrack-backup-test-')) {
    try { fs.rmSync(resolved, { recursive: true, force: true, maxRetries: 3, retryDelay: 200 }); } catch (_) {}
  }
});
