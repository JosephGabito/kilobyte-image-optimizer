const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const fs = require( 'fs' );
const path = require( 'path' );

const manifestPath = path.resolve( __dirname, '.cache/docs-ssg/manifest.json' );

class DocsManifestPlugin {
	constructor( mapFn ) {
		this.mapFn = mapFn;
	}

	apply( compiler ) {
		compiler.hooks.afterEmit.tap( 'DocsManifestPlugin', ( compilation ) => {
			let current = {};
			try {
				if ( fs.existsSync( manifestPath ) ) {
					current = JSON.parse( fs.readFileSync( manifestPath, 'utf8' ) );
				}
			} catch {
				current = {};
			}
			const next = { ...current, ...this.mapFn( compilation ) };
			fs.mkdirSync( path.dirname( manifestPath ), { recursive: true } );
			fs.writeFileSync( manifestPath, JSON.stringify( next, null, 2 ) + '\n', 'utf8' );
		} );
	}
}

function getEntryAsset( compilation, entryName, ext ) {
	const entrypoint = compilation.entrypoints.get( entryName );
	if ( ! entrypoint ) {
		return null;
	}
	const files = entrypoint.getFiles();
	const match = files.find( ( f ) => f.endsWith( ext ) && ! f.endsWith( `-rtl${ ext }` ) );
	return match ? `/dist/${ match }` : null;
}

const basePlugins = ( defaultConfig.plugins || [] )
	.filter( ( plugin ) => {
		const name = plugin?.constructor?.name;
		return name !== 'DependencyExtractionWebpackPlugin' && name !== 'RtlCssPlugin';
	} )
	.map( ( plugin ) => {
		if ( plugin?.constructor?.name === 'MiniCssExtractPlugin' ) {
			const MiniCssExtractPlugin = plugin.constructor;
			return new MiniCssExtractPlugin( {
				filename: '[name].[contenthash:8].css',
			} );
		}
		return plugin;
	} );

// Keep /assets/fonts/... URLs untouched in CSS so they match <link rel="preload"> paths exactly.
function disableCssUrlResolve( rules ) {
	return rules.map( ( rule ) => {
		if ( ! rule || ! Array.isArray( rule.use ) ) {
			return rule;
		}
		return {
			...rule,
			use: rule.use.map( ( loader ) => {
				if (
					typeof loader === 'object' &&
					loader.loader &&
					loader.loader.includes( 'css-loader' ) &&
					! loader.loader.includes( 'postcss-loader' )
				) {
					return {
						...loader,
						options: {
							...( loader.options || {} ),
							url: false,
						},
					};
				}
				return loader;
			} ),
		};
	} );
}

const stripImportMetaRule = {
	test: /[\\/]node_modules[\\/]@jsquash[\\/](webp|avif)[\\/]codec[\\/]enc[\\/].*\.js$/,
	enforce: 'pre',
	use: [ path.resolve( __dirname, 'docs-src/site/stripImportMetaLoader.cjs' ) ],
};

const wasmAssetRule = {
	test: /\.wasm$/,
	type: 'asset/resource',
	generator: {
		filename: '../codecs/[name][ext]',
	},
};

const sharedResolve = {
	...defaultConfig.resolve,
	fallback: {
		...( defaultConfig.resolve?.fallback || {} ),
		fs: false,
		path: false,
		crypto: false,
		worker_threads: false,
	},
};

const browserConfig = {
	...defaultConfig,
	name: 'docs-browser',
	entry: {
		home: path.resolve( __dirname, 'docs-src/site/homeClient.ts' ),
		tool: path.resolve( __dirname, 'docs-src/site/toolClient.ts' ),
		nav: path.resolve( __dirname, 'docs-src/site/navClient.ts' ),
		site: path.resolve( __dirname, 'docs-src/site/cssEntry.ts' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'docs/dist' ),
		filename: '[name].[contenthash:8].js',
		chunkFilename: 'browser-[name].[contenthash:8].js',
		publicPath: 'auto',
		clean: false,
	},
	module: {
		...defaultConfig.module,
		rules: [ stripImportMetaRule, ...disableCssUrlResolve( defaultConfig.module.rules ), wasmAssetRule ],
	},
	resolve: sharedResolve,
	plugins: [
		...basePlugins,
		new DocsManifestPlugin( ( compilation ) => ( {
			siteCss: getEntryAsset( compilation, 'site', '.css' ),
			siteJsShim: getEntryAsset( compilation, 'site', '.js' ),
			homeJs: getEntryAsset( compilation, 'home', '.js' ),
			toolJs: getEntryAsset( compilation, 'tool', '.js' ),
			navJs: getEntryAsset( compilation, 'nav', '.js' ),
		} ) ),
	],
};

const workerConfig = {
	...defaultConfig,
	name: 'docs-worker',
	target: 'webworker',
	entry: {
		'tool-worker': path.resolve( __dirname, 'docs-src/engine/toolWorker.ts' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'docs/dist' ),
		filename: '[name].[contenthash:8].js',
		chunkFilename: 'worker-[name].[contenthash:8].js',
		publicPath: 'auto',
		clean: false,
	},
	module: {
		...defaultConfig.module,
		rules: [ stripImportMetaRule, ...defaultConfig.module.rules, wasmAssetRule ],
	},
	resolve: sharedResolve,
	plugins: [
		...basePlugins.filter( ( plugin ) => {
			const name = plugin?.constructor?.name;
			return name !== 'CleanWebpackPlugin' && name !== 'MiniCssExtractPlugin';
		} ),
		new DocsManifestPlugin( ( compilation ) => ( {
			toolWorkerJs: getEntryAsset( compilation, 'tool-worker', '.js' ),
		} ) ),
	],
};

const ssgConfig = {
	...defaultConfig,
	name: 'docs-ssg',
	target: 'node',
	entry: {
		prerender: path.resolve( __dirname, 'docs-src/site/prerender.ts' ),
		'core-test-bundle': path.resolve( __dirname, 'src-js/core/index.ts' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, '.cache/docs-ssg' ),
		filename: '[name].cjs',
		libraryTarget: 'commonjs2',
		clean: false,
	},
	plugins: basePlugins.filter( ( plugin ) => {
		const name = plugin?.constructor?.name;
		return name !== 'MiniCssExtractPlugin' && name !== 'CleanWebpackPlugin';
	} ),
};

module.exports = [ browserConfig, workerConfig, ssgConfig ];
