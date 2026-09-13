<?php

namespace App\Core;

/**
 * Renders a view fragment under app/Views/, optionally wrapped in a layout
 * (also under app/Views/) which receives the rendered fragment as $content.
 */
class View
{
    private const BASE = __DIR__ . '/../../app/Views/';

    public static function render(string $view, array $data = [], ?string $layout = 'layouts/app'): void
    {
        $content = self::capture($view, $data);

        if ($layout === null) {
            echo $content;
            return;
        }

        echo self::capture($layout, array_merge($data, ['content' => $content]));
    }

    private static function capture(string $view, array $data): string
    {
        $file = self::BASE . $view . '.php';
        extract($data);
        ob_start();
        require $file;
        return ob_get_clean();
    }
}
