<?php

declare(strict_types=1);

namespace Hermes\CropImage\Tests;

use Hermes\CropImage\CropImage;
use Hermes\CropImage\ImageProcessor;
use Hermes\CropImage\ImageRepository;
use Iscos\Voodoo\Voodoo;
use PHPUnit\Framework\TestCase;

final class CropImageTest extends TestCase
{
    private string $dir;
    private string $foto;
    private string $logo;
    private \Iscos\Voodoo\Database $db;

    protected function setUp(): void
    {
        if (!extension_loaded('gd') || !function_exists('imagecrop')) {
            self::markTestSkipped('ext-gd necessario.');
        }

        $this->dir = sys_get_temp_dir() . '/hermes-crop-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);

        $this->foto = $this->dir . '/foto.jpg';
        $im = imagecreatetruecolor(200, 150);
        imagefilledrectangle($im, 0, 0, 199, 149, imagecolorallocate($im, 120, 180, 220));
        imagejpeg($im, $this->foto, 90);
        $this->logo = $this->dir . '/logo.png';
        $l = imagecreatetruecolor(40, 30);
        imagesavealpha($l, true);
        imagealphablending($l, false); // logo PNG real: canvas nao mistura, fundo fica transparente
        $t = imagecolorallocatealpha($l, 0, 0, 0, 127);
        imagefill($l, 0, 0, $t);
        imagealphablending($l, true); // retangulo opaco volta a misturar normalmente
        imagefilledrectangle($l, 5, 5, 35, 25, imagecolorallocate($l, 0, 0, 0));
        imagepng($l, $this->logo);
        $this->db = Voodoo::open('sqlite:' . $this->dir . '/teste.sqlite');
        (new ImageRepository($this->db))->criaTabela();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->dir . '/cache/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/cache');
        @rmdir($this->dir);
    }

    /* ============ processador ============ */

    public function testCropPosicionado(): void
    {
        $dest = $this->dir . '/recorte.jpg';
        ImageProcessor::crop($this->foto, $dest, 50, 50, 100, 80);

        [$w, $h] = getimagesize($dest);
        $this->assertSame([100, 80], [$w, $h]);
    }

    public function testThumbComCache(): void
    {
        $a = ImageProcessor::thumb($this->foto, 80, 80, $this->dir . '/cache');
        $b = ImageProcessor::thumb($this->foto, 80, 80, $this->dir . '/cache');

        $this->assertSame($a, $b, 'cache hit');
        [$w, $h] = getimagesize($a);
        $this->assertSame([80, 80], [$w, $h]);
    }

    public function testWebp(): void
    {
        $dest = $this->dir . '/foto.webp';
        ImageProcessor::toWebp($this->foto, $dest, 80);

        $this->assertSame('image/webp', mime_content_type($dest));
    }

    public function testWatermark(): void
    {
        $dest = $this->dir . '/com-logo.jpg';
        ImageProcessor::watermark($this->foto, $dest, $this->logo, 'center', 0, 100);

        $this->assertFileExists($dest);
    }

    public function testWatermarkEscalaPercentual(): void
    {
        // alpha real (sem retangulo escuro). Salva em PNG (jpg nao tem alpha).
        $total = $this->dir . '/wm-total.png';
        ImageProcessor::watermark($this->foto, $total, $this->logo, 'center', 0, 100, 85, 100);
        $im = imagecreatefrompng($total);
        $corCentro = imagecolorat($im, 100, 75);   // dentro do retangulo preto escalado
        $corCanto = imagecolorat($im, 10, 10);     // area transparente da logo (fora do retangulo)
        $redCentro = ($corCentro >> 16) & 0xFF;
        $redCanto = ($corCanto >> 16) & 0xFF;

        // scale=10: logo 20x15 centrada -> o CANTO (10,10) fica fora da logo
        $pequena = $this->dir . '/wm-pequena.png';
        ImageProcessor::watermark($this->foto, $pequena, $this->logo, 'center', 0, 100, 85, 10);
        $im2 = imagecreatefrompng($pequena);
        $cor2 = imagecolorat($im2, 10, 10);
        $redPequena = ($cor2 >> 16) & 0xFF;

        $this->assertLessThan(60, $redCentro, 'scale=100 deve cobrir o centro (preto opaco)');
        $this->assertGreaterThan(60, $redCanto, 'area transparente da logo nao pinta (alpha real)');
        $this->assertGreaterThan(60, $redPequena, 'scale=10 nao deve tocar o canto (azul original)');
    }

    /* ============ banco (interacoes) ============ */

    public function testCriaTabelaEIdempotente(): void
    {
        $repo = new ImageRepository($this->db);
        $repo->criaTabela(); // segunda chamada nao deve quebrar

        $this->assertSame(0, $repo->contar());
    }

    public function testRegistrarBuscarListar(): void
    {
        $repo = new ImageRepository($this->db);
        $id = $repo->registrar([
            'tipo' => 'avatar',
            'arquivo_original' => '/tmp/avatar.jpg',
            'largura' => 800,
            'altura' => 600,
            'webp' => '/tmp/avatar.webp',
            'thumbs' => ['400x400' => '/tmp/cache/avatar-400x400-x.jpg'],
            'watermark' => true,
        ]);

        $imagem = $repo->buscar($id);
        $this->assertNotNull($imagem);
        $this->assertSame('avatar', $imagem->tipo);
        $this->assertSame(1, (int) $imagem->watermark);
        $this->assertSame(1, $repo->contar('avatar'));
        $this->assertSame(0, $repo->contar('galeria'));
        $this->assertCount(1, $repo->listar('avatar'));
    }

    /* ============ fachada (processa + grava) ============ */

    public function testProcessCompletoRegistraNoBanco(): void
    {
        $registro = CropImage::process($this->db, $this->foto, 'galeria', [
            'webp' => 80,
            'thumbs' => [[400, null], [400, 400]],
            'watermark' => ['logo' => $this->logo, 'position' => 'bottom-right', 'opacity' => 80, 'scale' => 12],
            'cache_dir' => $this->dir . '/cache',
        ]);

        $this->assertGreaterThan(0, $registro['id']);
        $this->assertTrue(is_file($registro['arquivo_original']));
        $this->assertStringEndsWith('-wm.jpg', $registro['arquivo_original'], 'watermark aplicado');
        $this->assertSame(1, (int) $registro['watermark']);
        $this->assertTrue(is_file($registro['webp']));
        $this->assertArrayHasKey('400x400', $registro['thumbs']);
        $this->assertArrayHasKey('400xauto', $registro['thumbs']);

        // registro no banco bate com o retorno
        $noBanco = (new ImageRepository($this->db))->buscar($registro['id']);
        $this->assertSame($registro['arquivo_original'], $noBanco->arquivo_original);
        $this->assertSame(1, (new ImageRepository($this->db))->contar('galeria'));
    }

    public function testApagarRemoveArquivosERegistro(): void
    {
        $registro = CropImage::process($this->db, $this->foto, 'temporaria', [
            'thumbs' => [[100, 100]],
            'cache_dir' => $this->dir . '/cache',
        ]);

        // ORIGEM do usuario NAO e apagada (protecao caminhoSeguro: so remove
        // caminhos relativos gerenciados pelo pacote; a foto de entrada e absoluta).
        $this->assertTrue(is_file($this->foto), 'origem existe antes do apagar');

        $repo = new ImageRepository($this->db);
        $this->assertTrue($repo->apagar($registro['id']));
        $this->assertNull($repo->buscar($registro['id']));

        // artefatos gerados em caminho relativo/seguro sao removidos
        foreach (array_values($registro['thumbs']) as $thumb) {
            if ($repo->caminhoSeguroPublico((string) $thumb)) {
                $this->assertFileDoesNotExist($thumb);
            }
        }

        // a origem do usuario permanece (nunca apagamos a entrada do cliente)
        $this->assertFileExists($this->foto, 'origem do usuario nunca e apagada');

        $this->assertFalse($repo->apagar(999), 'id inexistente retorna false');
    }
}
