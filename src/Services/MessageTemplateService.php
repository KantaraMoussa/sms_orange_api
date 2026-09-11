<?php

namespace App\Services;

/**
 * Moteur de rendu des variables dynamiques (cahier des charges §16-17, §62).
 * Utilisé à la fois pour la prévisualisation et pour l'envoi réel — même
 * moteur des deux côtés, pour que ce qui est prévisualisé soit exactement
 * ce qui est envoyé, comme l'exige explicitement le §17.
 */
class MessageTemplateService
{
    /**
     * @param array<string,mixed> $data
     * @return array{message:string, missing:list<string>}
     */
    public static function render(string $template, array $data): array
    {
        $missing = [];

        $message = preg_replace_callback('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/', function ($m) use ($data, &$missing) {
            $key = $m[1];

            if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                $missing[] = $key;
                return '';
            }

            return (string) $data[$key];
        }, $template);

        return [
            'message' => $message ?? $template,
            'missing' => array_values(array_unique($missing)),
        ];
    }
}
