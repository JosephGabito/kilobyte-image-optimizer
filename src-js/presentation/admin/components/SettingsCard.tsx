import { __ } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardDivider,
	CardFooter,
	CardHeader,
	Icon,
	PanelBody,
	RangeControl,
	ToggleControl,
	__experimentalGrid as Grid,
	__experimentalHStack as HStack,
	__experimentalHeading as Heading,
	__experimentalNumberControl as NumberControl,
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { cog } from '@wordpress/icons';
import {
	QUALITY_PRESETS,
	inferPresetFromQuality,
	type QualityPresetId,
} from '../../../domain/presets';
import type { OutputFormat, PluginSettings } from '../../../domain/types';

interface SettingsCardProps {
	settings: PluginSettings;
	dirty: boolean;
	saving: boolean;
	onChange: ( next: PluginSettings ) => void;
	onSave: () => void;
}

export function SettingsCard( {
	settings,
	dirty,
	saving,
	onChange,
	onSave,
}: SettingsCardProps ) {
	const patch = ( partial: Partial< PluginSettings > ) =>
		onChange( { ...settings, ...partial } );

	const activePreset = inferPresetFromQuality( settings.quality );

	const formatHelp: Record< OutputFormat, string > = {
		webp: __(
			'A smaller copy that works in most browsers.',
			'kilobyte-image-optimizer'
		),
		avif: __(
			'A smaller copy when your browser and site support it. WebP is used when available.',
			'kilobyte-image-optimizer'
		),
		original: __(
			'Compresses supported photos in the same file type.',
			'kilobyte-image-optimizer'
		),
	};

	const presetHelp: Record< QualityPresetId, string > = {
		high: __( 'Best for photography and portfolios (quality 90).', 'kilobyte-image-optimizer' ),
		balanced: __( 'Balances image quality and file size.', 'kilobyte-image-optimizer' ),
		compact: __( 'Smaller files with more visible changes.', 'kilobyte-image-optimizer' ),
	};

	return (
		<Card>
			<CardHeader>
				<HStack spacing={ 2 } justify="flex-start">
					<Icon icon={ cog } size={ 20 } />
					<Heading level={ 3 } size={ 15 }>
						{ __( 'Preference', 'kilobyte-image-optimizer' ) }
					</Heading>
				</HStack>
			</CardHeader>

			<fieldset disabled={ saving } style={ { border: 0, margin: 0, padding: 0, minWidth: 0 } }>
			<CardBody>
				<VStack spacing={ 5 }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Optimize images automatically on upload', 'kilobyte-image-optimizer' ) }
						help={ __(
							'Keep the original upload and create a smaller copy when supported.',
							'kilobyte-image-optimizer'
						) }
						checked={ settings.enabled }
						onChange={ ( enabled ) => patch( { enabled } ) }
					/>

					<ToggleGroupControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						isBlock
						label={ __( 'Image format', 'kilobyte-image-optimizer' ) }
						help={ formatHelp[ settings.format ] }
						value={ settings.format }
						onChange={ ( val ) => patch( { format: val as OutputFormat } ) }
					>
						<ToggleGroupControlOption
							value="webp"
							label={ __( 'WebP (recommended)', 'kilobyte-image-optimizer' ) }
						/>
						<ToggleGroupControlOption
							value="avif"
							label={ __( 'AVIF', 'kilobyte-image-optimizer' ) }
						/>
						<ToggleGroupControlOption
							value="original"
							label={ __( 'Keep original', 'kilobyte-image-optimizer' ) }
						/>
					</ToggleGroupControl>

					<ToggleGroupControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						isBlock
						label={ __( 'Compression level', 'kilobyte-image-optimizer' ) }
						help={ presetHelp[ activePreset ] }
						value={ activePreset }
						onChange={ ( val ) => {
							const preset = QUALITY_PRESETS[ val as QualityPresetId ];
							if ( preset ) {
								patch( { quality: preset.quality } );
							}
						} }
					>
						<ToggleGroupControlOption
							value="high"
							label={ __( 'High detail', 'kilobyte-image-optimizer' ) }
						/>
						<ToggleGroupControlOption
							value="balanced"
							label={ __( 'Balanced', 'kilobyte-image-optimizer' ) }
						/>
						<ToggleGroupControlOption
							value="compact"
							label={ __( 'Smallest files', 'kilobyte-image-optimizer' ) }
						/>
					</ToggleGroupControl>
				</VStack>
			</CardBody>

			<CardDivider />

			<PanelBody
				title={ __( 'Advanced', 'kilobyte-image-optimizer' ) }
				initialOpen={ false }
			>
				<VStack spacing={ 4 }>
					<RangeControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Fine-tune quality', 'kilobyte-image-optimizer' ) }
						help={ __( 'Adjust compression precisely between 40 and 95.', 'kilobyte-image-optimizer' ) }
						value={ settings.quality }
						min={ 40 }
						max={ 95 }
						onChange={ ( val ) => patch( { quality: val ?? 82 } ) }
					/>

					<Grid columns={ 2 } gap={ 4 }>
						<NumberControl
							__next40pxDefaultSize
							label={ __( 'Maximum width (px)', 'kilobyte-image-optimizer' ) }
							help={ __( 'Resize oversized uploads.', 'kilobyte-image-optimizer' ) }
							min={ 0 }
							max={ 8000 }
							value={ settings.max_width }
							onChange={ ( val ) =>
								patch( { max_width: Number( val || 0 ) } )
							}
						/>
						<NumberControl
							__next40pxDefaultSize
							label={ __( 'Maximum height (px)', 'kilobyte-image-optimizer' ) }
							help={ __( 'Set to 0 to keep original size.', 'kilobyte-image-optimizer' ) }
							min={ 0 }
							max={ 8000 }
							value={ settings.max_height }
							onChange={ ( val ) =>
								patch( { max_height: Number( val || 0 ) } )
							}
						/>
					</Grid>

					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( "Keep original if compression doesn't save space", 'kilobyte-image-optimizer' ) }
						checked={ settings.skip_smaller }
						onChange={ ( skip_smaller ) => patch( { skip_smaller } ) }
					/>

					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Generate alt text from filenames', 'kilobyte-image-optimizer' ) }
						help={ __(
							'Fill in missing image descriptions automatically using clean filenames.',
							'kilobyte-image-optimizer'
						) }
						checked={ settings.auto_alt_text }
						onChange={ ( auto_alt_text ) => patch( { auto_alt_text } ) }
					/>

					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Add image decoding hints', 'kilobyte-image-optimizer' ) }
						help={ __(
							'Suggests that browsers decode images in the background.',
							'kilobyte-image-optimizer'
						) }
						checked={ settings.cwv_enhancements }
						onChange={ ( cwv_enhancements ) => patch( { cwv_enhancements } ) }
					/>
				</VStack>
			</PanelBody>
			</fieldset>

			<CardFooter justify="flex-end">
				<Button
					variant="secondary"
					size="compact"
					isBusy={ saving }
					disabled={ saving || ! dirty }
					onClick={ onSave }
				>
					{ saving
						? __( 'Saving...', 'kilobyte-image-optimizer' )
						: __( 'Save preference', 'kilobyte-image-optimizer' ) }
				</Button>
			</CardFooter>
		</Card>
	);
}
