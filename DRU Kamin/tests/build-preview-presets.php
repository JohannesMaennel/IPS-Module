<?php

declare(strict_types=1);

$ui = dirname(__DIR__) . '/UI';
$presets = json_decode(file_get_contents($ui . '/wave-presets.json'), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($presets) || count($presets) !== 3) {
    throw new RuntimeException('Expected three Wave presets');
}
foreach ($presets as $preset) {
    if (!is_string($preset['id']) || !is_string($preset['name'])
        || !is_int($preset['interval']) || $preset['interval'] < 5 || $preset['interval'] > 60
        || count($preset['stages']) !== 20) {
        throw new RuntimeException('Invalid Wave preset');
    }
    foreach ($preset['stages'] as $stage) {
        if (!is_int($stage) || $stage < 0 || $stage > 100) {
            throw new RuntimeException('Invalid Wave stage');
        }
    }
}
$content = "// Generated from wave-presets.json by tests/build-preview-presets.php.\nwindow.druWavePresets = ";
$content .= json_encode($presets, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
$content .= ";\n";
if (file_put_contents($ui . '/preview-presets.js', $content) === false) {
    throw new RuntimeException('Could not write preview preset file');
}
