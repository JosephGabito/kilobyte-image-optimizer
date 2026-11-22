const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		admin: path.resolve( __dirname, 'src-js/presentation/admin/index.tsx' ),
		'image-worker': path.resolve( __dirname, 'src-js/infrastructure/imageWorker.ts' ),
		'upload-interceptor': path.resolve(
			__dirname,
			'src-js/presentation/upload/index.ts'
		),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
};
