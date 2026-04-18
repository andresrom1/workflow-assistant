<?php

namespace App\Services;

use App\Models\CoverageDocument;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Embeddings;

class ChunkAndEmbedService
{
    /**
     * Maximum words per chunk (soft limit — splits at section boundaries).
     */
    private const MAX_CHUNK_WORDS = 600;

    /**
     * Minimum words per chunk to avoid tiny fragments.
     */
    private const MIN_CHUNK_WORDS = 50;

    /**
     * Overlap words appended from the previous chunk for context continuity.
     */
    private const OVERLAP_WORDS = 40;

    /**
     * Chunk the document's extracted content, generate embeddings, and store them.
     * Runs synchronously — intended for admin operations.
     */
    public function execute(CoverageDocument $document): int
    {
        Log::info('ChunkAndEmbedService: starting', [
            'document_id' => $document->id,
        ]);

        $content = $document->extracted_content;

        if (empty($content)) {
            Log::warning('ChunkAndEmbedService: no content to chunk', [
                'document_id' => $document->id,
            ]);

            return 0;
        }

        // 1. Delete old chunks
        $document->chunks()->delete();

        // 2. Chunk the extracted text by section boundaries
        $chunks = $this->chunkText($content);

        if (empty($chunks)) {
            Log::warning('ChunkAndEmbedService: no chunks generated', [
                'document_id' => $document->id,
            ]);

            return 0;
        }

        // 3. Generate embeddings in batch
        $texts = array_column($chunks, 'content');
        $response = Embeddings::for($texts)->dimensions(1536)->generate();
        $embeddings = $response->embeddings;

        // 4. Store chunks with embeddings
        foreach ($chunks as $i => $chunk) {
            $document->chunks()->create([
                'chunk_index' => $i,
                'content' => $chunk['content'],
                'embedding' => $embeddings[$i],
                'metadata' => $chunk['metadata'],
            ]);
        }

        // 5. Bust cache
        $document->bustCache();

        Log::info('ChunkAndEmbedService: completed', [
            'document_id' => $document->id,
            'chunks_count' => count($chunks),
        ]);

        return count($chunks);
    }

    /**
     * Split text by markdown headers (## / ###) — natural section boundaries
     * in insurance documents. Each section becomes one or more chunks of
     * MAX_CHUNK_WORDS words. Consecutive small sections are merged.
     *
     * @return array<int, array{content: string, metadata: array{section: string|null}}>
     */
    private function chunkText(string $text): array
    {
        // Split by markdown headers (##, ###, etc.)
        $sections = preg_split('/(?=^#{2,4}\s)/m', $text, -1, PREG_SPLIT_NO_EMPTY);

        if ($sections === false || empty($sections)) {
            $sections = [$text];
        }

        $chunks = [];
        $previousTail = '';

        foreach ($sections as $section) {
            $section = trim($section);

            if (empty($section)) {
                continue;
            }

            $sectionName = null;
            if (preg_match('/^#{2,4}\s+(.+)$/m', $section, $match)) {
                $sectionName = trim($match[1]);
            }

            $words = preg_split('/\s+/', $section);
            $wordCount = count($words);

            if ($wordCount <= self::MAX_CHUNK_WORDS) {
                $chunkContent = $previousTail !== ''
                    ? $previousTail."\n\n".$section
                    : $section;

                if (str_word_count($section) >= self::MIN_CHUNK_WORDS) {
                    $chunks[] = [
                        'content' => trim($chunkContent),
                        'metadata' => ['section' => $sectionName],
                    ];

                    $previousTail = implode(' ', array_slice($words, -self::OVERLAP_WORDS));
                } else {
                    $previousTail = $previousTail !== ''
                        ? $previousTail."\n\n".$section
                        : $section;
                }
            } else {
                $paragraphs = preg_split('/\n\n+/', $section, -1, PREG_SPLIT_NO_EMPTY);
                $currentChunk = $previousTail;
                $currentWordCount = str_word_count($previousTail);

                foreach ($paragraphs as $paragraph) {
                    $paragraphWords = str_word_count($paragraph);

                    if ($currentWordCount + $paragraphWords > self::MAX_CHUNK_WORDS && $currentWordCount >= self::MIN_CHUNK_WORDS) {
                        $chunks[] = [
                            'content' => trim($currentChunk),
                            'metadata' => ['section' => $sectionName],
                        ];

                        $overlapWords = preg_split('/\s+/', $currentChunk);
                        $previousTail = implode(' ', array_slice($overlapWords, -self::OVERLAP_WORDS));
                        $currentChunk = $previousTail."\n\n".$paragraph;
                        $currentWordCount = str_word_count($currentChunk);
                    } else {
                        $currentChunk .= ($currentChunk !== '' ? "\n\n" : '').$paragraph;
                        $currentWordCount += $paragraphWords;
                    }
                }

                if (trim($currentChunk) !== '') {
                    if ($currentWordCount >= self::MIN_CHUNK_WORDS) {
                        $chunks[] = [
                            'content' => trim($currentChunk),
                            'metadata' => ['section' => $sectionName],
                        ];

                        $overlapWords = preg_split('/\s+/', $currentChunk);
                        $previousTail = implode(' ', array_slice($overlapWords, -self::OVERLAP_WORDS));
                    } else {
                        $previousTail = trim($currentChunk);
                    }
                }
            }
        }

        // Flush any remaining tail
        if (trim($previousTail) !== '' && str_word_count($previousTail) >= self::MIN_CHUNK_WORDS) {
            $lastContent = ! empty($chunks) ? $chunks[count($chunks) - 1]['content'] : '';
            if ($lastContent !== trim($previousTail)) {
                $chunks[] = [
                    'content' => trim($previousTail),
                    'metadata' => ['section' => null],
                ];
            }
        }

        return $chunks;
    }
}
