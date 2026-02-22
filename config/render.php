<?php

return [
    'birds_eye_native_enabled' => env('BIRDSEYE_NATIVE_RENDERER_ENABLED', false),
    'birds_eye_native_binary' => env(
        'BIRDSEYE_NATIVE_RENDERER_BINARY',
        base_path('native/isometric-renderer/target/release/isometric-renderer'.(PHP_OS_FAMILY === 'Windows' ? '.exe' : ''))
    ),
    'birds_eye_native_timeout_seconds' => (int) env('BIRDSEYE_NATIVE_RENDERER_TIMEOUT_SECONDS', 300),
    'birds_eye_profile_enabled' => env('BIRDSEYE_RENDER_PROFILE_ENABLED', false),
    'isometric_native_enabled' => env('ISOMETRIC_NATIVE_RENDERER_ENABLED', false),
    'isometric_native_binary' => env(
        'ISOMETRIC_NATIVE_RENDERER_BINARY',
        base_path('native/isometric-renderer/target/release/isometric-renderer'.(PHP_OS_FAMILY === 'Windows' ? '.exe' : ''))
    ),
    'isometric_native_timeout_seconds' => (int) env('ISOMETRIC_NATIVE_RENDERER_TIMEOUT_SECONDS', 300),
    'isometric_native_pixel_scale' => max(1, (int) env('ISOMETRIC_NATIVE_RENDERER_PIXEL_SCALE', 2)),
    'isometric_profile_enabled' => env('ISOMETRIC_RENDER_PROFILE_ENABLED', false),
];
