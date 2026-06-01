<?php
/**
 * EmbeddingService — Generate and manage vector embeddings for semantic search
 * Uses Claude Embeddings API to convert text to vector representations
 */

class EmbeddingService {
    private $conn;
    private $claudeService;

    public function __construct(mysqli $conn, ClaudeService $claudeService) {
        $this->conn = $conn;
        $this->claudeService = $claudeService;
    }

    /**
     * Generate embedding for a researcher profile
     * Concatenates bio, topics, geography, institution + ORCID publications (if available)
     */
    public function generateResearcherEmbedding(int $researcherId, string $embeddingType = 'profile'): bool {
        $stmt = $this->conn->prepare(
            "SELECT CONCAT_WS(' | ',
                COALESCE(CONCAT(first_name, ' ', last_name), ''),
                COALESCE(title, ''),
                COALESCE(bio, ''),
                COALESCE(focus_area, ''),
                COALESCE(focus_area_detail, ''),
                COALESCE(topics, ''),
                COALESCE(geography, ''),
                COALESCE(institution, ''),
                COALESCE(department, ''),
                COALESCE(orcid_id, '')
            ) as content,
            orcid_id
            FROM researchers WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $researcherId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        if (!$result || empty($result['content'])) {
            return false;
        }

        $content = $result['content'];

        // If researcher has ORCID, include publication data for richer semantic understanding
        if (!empty($result['orcid_id'])) {
            $pubStmt = $this->conn->prepare(
                "SELECT CONCAT_WS(' | ',
                    COALESCE(title, ''),
                    COALESCE(journal_name, ''),
                    COALESCE(GROUP_CONCAT(CONCAT('Year:', publication_year) SEPARATOR ', '), '')
                ) as pub_info
                FROM researcher_publications
                WHERE researcher_id = ?
                ORDER BY publication_year DESC
                LIMIT 20"
            );
            $pubStmt->bind_param('i', $researcherId);
            $pubStmt->execute();
            $pubs = $pubStmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];

