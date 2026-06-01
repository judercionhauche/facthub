<?php
/**
 * ClaudeService — wrapper around the Anthropic Claude API.
 * Handles: caching, retries, token tracking, graceful failures.
 * All public methods return null on failure (never throw).
 */
class ClaudeService {
    const MODEL_HAIKU  = 'claude-haiku-4-5-20251001';   // bulk scoring, search parsing
    const MODEL_SONNET = 'claude-sonnet-4-6';            // quality summaries

    // Pricing per million tokens (USD)
    const PRICING = [
        'claude-haiku-4-5-20251001' => ['in' => 0.80,  'out' => 4.00],
        'claude-sonnet-4-6'         => ['in' => 3.00,  'out' => 15.00],
    ];

    const API_URL = 'https://api.anthropic.com/v1/messages';
    const API_VERSION = '2023-06-01';

    private mysqli $conn;
    private string $apiKey;
    private string $triggeredBy;

    public function __construct(mysqli $conn, string $triggeredBy = 'system') {
        $this->conn = $conn;
        $this->apiKey = (string)getenv('ANTHROPIC_API_KEY');
        $this->triggeredBy = $triggeredBy;
    }

    /**
     * Escape user input for safe embedding in prompts.
     * Prevents prompt injection by using JSON encoding.
     */
    private function escapePromptInput(string $input): string {
        if (mb_strlen($input) > 500) {
            throw new Exception('Prompt input exceeds maximum length (500 chars)');
        }
        return json_encode($input, JSON_UNESCAPED_UNICODE);
    }

    public function isAvailable(): bool {
        return !empty($this->apiKey);
    }

    /**
     * Extract JSON from Claude response (handles markdown-wrapped responses).
     */
    private function extractJson(string $response): ?array {
        $response = trim($response);
        // Remove markdown code blocks if present
        if (strpos($response, '```') === 0) {
            $response = preg_replace('/^```(?:json)?\s*/i', '', $response);
            $response = preg_replace('/\s*```$/', '', $response);
            $response = trim($response);
        }
        return json_decode($response, true);
    }

    /**
     * Score how well a researcher matches a funding call.
     * Returns ['score' => int(0-100), 'explanation' => string] or null.
     */
    public function scoreMatch(int $fcId, array $fc, int $rId, array $r): ?array {
        if (!$this->isAvailable()) return null;

        // Check cache
        $stmt = $this->conn->prepare('SELECT score_ai, explanation FROM match_scores WHERE funding_call_id=? AND researcher_id=? LIMIT 1');
        $stmt->bind_param('ii', $fcId, $rId); $stmt->execute();
        $cached = $stmt->get_result()->fetch_assoc();
        if ($cached && $cached['score_ai'] !== null) {
            return ['score' => (int)$cached['score_ai'], 'explanation' => $cached['explanation']];
        }

        // Compute keyword score baseline
        $fcTopics = parse_tags($fc['topics'] ?? '');
        $fcGeo = parse_tags($fc['geography'] ?? '');
        $rTopics = parse_tags($r['topics'] ?? '');
        $rGeo = parse_tags($r['geography'] ?? '');
        $keywordScore = compute_match_score($fcTopics, $fcGeo, $rTopics, $rGeo);
        $scoreKeyword = (int)($keywordScore['totalScore'] ?? 0);

        // Build and send prompt
        $rName = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        $prompt = $this->buildPrompt(
            'scoreMatch',
            [
                'title' => $fc['title'] ?? '',
                'funder' => $fc['funder'] ?? '',
                'description' => $fc['description'] ?? '',
                'topics' => $fc['topics'] ?? '',
                'geography' => $fc['geography'] ?? '',
                'name' => $rName,
                'institution' => $r['institution'] ?? '',
                'bio' => $r['bio'] ?? '',
                'researcher_topics' => $r['topics'] ?? '',
                'researcher_geography' => $r['geography'] ?? '',
            ]
        );

        $response = $this->call(self::MODEL_HAIKU, $prompt, 'match_scoring', 400);
        if (!$response) return null;

        $parsed = $this->extractJson($response['content']);
        if (!is_array($parsed) || !isset($parsed['score']) || !isset($parsed['explanation'])) {
            error_log('[ClaudeService] Invalid match response: ' . $response['content']);
            return null;
        }

        $scoreAi = (int)$parsed['score'];
        $explanation = (string)$parsed['explanation'];

        // Cache to DB
        $stmt = $this->conn->prepare(
            'INSERT INTO match_scores (funding_call_id, researcher_id, score_keyword, score_ai, explanation, model_used)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE score_ai=VALUES(score_ai), explanation=VALUES(explanation),
                                     model_used=VALUES(model_used), computed_at=CURRENT_TIMESTAMP'
        );
        $modelUsed = self::MODEL_HAIKU;
        $scoreAiFloat = (float)$scoreAi;
        $stmt->bind_param('iidiss', $fcId, $rId, $scoreKeyword, $scoreAiFloat, $explanation, $modelUsed);
        $stmt->execute();

        return ['score' => $scoreAi, 'explanation' => $explanation];
    }

