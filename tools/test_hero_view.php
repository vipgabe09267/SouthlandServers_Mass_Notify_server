<?php
declare(strict_types=1);

$view = dirname(__DIR__) . '/slsmassnotifyserver/views/hero.php';

$render = static function ($value) use ($view): string {
    $hero_image = $value;
    ob_start();
    try {
        require $view;
        $output = ob_get_contents();
        if (!is_string($output)) {
            throw new RuntimeException('Hero output buffer was unavailable.');
        }
        return $output;
    } finally {
        ob_end_clean();
    }
};

$encoded = $render('" onerror="alert(1)');
if (!str_contains($encoded, 'src="&quot; onerror=&quot;alert(1)"')
    || str_contains($encoded, 'src="" onerror=')) {
    throw new RuntimeException('Hero image attribute encoding regressed.');
}

$nonScalar = $render(['unexpected']);
if (!str_contains($nonScalar, 'src=""')) {
    throw new RuntimeException('Hero view did not reject a non-text image value safely.');
}

echo "Hero view: malformed values are contained and attributes remain encoded.\n";
