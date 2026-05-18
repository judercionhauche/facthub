# Database Schema Audit & Fix — May 18, 2026

## Problem Identified

The FACT Alliance Hub database had multiple schema mismatches causing 500 errors on:
- Funding page (blank/error when adding/editing funding calls)
- Admin panel (blank/error when viewing admin dashboard)
- AI search (errors in ClaudeService API usage tracking)

## Root Causes

### 1. Missing 'funder' Column in funding_calls
**Impact**: HIGH
- **Code expectation**: `funder` VARCHAR column (text name of funder, e.g. "World Health Organization")
- **Code locations**:
  - funding/index.php: POST handler saves funder (line 22, 34, 40)
  - funding/index.php: View displays funder (lines 395, 406, 411)
  - ClaudeService: Includes funder in AI summary prompts (lines 88, 197)
  - chat_search.php: Uses funder in search scoring (line 196)
- **Database state**: Only had `funder_id` (INT FK), or missing entirely

### 2. Missing Profile Columns in researchers
**Impact**: MEDIUM
- **Missing columns**:
  - `notify_matches`: TINYINT - controls whether researcher gets match notifications
  - `focus_area`: VARCHAR - research focus area
  - `focus_area_detail`: VARCHAR - detailed focus area description
  - `co_advising`: TINYINT - boolean flag for co-advising availability
  - `co_advising_details`: VARCHAR - details about co-advising

### 3. Missing Token Tracking in ai_summaries
**Impact**: MEDIUM
- **Missing columns**:
  - `token_input`: INT - input tokens for cost calculation
  - `token_output`: INT - output tokens for cost calculation
- **Code location**: ClaudeService lines 173, 181, 231, 239

### 4. Missing Model Tracking in match_scores
**Impact**: MEDIUM
- **Missing columns**:
  - `model_used`: VARCHAR - which Claude model generated the score
  - `computed_at`: TIMESTAMP - when the score was computed
- **Code location**: ClaudeService lines 114, 117

## Solution Implemented

### Updated: app/core/schema_updates.php

Added idempotent SQL migrations that run on every app start (via public/index.php):

1. **Funding Calls**:
   ```sql
   ALTER TABLE funding_calls ADD COLUMN funder VARCHAR(255) DEFAULT NULL;
   ```

2. **Researchers Profile**:
   ```sql
   ALTER TABLE researchers ADD COLUMN notify_matches TINYINT DEFAULT 1;
   ALTER TABLE researchers ADD COLUMN focus_area VARCHAR(255);
   ALTER TABLE researchers ADD COLUMN focus_area_detail VARCHAR(255);
   ALTER TABLE researchers ADD COLUMN co_advising TINYINT DEFAULT 0;
   ALTER TABLE researchers ADD COLUMN co_advising_details VARCHAR(255);
   ```

3. **AI Summaries Tokens**:
   ```sql
   ALTER TABLE ai_summaries ADD COLUMN token_input INT DEFAULT 0;
   ALTER TABLE ai_summaries ADD COLUMN token_output INT DEFAULT 0;
   ```

4. **Match Scores Tracking**:
   ```sql
   ALTER TABLE match_scores ADD COLUMN model_used VARCHAR(100);
   ALTER TABLE match_scores ADD COLUMN computed_at TIMESTAMP NULL;
   ```

## How Migrations Work

- **Idempotent**: All migrations check if column exists before adding
- **Non-disruptive**: Uses `information_schema` checks, won't fail if column already exists
- **Automatic**: Runs on first request after code deployment (in public/index.php, lines 40-48)
- **Safe**: Uses `@` error suppression for graceful handling of schema issues

## Testing

### Before Deployment
```bash
# Verify all columns exist in local MySQL
DESCRIBE funding_calls;  # Should have 'funder' VARCHAR
DESCRIBE researchers;    # Should have 'notify_matches', 'focus_area', etc.
DESCRIBE ai_summaries;   # Should have 'token_input', 'token_output'
DESCRIBE match_scores;   # Should have 'model_used', 'computed_at'
```

### After Deployment to EC2
1. First page load triggers schema_updates
2. Check error_log for migration success
3. Verify funding page loads without errors
4. Test adding/editing funding calls
5. Test admin panel access
6. Test AI search queries

## Related Commits

- `94a92f3`: Feat: Ensure all required database columns exist
- `b0b9b50`: Fix: Use correct funding_calls column names (partial - removed from chat_search only)

## Future Prevention

- Code review must verify database column usage before implementation
- Schema changes should be tested locally before EC2 deployment
- Add integration tests that verify schema matches code expectations
