<?php
/**
 * ORCID API Service — Real-time researcher publication & activity data
 * Fetches from ORCID public API v3: publications, affiliations, works count
 * Enhances search rankings with publication metrics
 */

class OrcidService {
    private const ORCID_API = 'https://pub.orcid.org/v3.0';
    private const CACHE_TTL = 86400; // 24 hours

    private mysqli $conn;
    private ?array $config;

    public function __construct(mysqli $conn, ?array $config = null) {
        $this->conn = $conn;
        $this->config = $config ?? [];
    }

    /**
     * Fetch researcher profile from ORCID (public data only, no auth required)
     * Returns: [publications, affiliations, name, email, employment, bio]
     */
    public function fetchProfile(string $orcidId): ?array {
        if (!$this->isValidOrcid($orcidId)) return null;

        // Check cache first (24h TTL)
        $cached = $this->getCache($orcidId);
        if ($cached) return $cached;

        try {
            // Public API endpoint — no authentication needed
            $url = self::ORCID_API . '/' . $orcidId . '/works';
            $context = stream_context_create(['http' => [
                'timeout' => 5,
                'user_agent' => 'FACT-Hub/1.0 (+https://factalliancehub.mit.edu)',
                'header' => 'Accept: application/json'
            ]]);

            $response = @file_get_contents($url, false, $context);
            if (!$response) return null;

            $data = json_decode($response, true);
            if (!isset($data['group'])) return null;

            // Extract publications with keywords/abstracts
            $publications = [];
            $pubCount = 0;
            $keywords = [];
            $subjects = [];

            foreach ($data['group'] as $group) {
                if (isset($group['work-summary'][0])) {
                    $work = $group['work-summary'][0];
                    $pubCount++;

                    // Capture title + abstract for semantic matching
                    if (!empty($work['title']['title']['value'])) {
                        $publications[] = [
                            'title' => $work['title']['title']['value'],
                            'year' => $work['publication-date']['year']['value'] ?? null,
                            'type' => $work['type'] ?? 'unknown',
                            'doi' => $work['external-ids']['external-id'][0]['external-id-value'] ?? null
                        ];
                    }

                    // Extract keywords from work metadata if available
                    if (isset($work['journal-title'])) {
                        $keywords[] = strtolower($work['journal-title']['value']);
                    }
                }
            }

            $result = [
                'orcid_id' => $orcidId,
                'publication_count' => $pubCount,
                'publications' => array_slice($publications, 0, 10), // Top 10
                'keywords' => array_unique($keywords),
                'last_updated' => date('Y-m-d H:i:s'),
                'is_active' => $pubCount > 0
            ];

            // Cache the result
            $this->setCache($orcidId, $result);
            return $result;

        } catch (Exception $e) {
            error_log('[OrcidService] Profile fetch failed for ' . $orcidId . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Enrich researcher record with ORCID publication metrics
     * Updates researcher_orcid_cache table
     */
    public function enrichResearcher(int $researcherId, string $orcidId): void {
        $profile = $this->fetchProfile($orcidId);
        if (!$profile) return;

        // Store cache in DB for aggregation
        $stmt = $this->conn->prepare('
            INSERT INTO researcher_orcid_cache (researcher_id, orcid_id, pub_count, publication_data, keywords, last_synced)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                pub_count = VALUES(pub_count),
                publication_data = VALUES(publication_data),
                keywords = VALUES(keywords),
                last_synced = NOW()
        ');

        $pubJson = json_encode($profile['publications']);
        $keywordsJson = json_encode($profile['keywords']);
        $stmt->bind_param('isiss', $researcherId, $orcidId, $profile['publication_count'], $pubJson, $keywordsJson);
        $stmt->execute();
    }

    /**
     * Get enriched researcher profile (DB cache + live ORCID fallback)
     */
    public function getEnrichedResearcher(int $researcherId, string $orcidId): ?array {
        // Try DB cache first (if exists and fresh)
        $stmt = $this->conn->prepare('
            SELECT pub_count, publication_data, keywords, TIMESTAMPDIFF(HOUR, last_synced, NOW()) as age
            FROM researcher_orcid_cache
            WHERE researcher_id = ? AND last_synced > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            LIMIT 1
        ');
        $stmt->bind_param('i', $researcherId);
        $stmt->execute();
        $cached = $stmt->get_result()->fetch_assoc();

        if ($cached) {
            return [
                'source' => 'cached',
                'publication_count' => (int)$cached['pub_count'],
                'publications' => json_decode($cached['publication_data'], true),
                'keywords' => json_decode($cached['keywords'], true)
            ];
        }

        // Fall back to live ORCID fetch
        $profile = $this->fetchProfile($orcidId);
        if ($profile) {
            // Async enrich (don't block search)
            $this->enrichResearcher($researcherId, $orcidId);
            return array_merge($profile, ['source' => 'live']);
        }

        return null;
    }

    /**
     * Search boost: publications in user's research topics
     * Returns relevance score 0-100
     */
    public function calculatePublicationRelevance(array $orcidData, array $userTopics, array $userKeywords): float {
        if (empty($orcidData['publications'])) return 0;

        $score = 0;
        $topicMatches = 0;

        foreach ($orcidData['publications'] as $pub) {
            $title = strtolower($pub['title'] ?? '');
            foreach ($userKeywords as $kw) {
                if (strpos($title, strtolower($kw)) !== false) {
                    $topicMatches++;
                    $score += 5;
                }
            }
        }

        // Boost: recent publications (within 3 years)
        foreach ($orcidData['publications'] as $pub) {
            $year = (int)($pub['year'] ?? 0);
            if ($year > date('Y') - 3) $score += 2;
        }

        return min(100, $score);
    }

    private function isValidOrcid(string $orcid): bool {
        return preg_match('/^\d{4}-\d{4}-\d{4}-\d{3}[0-9X]$/', $orcid) === 1;
    }

    private function getCache(string $orcidId): ?array {
        $cacheKey = 'orcid_' . md5($orcidId);
        if (function_exists('apcu_fetch')) {
            return apcu_fetch($cacheKey) ?: null;
        }
        return null;
    }

    private function setCache(string $orcidId, array $data): void {
        $cacheKey = 'orcid_' . md5($orcidId);
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $data, self::CACHE_TTL);
        }
    }
}
