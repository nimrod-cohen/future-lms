const fs = require('fs');
const path = require('path');
const runFixtures = require('./wp');

module.exports = async config => {
  const baseURL = config.projects[0].use.baseURL;
  const { hostname } = new URL(baseURL);
  const data = runFixtures('setup');

  const authDir = path.join(__dirname, '.auth');
  fs.mkdirSync(authDir, { recursive: true });
  fs.writeFileSync(path.join(authDir, 'fixtures.json'), JSON.stringify(data, null, 2));

  for (const who of ['admin', 'student']) {
    const cookies = data.cookies[who].map(c => ({
      name: c.name,
      value: c.value,
      domain: hostname,
      path: '/',
      expires: Math.floor(Date.now() / 1000) + 4 * 3600,
      httpOnly: true,
      secure: baseURL.startsWith('https'),
      sameSite: 'Lax',
    }));
    // The site's cookie banner covers the page until answered; answer it the
    // most private way (essential only) so it doesn't block clicks.
    const consent = { essential: true, analytics: false, marketing: false, ts: Date.now() };
    const origins = [{ origin: new URL(baseURL).origin, localStorage: [{ name: 'scb_consent', value: JSON.stringify(consent) }] }];
    fs.writeFileSync(path.join(authDir, `${who}.json`), JSON.stringify({ cookies, origins }));
  }
};
