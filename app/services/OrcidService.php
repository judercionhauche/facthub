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
     * Fetch comprehensive researcher profile from ORCID (public data only, no auth required)
     * Returns: [publications, affiliations, education, fundings, peer-reviews, keywords, activity_score]
     */
    public function fetchProfile(string $orcidId): ?array {
        if (!$this->isValidOrcid($orcidId)) return null;

        // Check cache first (24h TTL)
        $cached = $this->getCache($orcidId);
        if ($cached) return $cached;

        try {
            $result = [
                'orcid_id' => $orcidId,
                'publications' => $this->fetchWorks($orcidId),
                'affiliations' => $this->fetchAffiliations($orcidId),
                'education' => $this->fetchEducation($orcidId),
                'fundings' => $this->fetchFundings($orcidId),
                'peer_reviews' => $this->fetchPeerReviews($orcidId),
                'last_updated' => date('Y-m-d H:i:s'),
            ];

            // Compute activity score (0-100)
            $result['activity_score'] = $this->computeActivityScore($result);
            $result['keywords'] = $this->extractKeywords($result);
            $result['is_active'] = $result['activity_score'] > 20;

            // Cache the comprehensive result
            $this->setCache($orcidId, $result);
            return $result;

        } catch (Exception $e) {
            error_log('[OrcidService] Profile fetch failed for ' . $orcidId . ': ' . $e->getMessage());
            return null;
        }
    }

    private function fetchWorks(string $orcidId): array {
        try {
            $url = self::ORCID_API . '/' . $orcidId . '/works';
            $response = @file_get_contents($url, false, $this->getContext());
            if (!$response) return [];

            $data = json_decode($response, true);
            if (!isset($data['group'])) return [];

            $publications = [];
            $seen = [];
            // Fetch ALL works from ALL groups (not just first 20, and not just first work per group)
            foreach ($data['group'] as $group) {
                if (isset($group['work-summary']) && is_array($group['work-summary'])) {
                    // Extract ALL works from this group, not just [0]
                    foreach ($group['work-summary'] as $work) {
                        if (!empty($work['title']['title']['value'])) {
                            $title = $work['title']['title']['value'];
                            $year = $work['publication-date']['year']['value'] ?? null;
                            // Deduplicate by title + year
                            $key = $title . '|' . $year;
                            if (!isset($seen[$key])) {
                                $seen[$key] = true;
                                $publications[] = [
                                    'title' => $title,
                                    'year' => $year,
                                    'type' => $work['type'] ?? 'unknown',
                                    'doi' => $work['external-ids']['external-id'][0]['external-id-value'] ?? null,
                                    'journal' => $work['journal-title']['value'] ?? null
                                ];
                            }
                        }
                    }
                }
            }
            return $publications;
        } catch (Exception $e) {
            error_log('[OrcidService] Works fetch failed: ' . $e->getMessage());
            return [];
        }
    }

    private function fetchAffiliations(string $orcidId): array {
        try {
            $url = self::ORCID_API . '/' . $orcidId . '/employments';
            $response = @file_get_contents($url, false, $this->getContext());
            if (!$response) return [];

            $data = json_decode($response, true);
            if (!isset($data['affiliation-group'])) return [];

            $affiliations = [];
            foreach (array_slice($data['affiliation-group'], 0, 10) as $aff) {
                if (isset($aff['summaries'][0])) {
                    $summary = $aff['summaries'][0];
                    $affiliations[] = [
                        'organization' => $summary['organization']['name'] ?? null,
                        'role' => $summary['role-title'] ?? null,
                        'start_year' => $summary['start-date']['year']['value'] ?? null,
                        'end_year' => $summary['end-date']['year']['value'] ?? null,
                        'country' => $summary['organization']['address']['country'] ?? null
                    ];
                }
            }
            return $affiliations;
        } catch (Exception $e) {
            error_log('[OrcidService] Affiliations fetch failed: ' . $e->getMessage());
            return [];
        }
    }

    private function fetchEducation(string $orcidId): array {
        try {
            $url = self::ORCID_API . '/' . $orcidId . '/educations';
            $response = @file_get_contents($url, false, $this->getContext());
            if (!$response) return [];

            $data = json_decode($response, true);
            if (!isset($data['affiliation-group'])) return [];

            $education = [];
            foreach (array_slice($data['affiliation-group'], 0, 10) as $edu) {
                if (isset($edu['summaries'][0])) {
                    $summary = $edu['summaries'][0];
                    $education[] = [
                        'institution' => $summary['organization']['name'] ?? null,
                        'degree' => $summary['role-title'] ?? null,
                        'field' => $summary['department-name'] ?? null,
                        'year' => $summary['end-date']['year']['value'] ?? null,
                        'country' => $summary['organization']['address']['country'] ?? null
                    ];
                }
            }
            return $education;
        } catch (Exception $e) {
            error_log('[OrcidService] Education fetch failed: ' . $e->getMessage());
            return [];
        }
    }

    private function fetchFundings(string $orcidId): array {
        try {
            $url = self::ORCID_API . '/' . $orcidId . '/fundings';
            $response = @file_get_contents($url, false, $this->getContext());
            if (!$response) return [];

            $data = json_decode($response, true);
            if (!isset($data['group'])) return [];

            $fundings = [];
            foreach (array_slice($data['group'], 0, 15) as $group) {
                if (isset($group['funding-summary'][0])) {
                    $fund = $group['funding-summary'][0];
                    $fundings[] = [
                        'title' => $fund['title']['title']['value'] ?? null,
                        'funder' => $fund['organization']['name'] ?? null,
                        'type' => $fund['type'] ?? null,
                        'amount' => $fund['amount']['value'] ?? null,
                        'currency' => $fund['amount']['currency'] ?? null,
                        'start_year' => $fund['start-date']['year']['value'] ?? null,
                        'end_year' => $fund['end-date']['year']['value'] ?? null
                    ];
                }
            }
            return $fundings;
        } catch (Exception $e) {
            error_log('[OrcidService] Fundings fetch failed: ' . $e->getMessage());
            return [];
        }
    }

    private function fetchPeerReviews(string $orcidId): array {
        try {
            $url = self::ORCID_API . '/' . $orcidId . '/peer-reviews';
            $response = @file_get_contents($url, false, $this->getContext());
            if (!$response) return [];

            $data = json_decode($response, true);
            if (!isset($data['group'])) return [];

            $reviews = [];
            foreach (array_slice($data['group'], 0, 10) as $group) {
                if (isset($group['peer-review-summary'][0])) {
                    $review = $group['peer-review-summary'][0];
                    $reviews[] = [
                        'title' => $review['review-type'] ?? null,
                        'completion_year' => $review['completion-date']['year']['value'] ?? null,
                        'organization' => $review['organization']['name'] ?? null
                    ];
                }
            }
            return $reviews;
        } catch (Exception $e) {
            error_log('[OrcidService] Peer reviews fetch failed: ' . $e->getMessage());
            return [];
        }
    }

    private function computeActivityScore(array $profile): float {
        $score = 0;

        // Publications (0-40 points)
        $pubCount = count($profile['publications'] ?? []);
        $score += min(40, $pubCount * 2);

        // Affiliations (0-20 points)
        $score += min(20, count($profile['affiliations'] ?? []) * 5);

        // Education (0-10 points)
        $score += min(10, count($profile['education'] ?? []) * 3);

        // Fundings (0-20 points)
        $score += min(20, count($profile['fundings'] ?? []) * 2);

        // Peer reviews (0-10 points)
        $score += min(10, count($profile['peer_reviews'] ?? []) * 2);

        // Recent activity boost (0-20 points extra)
        if ($pubCount > 0 && !empty($profile['publications'][0]['year'])) {
            $lastPubYear = (int)$profile['publications'][0]['year'];
            if ($lastPubYear >= date('Y') - 1) $score += 20; // Recent pub
            elseif ($lastPubYear >= date('Y') - 2) $score += 10;
            elseif ($lastPubYear >= date('Y') - 3) $score += 5;
        }

        return min(100, $score);
    }

    private function extractKeywords(array $profile): array {
        $keywords = [];

        // From publications
        foreach ($profile['publications'] ?? [] as $pub) {
            if (!empty($pub['journal'])) {
                $keywords[] = strtolower($pub['journal']);
            }
            if (!empty($pub['title'])) {
                // Extract key terms from title
                $terms = preg_split('/[\s\-,;:]+/', strtolower($pub['title']));
                $keywords = array_merge($keywords, array_filter($terms, fn($t) => strlen($t) > 3));
            }
        }

        // From affiliations
        foreach ($profile['affiliations'] ?? [] as $aff) {
            if (!empty($aff['role'])) {
                $keywords[] = strtolower($aff['role']);
            }
        }

        // From education
        foreach ($profile['education'] ?? [] as $edu) {
            if (!empty($edu['field'])) {
                $keywords[] = strtolower($edu['field']);
            }
        }

        // From fundings
        foreach ($profile['fundings'] ?? [] as $fund) {
            if (!empty($fund['title'])) {
                $terms = preg_split('/[\s\-,;:]+/', strtolower($fund['title']));
                $keywords = array_merge($keywords, array_filter($terms, fn($t) => strlen($t) > 3));
            }
        }

        return array_unique(array_slice(array_values($keywords), 0, 20));
    }

    private function getContext() {
        return stream_context_create(['http' => [
            'timeout' => 5,
            'user_agent' => 'FACT-Hub/1.0 (+https://factalliancehub.mit.edu)',
            'header' => 'Accept: application/json'
        ]]);
    }

    /**
     * Enrich researcher record with comprehensive ORCID data
     * Stores publications, affiliations, education, fundings, peer reviews, activity score
     */
    public function enrichResearcher(int $researcherId, string $orcidId): void {
        $profile = $this->fetchProfile($orcidId);
        if (!$profile) return;

        $stmt = $this->conn->prepare('
            INSERT INTO researcher_orcid_cache (
                researcher_id, orcid_id, activity_score,
                pub_count, affiliation_count, education_count, funding_count, peer_review_count,
                publication_data, affiliation_data, education_data, funding_data, peer_review_data,
                keywords, is_active, last_synced
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                activity_score = VALUES(activity_score),
                pub_count = VALUES(pub_count),
                affiliation_count = VALUES(affiliation_count),
                education_count = VALUES(education_count),
                funding_count = VALUES(funding_count),
                peer_review_count = VALUES(peer_review_count),
                publication_data = VALUES(publication_data),
                affiliation_data = VALUES(affiliation_data),
                education_data = VALUES(education_data),
                funding_data = VALUES(funding_data),
                peer_review_data = VALUES(peer_review_data),
                keywords = VALUES(keywords),
                is_active = VALUES(is_active),
                last_synced = NOW()
        ');

        $pubCount = count($profile['publications'] ?? []);
        $affCount = count($profile['affiliations'] ?? []);
        $eduCount = count($profile['education'] ?? []);
        $fundCount = count($profile['fundings'] ?? []);
        $prCount = count($profile['peer_reviews'] ?? []);
        $isActive = $profile['is_active'] ? 1 : 0;
        $activityScore = (float)($profile['activity_score'] ?? 0);
        $pubData = json_encode($profile['publications']);
        $affData = json_encode($profile['affiliations']);
        $eduData = json_encode($profile['education']);
        $fundData = json_encode($profile['fundings']);
        $prData = json_encode($profile['peer_reviews']);
        $keywordData = json_encode($profile['keywords']);

        $stmt->bind_param(
            'isdiiiiissssssi',
            $researcherId,
            $orcidId,
            $activityScore,
            $pubCount,
            $affCount,
            $eduCount,
            $fundCount,
            $prCount,
            $pubData,
            $affData,
            $eduData,
            $fundData,
            $prData,
            $keywordData,
            $isActive
        );
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
     * Calculate comprehensive ORCID relevance score (0-100)
     * Checks ENTIRE ORCID profile: publications, affiliations, education, fundings for keyword matches
     */
    public function calculateOrcidRelevance(array $orcidData, array $userTopics, array $userKeywords): float {
        $score = 0;
        $pubMatchCount = 0;

        // PUBLICATION RELEVANCE (0-45 points) — most important signal
        // Check EVERY publication title + journal thoroughly
        foreach ($orcidData['publications'] ?? [] as $pub) {
            $title = strtolower($pub['title'] ?? '');
            $journal = strtolower($pub['journal'] ?? '');
            $fullPub = $title . ' ' . $journal;

            // Check EACH keyword against publication
            foreach ($userKeywords as $kw) {
                $kwLower = strtolower($kw);
                // Exact phrase match gets bonus
                if (strpos($fullPub, $kwLower) !== false) {
                    $score += 4;
                    $pubMatchCount++;
                }
                // Also check for word variants (e.g., "water" in "water harvesting")
                else {
                    // Check if keyword is contained as a word (not substring)
                    $words = preg_split('/[\s\-,;:()]+/', $fullPub);
                    foreach ($words as $word) {
                        if (strlen($word) > 2 && strpos($word, $kwLower) !== false) {
                            $score += 2;
                            $pubMatchCount++;
                        }
                    }
                }
            }

            // Also check topics (research areas)
            foreach ($userTopics as $topic) {
                if (strpos($title, strtolower($topic)) !== false) {
                    $score += 3;
                    $pubMatchCount++;
                }
            }

            // Recency boost (recent pubs = more active researcher)
            $year = (int)($pub['year'] ?? 0);
            if ($year >= date('Y') - 1) $score += 3;
            elseif ($year >= date('Y') - 2) $score += 1.5;
        }
        $score = min(45, $score);

        // AFFILIATION RELEVANCE (0-20 points)
        // Check organization name + role for keywords
        foreach ($orcidData['affiliations'] ?? [] as $aff) {
            $orgRole = strtolower(($aff['organization'] ?? '') . ' ' . ($aff['role'] ?? ''));
            foreach ($userKeywords as $kw) {
                if (strpos($orgRole, strtolower($kw)) !== false) {
                    $score += 3;
                }
            }
            foreach ($userTopics as $topic) {
                if (strpos($orgRole, strtolower($topic)) !== false) {
                    $score += 2;
                }
            }
        }

        // EDUCATION RELEVANCE (0-15 points)
        // Check institution + degree + field
        foreach ($orcidData['education'] ?? [] as $edu) {
            $eduText = strtolower(($edu['institution'] ?? '') . ' ' . ($edu['degree'] ?? '') . ' ' . ($edu['field'] ?? ''));
            foreach ($userTopics as $topic) {
                if (strpos($eduText, strtolower($topic)) !== false) {
                    $score += 3;
                }
            }
            foreach ($userKeywords as $kw) {
                if (strpos($eduText, strtolower($kw)) !== false) {
                    $score += 1;
                }
            }
        }

        // FUNDING RELEVANCE (0-20 points)
        // Check funding title + funder for keywords
        foreach ($orcidData['fundings'] ?? [] as $fund) {
            $fundText = strtolower(($fund['title'] ?? '') . ' ' . ($fund['funder'] ?? ''));
            foreach ($userKeywords as $kw) {
                if (strpos($fundText, strtolower($kw)) !== false) {
                    $score += 3;
                }
            }
            foreach ($userTopics as $topic) {
                if (strpos($fundText, strtolower($topic)) !== false) {
                    $score += 2;
                }
            }
        }

        // ACTIVITY SCORE (0-10 points) — researchers with diverse activity (pubs + funding + teaching) rank higher
        $activity = $orcidData['activity_score'] ?? 0;
        $score += min(10, $activity / 10);

        // PUBLICATION COUNT BONUS (0-10 points)
        // Researchers with many relevant publications get extra boost
        if ($pubMatchCount > 3) $score += 10;
        elseif ($pubMatchCount > 1) $score += 5;

        return min(100, $score);
    }

    /**
     * Legacy method for backwards compatibility
     */
    public function calculatePublicationRelevance(array $orcidData, array $userTopics, array $userKeywords): float {
        return $this->calculateOrcidRelevance($orcidData, $userTopics, $userKeywords);
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
