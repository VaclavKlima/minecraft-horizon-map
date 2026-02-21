use serde::Deserialize;
use std::collections::HashMap;
use std::fs;
use std::fs::File;
use std::io::BufWriter;
use std::process::ExitCode;

const DEPTH_SCALE: f64 = 0.6;
const HEIGHT_BASELINE: i32 = -128;
const HEIGHT_CEILING: i32 = 384;
const HORIZONTAL_PADDING: i32 = 1;
const TOP_PADDING: i32 = 1;
const BOTTOM_PADDING: usize = 8;
const SHADOW_TINT_COLOR: [u8; 3] = [92, 106, 134];
const SHADOW_TINT_STRENGTH: f64 = 0.22;

type RunsEntry = (u32, Vec<i32>);

#[derive(Deserialize)]
struct RenderInput {
    source_width: Option<usize>,
    source_height: Option<usize>,
    chunks: Vec<ChunkInput>,
}

#[derive(Deserialize)]
struct ChunkInput {
    chunk_x: i32,
    chunk_z: i32,
    sections: Vec<SectionInput>,
}

#[derive(Deserialize)]
struct SectionInput {
    section_y: i32,
    palette_is_air: Vec<bool>,
    palette_is_water: Vec<bool>,
    palette_uses_grass_tint: Vec<bool>,
    palette_uses_foliage_tint: Vec<bool>,
    palette_colors: Vec<[u8; 3]>,
    uniform_palette_index: Option<usize>,
    block_data_words: Vec<LongWord>,
    bits_per_entry: Option<u8>,
    values_per_long: Option<usize>,
    uses_padded_layout: bool,
    biome_palette_tints: Vec<[u8; 3]>,
    biome_uniform_palette_index: Option<usize>,
    biome_data_words: Vec<LongWord>,
    biome_bits_per_entry: Option<u8>,
    biome_values_per_long: Option<usize>,
    biome_uses_padded_layout: bool,
}

#[derive(Deserialize)]
struct LongWord {
    hi: u32,
    lo: u32,
}

fn parse_args() -> Result<HashMap<String, String>, String> {
    let mut args = std::env::args().skip(1);
    let mut values = HashMap::new();

    while let Some(key) = args.next() {
        if !key.starts_with("--") {
            return Err(format!("unexpected argument: {key}"));
        }

        let value = args
            .next()
            .ok_or_else(|| format!("missing value for argument: {key}"))?;
        values.insert(key, value);
    }

    Ok(values)
}

fn require_arg(values: &HashMap<String, String>, key: &str) -> Result<String, String> {
    values
        .get(key)
        .cloned()
        .ok_or_else(|| format!("missing required argument: {key}"))
}

fn parse_u32_arg(values: &HashMap<String, String>, key: &str) -> Result<u32, String> {
    let value = require_arg(values, key)?;
    value
        .parse::<u32>()
        .map_err(|_| format!("invalid unsigned integer for {key}: {value}"))
}

fn depth_offset_for_height(height: i32) -> i32 {
    let clamped = height.clamp(HEIGHT_BASELINE, HEIGHT_CEILING);
    ((clamped - HEIGHT_BASELINE) as f64 * DEPTH_SCALE).round() as i32
}

fn lut(multiplier: f64) -> [u8; 256] {
    let mut out = [0u8; 256];

    for channel in 0..=255 {
        out[channel] = (channel as f64 * multiplier).round() as u8;
    }

    out
}

fn color_at(colors: &[u8], voxel_index: usize) -> (u8, u8, u8) {
    let offset = voxel_index * 3;
    (colors[offset], colors[offset + 1], colors[offset + 2])
}

fn apply_brightness(channel: u8, factor: f64) -> u8 {
    (channel as f64 * factor).round().clamp(0.0, 255.0) as u8
}

fn apply_height_shading(red: u8, green: u8, blue: u8, world_y: i32) -> (u8, u8, u8) {
    let normalized_height = (world_y.clamp(HEIGHT_BASELINE, HEIGHT_CEILING) - HEIGHT_BASELINE)
        as f64
        / (HEIGHT_CEILING - HEIGHT_BASELINE) as f64;
    let mut brightness_factor = 0.86 + (normalized_height * 0.28);

    // Subtle terrace cue every 8 levels to make vertical differences easier to read.
    if world_y.rem_euclid(8) == 0 {
        brightness_factor *= 0.92;
    }

    (
        apply_brightness(red, brightness_factor),
        apply_brightness(green, brightness_factor),
        apply_brightness(blue, brightness_factor),
    )
}

