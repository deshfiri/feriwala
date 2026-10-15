<?php

declare(strict_types=1);

namespace Storefront\Http;

/**
 * Plain PHP templates in `views/`, rendered inside `views/layout.php`.
 */
final class View
{
    /** @var array<string, mixed> shared with every template (site, cart count, …) */
    private static array $shared = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public static function share(array $data): void
    {
        self::$shared = array_merge(self::$shared, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function render(string $template, array $data = [], bool $layout = true, int $status = 200): Response
    {
        $content = self::capture($template, array_merge(self::$shared, $data));

        if (! $layout) {
            return Response::html($content, $status);
        }

        return Response::html(self::capture('layout', array_merge(
            self::$shared,
            $data,
            ['content' => $content, 'flash' => Session::pullFlash()],
        )), $status);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function partial(string $template, array $data = []): string
    {
        return self::capture('partials/'.$template, array_merge(self::$shared, $data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function capture(string $template, array $data): string
    {
        $file = dirname(__DIR__, 2).'/views/'.$template.'.php';

        if (! is_file($file)) {
            throw new \RuntimeException("Template [{$template}] does not exist.");
        }

        extract($data, EXTR_SKIP);
        ob_start();

        try {
            require $file;
        } catch (\Throwable $throwable) {
            ob_end_clean();

            throw $throwable;
        }

        return (string) ob_get_clean();
    }
}
