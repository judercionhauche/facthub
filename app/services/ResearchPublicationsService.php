<?php
/**
 * Research & Publications Management Service
 * Handles CRUD for research projects and publications showcase
 */

class ResearchPublicationsService {
    private mysqli $conn;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    // ──────────────────────────────────────────────────────────────
    // RESEARCH PROJECTS
    // ──────────────────────────────────────────────────────────────

    public function createResearchProject(array $data): ?int {
        $stmt = $this->conn->prepare("
            INSERT INTO research_projects
            (title, description, url, team_members, status, funder_name, grant_amount, start_year, end_year)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $title = $data['title'];
        $desc = $data['description'] ?? null;
        $url = $data['url'] ?? null;
        $team = $data['team_members'] ?? null;
        $status = $data['status'] ?? 'active';
        $funder = $data['funder_name'] ?? null;
        $amount = $data['grant_amount'] ?? null;
        $startYear = $data['start_year'] ?? null;
        $endYear = $data['end_year'] ?? null;

        $stmt->bind_param(
            'ssssssdii',
            $title, $desc, $url, $team, $status, $funder, $amount, $startYear, $endYear
        );

        if ($stmt->execute()) {
            return $this->conn->insert_id;
        }
        return null;
    }

    public function updateResearchProject(int $id, array $data): bool {
        $stmt = $this->conn->prepare("
            UPDATE research_projects
            SET title = ?, description = ?, url = ?, team_members = ?, status = ?,
                funder_name = ?, grant_amount = ?,
                start_year = ?, end_year = ?
            WHERE id = ?
        ");

        $title = $data['title'];
        $desc = $data['description'] ?? null;
        $url = $data['url'] ?? null;
        $team = $data['team_members'] ?? null;
        $status = $data['status'] ?? 'active';
        $funder = $data['funder_name'] ?? null;
        $amount = $data['grant_amount'] ?? null;
        $startYear = $data['start_year'] ?? null;
        $endYear = $data['end_year'] ?? null;

        $stmt->bind_param(
            'ssssssdiii',
            $title, $desc, $url, $team, $status, $funder, $amount, $startYear, $endYear, $id
        );

        return $stmt->execute();
    }

    public function getResearchProject(int $id): ?array {
        $stmt = $this->conn->prepare("
            SELECT * FROM research_projects
            WHERE id = ? AND deleted_at IS NULL
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function listResearchProjects(): array {
        $result = $this->conn->query("
            SELECT * FROM research_projects
            WHERE deleted_at IS NULL
            ORDER BY created_at DESC
        ");
        return $result->fetch_all(MYSQLI_ASSOC) ?? [];
    }

    public function deleteResearchProject(int $id): bool {
        $stmt = $this->conn->prepare("UPDATE research_projects SET deleted_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    // ──────────────────────────────────────────────────────────────
    // PUBLICATIONS
    // ──────────────────────────────────────────────────────────────

    public function createPublication(array $data): ?int {
        $stmt = $this->conn->prepare("
            INSERT INTO publications_showcase
            (title, description, url, team_members, publication_year, funder_name, grant_amount)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $title = $data['title'];
        $desc = $data['description'] ?? null;
        $url = $data['url'];
        $team = $data['team_members'] ?? null;
        $year = $data['publication_year'] ?? null;
        $funder = $data['funder_name'] ?? null;
        $amount = $data['grant_amount'] ?? null;

        $stmt->bind_param(
            'ssssisd',
            $title, $desc, $url, $team, $year, $funder, $amount
        );

        if ($stmt->execute()) {
            return $this->conn->insert_id;
        }
        return null;
    }

    public function updatePublication(int $id, array $data): bool {
        $stmt = $this->conn->prepare("
            UPDATE publications_showcase
            SET title = ?, description = ?, url = ?, team_members = ?,
                publication_year = ?, funder_name = ?, grant_amount = ?
            WHERE id = ?
        ");

        $title = $data['title'];
        $desc = $data['description'] ?? null;
        $url = $data['url'];
        $team = $data['team_members'] ?? null;
        $year = $data['publication_year'] ?? null;
        $funder = $data['funder_name'] ?? null;
        $amount = $data['grant_amount'] ?? null;

        $stmt->bind_param(
            'ssssisdi',
            $title, $desc, $url, $team, $year, $funder, $amount, $id
        );

        return $stmt->execute();
    }

    public function getPublication(int $id): ?array {
        $stmt = $this->conn->prepare("
            SELECT * FROM publications_showcase
            WHERE id = ? AND deleted_at IS NULL
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function listPublications(): array {
        $result = $this->conn->query("
            SELECT * FROM publications_showcase
            WHERE deleted_at IS NULL
            ORDER BY created_at DESC
        ");
        return $result->fetch_all(MYSQLI_ASSOC) ?? [];
    }

    public function deletePublication(int $id): bool {
        $stmt = $this->conn->prepare("UPDATE publications_showcase SET deleted_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }
}
