-- Schema do hermes/crop-image
-- Tabela que registra as imagens processadas (original + webp + thumbs).
-- SQLite; para MySQL/PostgreSQL adapte os tipos (INTEGER->BIGINT, TEXT->VARCHAR).

CREATE TABLE IF NOT EXISTS hermes_images (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo             TEXT    NOT NULL DEFAULT 'imagem',   -- rotulo: avatar, galeria, produto...
    arquivo_original TEXT    NOT NULL,                     -- caminho do arquivo original
    largura          INTEGER,
    altura           INTEGER,
    webp             TEXT,                                 -- caminho do webp gerado (se houver)
    thumbs           TEXT,                                 -- JSON: {"400x300": "cache/foto-400x300-h.jpg", ...}
    watermark        INTEGER NOT NULL DEFAULT 0,           -- 1 se recebeu marca d'agua
    criado_em        TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_hermes_images_tipo ON hermes_images (tipo);
