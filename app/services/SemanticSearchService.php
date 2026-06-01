<?php
/**
 * SemanticSearchService — Advanced semantic search with hybrid ranking
 * Combines keyword matching + semantic similarity + research field overlap
 */

class SemanticSearchService {
    private $conn;
    private $claudeService;

    public function __construct(mysqli $conn, ClaudeService $claudeService) {
        $this->conn = $conn;
        $this->claudeService = $claudeService;
    }

    /**
     * Semantic search for researchers
     * Runs keyword search + semantic expansion + hybrid ranking
     */
    public function searchResearchers(
        string $query,
        array $topicFilters = [],
        array $geoFilters = [],
        int $limit = 20
    ): array {
        // Step 1: Parse and expand query via Claude
        $queryExpansion = $this->expandQuery($query);
        $expandedKeywords = $queryExpansion['keywords'] ?? [$query];
        $suggestedTopics = $queryExpansion['topics'] ?? [];
        $suggestedGeos = $queryExpansion['geographies'] ?? [];

        // Step 2: Run keyword search
        $keywordResults = $this->keywordSearchResearchers(
            $query,
            $topicFilters ?: $suggestedTopics,
            $geoFilters ?: $suggestedGeos
        );

        // Step 3: Run semantic search (concept-based)
        $semanticResults = $this->semanticSearchResearchersConcepts(
            $query,
            $expandedKeywords,
            $suggestedTopics,
            $suggestedGeos,
            $limit
        );

        // Step 4: Merge results with hybrid ranking
        $merged = $this->mergeAndRankResults($keywordResults, $semanticResults, $query);

        // Step 5: Limit and return
        return array_slice($merged, 0, $limit);
    }

    /**
     * Expand search query using Claude to identify related concepts
     */
    private function expandQuery(string $query): array {
        $prompt = <<<PROMPT
Analyze this research search query and expand it to related concepts:
"$query"

Return JSON with:
- keywords: [list of key search terms]
- topics: [related research domains/topics]
- geographies: [relevant geographic regions]
- concepts: [related conceptual areas]

Examples:
- "food systems" → topics: ["agriculture", "food security", "nutrition", "supply chains"]
- "climate change" → topics: ["environment", "sustainability", "energy", "adaptation"]

Be thorough but focused. Return valid JSON only.
PROMPT;

        $result = $this->claudeService->call('claude-haiku-4-5-20251001', $prompt, 'query_expansion', 500);
        if (!$result) {
            return ['keywords' => [$query], 'topics' => [], 'geographies' => [], 'concepts' => []];
        }

        $parsed = json_decode($result, true) ?? [];
        return [
            'keywords' => (array)($parsed['keywords'] ?? [$query]),
            'topics' => (array)($parsed['topics'] ?? []),
            'geographies' => (array)($parsed['geographies'] ?? []),
            'concepts' => (array)($parsed['concepts'] ?? []),
        ];
    }

    /**
     * Traditional keyword search
     */
    private function keywordSearchResearchers(string $query, array $topics = [], array $geos = []): array {
        $searchTerm = '%' . addslashes($query) . '%';
        $sql = "SELECT r.*, COALESCE(r.bio, '') + COALESCE(r.topics, '') + COALESCE(r.focus_area, '') as relevance_text
                FROM researchers r
                WHERE r.deleted_at IS NULL
                AND r.status IN ('active', 'pending_approval')
                AND (
                    r.first_name LIKE ? OR r.last_name LIKE ?
                    OR r.bio LIKE ? OR r.topics LIKE ?
                    OR r.focus_area LIKE ? OR r.institution LIKE ?
                )";

        $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm];

        if (!empty($topics)) {
            $topicConditions = array_map(fn($t) => "r.topics LIKE ?", $topics);
            $sql .= " AND (" . implode(" OR ", $topicConditions) . ")";
            $params = array_merge($params, array_map(fn($t) => '%' . addslashes($t) . '%', $topics));
        }

        if (!empty($geos)) {
            $geoConditions = array_map(fn($g) => "r.geography LIKE ?", $geos);
            $sql .= " AND (" . implode(" OR ", $geoConditions) . ")";
            $params = array_merge($params, array_map(fn($g) => '%' . addslashes($g) . '%', $geos));
        }

