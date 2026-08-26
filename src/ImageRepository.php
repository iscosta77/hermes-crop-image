<?php

declare(strict_types=1);

namespace Hermes\CropImage;

use Iscos\Voodoo\Row;
use Iscos\Voodoo\Voodoo;
use RuntimeException;

/**
 * ImageRepository — as interacoes da imagem com o banco (via iscos/voodoo-2026).
 *
 * Registra, consulta e apaga imagens processadas na tabela hermes_images.
 */
final class ImageRepository
{
    private \Iscos\Voodoo\Database $db;

    public function __construct(\Iscos\Voodoo\Database $db)
    {
        $this->db = $db;
    }

    /** Garante que a tabela hermes_images existe (idempotente). */
    public function criaTabela(): void
    {
        $schema = file_get_contents(__DIR__ . '/../schema.sql');
        if ($schema === false) {
            throw new RuntimeException('Nao foi possivel ler o schema.sql.');
        }

        // remove comentarios antes de dividir (o ';' dentro de comentario quebraria)
        $linhas = array_filter(
            array_map('trim', explode("\n", $schema)),
            fn (string $l) => $l !== '' && !str_starts_with($l, '--'),
        );
        foreach (array_filter(array_map('trim', explode(';', implode("\n", $linhas)))) as $statement) {
            $this->db->run($statement);
        }
    }

    /**
     * Registra uma imagem processada.
     *
     * @param array{ tipo?: string, arquivo_original: string, largura?: int,
     *                altura?: int, webp?: ?string, thumbs?: array<string, string>,
     *                watermark?: bool } $dados
     */
    public function registrar(array $dados): int
    {
        $registro = [
            'tipo' => $dados['tipo'] ?? 'imagem',
            'arquivo_original' => $dados['arquivo_original'],
            'largura' => $dados['largura'] ?? null,
            'altura' => $dados['altura'] ?? null,
            'webp' => $dados['webp'] ?? null,
            'thumbs' => isset($dados['thumbs']) ? json_encode($dados['thumbs']) : null,
            'watermark' => !empty($dados['watermark']) ? 1 : 0,
        ];

        return (int) $this->db->table('hermes_images')->insert($registro);
    }

    public function buscar(int $id): ?Row
    {
        return $this->db->table('hermes_images')->findById($id);
    }

    /** @return array<int, Row> */
    public function listar(?string $tipo = null, int $limite = 50): array
    {
        $q = $this->db->table('hermes_images');
        if ($tipo !== null) {
            $q->where('tipo', $tipo);
        }

        return $q->orderBy('id', 'DESC')->limit($limite)->find();
    }

    public function contar(?string $tipo = null): int
    {
        $q = $this->db->table('hermes_images');
        if ($tipo !== null) {
            $q->where('tipo', $tipo);
        }

        return $q->count();
    }

    /**
     * Apaga o registro E os arquivos (original, webp e thumbs).
     * Retorna false se o registro nao existir.
     */
    public function apagar(int $id): bool
    {
        $imagem = $this->buscar($id);
        if ($imagem === null) {
            return false;
        }

        foreach ([$imagem->arquivo_original, $imagem->webp] as $arquivo) {
            if ($arquivo !== null && is_file($arquivo)) {
                @unlink($arquivo);
            }
        }
        foreach (json_decode((string) ($imagem->thumbs ?? '[]'), true) ?: [] as $thumb) {
            if (is_string($thumb) && is_file($thumb)) {
                @unlink($thumb);
            }
        }

        $this->db->table('hermes_images')->where('id', $id)->delete();

        return true;
    }

    /** Atalho: abre a conexao pelo .env (formato Laravel) e devolve o repositorio. */
    public static function fromEnv(?string $envPath = null): self
    {
        $db = $envPath === null
            ? Voodoo::fromEnv()
            : Voodoo::openFromConfig(\Iscos\Voodoo\Connection::fromEnv(\Iscos\Voodoo\Env::merge(getenv(), $envPath)));

        return new self($db);
    }
}
