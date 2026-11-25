const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

const plugins = ( defaultConfig.plugins || [] ).filter( ( plugin ) => {
	const name = plugin?.constructor?.name;
	return name !== 'DependencyExtractionWebpackPlugin';
} );

module.exports = {
	...defaultConfig,
	entry: {
		landing: path.resolve( __dirname, 'docs-src/index.tsx' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'docs/dist' ),
		filename: '[name].js',
	},
	plugins,
};
