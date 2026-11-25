// Strip Emscripten's `import.meta.url` from single-threaded @jsquash codec glue
// so webpack never embeds local developer `file://` paths into public bundles.
// Runtime WASM URLs are always supplied explicitly via `locateFile` in codecs.ts.
module.exports = function stripImportMetaLoader( source ) {
	return source
		.replace(
			/if\s*\(\s*import\.meta\.url\s*===\s*undefined\s*\)\s*\{\s*import\.meta\.url\s*=\s*["'][^"']*["']\s*;?\s*\}/g,
			''
		)
		.replace( /\bimport\.meta\.url\b/g, '""' );
};
