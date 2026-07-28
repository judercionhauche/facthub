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
            (title, description, url, status, funder_name, grant_amount, start_year, end_year)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $title = $data['title'];
        $desc = $data['description'] ?? null;
        $url = $data['url'] ?? null;
        $status = $data['status'] ?? 'active';
        $funder = $data['funder_name'] ?? null;
        $amount = $data['grant_amount'] ?? null;
        $startYear = $data['start_year'] ?? null;
        $endYear = $data['end_year'] ?? null;

        $stmt->bind_param(
            'sssssdii',
            $title, $desc, $url, $status, $funder, $amount, $startYear, $endYear
        );

        if ($stmt->execute()) {
            return $this->conn->insert_id;
        }
        return null;
    }

    public function updateResearchProject(int $id, array $data): bool {
        $stmt = $this->conn->prepare("
            UPDATE research_projects
            SET title = ?, description = ?, url = ?, status = ?,
                funder_name = ?, grant_amount = ?,
                start_year = ?, end_year = ?
            WHERE id = ?
        ");

        $title = $data['title'];
        $desc = $data['description'] ?? null;
        $url = $data['url'] ?? null;
        $status = $data['status'] ?? 'active';
        $funder = $data['funder_name'] ?? null;
        $amount = $data['grant_amount'] ?? null;
        $startYear = $data['start_year'] ?? null;
        $endYear = $data['end_year'] ?? null;

        $stmt->bind_param(
            'sssssdiii',
            $title, $desc, $url, $status, $funder, $amount, $startYear, $endYear, $id
        );

        return $stmt->execute();
    }

    public function getResearchProject(int $id): ?array {
        $stmt = $this->conn->prepare("
            SELECT rp.*,
                   GROUP_CONCAT(CONCAT(r.first_name, ' ', r.last_name) ORDER BY rpt.display_order SEPARATOR ', ') as team_members,
                   GROUP_CONCAT(r.id ORDER BY rpt.display_order SEPARATOR ',') as team_ids
            FROM research_projects rp
            LEFT JOIN research_project_team rpt ON rp.id = rpt.research_project_id
            LEFT JOIN researchers r ON rpt.researcher_id = r.id
            WHERE rp.id = ? AND rp.deleted_at IS NULL
            GROUP BY rp.id
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function listResearchProjects(): array {
        $result = $this->conn->query("
            SELECT rp.*,
                   GROUP_CONCAT(CONCAT(r.first_name, ' ', r.last_name) ORDER BY rpt.display_order SEPARATOR ', ') as team_members
            FROM research_projects rp
            LEFT JOIN research_project_team rpt ON rp.id = rpt.research_project_id
            LEFT JOIN researchers r ON rpt.researcher_id = r.id
            WHERE rp.deleted_at IS NULL
            GROUP BY rp.id
            ORDER BY rp.created_at DESC
        ");
        return $result->fetch_all(MYSQLI_ASSOC) ?? [];
    }

    public function deleteResearchProject(int $id): bool {
        $stmt = $this->conn->prepare("UPDATE research_projects SET deleted_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    public function addResearchTeamMember(int $projectId, int $researcherId, int $order = 0): bool {
        $stmt = $this->conn->prepare("
            INSERT INTO research_project_team (research_project_id, researcher_id, display_order)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE display_order = ?
        ");
        $stmt->bind_param('iiii', $projectId, $researcherId, $order, $order);
        return $stmt->execute();
    }

    public function removeResearchTeamMember(int $projectId, int $researcherId): bool {
        $stmt = $this->conn->prepare("DELETE FROM research_project_team WHERE research_project_id = ? AND researcher_id = ?");
        $stmt->bind_param('ii', $projectId, $researcherId);
        return $stmt->execute();
    }

    // ──────────────────────────────────────────────────────────────
    // PUBLICATIONS
    // ──────────────────────────────────────────────────────────────

    public function createPublication(array $data): ?int {
        $stmt = $this->conn->prepare("
            INSERT INTO publications_showcase
            (title, description, url, publication_year, funder_name, grant_amount, grant_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $title = $data['title'];
        $desc = $data['description'] ?? null;
        $url = $data['url'];
        $year = $data['publication_year'] ?? null;
        $funder = $data['funder_name'] ?? null;
        $amount = $data['grant_amount'] ?? null;
        $grantId = $data['grant_id'] ?? null;

        $stmt->bind_param(
            'sssisds',
            $title, $desc, $url, $year, $funder, $amount, $grantId
        );

        if ($stmt->execute()) {
            return $this->conn->insert_id;
        }
        return null;
    }

    public function updatePublication(int $id, array $data): bool {
        $stmt = $this->conn->prepare("
            UPDATE publications_showcase
            SET title = ?, description = ?, url = ?, publication_year = ?,
                funder_name = ?, grant_amount = ?, grant_id = ?
            WHERE id = ?
        ");

        $title = $data['title'];
        $desc = $data['description'] ?? null;
        $url = $data['url'];
        $year = $data['publication_year'] ?? null;
        $funder = $data['funder_name'] ?? null;
        $amount = $data['grant_amount'] ?? null;
        $grantId = $data['grant_id'] ?? null;

        $stmt->bind_param(
            'sssisdsii',
            $title, $desc, $url, $year, $funder, $amount, $grantId, $id
        );

        return $stmt->execute();
    }

    public function getPublication(int $id): ?array {
        $stmt = $this->conn->prepare("
            SELECT ps.*,
                   GROUP_CONCAT(CONCAT(r.first_name, ' ', r.last_name) ORDER BY pt.display_order SEPARATOR ', ') as team_members,
                   GROUP_CONCAT(r.id ORDER BY pt.display_order SEPARATOR ',') as team_ids
            FROM publications_showcase ps
            LEFT JOIN publication_team pt ON ps.id = pt.publication_id
            LEFT JOIN researchers r ON pt.researcher_id = r.id
            WHERE ps.id = ? AND ps.deleted_at IS NULL
            GROUP BY ps.id
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function listPublications(): array {
        $result = $this->conn->query("
            SELECT ps.*,
                   GROUP_CONCAT(CONCAT(r.first_name, ' ', r.last_name) ORDER BY pt.display_order SEPARATOR ', ') as team_members
            FROM publications_showcase ps
            LEFT JOIN publication_team pt ON ps.id = pt.publication_id
            LEFT JOIN researchers r ON pt.researcher_id = r.id
            WHERE ps.deleted_at IS NULL
            GROUP BY ps.id
            ORDER BY ps.created_at DESC
        ");
        return $result->fetch_all(MYSQLI_ASSOC) ?? [];
    }

    public function deletePublication(int $id): bool {
        $stmt = $this->conn->prepare("UPDATE publications_showcase SET deleted_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    public function addPublicationTeamMember(int $publicationId, int $researcherId, int $order = 0): bool {
        $stmt = $this->conn->prepare("
            INSERT INTO publication_team (publication_id, researcher_id, display_order)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE display_order = ?
        ");
        $stmt->bind_param('iiii', $publicationId, $researcherId, $order, $order);
        return $stmt->execute();
    }

    public function removePublicationTeamMember(int $publicationId, int $researcherId): bool {
        $stmt = $this->conn->prepare("DELETE FROM publication_team WHERE publication_id = ? AND researcher_id = ?");
        $stmt->bind_param('ii', $publicationId, $researcherId);
        return $stmt->execute();
    }

    public function setResearchTeam(int $projectId, array $researcherIds): void {
        $stmt = $this->conn->prepare("DELETE FROM research_project_team WHERE research_project_id = ?");
        $stmt->bind_param('i', $projectId);
        $stmt->execute();
        $order = 0;
        foreach ($researcherIds as $rid) {
            $this->addResearchTeamMember($projectId, (int)$rid, $order++);
        }
    }

    public function setPublicationTeam(int $publicationId, array $researcherIds): void {
        $stmt = $this->conn->prepare("DELETE FROM publication_team WHERE publication_id = ?");
        $stmt->bind_param('i', $publicationId);
        $stmt->execute();
        $order = 0;
        foreach ($researcherIds as $rid) {
            $this->addPublicationTeamMember($publicationId, (int)$rid, $order++);
        }
    }

    public function getResearcherOptions(): array {
        $result = $this->conn->query("
            SELECT id, CONCAT(first_name, ' ', last_name) as name
            FROM researchers
            WHERE deleted_at IS NULL AND status = 'active'
            ORDER BY first_name, last_name
        ");
        return $result->fetch_all(MYSQLI_ASSOC) ?? [];
    }
}