            if (!empty($pubs)) {
                $pubTitles = array_map(fn($p) => $p['pub_info'], $pubs);
                $content .= ' | PUBLICATIONS: ' . implode(' | ', $pubTitles);
            }
        }

        return $this->storeEmbedding(
            'researcher',
            $researcherId,
            $embeddingType,
            $content
        );
    }

    /**
     * Generate embedding for a funding call
     * Concatenates title, description, topics, geography
     */
    public function generateFundingCallEmbedding(int $fundingCallId, string $embeddingType = 'full'): bool {
        $stmt = $this->conn->prepare(
            "SELECT CONCAT_WS(' | ',
                COALESCE(title, ''),
                COALESCE(description, ''),
                COALESCE(funder, ''),
                COALESCE(topics, ''),
                COALESCE(geography, '')
            ) as content
            FROM funding_calls WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $fundingCallId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        if (!$result || empty($result['content'])) {
            return false;
        }

        return $this->storeEmbedding(
            'funding_call',
            $fundingCallId,
            $embeddingType,
            $result['content']
        );
    }

    /**
     * Generate embedding for a search query
     * Returns the embedding vector as array of floats
     */
    public function generateQueryEmbedding(string $query): ?array {
        $embedding = $this->claudeService->getEmbedding($query);
        if (!$embedding) {
            error_log("[EmbeddingService] Failed to generate query embedding for: $query");
            return null;
        }
        return $embedding;
    }

    /**
     * Store embedding in database, checking for content changes via hash
     */
    private function storeEmbedding(string $entityType, int $entityId, string $embeddingType, string $content): bool {
        $contentHash = hash('sha256', $content);

        // Check if embedding already exists with same content hash
        $checkStmt = $this->conn->prepare(
            "SELECT id FROM " . ($entityType === 'researcher' ? 'researcher_embeddings' : 'funding_call_embeddings') . "
             WHERE " . ($entityType === 'researcher' ? 'researcher_id' : 'funding_call_id') . " = ?
             AND embedding_type = ? AND content_hash = ? LIMIT 1"
        );
        $checkStmt->bind_param('iss', $entityId, $embeddingType, $contentHash);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            return true; // Already have this embedding
        }

        // Generate new embedding via Claude API
        $embedding = $this->claudeService->getEmbedding($content);
        if (!$embedding) {
            error_log("[EmbeddingService] Failed to generate embedding for $entityType:$entityId");
            return false;
        }

        $embeddingJson = json_encode($embedding);
        $modelUsed = 'claude-embeddings-v3.5';
        $table = $entityType === 'researcher' ? 'researcher_embeddings' : 'funding_call_embeddings';
        $idColumn = $entityType === 'researcher' ? 'researcher_id' : 'funding_call_id';

        // Delete old embedding if content changed
        $deleteStmt = $this->conn->prepare(
            "DELETE FROM $table WHERE $idColumn = ? AND embedding_type = ?"
        );
        $deleteStmt->bind_param('is', $entityId, $embeddingType);
        @$deleteStmt->execute();

        // Insert new embedding
        $insertStmt = $this->conn->prepare(
            "INSERT INTO $table ($idColumn, embedding_type, embedding, content_hash, model_used)
             VALUES (?, ?, ?, ?, ?)"
        );
        $insertStmt->bind_param('issss', $entityId, $embeddingType, $embeddingJson, $contentHash, $modelUsed);

        if (!$insertStmt->execute()) {
            error_log("[EmbeddingService] Failed to insert embedding: " . $this->conn->error);
            return false;
        }

        return true;
    }

    /**
     * Calculate cosine similarity between two embeddings
     * Returns value between -1 and 1, typically 0.5-1.0 for relevant matches
     */
    public static function cosineSimilarity(array $emb1, array $emb2): float {
        if (count($emb1) === 0 || count($emb2) === 0) {
            return 0.0;
        }

        // Handle mismatched dimensions by using minimum
        $minLen = min(count($emb1), count($emb2));

        $dotProduct = 0.0;
        $norm1 = 0.0;
        $norm2 = 0.0;

        for ($i = 0; $i < $minLen; $i++) {
            $dotProduct += (float)$emb1[$i] * (float)$emb2[$i];
            $norm1 += (float)$emb1[$i] * (float)$emb1[$i];
            $norm2 += (float)$emb2[$i] * (float)$emb2[$i];
        }

        $magnitude = sqrt($norm1) * sqrt($norm2);
        if ($magnitude === 0.0) {
            return 0.0;
        }

        // Normalize to 0-1 range for consistency
        $similarity = $dotProduct / $magnitude;
        return max(0.0, $similarity); // Ensure non-negative
    }

    /**
     * Find researchers similar to a query embedding above threshold
     * Returns array of [researcher_id, similarity_score, researcher_data]
     */
    public function findSimilarResearchers(array $queryEmbedding, float $threshold = 0.75, int $limit = 20): array {
        $stmt = $this->conn->prepare(
            "SELECT r.*, re.embedding
             FROM researcher_embeddings re
             JOIN researchers r ON r.id = re.researcher_id
             WHERE re.embedding_type = 'profile'
             AND r.status = 'active'
             AND r.deleted_at IS NULL
             LIMIT ?"
        );
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];

        $scored = [];
        foreach ($results as $row) {
            $embedding = json_decode($row['embedding'], true);
            if (!$embedding) continue;

            $similarity = self::cosineSimilarity($queryEmbedding, $embedding);
            if ($similarity >= $threshold) {
                $scored[] = [
                    'researcher_id' => $row['id'],
                    'similarity' => $similarity,
                    'researcher' => $row
                ];
            }
        }

        // Sort by similarity descending
        usort($scored, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
        return array_slice($scored, 0, $limit);
    }

    /**
     * Batch generate embeddings for all researchers (admin task)
     * Use for initial setup or after bulk imports
     */
    public function batchGenerateResearcherEmbeddings(int $limit = 100, int $offset = 0): array {
        $stmt = $this->conn->prepare(
            "SELECT id FROM researchers WHERE deleted_at IS NULL LIMIT ? OFFSET ?"
        );
        $stmt->bind_param('ii', $limit, $offset);
        $stmt->execute();
        $researchers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];

        $results = [
            'total' => count($researchers),
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach ($researchers as $r) {
            if ($this->generateResearcherEmbedding($r['id'], 'profile')) {
                $results['success']++;
            } else {
                $results['failed']++;
                $results['errors'][] = "Failed to generate embedding for researcher {$r['id']}";
            }
        }

        return $results;
    }

    /**
     * Check if researcher has valid embedding
     */
    public function hasValidEmbedding(int $researcherId): bool {
        $stmt = $this->conn->prepare(
            "SELECT 1 FROM researcher_embeddings
             WHERE researcher_id = ? AND embedding_type = 'profile'
             AND embedding IS NOT NULL LIMIT 1"
        );
        $stmt->bind_param('i', $researcherId);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    /**
     * Regenerate embedding if content hash changed
     */
    public function regenerateIfNeeded(string $entityType, int $entityId): bool {
        if ($entityType === 'researcher') {
            return $this->generateResearcherEmbedding($entityId, 'profile');
        } elseif ($entityType === 'funding_call') {
            return $this->generateFundingCallEmbedding($entityId, 'full');
        }
        return false;
    }
}
?>