fn apply_water_depth_shading(red: u8, green: u8, blue: u8, water_depth: u16) -> (u8, u8, u8) {
    if water_depth <= 1 {
        return (red, green, blue);
    }

    let depth_steps = (water_depth.saturating_sub(1).min(20)) as f64;
    let brightness_factor = 1.0 - (depth_steps * 0.03);
    let depth_blue_lift = (depth_steps * 1.2).round() as i32;
    let shaded_red = apply_brightness(red, brightness_factor);
    let shaded_green = apply_brightness(green, brightness_factor);
    let shaded_blue = apply_brightness(blue, brightness_factor) as i32 + depth_blue_lift;

    (shaded_red, shaded_green, shaded_blue.clamp(0, 255) as u8)
}

fn write_png(path: &str, width: usize, height: usize, rgba: &[u8]) -> Result<(), String> {
    let file =
        File::create(path).map_err(|error| format!("unable to create output file: {error}"))?;
    let writer = BufWriter::new(file);
    let mut encoder = png::Encoder::new(writer, width as u32, height as u32);
    encoder.set_color(png::ColorType::Rgba);
    encoder.set_depth(png::BitDepth::Eight);
    let mut png_writer = encoder
        .write_header()
        .map_err(|error| format!("unable to write png header: {error}"))?;
    png_writer
        .write_image_data(rgba)
        .map_err(|error| format!("unable to write png data: {error}"))?;

    Ok(())
}

fn read_packed_bits(
    words: &[LongWord],
    long_index: usize,
    bit_offset: usize,
    bit_count: usize,
) -> u32 {
    if long_index >= words.len() || bit_count == 0 {
        return 0;
    }

    let first_word = ((words[long_index].hi as u64) << 32) | words[long_index].lo as u64;

    if bit_offset + bit_count <= 64 {
        let mask = if bit_count == 64 {
            u64::MAX
        } else {
            (1u64 << bit_count) - 1
        };

        return ((first_word >> bit_offset) & mask) as u32;
    }

    let first_part_bit_count = 64 - bit_offset;
    let first_mask = if first_part_bit_count == 64 {
        u64::MAX
    } else {
        (1u64 << first_part_bit_count) - 1
    };
    let first_part = (first_word >> bit_offset) & first_mask;
    let remaining_bit_count = bit_count - first_part_bit_count;

    if long_index + 1 >= words.len() {
        return first_part as u32;
    }

    let second_word = ((words[long_index + 1].hi as u64) << 32) | words[long_index + 1].lo as u64;
    let second_mask = if remaining_bit_count == 64 {
        u64::MAX
    } else {
        (1u64 << remaining_bit_count) - 1
    };
    let second_part = second_word & second_mask;

    (first_part | (second_part << first_part_bit_count)) as u32
}

fn palette_index_at(
    section: &SectionInput,
    local_x: usize,
    local_z: usize,
    local_y: usize,
) -> usize {
    let Some(bits_per_entry_u8) = section.bits_per_entry else {
        return 0;
    };

    if section.block_data_words.is_empty() {
        return 0;
    }

    let bits_per_entry = bits_per_entry_u8 as usize;
    let block_index = (local_y * 256) + (local_z * 16) + local_x;

    if section.uses_padded_layout {
        let values_per_long = section.values_per_long.unwrap_or(0);

        if values_per_long == 0 {
            return 0;
        }

        let long_index = block_index / values_per_long;
        let index_in_long = block_index % values_per_long;
        let bit_offset = index_in_long * bits_per_entry;

        return read_packed_bits(
            &section.block_data_words,
            long_index,
            bit_offset,
            bits_per_entry,
        ) as usize;
    }

    let start_bit = block_index * bits_per_entry;
    let long_index = start_bit / 64;
    let bit_offset = start_bit % 64;

    read_packed_bits(
        &section.block_data_words,
        long_index,
        bit_offset,
        bits_per_entry,
    ) as usize
}

fn is_air_palette(section: &SectionInput, palette_index: usize) -> bool {
    section
        .palette_is_air
        .get(palette_index)
        .copied()
        .unwrap_or(true)
}

fn is_water_palette(section: &SectionInput, palette_index: usize) -> bool {
    section
        .palette_is_water
        .get(palette_index)
        .copied()
        .unwrap_or(false)
}

fn uses_grass_tint(section: &SectionInput, palette_index: usize) -> bool {
    section
        .palette_uses_grass_tint
        .get(palette_index)
        .copied()
        .unwrap_or(false)
}

fn uses_foliage_tint(section: &SectionInput, palette_index: usize) -> bool {
    section
        .palette_uses_foliage_tint
        .get(palette_index)
        .copied()
        .unwrap_or(false)
}

fn palette_color(section: &SectionInput, palette_index: usize) -> [u8; 3] {
    section
        .palette_colors
        .get(palette_index)
        .copied()
        .unwrap_or([90, 90, 92])
}

