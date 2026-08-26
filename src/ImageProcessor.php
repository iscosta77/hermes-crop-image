<?php

declare(strict_types=1);

namespace Hermes\CropImage;

use GdImage;
use RuntimeException;

/**
 * ImageProcessor — o motor de processamento (zero dependencias, so GD).
 *
 * - crop():       recorte com posicionamento livre (x, y, largura, altura)
 * - thumb():      thumbnail com cache por dimensao (padrao CoffeeCode Cropper)
 * - toWebp():     conversao + compactacao para WebP
 * - watermark():  marca d'agua com logo PNG
 */
final class ImageProcessor
{
    private const POSICOES = ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'center'];

    public static function crop(string $src, string $dest, int $x, int $y, int $w, int $h, int $quality = 85): bool
    {
        self::assertGd();
        $im = self::carregar($src);

        $x = max(0, $x);
        $y = max(0, $y);
        $w = min($w, imagesx($im) - $x);
        $h = min($h, imagesy($im) - $y);
        if ($w <= 0 || $h <= 0) {
            imagedestroy($im);
            throw new RuntimeException('Recorte fora dos limites da imagem.');
        }

        $cortada = imagecrop($im, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);
        imagedestroy($im);
        if ($cortada === false) {
            throw new RuntimeException('Falha ao recortar a imagem.');
        }

        return self::salvar($cortada, $dest, $quality);
    }

    /**
     * @return string caminho do arquivo gerado (para usar em <img>)
     */
    public static function thumb(string $src, int $w, ?int $h = null, string $cacheDir = 'cache', int $quality = 75): string
    {
        self::assertGd();
        $info = @getimagesize($src);
        if ($info === false) {
            throw new RuntimeException("Arquivo de imagem invalido: {$src}");
        }
        [$iw, $ih] = $info;
        $w = max(1, $w);
        $h = $h === null ? (int) round($ih * $w / $iw) : max(1, $h);

        $cacheDir = rtrim($cacheDir, '/\\');
        $hash = md5((string) realpath($src) . (string) filesize($src) . "{$w}x{$h}x{$quality}");
        $nome = pathinfo($src, PATHINFO_FILENAME) . "-{$w}x{$h}-{$hash}.jpg";
        $dest = "{$cacheDir}/{$nome}";

        if (is_file($dest)) {
            return $dest;
        }

        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0777, true) && !is_dir($cacheDir)) {
            throw new RuntimeException("Nao foi possivel criar o cache: {$cacheDir}");
        }

        $im = self::carregar($src);

        $ratio = max($w / $iw, $h / $ih);
        $nw = (int) round($iw * $ratio);
        $nh = (int) round($ih * $ratio);

        $thumb = imagecreatetruecolor($w, $h);
        $branco = imagecolorallocate($thumb, 255, 255, 255);
        imagefill($thumb, 0, 0, $branco);
        imagecopyresampled(
            $thumb,
            $im,
            0,
            0,
            (int) (($nw - $w) / 2),
            (int) (($nh - $h) / 2),
            $w,
            $h,
            $nw,
            $nh,
        );

        $ok = imagejpeg($thumb, $dest, self::qualidade($quality));
        imagedestroy($im);
        imagedestroy($thumb);
        if (!$ok) {
            throw new RuntimeException("Falha ao gerar thumb: {$dest}");
        }

        return $dest;
    }

    public static function toWebp(string $src, string $dest, int $quality = 85): bool
    {
        self::assertGd();
        $im = self::carregar($src);

        $ok = imagewebp($im, $dest, self::qualidade($quality));
        imagedestroy($im);
        if (!$ok) {
            throw new RuntimeException('Falha ao converter para WebP.');
        }

        return true;
    }

    public static function watermark(
        string $src,
        string $dest,
        string $logo,
        string $position = 'bottom-right',
        int $margin = 10,
        int $opacity = 80,
        int $quality = 85,
        ?int $scale = 15,
    ): bool {
        self::assertGd();
        if (!in_array($position, self::POSICOES, true)) {
            throw new RuntimeException("Posicao invalida: {$position} (use " . implode(', ', self::POSICOES) . ').');
        }

        $im = self::carregar($src);
        $logoIm = self::carregar($logo, true);

        $lw = imagesx($logoIm);
        $lh = imagesy($logoIm);
        $iw = imagesx($im);
        $ih = imagesy($im);

        // marca d'agua proporcional: a logo e escalada para um percentual
        // da largura da imagem final (evita logo gigante em imagem pequena
        // e logo insignificante em imagem grande). scale=null = tamanho nativo.
        if ($scale !== null && $scale > 0) {
            $novoLw = max(1, (int) round($iw * $scale / 100));
            $novoLh = max(1, (int) round($lh * $novoLw / $lw));

            $redimensionada = imagecreatetruecolor($novoLw, $novoLh);
            imagealphablending($redimensionada, false);
            imagesavealpha($redimensionada, true);
            imagecopyresampled($redimensionada, $logoIm, 0, 0, 0, 0, $novoLw, $novoLh, $lw, $lh);
            imagedestroy($logoIm);
            $logoIm = $redimensionada;
            $lw = $novoLw;
            $lh = $novoLh;
        }

        $lx = match ($position) {
            'top-left' => $margin,
            'top-right' => $iw - $lw - $margin,
            'bottom-left' => $margin,
            'bottom-right' => $iw - $lw - $margin,
            'center' => (int) (($iw - $lw) / 2),
        };
        $ly = match ($position) {
            'top-left', 'top-right' => $margin,
            'bottom-left', 'bottom-right' => $ih - $lh - $margin,
            'center' => (int) (($ih - $lh) / 2),
        };
        $lx = max(0, $lx);
        $ly = max(0, $ly);

        imagecopymerge($im, $logoIm, $lx, $ly, 0, 0, $lw, $lh, self::qualidade($opacity));
        imagedestroy($logoIm);

        $ok = self::salvar($im, $dest, $quality);
        imagedestroy($im);

        return $ok;
    }

    private static function carregar(string $caminho, bool $preservarAlpha = false): GdImage
    {
        if (!is_file($caminho)) {
            throw new RuntimeException("Arquivo nao encontrado: {$caminho}");
        }

        $info = @getimagesize($caminho);
        if ($info === false) {
            throw new RuntimeException("Arquivo de imagem invalido: {$caminho}");
        }

        $im = match ($info[2]) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($caminho),
            IMAGETYPE_PNG => imagecreatefrompng($caminho),
            IMAGETYPE_GIF => imagecreatefromgif($caminho),
            IMAGETYPE_WEBP => imagecreatefromwebp($caminho),
            default => throw new RuntimeException('Formato nao suportado (use JPEG, PNG, GIF ou WebP): ' . $info['mime']),
        };

        if ($im === false) {
            throw new RuntimeException("Falha ao abrir: {$caminho}");
        }

        if ($preservarAlpha) {
            imagealphablending($im, false);
            imagesavealpha($im, true);
        }

        return $im;
    }

    private static function salvar(GdImage $im, string $dest, int $quality): bool
    {
        $ext = strtolower(pathinfo($dest, PATHINFO_EXTENSION));

        $ok = match ($ext) {
            'webp' => imagewebp($im, $dest, self::qualidade($quality)),
            'png' => imagepng($im, $dest, (int) round((100 - $quality) / 10)),
            'gif' => imagegif($im, $dest),
            default => imagejpeg($im, $dest, self::qualidade($quality)),
        };

        if (!$ok) {
            throw new RuntimeException("Falha ao salvar: {$dest}");
        }

        return true;
    }

    private static function qualidade(int $q): int
    {
        return max(0, min(100, $q));
    }

    private static function assertGd(): void
    {
        if (!extension_loaded('gd') || !function_exists('imagecrop')) {
            throw new RuntimeException('A extensao GD do PHP e necessaria (habilite ext-gd).');
        }
    }
}
