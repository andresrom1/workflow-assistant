-- Habilita pgvector en la base de la app (RAG de coberturas: tabla coverage_chunks
-- usa columna vector(1536)). Corre una única vez, en la primera inicialización del
-- volumen de datos de Postgres.
CREATE EXTENSION IF NOT EXISTS vector;