fn biome_palette_index_at(
    section: &SectionInput,
    local_x: usize,
    local_z: usize,
    local_y: usize,
) -> usize {
    if let Some(uniform_palette_index) = section.biome_uniform_palette_index {
        return uniform_palette_index;
    }

    let Some(bits_per_entry_u8) = section.biome_bits_per_entry else {
        return 0;
    };

    if section.biome_data_words.is_empty() {
        return 0;
    }

    let biome_x = local_x / 4;
    let biome_z = local_z / 4;
    let biome_y = local_y / 4;
    let biome_index = (biome_y * 16) + (biome_z * 4) + biome_x;
    let bits_per_entry = bits_per_entry_u8 as usize;

    if section.biome_uses_padded_layout {
        let values_per_long = section.biome_values_per_long.unwrap_or(0);

        if values_per_long == 0 {
            return 0;
        }

        let long_index = biome_index / values_per_long;
        let index_in_long = biome_index % values_per_long;
        let bit_offset = index_in_long * bits_per_entry;

        return read_packed_bits(
            &section.biome_data_words,
            long_index,
            bit_offset,
            bits_per_entry,
        ) as usize;
    }

    let start_bit = biome_index * bits_per_entry;
    let long_index = start_bit / 64;
    let bit_offset = start_bit % 64;

    read_packed_bits(
        &section.biome_data_words,
        long_index,
        bit_offset,
        bits_per_entry,
    ) as usize
}

fn biome_tint(section: &SectionInput, palette_index: usize) -> [u8; 3] {
    section
        .biome_palette_tints
        .get(palette_index)
        .copied()
        .unwrap_or([255, 255, 255])
}

fn blend_tint(base: [u8; 3], tint: [u8; 3], strength: f64) -> [u8; 3] {
    let tinted = [
        ((base[0] as f64 * tint[0] as f64) / 255.0)
            .round()
            .clamp(0.0, 255.0) as u8,
        ((base[1] as f64 * tint[1] as f64) / 255.0)
            .round()
            .clamp(0.0, 255.0) as u8,
        ((base[2] as f64 * tint[2] as f64) / 255.0)
            .round()
            .clamp(0.0, 255.0) as u8,
    ];
    let clamped_strength = strength.clamp(0.0, 1.0);

    [
        (base[0] as f64 + ((tinted[0] as f64 - base[0] as f64) * clamped_strength))
            .round()
            .clamp(0.0, 255.0) as u8,
        (base[1] as f64 + ((tinted[1] as f64 - base[1] as f64) * clamped_strength))
            .round()
            .clamp(0.0, 255.0) as u8,
        (base[2] as f64 + ((tinted[2] as f64 - base[2] as f64) * clamped_strength))
            .round()
            .clamp(0.0, 255.0) as u8,
    ]
}

fn apply_shadow_tint(red: u8, green: u8, blue: u8) -> (u8, u8, u8) {
    let strength = SHADOW_TINT_STRENGTH.clamp(0.0, 1.0);
    let mixed_red = (red as f64 * (1.0 - strength) + SHADOW_TINT_COLOR[0] as f64 * strength)
        .round()
        .max((red as f64 * 0.55) + 24.0)
        .min(255.0) as u8;
    let mixed_green = (green as f64 * (1.0 - strength) + SHADOW_TINT_COLOR[1] as f64 * strength)
        .round()
        .max((green as f64 * 0.55) + 24.0)
        .min(255.0) as u8;
    let mixed_blue = (blue as f64 * (1.0 - strength) + SHADOW_TINT_COLOR[2] as f64 * strength)
        .round()
        .max((blue as f64 * 0.55) + 24.0)
        .min(255.0) as u8;

    (mixed_red, mixed_green, mixed_blue)
}

fn chunk_local_bounds(
    chunk_x: i32,
    chunk_z: i32,
    source_width: usize,
    source_height: usize,
) -> Option<(usize, usize, usize, usize)> {
    let chunk_base_x = chunk_x * 16;
    let chunk_base_z = chunk_z * 16;

    let min_local_x = 0.max(-chunk_base_x) as usize;
    let max_local_x = 15.min((source_width as i32 - 1) - chunk_base_x) as usize;
    let min_local_z = 0.max(-chunk_base_z) as usize;
    let max_local_z = 15.min((source_height as i32 - 1) - chunk_base_z) as usize;

    if min_local_x > max_local_x || min_local_z > max_local_z {
        return None;
    }

    Some((min_local_x, max_local_x, min_local_z, max_local_z))
}