        $sql .= " LIMIT 100";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return [];

        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];

        // Score each result
        $scored = [];
        foreach ($results as $r) {
            $score = $this->scoreKeywordMatch($query, $r);
            $scored[] = ['researcher' => $r, 'keyword_score' => $score, 'type' => 'keyword'];
        }

        usort($scored, fn($a, $b) => $b['keyword_score'] <=> $a['keyword_score']);
        return $scored;
    }

    /**
     * Semantic search using concept matching
     */
    private function semanticSearchResearchersConcepts(
        string $query,
        array $keywords,
        array $topics,
        array $geos,
        int $limit
    ): array {
        // Find researchers whose topics/geography overlap with suggested concepts
        $allConcepts = array_merge($keywords, $topics, $geos);
        $allConcepts = array_unique(array_filter($allConcepts));

        if (empty($allConcepts)) {
            return [];
        }

        $searchTerms = array_map(fn($c) => '%' . addslashes($c) . '%', $allConcepts);
        $placeholders = implode(',', array_fill(0, count($searchTerms), '?'));

        $sql = "SELECT r.*, ROUND(
                  (CHAR_LENGTH(r.topics) - CHAR_LENGTH(REPLACE(LOWER(r.topics), LOWER(?), ''))) / CHAR_LENGTH(?) as concept_matches
                ) as semantic_boost
                FROM researchers r
                WHERE r.deleted_at IS NULL
                AND r.status IN ('active', 'pending_approval')
                AND (
                  r.topics LIKE ? OR r.geography LIKE ? OR r.focus_area LIKE ? OR r.bio LIKE ?
                )
                ORDER BY semantic_boost DESC, r.created_at DESC
                LIMIT ?";

        $params = [$query, $query, '%' . addslashes($query) . '%', '%' . addslashes($query) . '%',
                   '%' . addslashes($query) . '%', '%' . addslashes($query) . '%', $limit];

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return [];

        $stmt->bind_param('ssssssi', ...$params);
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];

        // Score semantic matches
        $scored = [];
        foreach ($results as $r) {
            $score = $this->scoreSemanticMatch($query, $r, $topics, $geos);
            $scored[] = ['researcher' => $r, 'semantic_score' => $score, 'type' => 'semantic'];
        }

        usort($scored, fn($a, $b) => $b['semantic_score'] <=> $a['semantic_score']);
        return $scored;
    }

    /**
     * Score keyword match (0-100)
     */
    private function scoreKeywordMatch(string $query, array $researcher): float {
        $score = 0.0;
        $queryLower = strtolower($query);
        $bioLower = strtolower($researcher['bio'] ?? '');
        $topicsLower = strtolower($researcher['topics'] ?? '');
        $nameMatch = strtolower($researcher['first_name'] ?? '') . ' ' . strtolower($researcher['last_name'] ?? '');

        // Exact name match
        if (stripos($nameMatch, $queryLower) === 0) {
            $score += 20;
        }

        // Topic match
        if (stripos($topicsLower, $queryLower) !== false) {
            $score += 15;
        }

        // Bio mention
        if (stripos($bioLower, $queryLower) !== false) {
            $score += 10;
        }

        // Word count overlap
        $queryWords = array_filter(preg_split('/\s+/', $queryLower));
        $topicWords = array_filter(preg_split('/\s+/', $topicsLower));
        $overlap = count(array_intersect($queryWords, $topicWords));
        $score += min($overlap * 5, 20);

        return min($score, 100);
    }

    /**
     * Score semantic match (0-100)
     * Based on research field overlap and concept similarity
     */
    private function scoreSemanticMatch(string $query, array $researcher, array $topics, array $geos): float {
        $score = 0.0;

        $topicsLower = strtolower($researcher['topics'] ?? '');
        $geoLower = strtolower($researcher['geography'] ?? '');

        // Topic overlap
        foreach ($topics as $topic) {
            if (stripos($topicsLower, $topic) !== false) {
                $score += 20;
            }
        }

        // Geographic overlap
        foreach ($geos as $geo) {
            if (stripos($geoLower, $geo) !== false) {
                $score += 10;
            }
        }

        // Profile completeness bonus
        $profileStrength = (int)($researcher['bio'] ? 10 : 0)
                         + (int)($researcher['topics'] ? 10 : 0)
                         + (int)($researcher['geography'] ? 10 : 0)
                         + (int)($researcher['orcid_id'] ? 5 : 0);
        $score += min($profileStrength, 20);

        return min($score, 100);
    }

    /**
     * Merge keyword and semantic results using hybrid ranking
     * Weights: 45% semantic + 30% keyword + 15% field + 10% profile
     */
    private function mergeAndRankResults(array $keywordResults, array $semanticResults, string $query): array {
        $merged = [];
        $seen = [];

        // Add semantic results first (primary)
        foreach ($semanticResults as $result) {
            $rid = $result['researcher']['id'];
            $semanticScore = $result['semantic_score'] / 100.0;

            // Calculate field strength
            $fieldScore = $this->calculateFieldStrength($result['researcher'], $query);

            // Calculate profile strength
            $profileScore = $this->calculateProfileStrength($result['researcher']);

            $finalScore = (0.45 * $semanticScore) + (0.30 * 0) + (0.15 * $fieldScore) + (0.10 * $profileScore);

            $merged[$rid] = [
                'researcher' => $result['researcher'],
                'semantic_score' => $semanticScore,
                'keyword_score' => 0,
                'field_score' => $fieldScore,
                'profile_score' => $profileScore,
                'final_score' => $finalScore,
                'explanation' => null
            ];

            $seen[$rid] = true;
        }

        // Add keyword results that weren't in semantic
        foreach ($keywordResults as $result) {
            $rid = $result['researcher']['id'];
            if (isset($seen[$rid])) {
                // Boost existing result
                $keywordScore = $result['keyword_score'] / 100.0;
                $merged[$rid]['keyword_score'] = $keywordScore;
                $merged[$rid]['final_score'] = (0.45 * $merged[$rid]['semantic_score'])
                                              + (0.30 * $keywordScore)
                                              + (0.15 * $merged[$rid]['field_score'])
                                              + (0.10 * $merged[$rid]['profile_score']);
            } else {
                $keywordScore = $result['keyword_score'] / 100.0;
                $fieldScore = $this->calculateFieldStrength($result['researcher'], $query);
                $profileScore = $this->calculateProfileStrength($result['researcher']);

                $finalScore = (0.45 * 0) + (0.30 * $keywordScore) + (0.15 * $fieldScore) + (0.10 * $profileScore);

                $merged[$rid] = [
                    'researcher' => $result['researcher'],
                    'semantic_score' => 0,
                    'keyword_score' => $keywordScore,
                    'field_score' => $fieldScore,
                    'profile_score' => $profileScore,
                    'final_score' => $finalScore,
                    'explanation' => null
                ];

                $seen[$rid] = true;
            }
        }

        // Sort by final score
        usort($merged, fn($a, $b) => $b['final_score'] <=> $a['final_score']);
        return $merged;
    }

    /**
     * Calculate research field strength (0-1)
     */
    private function calculateFieldStrength(array $researcher, string $query): float {
        $topics = $researcher['topics'] ?? '';
        $focusArea = $researcher['focus_area'] ?? '';

        if (empty($topics) && empty($focusArea)) {
            return 0.3; // Partial credit for existence
        }

        // Check if query terms appear in research description
        $combined = strtolower($topics . ' ' . $focusArea);
        $score = 0.0;

        foreach (preg_split('/\s+/', strtolower($query)) as $word) {
            if (strlen($word) > 2 && stripos($combined, $word) !== false) {
                $score += 0.2;
            }
        }

        return min($score, 1.0);
    }

    /**
     * Calculate profile completeness (0-1)
     */
    private function calculateProfileStrength(array $researcher): float {
        $strength = 0.0;

        // Presence of key fields
        if (!empty($researcher['bio'])) $strength += 0.2;
        if (!empty($researcher['topics'])) $strength += 0.2;
        if (!empty($researcher['geography'])) $strength += 0.15;
        if (!empty($researcher['institution'])) $strength += 0.15;
        if (!empty($researcher['orcid_id'])) $strength += 0.15;
        if (!empty($researcher['google_scholar_url'])) $strength += 0.15;

        return min($strength, 1.0);
    }

    /**
     * Generate natural explanation for why a researcher matched
     */
    public function explainMatch(array $researcher, string $query): string {
        $explanations = [];

        // Topic match
        if (!empty($researcher['topics'])) {
            $topics = array_slice(preg_split('/,\s*/', $researcher['topics']), 0, 3);
            $explanations[] = "Works on: " . implode(', ', $topics);
        }

        // Geographic focus
        if (!empty($researcher['geography'])) {
            $geos = array_slice(preg_split('/,\s*/', $researcher['geography']), 0, 2);
            $explanations[] = "Geographic focus: " . implode(', ', $geos);
        }

        // Institution
        if (!empty($researcher['institution'])) {
            $explanations[] = "Based at: " . $researcher['institution'];
        }

        if (empty($explanations)) {
            return "Researcher matches your search criteria.";
        }

        return implode(". ", $explanations) . ".";
    }
}
?>
