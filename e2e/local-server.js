// Starts a local demo store for the browser tests: PHP's built-in web server
// on http://127.0.0.1:8181 with the settings of a public demo (APP_DEMO=true,
// the payment simulator and the demo inbox), on a freshly installed scratch
// database. Playwright starts it by itself when BASE_URL is not set (see
// playwright.config.js); it can also be started by hand:
//
//     DB_PORT=3306 DB_NAME=bookwise_e2e_test npm run serve:e2e
//
// It first drops every table of DB_NAME, which must end in "_test", then runs
// "php bin/install.php --seed". Logs, sessions and the payment simulator's
// state go to the folder bookwise-e2e-8181 in the system's temporary folder,
// which is emptied when the store starts and deleted when it stops (on
// Windows, Playwright ends the store without letting it clean up, so the
// folder stays until the next start). DB_HOST, DB_PORT, DB_USER and DB_PASS
// come from the environment, or else from the project's .env file (defaults:
// 127.0.0.1, 3306, root, empty); PHP_BINARY names another PHP executable than
// "php". Stops with Ctrl+C.
import { spawn, spawnSync } from 'node:child_process';
import { mkdirSync, rmSync } from 'node:fs';
import { connect } from 'node:net';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const php = process.env.PHP_BINARY || 'php';
const host = '127.0.0.1';
const port = '8181';
const databaseName = process.env.DB_NAME || 'bookwise_e2e_test';

if (!databaseName.endsWith('_test')) {
    console.error(`Refusing to use the database "${databaseName}": its tables are dropped, so its name must end in "_test".`);
    process.exit(1);
}

// A store that already runs on the port keeps its database and its files:
// nothing is reset while it may still be using them.
const portInUse = await new Promise((resolve) => {
    const socket = connect({ host, port: Number(port) });
    socket.once('connect', () => {
        socket.destroy();
        resolve(true);
    });
    socket.once('error', () => resolve(false));
});
if (portInUse) {
    console.error(`Something already answers on http://${host}:${port}. Stop it first; this script would reset the database it may be using.`);
    process.exit(1);
}

// One folder for the port, so runs that end without cleaning up (see above)
// leave at most one folder behind.
const runDirectory = join(tmpdir(), `bookwise-e2e-${port}`);
const sessionDirectory = join(runDirectory, 'sessions');
rmSync(runDirectory, { recursive: true, force: true });
mkdirSync(sessionDirectory, { recursive: true });

// The demo's settings are given explicitly, so a .env file in the project
// cannot change them. The database connection (DB_HOST, DB_PORT, DB_USER,
// DB_PASS) is left to the shell: load_env() in src/env.php never fills a
// variable the process already has, even an empty one, so a default set here
// would hide the .env file's values, such as a password kept there.
const environment = {
    ...process.env,
    APP_NAME: 'BookWise',
    APP_ENV: 'local',
    APP_DEBUG: 'false',
    APP_URL: `http://${host}:${port}`,
    APP_TIMEZONE: 'Asia/Riyadh',
    APP_DEFAULT_LOCALE: 'en',
    APP_DEMO: 'true',
    PAYMENT_SIMULATOR: 'true',
    PAYMENT_SIMULATOR_STORE: join(runDirectory, 'payment-simulator.json'),
    REQUIRE_EMAIL_VERIFICATION: process.env.REQUIRE_EMAIL_VERIFICATION || 'false',
    MAIL_DRIVER: 'database',
    LOG_DIR: join(runDirectory, 'logs'),
    STRIPE_CURRENCY: 'sar',
    DB_NAME: databaseName,
};

const run = (args) => {
    const result = spawnSync(php, args, { cwd: root, env: environment, stdio: 'inherit' });
    if (result.status !== 0) {
        console.error(`"${php} ${args.join(' ')}" failed${result.error ? `: ${result.error.message}` : ''}.`);
        rmSync(runDirectory, { recursive: true, force: true });
        process.exit(1);
    }
};

run(['e2e/support/reset-database.php']);
run(['bin/install.php', '--seed']);

console.log(`Serving the demo store on http://${host}:${port} (database ${databaseName}, files in ${runDirectory}).`);
const server = spawn(php, ['-d', `session.save_path=${sessionDirectory}`, '-S', `${host}:${port}`, '-t', 'public'], {
    cwd: root,
    env: environment,
    stdio: ['ignore', 'inherit', 'pipe'],
});
// The built-in server logs every connection; only other lines (start-up
// messages, PHP errors) are passed on.
const accessLog = /^\[[^\]]+\] [\d.:[\]a-f]+:\d+ (Accepted|Closing|\[\d{3}\]: )/;
let pending = '';
server.stderr.setEncoding('utf8');
server.stderr.on('data', (chunk) => {
    const lines = (pending + chunk).split(/\r?\n/);
    pending = lines.pop();
    for (const line of lines) {
        if (line !== '' && !accessLog.test(line)) {
            process.stderr.write(`${line}\n`);
        }
    }
});

const stop = () => {
    server.kill();
};
process.on('SIGINT', stop);
process.on('SIGTERM', stop);
server.on('exit', (code) => {
    rmSync(runDirectory, { recursive: true, force: true });
    process.exit(code ?? 0);
});
