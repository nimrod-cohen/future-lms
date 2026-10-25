const fs = require('fs');
const path = require('path');

/** Ids and dates created by global-setup for this run. */
module.exports = () => JSON.parse(fs.readFileSync(path.join(__dirname, '.auth', 'fixtures.json'), 'utf8'));