    /**
     * Generate a 2-3 sentence professional summary for a researcher.
     */
    public function summarizeResearcher(int $rId, array $r): ?string {
        if (!$this->isAvailable()) return null;

        $prompt = $this->buildPrompt(
            'summarizeResearcher',
            [
                'name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                'title' => $r['title'] ?? '',
                'institution' => $r['institution'] ?? '',
                'bio' => $r['bio'] ?? '',
                'focus_area' => $r['focus_area'] ?? '',
                'focus_area_detail' => $r['focus_area_detail'] ?? '',
                'topics' => $r['topics'] ?? '',
                'geography' => $r['geography'] ?? '',
                'co_advising_available' => ($r['co_advising'] ? 'Yes' : 'No'),
                'co_advising_details' => $r['co_advising_details'] ?? '',
            ]
        );
        $hash = hash('sha256', $prompt);

        // Check cache
        $stmt = $this->conn->prepare('SELECT summary, prompt_hash FROM ai_summaries WHERE entity_type=? AND entity_id=? LIMIT 1');
        $type = 'researcher';
        $stmt->bind_param('si', $type, $rId); $stmt->execute();
        $cached = $stmt->get_result()->fetch_assoc();
        if ($cached && $cached['prompt_hash'] === $hash) {
            return $cached['summary'];
        }

        // Generate
        $response = $this->call(self::MODEL_SONNET, $prompt, 'researcher_summary', 600);
        if (!$response) return null;

        $parsed = $this->extractJson($response['content']);
        if (!is_array($parsed) || !isset($parsed['summary'])) {
            error_log('[ClaudeService] Invalid researcher summary: ' . $response['content']);
            return null;
        }

        $summary = (string)$parsed['summary'];
        $modelUsed = self::MODEL_SONNET;

        // Cache
        $stmt = $this->conn->prepare(
            'INSERT INTO ai_summaries (entity_type, entity_id, summary, model_used, prompt_hash, token_input, token_output)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE summary=VALUES(summary), model_used=VALUES(model_used),
                                     prompt_hash=VALUES(prompt_hash), token_input=VALUES(token_input),
                                     token_output=VALUES(token_output), created_at=CURRENT_TIMESTAMP'
        );
        $inputTokens = $response['input_tokens'] ?? 0;
        $outputTokens = $response['output_tokens'] ?? 0;
        $stmt->bind_param('sisssii', $type, $rId, $summary, $modelUsed, $hash, $inputTokens, $outputTokens);
        $stmt->execute();

        return $summary;
    }

    /**
     * Generate a 2-3 sentence summary for a funding call.
     */
    public function summarizeFundingCall(int $fcId, array $fc): ?string {
        if (!$this->isAvailable()) return null;

        $prompt = $this->buildPrompt(
            'summarizeFundingCall',
            [
                'title' => $fc['title'] ?? '',
                'funder' => $fc['funder'] ?? '',
                'amount' => $fc['amount'] ?? '',
                'deadline' => $fc['deadline'] ?? '',
                'description' => $fc['description'] ?? '',
                'topics' => $fc['topics'] ?? '',
                'geography' => $fc['geography'] ?? '',
            ]
        );
        $hash = hash('sha256', $prompt);

        // Check cache
        $stmt = $this->conn->prepare('SELECT summary, prompt_hash FROM ai_summaries WHERE entity_type=? AND entity_id=? LIMIT 1');
        $type = 'funding_call';
        $stmt->bind_param('si', $type, $fcId); $stmt->execute();
        $cached = $stmt->get_result()->fetch_assoc();
        if ($cached && $cached['prompt_hash'] === $hash) {
            return $cached['summary'];
        }

        // Generate
        $response = $this->call(self::MODEL_SONNET, $prompt, 'funding_summary', 600);
        if (!$response) return null;

        $parsed = $this->extractJson($response['content']);
        if (!is_array($parsed) || !isset($parsed['summary'])) {
            error_log('[ClaudeService] Invalid funding summary: ' . $response['content']);
            return null;
        }

        $summary = (string)$parsed['summary'];
        $modelUsed = self::MODEL_SONNET;

        // Cache
        $stmt = $this->conn->prepare(
            'INSERT INTO ai_summaries (entity_type, entity_id, summary, model_used, prompt_hash, token_input, token_output)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE summary=VALUES(summary), model_used=VALUES(model_used),
                                     prompt_hash=VALUES(prompt_hash), token_input=VALUES(token_input),
                                     token_output=VALUES(token_output), created_at=CURRENT_TIMESTAMP'
        );
        $inputTokens = $response['input_tokens'] ?? 0;
        $outputTokens = $response['output_tokens'] ?? 0;
        $stmt->bind_param('sisssii', $type, $fcId, $summary, $modelUsed, $hash, $inputTokens, $outputTokens);
        $stmt->execute();

        return $summary;
    }

