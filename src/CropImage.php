<?php

declare(strict_types=1);

namespace Hermes\CropImage;

use Iscos\Voodoo\Database;
use RuntimeException;

/**
 * CropImage — a porta de entrada: processa a imagem e registra no banco.
 *
 * ```php
 * $registro = CropImage::process($db, 'uploads/foto.jpg', 'galeria', [
 *     'webp'      => 80,                          // gera foto.webp (qualidade)
 *     'thumbs'    => [[400, null], [400, 400]],   // cache: 400px largo + 400x400
 *     'watermark' => ['logo' => 'assets/logo.png', 'position' => 'bottom-right'],
 *     'cache_dir' => 'cache',
 * ]);
 * echo $registro['id'];         // id na tabela hermes_images
 * echo $registro['thumbs']['400x400'];
 * ```
 */
final class CropImage
{
    /**
     * @param array{ webp?: int|false, thumbs?: array<int, array{0:int,1:?int}>,
     *               watermark?: array{ logo: string, position?: string, margin?: int, opacity?: int },
     *               cache_dir?: string, tipo?: string } $opcoes
     *
     * @return array<string, mixed> o registro gravado (com 'id')
     */
    public static function process(Database $db, string $src, string $tipo = 'imagem', array $opcoes = []): array
    {
        if (!is_file($src)) {
            throw new RuntimeException("Arquivo nao encontrado: {$src}");
        }

        $info = @getimagesize($src);
        if ($info === false) {
            throw new RuntimeException("Arquivo de imagem invalido: {$src}");
        }
        [$largura, $altura] = $info;

        $cacheDir = $opcoes['cache_dir'] ?? 'cache';
        $base = $src;
        $comWatermark = false;

        // 1) marca d'agua (se pedida) — gera <nome>-wm.jpg ao lado do original
        $wm = $opcoes['watermark'] ?? null;
        if (is_array($wm) && isset($wm['logo'])) {
            $wmDest = self::destinoComSufixo($src, '-wm');
            ImageProcessor::watermark(
                $src,
                $wmDest,
                $wm['logo'],
                $wm['position'] ?? 'bottom-right',
                (int) ($wm['margin'] ?? 10),
                (int) ($wm['opacity'] ?? 80),
                85,
                isset($wm['scale']) ? (int) $wm['scale'] : 15,
            );
            $base = $wmDest;
            $comWatermark = true;
        }

        // 2) webp (se pedida) — <nome>.webp ao lado do original
        $webp = null;
        $qualidadeWebp = $opcoes['webp'] ?? false;
        if ($qualidadeWebp !== false) {
            $webp = self::destinoComExtensao($base, 'webp');
            ImageProcessor::toWebp($base, $webp, (int) $qualidadeWebp);
        }

        // 3) thumbs com cache (se pedidos)
        $thumbs = [];
        foreach ($opcoes['thumbs'] ?? [] as [$tw, $th]) {
            $thumbs["{$tw}x" . ($th ?? 'auto')] = ImageProcessor::thumb($base, $tw, $th, $cacheDir);
        }

        // 4) registro no banco
        $repo = new ImageRepository($db);
        $id = $repo->registrar([
            'tipo' => $tipo,
            'arquivo_original' => $base,
            'largura' => $largura,
            'altura' => $altura,
            'webp' => $webp,
            'thumbs' => $thumbs,
            'watermark' => $comWatermark,
        ]);

        return [
            'id' => $id,
            'tipo' => $tipo,
            'arquivo_original' => $base,
            'largura' => $largura,
            'altura' => $altura,
            'webp' => $webp,
            'thumbs' => $thumbs,
            'watermark' => $comWatermark,
        ];
    }

    private static function destinoComSufixo(string $src, string $sufixo): string
    {
        $info = pathinfo($src);

        return "{$info['dirname']}/{$info['filename']}{$sufixo}.{$info['extension']}";
    }

    private static function destinoComExtensao(string $src, string $ext): string
    {
        $info = pathinfo($src);

        return "{$info['dirname']}/{$info['filename']}.{$ext}";
    }
}
