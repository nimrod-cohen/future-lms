const runFixtures = require('./wp');

module.exports = async () => {
  if (process.env.FLMS_E2E_KEEP) return; // leave the data in place to look at it
  runFixtures('teardown');
};
