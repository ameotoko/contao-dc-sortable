const Encore = require('@symfony/webpack-encore');

if (!Encore.isRuntimeEnvironmentConfigured()) {
  Encore.configureRuntimeEnvironment(process.env.NODE_ENV || 'dev');
}

Encore
  .setOutputPath('public/')
  .setPublicPath('/bundles/ameotokodcsortable')
  .setManifestKeyPrefix('')
  .cleanupOutputBeforeBuild()
  .disableSingleRuntimeChunk()
  .enableVersioning()
  .addEntry('dcsortable', './dcsortable.js')
;

module.exports = Encore.getWebpackConfig();
