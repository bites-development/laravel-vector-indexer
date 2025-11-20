<?php

namespace Bites\VectorIndexer\Services\Vector;

class ChunkingService
{
    /**
     * Chunk text into smaller pieces with overlap
     * Smart chunking respects sentence boundaries
     */
    public function chunk(string $text, int $size = 1000, int $overlap = 200): array
    {
        if (empty($text)) {
            return [];
        }
        
        // If text is smaller than chunk size, return as-is
        if (strlen($text) <= $size) {
            return [$text];
        }
        
        // Try smart chunking first (sentence boundaries)
        $chunks = $this->smartChunk($text, $size, $overlap);
        
        // If smart chunking failed, fall back to simple chunking
        if (empty($chunks)) {
            $chunks = $this->simpleChunk($text, $size, $overlap);
        }
        
        return $chunks;
    }
    
    /**
     * Smart chunking that respects sentence boundaries
     */
    protected function smartChunk(string $text, int $size, int $overlap): array
    {
        // Split into sentences
        $sentences = $this->splitIntoSentences($text);
        
        if (empty($sentences)) {
            return [];
        }
        
        $chunks = [];
        $currentChunk = '';
        $overlapBuffer = [];
        
        foreach ($sentences as $sentence) {
            $sentenceLength = strlen($sentence);
            
            // If single sentence is larger than chunk size, we'll need to split it
            if ($sentenceLength > $size) {
                // Save current chunk if not empty
                if (!empty($currentChunk)) {
                    $chunks[] = trim($currentChunk);
                }
                
                // Split large sentence
                $subChunks = $this->simpleChunk($sentence, $size, $overlap);
                $chunks = array_merge($chunks, $subChunks);
                
                $currentChunk = '';
                $overlapBuffer = [];
                continue;
            }
            
            // Check if adding this sentence would exceed chunk size
            if (strlen($currentChunk) + $sentenceLength > $size && !empty($currentChunk)) {
                // Save current chunk
                $chunks[] = trim($currentChunk);
                
                // Start new chunk with overlap
                $currentChunk = implode(' ', $overlapBuffer) . ' ' . $sentence;
                $overlapBuffer = [$sentence];
            } else {
                // Add sentence to current chunk
                $currentChunk .= ($currentChunk ? ' ' : '') . $sentence;
                $overlapBuffer[] = $sentence;
                
                // Keep overlap buffer size manageable
                while (!empty($overlapBuffer) && strlen(implode(' ', $overlapBuffer)) > $overlap) {
                    array_shift($overlapBuffer);
                }
            }
        }
        
        // Add final chunk
        if (!empty($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }
        
        return $chunks;
    }
    
    /**
     * Simple chunking with overlap (fallback)
     */
    protected function simpleChunk(string $text, int $size, int $overlap): array
    {
        $chunks = [];
        $length = strlen($text);
        $position = 0;
        
        while ($position < $length) {
            $chunk = substr($text, $position, $size);
            $chunks[] = $chunk;
            $position += $size - $overlap;
        }
        
        return $chunks;
    }
    
    /**
     * Split text into sentences
     */
    protected function splitIntoSentences(string $text): array
    {
        // Common sentence endings
        $pattern = '/([.!?]+[\s\n]+)/u';
        
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        
        if ($parts === false) {
            return [$text];
        }
        
        $sentences = [];
        $currentSentence = '';
        
        foreach ($parts as $part) {
            $currentSentence .= $part;
            
            // If this part is a delimiter, we have a complete sentence
            if (preg_match($pattern, $part)) {
                $sentences[] = trim($currentSentence);
                $currentSentence = '';
            }
        }
        
        // Add any remaining text
        if (!empty($currentSentence)) {
            $sentences[] = trim($currentSentence);
        }
        
        return array_filter($sentences);
    }
    
    /**
     * Chunk with custom separator
     */
    public function chunkBySeparator(string $text, string $separator, int $maxSize): array
    {
        $parts = explode($separator, $text);
        $chunks = [];
        $currentChunk = '';
        
        foreach ($parts as $part) {
            if (strlen($currentChunk) + strlen($part) + strlen($separator) > $maxSize && !empty($currentChunk)) {
                $chunks[] = trim($currentChunk);
                $currentChunk = $part;
            } else {
                $currentChunk .= ($currentChunk ? $separator : '') . $part;
            }
        }
        
        if (!empty($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }
        
        return $chunks;
    }
    
    /**
     * Get chunk statistics
     */
    public function getChunkStats(array $chunks): array
    {
        $sizes = array_map('strlen', $chunks);
        
        return [
            'count' => count($chunks),
            'total_size' => array_sum($sizes),
            'avg_size' => count($chunks) > 0 ? (int)(array_sum($sizes) / count($chunks)) : 0,
            'min_size' => count($chunks) > 0 ? min($sizes) : 0,
            'max_size' => count($chunks) > 0 ? max($sizes) : 0,
        ];
    }
}
