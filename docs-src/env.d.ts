declare module '*.css';
declare module 'upng-js';
declare module '@jsquash/oxipng/codec/pkg/squoosh_oxipng.js';
declare module '@jsquash/webp/codec/enc/webp_enc.js';
declare module '@jsquash/avif/codec/enc/avif_enc.js';
declare module 'fs' {
	export function mkdirSync( path: string, options?: { recursive?: boolean } ): void;
	export function writeFileSync( path: string, data: string, encoding?: string ): void;
	export function readFileSync( path: string, encoding: string ): string;
	export function copyFileSync( src: string, dest: string ): void;
	export function chmodSync( path: string, mode: number ): void;
	export function statSync( path: string ): { mtime: Date };
	export function existsSync( path: string ): boolean;
	export function unlinkSync( path: string ): void;
}
declare module 'child_process' {
	export function execFileSync( file: string, args: string[], options?: { cwd?: string; encoding?: string } ): string;
}
declare module 'path' {
	export function dirname( p: string ): string;
	export function join( ...paths: string[] ): string;
}
declare var require: { main?: unknown };
declare var module: unknown;
declare var process: { cwd(): string };