    /**
     * Parse a natural language search query into structured filters.
     * Returns ['topics'=>[], 'geographies'=>[], 'keywords'=>[], 'intent'=>string] or null.
     */
    public function parseSearchQuery(string $query): ?array {
        if (!$this->isAvailable()) return null;
        try {
            $queryJson = $this->escapePromptInput($query);
        } catch (Exception $e) {
            error_log('[ClaudeService] Invalid search query: ' . $e->getMessage());
            return null;
        }

        $prompt = "Parse this search query from a research funding platform. Handle informal phrasing, typos, abbreviations, and incomplete terms gracefully.

User Input: {$queryJson}

Instructions:
- Correct obvious typos (e.g. \"agrculture\" → \"agriculture\", \"mozambiqe\" → \"mozambique\", \"climte\" → \"climate\")
- Expand abbreviations (e.g. \"sub-saharan\" → \"sub-saharan africa\", \"HIV\" → \"hiv/aids\")
- Extract topics: research domains, disciplines, subject areas (e.g. \"food security\", \"water\", \"climate change\")
- Extract geographies: countries, regions, continents — normalize to standard lowercase names
- Extract keywords: institution names, funder names, researcher names, or any term not captured above
- Infer intent: find_funding | find_researcher | find_collaboration | unknown
- Return up to 4 synonym/related terms for the primary topic (e.g. \"agriculture\" → [\"farming\",\"food security\",\"crops\",\"agronomy\"])
- Return the corrected version of the raw query if you fixed typos

Respond with valid JSON only, no markdown:
{\"corrected_query\":\"\",\"topics\":[],\"geographies\":[],\"keywords\":[],\"intent\":\"\",\"synonyms\":[]}";

        $response = $this->call(self::MODEL_HAIKU, $prompt, 'search_parse', 400);
        if (!$response) return null;

        $parsed = $this->extractJson($response['content']);
        if (!is_array($parsed) || !isset($parsed['topics']) || !isset($parsed['geographies']) || !isset($parsed['keywords']) || !isset($parsed['intent'])) {
            error_log('[ClaudeService] Invalid search parse response: ' . $response['content']);
            return null;
        }

        return [
            'corrected_query' => (string)($parsed['corrected_query'] ?? ''),
            'topics' => (array)($parsed['topics'] ?? []),
            'geographies' => (array)($parsed['geographies'] ?? []),
            'keywords' => (array)($parsed['keywords'] ?? []),
            'intent' => (string)($parsed['intent'] ?? 'unknown'),
            'synonyms' => (array)($parsed['synonyms'] ?? []),
        ];
    }

    /**
     * Multi-turn conversational search — maintains context across query refinements.
     * Returns ['response'=>string, 'topics'=>[], 'geographies'=>[], ...] or null.
     */
    public function conversationalSearch(string $query, array $history, string $resultsSummary): ?array {
        if (!$this->isAvailable()) return null;

        // Format conversation history for the prompt
        $historyBlock = '';
        foreach ($history as $i => $turn) {
            $historyBlock .= "Turn " . ($i + 1) . ":\n";
            $historyBlock .= "User: " . (string)($turn['user'] ?? '') . "\n";
            $historyBlock .= "Assistant: " . (string)($turn['assistant'] ?? '') . "\n\n";
        }

        try {
            $queryJson = $this->escapePromptInput($query);
        } catch (Exception $e) {
            error_log('[ClaudeService] Invalid conversational query: ' . $e->getMessage());
            return null;
        }

        $prompt = "You are a search assistant for FACT Alliance Hub, a platform connecting researchers with funding in global development, health, climate, and agriculture.

Conversation so far:
" . ($historyBlock ?: "(No prior conversation)") . "

[USER_INPUT]
{$queryJson}
[/USER_INPUT]

Top results found by the search engine:
" . $resultsSummary . "

Your tasks:
1. Write a SHORT helpful response (2-4 sentences) that:
   - Confirms what was found (or not found)
   - Highlights the most relevant 1-2 results by name if applicable
   - Naturally continues the conversation, building on prior context
   - If nothing found: suggest alternative terms or related topics
2. Extract structured filters from the FULL conversation context (cumulative understanding, not just this turn).

Respond with valid JSON only, no markdown:
{\"response\":\"\",\"topics\":[],\"geographies\":[],\"keywords\":[],\"intent\":\"\",\"synonyms\":[]}";

        $response = $this->call(self::MODEL_HAIKU, $prompt, 'conversational_search', 500);
        if (!$response) return null;

        $parsed = $this->extractJson($response['content']);
        if (!is_array($parsed) || !isset($parsed['response'])) {
            error_log('[ClaudeService] Invalid conversational search response: ' . $response['content']);
            return null;
        }

        return [
            'response' => (string)($parsed['response'] ?? ''),
            'topics' => (array)($parsed['topics'] ?? []),
            'geographies' => (array)($parsed['geographies'] ?? []),
            'keywords' => (array)($parsed['keywords'] ?? []),
            'intent' => (string)($parsed['intent'] ?? 'unknown'),
            'synonyms' => (array)($parsed['synonyms'] ?? []),
        ];
    }

    /**
     * Core API call with retry logic.
     */
    private function call(string $model, string $prompt, string $purpose, int $maxTokens): ?array {
        $startMs = (int)(microtime(true) * 1000);
        $attempts = 0;
        $waitSec = 1;

        while ($attempts < 3) {
            $ch = curl_init(self::API_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode([
                    'model'      => $model,
                    'max_tokens' => $maxTokens,
                    'messages'   => [['role' => 'user', 'content' => $prompt]],
                ]),
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'x-api-key: ' . $this->apiKey,
                    'anthropic-version: ' . self::API_VERSION,
                ],
            ]);

            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            $durationMs = (int)(microtime(true) * 1000) - $startMs;

            if ($status === 200 && $body) {
                $data = json_decode($body, true);
                if (is_array($data) && isset($data['content'][0]['text'])) {
                    $content = $data['content'][0]['text'];
                    $inputTokens = (int)($data['usage']['input_tokens'] ?? 0);
                    $outputTokens = (int)($data['usage']['output_tokens'] ?? 0);

                    $this->logUsage($model, $purpose, $inputTokens, $outputTokens, $durationMs, 'ok');
                    return [
                        'content' => $content,
                        'input_tokens' => $inputTokens,
                        'output_tokens' => $outputTokens,
                        'model' => $model,
                    ];
                }
            }

            // Retry on 429/529
            if (in_array($status, [429, 529], true)) {
                $this->logUsage($model, $purpose, 0, 0, $durationMs, 'retry', (string)$status);
                sleep($waitSec);
                $waitSec *= 2;
                $attempts++;
                continue;
            }

            // Non-retryable error
            $this->logUsage($model, $purpose, 0, 0, $durationMs, 'error', (string)$status);
            error_log("[ClaudeService] API error status={$status} purpose={$purpose} error={$error}");
            return null;
        }

        $durationMs = (int)(microtime(true) * 1000) - $startMs;
        $this->logUsage($model, $purpose, 0, 0, $durationMs, 'error', 'max_retries');
        error_log("[ClaudeService] All retries exhausted for purpose={$purpose}");
        return null;
    }

    /**
     * Log API usage to database.
     */
    private function logUsage(string $model, string $purpose, int $in, int $out, int $ms, string $status, ?string $errCode = null): void {
        // Calculate cost
        $pricing = self::PRICING[$model] ?? null;
        if (!$pricing) return;

        $cost = (($in / 1_000_000) * $pricing['in']) + (($out / 1_000_000) * $pricing['out']);

        $stmt = $this->conn->prepare(
            'INSERT INTO api_usage (model, purpose, token_input, token_output, cost_usd, duration_ms, status, error_code, triggered_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE token_input=token_input+VALUES(token_input),
                                     token_output=token_output+VALUES(token_output),
                                     cost_usd=cost_usd+VALUES(cost_usd),
                                     duration_ms=duration_ms+VALUES(duration_ms)'
        );
        $stmt->bind_param('ssiidisss', $model, $purpose, $in, $out, $cost, $ms, $status, $errCode, $this->triggeredBy);
        $stmt->execute();
    }

    /**
     * Build a prompt by template name and substitution map.
     */
    private function buildPrompt(string $template, array $vars): string {
        $prompts = [
            'scoreMatch' => <<<'PROMPT'
You are a research funding matching expert. Score how well this researcher matches this funding call.

FUNDING CALL:
Title: {{title}} | Funder: {{funder}}
Description: {{description}}
Topics: {{topics}} | Geography: {{geography}}

RESEARCHER:
Name: {{name}} | Institution: {{institution}}
Bio: {{bio}}
Topics: {{researcher_topics}} | Geography: {{researcher_geography}}

Score 0-100 (0=no match, 100=perfect). Respond with valid JSON only, no markdown:
{"score": <int>, "explanation": "<one sentence explaining alignment or gap>"}
PROMPT,

            'summarizeResearcher' => <<<'PROMPT'
Write a 2-3 sentence professional summary for a research funding platform. Be specific. Do not invent details.

Name: {{name}} | Title: {{title}} | Institution: {{institution}}
Bio: {{bio}}
Focus Areas: {{focus_area}} | {{focus_area_detail}}
Topics: {{topics}} | Geography: {{geography}}
Open to co-advising: {{co_advising_available}} {{co_advising_details}}

Respond with valid JSON only, no markdown:
{"summary": "<2-3 sentence summary>"}
PROMPT,

            'summarizeFundingCall' => <<<'PROMPT'
Summarize this funding call in 2-3 plain English sentences for researchers evaluating whether to apply. Be specific. Do not invent details.

Title: {{title}} | Funder: {{funder}} | Amount: {{amount}} | Deadline: {{deadline}}
Description: {{description}} | Topics: {{topics}} | Geography: {{geography}}

Respond with valid JSON only, no markdown:
{"summary": "<2-3 sentence summary>"}
PROMPT,
        ];

        if (!isset($prompts[$template])) {
            return '';
        }

        $prompt = $prompts[$template];
        foreach ($vars as $key => $value) {
            $prompt = str_replace('{{' . $key . '}}', (string)$value, $prompt);
        }

        return $prompt;
    }

    /**
     * Generate embedding vector for text via Claude Embeddings API
     * Returns array of floats (1536-dimensional vector) or null on failure
     */
    public function getEmbedding(string $text): ?array {
        if (!$this->isAvailable()) {
            return null;
        }

        // Truncate to reasonable length
        if (mb_strlen($text) > 8000) {
            $text = mb_substr($text, 0, 8000);
        }

        $startMs = (int)(microtime(true) * 1000);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'model'      => 'claude-3-5-sonnet-20241022',
                'max_tokens' => 1024,
                'messages'   => [[
                    'role' => 'user',
                    'content' => "Convert this text to a semantic embedding. Return ONLY a JSON array of exactly 1536 numbers between -1 and 1:\n\n$text"
                ]],
            ]),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
            ],
        ]);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $durationMs = (int)(microtime(true) * 1000) - $startMs;

        if ($status !== 200 || !$body) {
            $this->logUsage('claude-3-5-sonnet-20241022', 'embedding', 0, 0, $durationMs, 'error');
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['content'][0]['text'])) {
            $this->logUsage('claude-3-5-sonnet-20241022', 'embedding', 0, 0, $durationMs, 'error');
            return null;
        }

        $inputTokens = (int)($data['usage']['input_tokens'] ?? 0);
        $outputTokens = (int)($data['usage']['output_tokens'] ?? 0);
        $this->logUsage('claude-3-5-sonnet-20241022', 'embedding', $inputTokens, $outputTokens, $durationMs, 'ok');

        // Parse embedding from response
        $response = $data['content'][0]['text'];
        $embedding = json_decode($response, true);

        if (!is_array($embedding) || count($embedding) !== 1536) {
            error_log("[ClaudeService] Invalid embedding response: " . substr($response, 0, 200));
            return null;
        }

        return $embedding;
    }
}
?>
