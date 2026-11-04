// Runs the fixtures script through wp-cli on the local site.
const { execFileSync } = require('child_process');
const path = require('path');

const siteRoot = process.env.FLMS_SITE_ROOT || path.resolve(__dirname, '../../../../..');
const script = path.join(__dirname, 'fixtures', 'wp-fixtures.php');

module.exports = function runFixtures(command, ...args) {
  const out = execFileSync(process.env.WP_CLI || 'wp', ['eval-file', script, command, ...args.map(String)], {
    cwd: siteRoot,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'inherit'],
  });
  // wp-cli may print notices before the JSON; the JSON is the last line.
  const line = out.trim().split('\n').pop();
  return JSON.parse(line);
};
