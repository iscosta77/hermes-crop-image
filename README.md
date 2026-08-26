# CropImage — processamento de imagens completo, com banco de dados

> Parte da família **hermes_\*** — ferramentas completas, interligadas e com
> estrutura de banco para quem programa PHP na unha.
> Criado e mantido por **Hermes Agent (Nous Research)** · publicado por Ildefonso Costa.

Processamento de imagens **sem framework**: crop posicionado, thumb com cache por
dimensão, conversão WebP, marca d'água — **e cada imagem processada registrada no
banco** (`hermes_images`) com as interações prontas (registrar, buscar, listar,
contar, apagar arquivos + registro).

O banco é acessado via **[iscos/voodoo-2026](https://packagist.org/packages/iscos/voodoo-2026)** —
o micro-ORM da casa.

## Instalação

```bash
composer require hermes/crop-image
```

## Uso rápido

```php
use Hermes\CropImage\CropImage;
use Hermes\CropImage\ImageRepository;
use Iscos\Voodoo\Voodoo;

$db = Voodoo::fromEnv(); // .env formato Laravel

// processa TUDO e grava no banco
$registro = CropImage::process($db, 'uploads/foto.jpg', 'galeria', [
    'webp'      => 80,                        // gera foto.webp (qualidade 80)
    'thumbs'    => [[400, null], [400, 400]], // cache/ com 2 tamanhos
    'watermark' => ['logo' => 'assets/logo.png', 'position' => 'bottom-right', 'scale' => 15], // 15% da largura
    'cache_dir' => 'cache',
]);

echo $registro['id'];                                  // id na hermes_images
echo $registro['arquivo_original'];                    // (com -wm se marcada)
echo $registro['webp'];                                // foto.webp
echo $registro['thumbs']['400x400'];                   // cache/foto-400x400-...
```

## Interações com o banco

```php
$repo = new ImageRepository($db);

$repo->criaTabela();                          // idempotente (schema.sql)
$repo->registrar([...]);                      // grava um registro
$img = $repo->buscar(7);                      // Row da hermes_images
$repo->listar('galeria', 20);                 // últimas 20 do tipo
$repo->contar('avatar');                      // quantas por tipo
$repo->apagar(7);                             // remove arquivos + registro
```

## API do processador (sem banco)

| Método | Descrição |
|---|---|
| `ImageProcessor::crop($src, $dest, $x, $y, $w, $h)` | Recorte posicionado |
| `ImageProcessor::thumb($src, $w, $h = null, $cacheDir)` | Thumb com cache (retorna caminho) |
| `ImageProcessor::toWebp($src, $dest, $quality)` | Converte/compacta para WebP |
| `ImageProcessor::watermark($src, $dest, $logo, $position, $margin, $opacity, $quality, $scale = 15)` | Marca d'água proporcional (`scale` = % da largura; `null` = nativo) |

## Schema (hermes_images)

`tipo`, `arquivo_original`, `largura`, `altura`, `webp`, `thumbs` (JSON),
`watermark`, `criado_em` — com índice por `tipo`. O `schema.sql` já vem no pacote.

## Família hermes_*

| Pacote | Status |
|---|---|
| hermes/validators | ✅ v1.0.0 |
| **hermes/crop-image** | ✅ **v1.0.0 — este** |
| hermes/upload | em desenvolvimento (usa validators + crop-image) |
| hermes/upload-multiple | em desenvolvimento |
| hermes/gallery | em desenvolvimento (galerias 1:N, usa upload + crop-image) |

## Licença

MIT © 2026 Hermes Agent (Nous Research) — criador e mantenedor · Ildefonso Costa — publicador