fn set_voxel(
    occupancy: &mut [u8],
    colors: &mut [u8],
    water_mask: &mut [u8],
    source_width: usize,
    height_span: usize,
    world_x: usize,
    world_z: usize,
    y_offset: usize,
    color: [u8; 3],
    is_water: bool,
) {
    let column_index = (world_z * source_width) + world_x;
    let voxel_index = (column_index * height_span) + y_offset;
    let byte_index = voxel_index >> 3;
    let bit_index = voxel_index & 7;
    occupancy[byte_index] |= 1u8 << bit_index;

    let color_offset = voxel_index * 3;
    colors[color_offset] = color[0];
    colors[color_offset + 1] = color[1];
    colors[color_offset + 2] = color[2];
    water_mask[voxel_index] = if is_water { 1 } else { 0 };
}

fn is_voxel_solid(occupancy: &[u8], voxel_index: usize) -> bool {
    let byte_index = voxel_index >> 3;
    let bit_index = voxel_index & 7;
    (occupancy[byte_index] & (1u8 << bit_index)) != 0
}

fn main() -> ExitCode {
    let args = match parse_args() {
        Ok(args) => args,
        Err(error) => {
            eprintln!("{error}");

            return ExitCode::from(2);
        }
    };

    let output_path = match require_arg(&args, "--output-path") {
        Ok(value) => value,
        Err(error) => {
            eprintln!("{error}");

            return ExitCode::from(2);
        }
    };
    let source_width = match parse_u32_arg(&args, "--source-width") {
        Ok(value) => value as usize,
        Err(error) => {
            eprintln!("{error}");

            return ExitCode::from(2);
        }
    };
    let source_height = match parse_u32_arg(&args, "--source-height") {
        Ok(value) => value as usize,
        Err(error) => {
            eprintln!("{error}");

            return ExitCode::from(2);
        }
    };
    let pixel_scale = match parse_u32_arg(&args, "--pixel-scale") {
        Ok(value) if value > 0 => value as usize,
        Ok(_) => {
            eprintln!("invalid unsigned integer for --pixel-scale: 0");

            return ExitCode::from(2);
        }
        Err(error) => {
            eprintln!("{error}");

            return ExitCode::from(2);
        }
    };
    let sections_path = match require_arg(&args, "--sections-path") {
        Ok(value) => value,
        Err(error) => {
            eprintln!("{error}");

            return ExitCode::from(2);
        }
    };

    let input_raw = match fs::read(sections_path) {
        Ok(value) => value,
        Err(error) => {
            eprintln!("unable to read sections data: {error}");

            return ExitCode::from(1);
        }
    };

    let input: RenderInput = match serde_json::from_slice(&input_raw) {
        Ok(value) => value,
        Err(error) => {
            eprintln!("unable to parse sections data: {error}");

            return ExitCode::from(1);
        }
    };

    if let Some(input_width) = input.source_width {
        if input_width != source_width {
            eprintln!(
                "sections snapshot source_width mismatch: expected {source_width}, got {input_width}"
            );

            return ExitCode::from(1);
        }
    }

    if let Some(input_height) = input.source_height {
        if input_height != source_height {
            eprintln!(
                "sections snapshot source_height mismatch: expected {source_height}, got {input_height}"
            );

            return ExitCode::from(1);
        }
    }

    let mut min_y: Option<i32> = None;
    let mut max_y: Option<i32> = None;

    for chunk in &input.chunks {
        let Some((min_local_x, max_local_x, min_local_z, max_local_z)) =
            chunk_local_bounds(chunk.chunk_x, chunk.chunk_z, source_width, source_height)
        else {
            continue;
        };

        for section in &chunk.sections {
            if let Some(uniform_palette_index) = section.uniform_palette_index {
                if is_air_palette(section, uniform_palette_index) {
                    continue;
                }

                let section_min_y = section.section_y * 16;
                let section_max_y = section_min_y + 15;
                min_y = Some(min_y.map_or(section_min_y, |value| value.min(section_min_y)));
                max_y = Some(max_y.map_or(section_max_y, |value| value.max(section_max_y)));

                continue;
            }

            for local_y in 0..16usize {
                let world_y = (section.section_y * 16) + local_y as i32;

                for local_z in min_local_z..=max_local_z {
                    for local_x in min_local_x..=max_local_x {
                        let palette_index = palette_index_at(section, local_x, local_z, local_y);

                        if is_air_palette(section, palette_index) {
                            continue;
                        }

                        min_y = Some(min_y.map_or(world_y, |value| value.min(world_y)));
                        max_y = Some(max_y.map_or(world_y, |value| value.max(world_y)));
                    }
                }
            }
        }
    }

    let Some(min_y) = min_y else {
        return ExitCode::from(3);
    };
    let Some(max_y) = max_y else {
        return ExitCode::from(3);
    };

    let height_span = (max_y - min_y + 1) as usize;
    let total_columns = source_width * source_height;
    let total_voxels = total_columns * height_span;
    let mut occupancy = vec![0u8; (total_voxels + 7) / 8];
    let mut colors = vec![0u8; total_voxels * 3];
    let mut water_mask = vec![0u8; total_voxels];

    for chunk in &input.chunks {
        let Some((min_local_x, max_local_x, min_local_z, max_local_z)) =
            chunk_local_bounds(chunk.chunk_x, chunk.chunk_z, source_width, source_height)
        else {
            continue;
        };

        let chunk_base_x = chunk.chunk_x * 16;
        let chunk_base_z = chunk.chunk_z * 16;

        for section in &chunk.sections {
            if let Some(uniform_palette_index) = section.uniform_palette_index {
                if is_air_palette(section, uniform_palette_index) {
                    continue;
                }

                let color = palette_color(section, uniform_palette_index);
                let is_water = is_water_palette(section, uniform_palette_index);

                for local_y in 0..16usize {
                    let world_y = (section.section_y * 16) + local_y as i32;

                    if world_y < min_y || world_y > max_y {
                        continue;
                    }

                    let y_offset = (world_y - min_y) as usize;

                    for local_z in min_local_z..=max_local_z {
                        let world_z = (chunk_base_z + local_z as i32) as usize;

                        for local_x in min_local_x..=max_local_x {
                            let world_x = (chunk_base_x + local_x as i32) as usize;
                            let biome_index =
                                biome_palette_index_at(section, local_x, local_z, local_y);
                            let biome_tint = biome_tint(section, biome_index);
                            let tinted_color = if uses_grass_tint(section, uniform_palette_index) {
                                blend_tint(color, biome_tint, 0.45)
                            } else if uses_foliage_tint(section, uniform_palette_index) {
                                blend_tint(color, biome_tint, 0.55)
                            } else {
                                color
                            };
                            set_voxel(
                                &mut occupancy,
                                &mut colors,
                                &mut water_mask,
                                source_width,
                                height_span,
                                world_x,
                                world_z,
                                y_offset,
                                tinted_color,
                                is_water,
                            );
                        }
                    }
                }

                continue;
            }

            for local_y in 0..16usize {
                let world_y = (section.section_y * 16) + local_y as i32;

                if world_y < min_y || world_y > max_y {
                    continue;
                }

                let y_offset = (world_y - min_y) as usize;

                for local_z in min_local_z..=max_local_z {
                    let world_z = (chunk_base_z + local_z as i32) as usize;

                    for local_x in min_local_x..=max_local_x {
                        let palette_index = palette_index_at(section, local_x, local_z, local_y);

                        if is_air_palette(section, palette_index) {
                            continue;
                        }

                        let color = palette_color(section, palette_index);
                        let is_water = is_water_palette(section, palette_index);
                        let biome_index =
                            biome_palette_index_at(section, local_x, local_z, local_y);
                        let biome_tint = biome_tint(section, biome_index);
                        let tinted_color = if uses_grass_tint(section, palette_index) {
                            blend_tint(color, biome_tint, 0.45)
                        } else if uses_foliage_tint(section, palette_index) {
                            blend_tint(color, biome_tint, 0.55)
                        } else {
                            color
                        };
                        let world_x = (chunk_base_x + local_x as i32) as usize;
                        set_voxel(
                            &mut occupancy,
                            &mut colors,
                            &mut water_mask,
                            source_width,
                            height_span,
                            world_x,
                            world_z,
                            y_offset,
                            tinted_color,
                            is_water,
                        );
                    }
                }
            }
        }
    }

    let mut water_depths = vec![0u16; total_voxels];
    for column_index in 0..total_columns {
        let mut current_depth = 0u16;
        let column_base_index = column_index * height_span;

        for y_offset in (0..height_span).rev() {
            let voxel_index = column_base_index + y_offset;

            if is_voxel_solid(&occupancy, voxel_index) && water_mask[voxel_index] == 1 {
                current_depth = current_depth.saturating_add(1);
                water_depths[voxel_index] = current_depth;
            } else {
                current_depth = 0;
            }
        }
    }

    let mut runs: Vec<RunsEntry> = Vec::new();
    for column_index in 0..total_columns {
        let mut column_runs: Vec<i32> = Vec::new();
        let column_voxel_base_index = column_index * height_span;
        let mut current = height_span as i32 - 1;

        while current >= 0 {
            let voxel_index = column_voxel_base_index + current as usize;

            if !is_voxel_solid(&occupancy, voxel_index) {
                current -= 1;
                continue;
            }

            let run_top = current;
            let mut run_bottom = current;

            while run_bottom > 0 {
                let next_index = column_voxel_base_index + (run_bottom - 1) as usize;

                if !is_voxel_solid(&occupancy, next_index) {
                    break;
                }

                run_bottom -= 1;
            }

            column_runs.push(min_y + run_top);
            column_runs.push(min_y + run_bottom);
            current = run_bottom - 1;
        }

        if !column_runs.is_empty() {
            runs.push((column_index as u32, column_runs));
        }
    }

    let vertical_depth_padding = (height_span as f64 * DEPTH_SCALE).ceil() as i32;
    let min_depth_offset = depth_offset_for_height(min_y + 1);
    let max_depth_offset = depth_offset_for_height(max_y + 1);
    let vertical_shift = (max_depth_offset - vertical_depth_padding).max(0) + TOP_PADDING;
    let logical_iso_width = source_width + source_height + ((HORIZONTAL_PADDING * 2) as usize);
    let max_iso_base_y = ((source_width + source_height - 2) as f64 / 2.0).floor() as i32
        + vertical_depth_padding
        + vertical_shift;
    let max_projected_y = max_iso_base_y - min_depth_offset + 1;
    let logical_iso_height = (max_projected_y.max(0) as usize) + 1 + BOTTOM_PADDING;
    let iso_width = logical_iso_width * pixel_scale;
    let iso_height = logical_iso_height * pixel_scale;
    let image_pixel_count = iso_width * iso_height;
    let mut rgba = vec![0u8; image_pixel_count * 4];
    let mut depth_buffer = vec![i32::MIN; image_pixel_count];

    let east_lut = lut(0.8);
    let south_lut = lut(0.72);
    let shadow_alpha = 176u8;
    let opaque_alpha = 255u8;

    let mut depth_offsets_by_y = vec![0i32; (max_y - min_y + 1) as usize];
    for world_y in min_y..=max_y {
        let index = (world_y - min_y) as usize;
        depth_offsets_by_y[index] = depth_offset_for_height(world_y + 1);
    }

    let mut column_iso_base = vec![0i32; total_columns];
    for column_index in 0..total_columns {
        let world_x = (column_index % source_width) as i32;
        let world_z = (column_index / source_width) as i32;
        column_iso_base[column_index] = ((world_x + world_z) as f64 / 2.0).floor() as i32
            + vertical_depth_padding
            + vertical_shift;
    }

    let mut runs_lookup: HashMap<u32, usize> = HashMap::with_capacity(runs.len());
    for (index, entry) in runs.iter().enumerate() {
        runs_lookup.insert(entry.0, index);
    }

    let mut plot_pixel_if_closer =
        |x: i32, y: i32, red: u8, green: u8, blue: u8, alpha: u8, depth: i32| {
            if x < 0 || y < 0 {
                return;
            }

            let logical_x = x as usize;
            let logical_y = y as usize;

            if logical_x >= logical_iso_width || logical_y >= logical_iso_height {
                return;
            }

            let base_x = logical_x * pixel_scale;
            let base_y = logical_y * pixel_scale;

            for scale_y in 0..pixel_scale {
                for scale_x in 0..pixel_scale {
                    let index = ((base_y + scale_y) * iso_width) + (base_x + scale_x);

                    if depth_buffer[index] > depth {
                        continue;
                    }

                    depth_buffer[index] = depth;
                    let rgba_offset = index * 4;
                    rgba[rgba_offset] = red;
                    rgba[rgba_offset + 1] = green;
                    rgba[rgba_offset + 2] = blue;
                    rgba[rgba_offset + 3] = alpha;
                }
            }
        };

    for (column_index_u32, runs_values) in &runs {
        let column_index = *column_index_u32 as usize;
        let world_x = (column_index % source_width) as i32;
        let world_z = (column_index / source_width) as i32;
        let east_column_index = column_index + 1;
        let south_column_index = column_index + source_width;
        let iso_base_y = column_iso_base[column_index];
        let mut pending_shadow_source_y: Option<i32> = None;
        let column_voxel_base_index = column_index * height_span;
        let column_depth_base = (world_x + world_z) * 8192;
        let iso_x = (world_x - world_z) + (source_height as i32 - 1) + HORIZONTAL_PADDING;
        let east_at_boundary = world_x + 1 >= source_width as i32;
        let south_at_boundary = world_z + 1 >= source_height as i32;
        let east_neighbor_runs = if east_at_boundary {
            None
        } else {
            runs_lookup
                .get(&(east_column_index as u32))
                .map(|index| &runs[*index].1)
        };
        let south_neighbor_runs = if south_at_boundary {
            None
        } else {
            runs_lookup
                .get(&(south_column_index as u32))
                .map(|index| &runs[*index].1)
        };

        let mut run_index = 0usize;
        while run_index + 1 < runs_values.len() {
            let run_top = runs_values[run_index];
            let run_bottom = runs_values[run_index + 1];

            if let Some(shadow_source_y) = pending_shadow_source_y {
                let target_y_offset = (run_top - min_y) as usize;
                let target_voxel_index = column_voxel_base_index + target_y_offset;
                let (red, green, blue) = color_at(&colors, target_voxel_index);
                let (shade_red, shade_green, shade_blue) =
                    apply_height_shading(red, green, blue, run_top);
                let shadow_gap = shadow_source_y - run_top;

                if shadow_gap >= 2 && (red != 0 || green != 0 || blue != 0) {
                    let underlay_iso_y =
                        iso_base_y - depth_offsets_by_y[(run_top - min_y) as usize];
                    let shadow_depth = column_depth_base + (run_top * 4) + 4;
                    let (shadow_red, shadow_green, shadow_blue) =
                        apply_shadow_tint(shade_red, shade_green, shade_blue);
                    plot_pixel_if_closer(
                        iso_x,
                        underlay_iso_y,
                        shadow_red,
                        shadow_green,
                        shadow_blue,
                        shadow_alpha,
                        shadow_depth,
                    );
                }
            }

            let top_y_offset = (run_top - min_y) as usize;
            let top_voxel_index = column_voxel_base_index + top_y_offset;
            let (top_red, top_green, top_blue) = color_at(&colors, top_voxel_index);
            let (top_red, top_green, top_blue) =
                apply_height_shading(top_red, top_green, top_blue, run_top);
            let (top_red, top_green, top_blue) = if water_mask[top_voxel_index] == 1 {
                apply_water_depth_shading(
                    top_red,
                    top_green,
                    top_blue,
                    water_depths[top_voxel_index],
                )
            } else {
                (top_red, top_green, top_blue)
            };
            let iso_y = iso_base_y - depth_offsets_by_y[(run_top - min_y) as usize];
            let base_depth = column_depth_base + (run_top * 4);
            plot_pixel_if_closer(
                iso_x,
                iso_y,
                top_red,
                top_green,
                top_blue,
                opaque_alpha,
                base_depth + 3,
            );
            pending_shadow_source_y = Some(run_top);

            if east_at_boundary || east_neighbor_runs.is_none() {
                for world_y in (run_bottom..=run_top).rev() {
                    let y_offset = (world_y - min_y) as usize;
                    let voxel_index = column_voxel_base_index + y_offset;
                    let (red, green, blue) = color_at(&colors, voxel_index);
                    let (red, green, blue) = apply_height_shading(red, green, blue, world_y);
                    let iso_y = iso_base_y - depth_offsets_by_y[(world_y - min_y) as usize];
                    let base_depth = column_depth_base + (world_y * 4);
                    plot_pixel_if_closer(
                        iso_x + 1,
                        iso_y + 1,
                        east_lut[red as usize],
                        east_lut[green as usize],
                        east_lut[blue as usize],
                        opaque_alpha,
                        base_depth + 2,
                    );
                }
            } else if let Some(east_runs) = east_neighbor_runs {
                let mut remaining_top = run_top;
                let mut neighbor_index = 0usize;

                while neighbor_index + 1 < east_runs.len() {
                    let neighbor_top = east_runs[neighbor_index];
                    let neighbor_bottom = east_runs[neighbor_index + 1];

                    if neighbor_bottom > remaining_top || neighbor_top < run_bottom {
                        neighbor_index += 2;

                        continue;
                    }

                    let overlap_top = remaining_top.min(neighbor_top);
                    let overlap_bottom = run_bottom.max(neighbor_bottom);

                    if overlap_top < overlap_bottom {
                        neighbor_index += 2;

                        continue;
                    }

                    if remaining_top > overlap_top {
                        for world_y in ((overlap_top + 1)..=remaining_top).rev() {
                            let y_offset = (world_y - min_y) as usize;
                            let voxel_index = column_voxel_base_index + y_offset;
                            let (red, green, blue) = color_at(&colors, voxel_index);
                            let (red, green, blue) =
                                apply_height_shading(red, green, blue, world_y);
                            let iso_y = iso_base_y - depth_offsets_by_y[(world_y - min_y) as usize];
                            let base_depth = column_depth_base + (world_y * 4);
                            plot_pixel_if_closer(
                                iso_x + 1,
                                iso_y + 1,
                                east_lut[red as usize],
                                east_lut[green as usize],
                                east_lut[blue as usize],
                                opaque_alpha,
                                base_depth + 2,
                            );
                        }
                    }

                    remaining_top = overlap_bottom - 1;

                    if remaining_top < run_bottom {
                        break;
                    }

                    neighbor_index += 2;
                }

                if remaining_top >= run_bottom {
                    for world_y in (run_bottom..=remaining_top).rev() {
                        let y_offset = (world_y - min_y) as usize;
                        let voxel_index = column_voxel_base_index + y_offset;
                        let (red, green, blue) = color_at(&colors, voxel_index);
                        let (red, green, blue) = apply_height_shading(red, green, blue, world_y);
                        let iso_y = iso_base_y - depth_offsets_by_y[(world_y - min_y) as usize];
                        let base_depth = column_depth_base + (world_y * 4);
                        plot_pixel_if_closer(
                            iso_x + 1,
                            iso_y + 1,
                            east_lut[red as usize],
                            east_lut[green as usize],
                            east_lut[blue as usize],
                            opaque_alpha,
                            base_depth + 2,
                        );
                    }
                }
            }

            if south_at_boundary || south_neighbor_runs.is_none() {
                for world_y in (run_bottom..=run_top).rev() {
                    let y_offset = (world_y - min_y) as usize;
                    let voxel_index = column_voxel_base_index + y_offset;
                    let (red, green, blue) = color_at(&colors, voxel_index);
                    let (red, green, blue) = apply_height_shading(red, green, blue, world_y);
                    let iso_y = iso_base_y - depth_offsets_by_y[(world_y - min_y) as usize];
                    let base_depth = column_depth_base + (world_y * 4);
                    plot_pixel_if_closer(
                        iso_x - 1,
                        iso_y + 1,
                        south_lut[red as usize],
                        south_lut[green as usize],
                        south_lut[blue as usize],
                        opaque_alpha,
                        base_depth + 1,
                    );
                }
            } else if let Some(south_runs) = south_neighbor_runs {
                let mut remaining_top = run_top;
                let mut neighbor_index = 0usize;

                while neighbor_index + 1 < south_runs.len() {
                    let neighbor_top = south_runs[neighbor_index];
                    let neighbor_bottom = south_runs[neighbor_index + 1];

                    if neighbor_bottom > remaining_top || neighbor_top < run_bottom {
                        neighbor_index += 2;

                        continue;
                    }

                    let overlap_top = remaining_top.min(neighbor_top);
                    let overlap_bottom = run_bottom.max(neighbor_bottom);

                    if overlap_top < overlap_bottom {
                        neighbor_index += 2;

                        continue;
                    }

                    if remaining_top > overlap_top {
                        for world_y in ((overlap_top + 1)..=remaining_top).rev() {
                            let y_offset = (world_y - min_y) as usize;
                            let voxel_index = column_voxel_base_index + y_offset;
                            let (red, green, blue) = color_at(&colors, voxel_index);
                            let (red, green, blue) =
                                apply_height_shading(red, green, blue, world_y);
                            let iso_y = iso_base_y - depth_offsets_by_y[(world_y - min_y) as usize];
                            let base_depth = column_depth_base + (world_y * 4);
                            plot_pixel_if_closer(
                                iso_x - 1,
                                iso_y + 1,
                                south_lut[red as usize],
                                south_lut[green as usize],
                                south_lut[blue as usize],
                                opaque_alpha,
                                base_depth + 1,
                            );
                        }
                    }

                    remaining_top = overlap_bottom - 1;

                    if remaining_top < run_bottom {
                        break;
                    }

                    neighbor_index += 2;
                }

                if remaining_top >= run_bottom {
                    for world_y in (run_bottom..=remaining_top).rev() {
                        let y_offset = (world_y - min_y) as usize;
                        let voxel_index = column_voxel_base_index + y_offset;
                        let (red, green, blue) = color_at(&colors, voxel_index);
                        let (red, green, blue) = apply_height_shading(red, green, blue, world_y);
                        let iso_y = iso_base_y - depth_offsets_by_y[(world_y - min_y) as usize];
                        let base_depth = column_depth_base + (world_y * 4);
                        plot_pixel_if_closer(
                            iso_x - 1,
                            iso_y + 1,
                            south_lut[red as usize],
                            south_lut[green as usize],
                            south_lut[blue as usize],
                            opaque_alpha,
                            base_depth + 1,
                        );
                    }
                }
            }

            run_index += 2;
        }
    }

    if let Err(error) = write_png(&output_path, iso_width, iso_height, &rgba) {
        eprintln!("{error}");

        return ExitCode::from(1);
    }

    println!(
        "{{\"iso_width\":{},\"iso_height\":{}}}",
        iso_width, iso_height
    );

    ExitCode::SUCCESS
}
